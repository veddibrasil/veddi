<?php

namespace App\Services\Company;

use App\Contracts\AsaasServiceInterface;
use App\DTOs\CreditCardDTO;
use App\DTOs\CreditCardHolderDTO;
use App\Enums\Plan;
use App\Exceptions\CardPaymentDeclinedException;
use App\Exceptions\SubscriptionBillingException;
use App\Models\Company;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Cobranças no cartão que mexem na assinatura da empresa: ativar módulo, trocar de
 * plano e o checkout do onboarding.
 *
 * Regra de ordem: primeiro o que não move dinheiro e pode ser desfeito (criar ou
 * atualizar a assinatura), por último a cobrança. Se a cobrança é recusada, o passo
 * anterior é desfeito; se algo falha depois dela, ela é estornada. Assim o cliente
 * nunca fica cobrado sem o que pagou — foi o que aconteceu com a empresa 5 em 24/10,
 * quando o cartão foi cobrado e a nova assinatura recusada em seguida.
 */
class SubscriptionBillingService
{
    /** O Asaas não aceita cobrança abaixo de R$ 5,00: proporcional menor que isso não é cobrado. */
    public const MIN_CHARGE = 5.00;

    public function __construct(private AsaasServiceInterface $asaas) {}

    /**
     * Quanto a ativação do módulo cobra agora e quanto passa a ser a mensalidade.
     *
     * @return array{charge_now: float, prorated: bool, monthly_total: float, next_due_date: CarbonImmutable}
     */
    public function quoteModuleActivation(Company $company, string $module): array
    {
        $monthlyTotal = $this->monthlyTotal($company, AddonModulePricing::extraFor($company, [$module => true]));

        if (! $company->asaas_subscription_id) {
            return [
                'charge_now' => $monthlyTotal,
                'prorated' => false,
                'monthly_total' => $monthlyTotal,
                'next_due_date' => CarbonImmutable::today()->addMonthNoOverflow(),
            ];
        }

        $nextChargeDate = $this->nextChargeDate($company->asaas_subscription_id);

        return [
            'charge_now' => $this->proratedCharge(AddonModulePricing::price($module), $nextChargeDate),
            'prorated' => true,
            'monthly_total' => $monthlyTotal,
            'next_due_date' => $nextChargeDate,
        ];
    }

    /**
     * Ativa o módulo. Com assinatura ativa, só soma o módulo a ela (sem recriar nem
     * reenviar o cartão) e cobra o proporcional até o próximo vencimento; sem
     * assinatura, cria uma já com o módulo e cobra o 1º mês.
     *
     * @return array{charged: float, monthly_total: float, next_due_date: CarbonImmutable, subscription_id: string}
     *
     * @throws CardPaymentDeclinedException|SubscriptionBillingException
     */
    public function activateModule(
        Company $company,
        string $module,
        ?CreditCardDTO $creditCard,
        ?CreditCardHolderDTO $holderInfo,
        ?string $creditCardToken = null,
    ): array {
        return $this->withCompanyLock($company, function () use ($company, $module, $creditCard, $holderInfo, $creditCardToken) {
            $company->refresh();

            if ($company->{AddonModulePricing::MODULES[$module]['column']}) {
                throw new SubscriptionBillingException('Este módulo já está ativo.');
            }

            if ($module === 'waiter' && ! $company->pdv_module_enabled) {
                throw new SubscriptionBillingException('Ative o módulo PDV antes de ativar o módulo Garçom.');
            }

            if (! $company->asaas_customer_id) {
                throw new SubscriptionBillingException('Esta empresa não possui cadastro no Asaas. Entre em contato com o suporte.');
            }

            $result = $company->asaas_subscription_id
                ? $this->addModuleToSubscription($company, $module, $creditCard, $holderInfo, $creditCardToken)
                : $this->subscribeWithModule($company, $module, $creditCard, $holderInfo, $creditCardToken);

            if ($module === 'pdv') {
                UserPermissionService::grantPdvPermissions($company->fresh());
            }

            Log::channel('payments')->info('Módulo ativado na assinatura', [
                'company_id' => $company->id,
                'module' => $module,
                'charged' => $result['charged'],
                'monthly_total' => $result['monthly_total'],
                'subscription_id' => $result['subscription_id'],
            ]);

            return $result;
        });
    }

    /**
     * Troca de plano no cartão: cria a assinatura nova, cobra o 1º mês (+ taxa de ativação
     * quando vem do gratuito) e só então cancela a assinatura antiga.
     *
     * @return array{id: string, value: float, nextDueDate: string}
     *
     * @throws CardPaymentDeclinedException|SubscriptionBillingException
     */
    public function changePlanByCard(
        Company $company,
        Plan $targetPlan,
        ?CreditCardDTO $creditCard,
        ?CreditCardHolderDTO $holderInfo,
        ?string $creditCardToken = null,
    ): array {
        return $this->withCompanyLock($company, function () use ($company, $targetPlan, $creditCard, $holderInfo, $creditCardToken) {
            $company->refresh();

            // Sempre a partir do plano gravado na empresa, nunca do estado da tela.
            $isFromFree = $company->plan === Plan::Free;
            $extra = AddonModulePricing::extraFor($company);
            $chargeAmount = ($isFromFree ? $targetPlan->setupFee() : 0.0) + $targetPlan->monthlyPrice() + $extra['amount'];
            $description = $isFromFree
                ? "Taxa de ativação + 1º mês ({$targetPlan->label()}) — {$company->name}"
                : "1º mês plano {$targetPlan->label()} — {$company->name}";
            if ($extra['description'] !== '') {
                $description .= " + {$extra['description']}";
            }

            ['subscription' => $subscription, 'charge' => $charge] = $this->subscribeThenCharge(
                company: $company,
                plan: $targetPlan,
                extra: $extra,
                chargeAmount: $chargeAmount,
                description: $description,
                externalReference: "plan_change_{$company->id}_{$targetPlan->value}",
                creditCard: $creditCard,
                holderInfo: $holderInfo,
                creditCardToken: $creditCardToken,
            );

            $oldSubscriptionId = $company->asaas_subscription_id;

            $this->persistAfterCharge($company, $charge, $subscription['id'], function () use ($company, $targetPlan, $subscription, $isFromFree) {
                Subscription::create([
                    'company_id' => $company->id,
                    'asaas_subscription_id' => $subscription['id'],
                    'plan' => $targetPlan->value,
                    'status' => 'active',
                    'amount' => $subscription['value'],
                    'billing_cycle' => 'MONTHLY',
                    'next_due_date' => $subscription['nextDueDate'],
                ]);

                $company->update(array_merge([
                    'plan' => $targetPlan->value,
                    'pending_plan' => null,
                    'status' => 'ACTIVE',
                    'active' => true,
                    'asaas_subscription_id' => $subscription['id'],
                    'subscription_payment_method' => 'CREDIT_CARD',
                ], $isFromFree ? ['setup_fee_paid_at' => now()] : []));
            });

            $company->saveAsaasCreditCardFromCharge($charge);

            if ($oldSubscriptionId && $oldSubscriptionId !== $subscription['id']) {
                $this->retireSubscription($company, $oldSubscriptionId);
            }

            return $subscription;
        });
    }

    /**
     * Cria a assinatura no cartão e só depois cobra o valor imediato. Recusa em qualquer
     * dos dois passos não deixa nada para trás: a assinatura recém-criada é apagada.
     * Não grava nada no banco — quem chama persiste o resultado.
     *
     * @param  array{amount: float, description: string}  $extra
     * @return array{subscription: array{id: string, value: float, nextDueDate: string}, charge: array}
     *
     * @throws CardPaymentDeclinedException
     */
    public function subscribeThenCharge(
        Company $company,
        Plan $plan,
        array $extra,
        float $chargeAmount,
        string $description,
        string $externalReference,
        ?CreditCardDTO $creditCard,
        ?CreditCardHolderDTO $holderInfo,
        ?string $creditCardToken = null,
    ): array {
        try {
            $subscription = $this->asaas->createSubscription(
                customerId: $company->asaas_customer_id,
                plan: $plan,
                billingType: 'CREDIT_CARD',
                creditCard: $creditCardToken ? null : $creditCard,
                holderInfo: $creditCardToken ? null : $holderInfo,
                nextDueDate: CarbonImmutable::today()->addMonthNoOverflow()->toDateString(),
                extraAmount: $extra['amount'],
                extraDescription: $extra['description'],
                creditCardToken: $creditCardToken,
            );
        } catch (RuntimeException $e) {
            // 4xx na criação com cartão é o Asaas/banco recusando o cartão (invalid_creditCard).
            if ($e->getCode() >= 400 && $e->getCode() < 500) {
                throw new CardPaymentDeclinedException(previous: $e);
            }

            throw $e;
        }

        try {
            $charge = $this->asaas->createCreditCardCharge(
                customerId: $company->asaas_customer_id,
                amount: $chargeAmount,
                description: $description,
                externalReference: $externalReference,
                creditCard: $creditCardToken ? null : $creditCard,
                holderInfo: $creditCardToken ? null : $holderInfo,
                creditCardToken: $creditCardToken,
            );
        } catch (\Throwable $e) {
            $this->undo($company, 'assinatura criada antes da cobrança', fn () => $this->asaas->cancelSubscription($subscription['id']));

            throw $e;
        }

        if (($charge['status'] ?? '') !== 'CONFIRMED') {
            $this->undo($company, 'assinatura criada antes da cobrança recusada', fn () => $this->asaas->cancelSubscription($subscription['id']));

            throw CardPaymentDeclinedException::fromCharge($charge);
        }

        return ['subscription' => $subscription, 'charge' => $charge];
    }

    /**
     * @return array{charged: float, monthly_total: float, next_due_date: CarbonImmutable, subscription_id: string}
     */
    private function addModuleToSubscription(
        Company $company,
        string $module,
        ?CreditCardDTO $creditCard,
        ?CreditCardHolderDTO $holderInfo,
        ?string $creditCardToken,
    ): array {
        $subscriptionId = $company->asaas_subscription_id;
        $before = AddonModulePricing::extraFor($company);
        $after = AddonModulePricing::extraFor($company, [$module => true]);
        $monthlyBefore = $this->monthlyTotal($company, $before);
        $monthlyAfter = $this->monthlyTotal($company, $after);

        $nextChargeDate = $this->nextChargeDate($subscriptionId);
        $chargeNow = $this->proratedCharge(AddonModulePricing::price($module), $nextChargeDate);

        $this->asaas->updateSubscriptionValue($subscriptionId, $monthlyAfter, $this->subscriptionDescription($company->plan, $after));

        $restoreValue = fn () => $this->asaas->updateSubscriptionValue(
            $subscriptionId,
            $monthlyBefore,
            $this->subscriptionDescription($company->plan, $before),
        );

        $charge = null;

        if ($chargeNow > 0) {
            try {
                $charge = $this->asaas->createCreditCardCharge(
                    customerId: $company->asaas_customer_id,
                    amount: $chargeNow,
                    description: AddonModulePricing::MODULES[$module]['label']." — proporcional até {$nextChargeDate->format('d/m/Y')} — {$company->name}",
                    externalReference: "{$module}_module_activation_{$company->id}",
                    creditCard: $creditCardToken ? null : $creditCard,
                    holderInfo: $creditCardToken ? null : $holderInfo,
                    creditCardToken: $creditCardToken,
                );
            } catch (\Throwable $e) {
                $this->undo($company, 'valor da assinatura após falha na cobrança', $restoreValue);

                throw $e;
            }

            if (($charge['status'] ?? '') !== 'CONFIRMED') {
                $this->undo($company, 'valor da assinatura após cartão recusado', $restoreValue);

                throw CardPaymentDeclinedException::fromCharge($charge);
            }
        }

        try {
            DB::transaction(function () use ($company, $module, $subscriptionId, $monthlyAfter, $nextChargeDate) {
                $company->update([AddonModulePricing::MODULES[$module]['column'] => true]);

                Subscription::where('company_id', $company->id)
                    ->where('asaas_subscription_id', $subscriptionId)
                    ->update(['amount' => $monthlyAfter, 'next_due_date' => $nextChargeDate->toDateString()]);
            });
        } catch (\Throwable $e) {
            if ($charge) {
                $this->undo($company, 'cobrança do módulo (estorno)', fn () => $this->asaas->refundPayment($charge['id']));
            }
            $this->undo($company, 'valor da assinatura após falha ao gravar', $restoreValue);

            throw $e;
        }

        if ($charge) {
            $company->saveAsaasCreditCardFromCharge($charge);
        }

        return [
            'charged' => $chargeNow,
            'monthly_total' => $monthlyAfter,
            'next_due_date' => $nextChargeDate,
            'subscription_id' => $subscriptionId,
        ];
    }

    /**
     * @return array{charged: float, monthly_total: float, next_due_date: CarbonImmutable, subscription_id: string}
     */
    private function subscribeWithModule(
        Company $company,
        string $module,
        ?CreditCardDTO $creditCard,
        ?CreditCardHolderDTO $holderInfo,
        ?string $creditCardToken,
    ): array {
        $plan = $company->plan;
        $after = AddonModulePricing::extraFor($company, [$module => true]);
        $monthlyAfter = $this->monthlyTotal($company, $after);

        ['subscription' => $subscription, 'charge' => $charge] = $this->subscribeThenCharge(
            company: $company,
            plan: $plan,
            extra: $after,
            chargeAmount: $monthlyAfter,
            description: "{$this->subscriptionDescription($plan, $after)} — {$company->name}",
            externalReference: "{$module}_module_activation_{$company->id}",
            creditCard: $creditCard,
            holderInfo: $holderInfo,
            creditCardToken: $creditCardToken,
        );

        $this->persistAfterCharge($company, $charge, $subscription['id'], function () use ($company, $module, $plan, $subscription) {
            Subscription::create([
                'company_id' => $company->id,
                'asaas_subscription_id' => $subscription['id'],
                'plan' => $plan->value,
                'status' => 'active',
                'amount' => $subscription['value'],
                'billing_cycle' => 'MONTHLY',
                'next_due_date' => $subscription['nextDueDate'],
            ]);

            $company->update([
                AddonModulePricing::MODULES[$module]['column'] => true,
                'asaas_subscription_id' => $subscription['id'],
                'subscription_payment_method' => 'CREDIT_CARD',
            ]);
        });

        $company->saveAsaasCreditCardFromCharge($charge);

        return [
            'charged' => $monthlyAfter,
            'monthly_total' => (float) $subscription['value'],
            'next_due_date' => CarbonImmutable::parse($subscription['nextDueDate']),
            'subscription_id' => $subscription['id'],
        ];
    }

    /**
     * Data da próxima cobrança da assinatura: o vencimento da fatura pendente já gerada
     * ou, se ainda não houver, o nextDueDate da assinatura.
     *
     * @throws SubscriptionBillingException quando a assinatura não está ativa no Asaas
     */
    private function nextChargeDate(string $subscriptionId): CarbonImmutable
    {
        $subscription = $this->asaas->getSubscription($subscriptionId);

        if ($subscription['deleted'] || $subscription['status'] !== 'ACTIVE' || ! $subscription['nextDueDate']) {
            throw new SubscriptionBillingException('Sua assinatura não está ativa no momento. Fale com o suporte para regularizar antes de ativar módulos.');
        }

        $today = CarbonImmutable::today();

        $pendingDueDate = collect($this->asaas->getPendingSubscriptionPayments($subscriptionId))
            ->filter(fn (array $payment) => ($payment['status'] ?? null) === 'PENDING' && ! empty($payment['dueDate']))
            ->map(fn (array $payment) => CarbonImmutable::parse($payment['dueDate'])->startOfDay())
            ->filter(fn (CarbonImmutable $dueDate) => $dueDate->gte($today))
            ->sort()
            ->first();

        return $pendingDueDate ?? CarbonImmutable::parse($subscription['nextDueDate'])->startOfDay();
    }

    private function proratedCharge(float $monthlyAmount, CarbonImmutable $nextChargeDate): float
    {
        $amount = AddonModulePricing::prorate($monthlyAmount, CarbonImmutable::today(), $nextChargeDate);

        return $amount >= self::MIN_CHARGE ? $amount : 0.0;
    }

    /**
     * @param  array{amount: float, description: string}  $extra
     */
    private function monthlyTotal(Company $company, array $extra): float
    {
        return ($company->plan?->monthlyPrice() ?? 0.0) + $extra['amount'];
    }

    /**
     * Mesmo formato que AsaasService::createSubscription usa na descrição da assinatura.
     *
     * @param  array{amount: float, description: string}  $extra
     */
    private function subscriptionDescription(Plan $plan, array $extra): string
    {
        return $extra['description'] !== ''
            ? "{$plan->asaasDescription()} + {$extra['description']}"
            : $plan->asaasDescription();
    }

    /**
     * Grava no banco o resultado de uma cobrança já aprovada. Se a gravação falhar, a
     * cobrança é estornada e a assinatura nova apagada, para não ficar cobrança sem registro.
     */
    private function persistAfterCharge(Company $company, array $charge, string $subscriptionId, callable $persist): void
    {
        try {
            DB::transaction($persist);
        } catch (\Throwable $e) {
            $this->undo($company, 'cobrança aprovada (estorno)', fn () => $this->asaas->refundPayment($charge['id']));
            $this->undo($company, 'assinatura criada', fn () => $this->asaas->cancelSubscription($subscriptionId));

            throw $e;
        }
    }

    /**
     * Cancela a assinatura substituída. A nova já está paga e gravada, então uma falha
     * aqui não desfaz a troca: vira alerta, porque o cliente ficaria com duas assinaturas.
     */
    private function retireSubscription(Company $company, string $subscriptionId): void
    {
        try {
            $this->asaas->cancelSubscription($subscriptionId);

            Subscription::where('company_id', $company->id)
                ->where('asaas_subscription_id', $subscriptionId)
                ->whereIn('status', ['active', 'pending', 'overdue'])
                ->update(['status' => 'cancelled']);
        } catch (\Throwable $e) {
            Log::channel('discord')->error('Assinatura antiga não foi cancelada após troca de plano — cliente com duas assinaturas ativas', [
                'type' => 'payments',
                'company_id' => $company->id,
                'subscription_id' => $subscriptionId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Desfaz um passo já executado no Asaas. Se o próprio desfazer falhar, o caso precisa
     * de ação manual: vai para o Discord com o que ficou pendurado.
     */
    private function undo(Company $company, string $step, callable $undo): void
    {
        try {
            $undo();
        } catch (\Throwable $e) {
            Log::channel('discord')->error("Cobrança de assinatura: falha ao desfazer {$step} — verificar no Asaas", [
                'type' => 'payments',
                'company_id' => $company->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Uma operação de cobrança por empresa por vez: clique duplo ou duas abas não podem
     * cobrar duas vezes.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withCompanyLock(Company $company, callable $callback): mixed
    {
        $lock = Cache::lock("subscription-billing:company:{$company->id}", 120);

        if (! $lock->get()) {
            throw new SubscriptionBillingException('Já existe um pagamento em processamento para esta empresa. Aguarde alguns segundos e atualize a página.');
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }
}
