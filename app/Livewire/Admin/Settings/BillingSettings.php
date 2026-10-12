<?php

namespace App\Livewire\Admin\Settings;

use App\Contracts\AsaasServiceInterface;
use App\DTOs\CreditCardDTO;
use App\DTOs\CreditCardHolderDTO;
use App\Enums\Plan;
use App\Jobs\CreateAsaasSubscription;
use App\Models\Company;
use App\Models\Subscription;
use App\Services\Company\AddonModulePricing;
use App\Services\Company\UserPermissionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

// Assinatura da empresa: troca de plano e módulos adicionais (PDV, Fiscal, Garçom).
// Todo valor cobrado é calculado no servidor a partir da empresa e do config
// (AddonModulePricing) — as propriedades #[Locked] abaixo são só exibição e não
// podem ser alteradas pelo navegador.
class BillingSettings extends Component
{
    #[Locked]
    public string $plan = 'free';

    #[Locked]
    public string $status = 'ACTIVE';

    #[Locked]
    public ?string $nextDueDate = null;

    #[Locked]
    public ?string $lastPaymentAt = null;

    #[Locked]
    public ?string $setupFeePaidAt = null;

    #[Locked]
    public ?float $amount = null;

    #[Locked]
    public ?string $asaasSubscriptionId = null;

    #[Locked]
    public array $payments = [];

    public bool $confirmingPlanChange = false;

    public string $targetPlan = '';

    public string $paymentMethod = 'credit_card';

    // Credit card fields — limpos ao fim de toda tentativa de cobrança (ver clearCardData)
    public string $cardNumber = '';

    public string $cardExpiry = '';

    public string $cardCvv = '';

    public string $cardHolderName = '';

    public string $cardCpfCnpj = '';

    public string $cardPostalCode = '';

    public string $cardAddressNumber = '';

    public bool $cardProcessing = false;

    public ?string $cardError = null;

    public bool $cardSuccess = false;

    public bool $acceptedTerms = false;

    // Cartão salvo (token Asaas) — permite reusar sem redigitar PAN/CVV
    public bool $useSavedCard = false;

    #[Locked]
    public ?string $savedCardLabel = null;

    public ?string $planChangeError = null;

    // Módulo PDV (cobrado junto com a assinatura do plano, mesma fatura)
    #[Locked]
    public bool $pdvModuleEnabled = false;

    #[Locked]
    public float $pdvAddonAmount = 99.00;

    #[Locked]
    public float $combinedMonthlyAmount = 0.0;

    public bool $confirmingPdvActivation = false;

    public bool $confirmingPdvCancellation = false;

    public bool $pdvAcceptedTerms = false;

    public bool $pdvProcessing = false;

    public ?string $pdvError = null;

    public bool $pdvSuccess = false;

    // Módulo Fiscal (cobrado junto com a assinatura do plano, mesma fatura — mesmo padrão do PDV)
    #[Locked]
    public bool $fiscalModuleEnabled = false;

    #[Locked]
    public float $fiscalAddonAmount = 149.00;

    public bool $confirmingFiscalActivation = false;

    public bool $confirmingFiscalCancellation = false;

    public bool $fiscalAcceptedTerms = false;

    public bool $fiscalProcessing = false;

    public ?string $fiscalError = null;

    public bool $fiscalSuccess = false;

    // Módulo Garçom (cobrado junto com a assinatura do plano, mesma fatura — mesmo padrão do PDV/Fiscal).
    // Exige o módulo PDV já ativo, pois o garçom só opera dentro de mesas/comandas do PDV.
    #[Locked]
    public bool $waiterModuleEnabled = false;

    #[Locked]
    public float $waiterAddonAmount = 99.00;

    public bool $confirmingWaiterActivation = false;

    public bool $confirmingWaiterCancellation = false;

    public bool $waiterAcceptedTerms = false;

    public bool $waiterProcessing = false;

    public ?string $waiterError = null;

    public bool $waiterSuccess = false;

    private const CANCELLATION_MESSAGES = [
        'pdv' => 'Módulo PDV cancelado (o módulo Garçom, que depende dele, também foi cancelado). O valor foi debitado da sua assinatura a partir do próximo vencimento.',
        'fiscal' => 'Módulo Fiscal cancelado. O valor foi debitado da sua assinatura a partir do próximo vencimento.',
        'waiter' => 'Módulo Garçom cancelado. O valor foi debitado da sua assinatura a partir do próximo vencimento.',
    ];

    public function mount(AsaasServiceInterface $asaasService): void
    {
        $company = app('current.company');

        $this->plan = $company->plan?->value ?? 'free';
        $this->status = $company->status ?? 'ACTIVE';
        $this->asaasSubscriptionId = $company->asaas_subscription_id;
        $this->setupFeePaidAt = $company->setup_fee_paid_at?->format('d/m/Y');
        $this->pdvModuleEnabled = (bool) $company->pdv_module_enabled;
        $this->pdvAddonAmount = AddonModulePricing::price('pdv');
        $this->fiscalModuleEnabled = (bool) $company->fiscal_notes_enabled;
        $this->fiscalAddonAmount = AddonModulePricing::price('fiscal');
        $this->waiterModuleEnabled = (bool) $company->waiter_module_enabled;
        $this->waiterAddonAmount = AddonModulePricing::price('waiter');
        $this->savedCardLabel = $company->savedAsaasCardLabel();
        $this->combinedMonthlyAmount = ($company->plan?->monthlyPrice() ?? 0.0)
            + AddonModulePricing::extraFor($company)['amount'];

        /** @var Subscription|null $subscription */
        $subscription = $company->subscriptions()->latest()->first();

        if ($subscription) {
            $this->amount = (float) $subscription->amount;
            $this->nextDueDate = $subscription->next_due_date?->format('d/m/Y');
            $this->lastPaymentAt = $subscription->last_payment_at?->format('d/m/Y');
        }

        if ($this->asaasSubscriptionId) {
            try {
                $this->payments = Cache::remember(
                    "asaas_payments_{$this->asaasSubscriptionId}",
                    now()->addMinutes(5),
                    fn () => $asaasService->getSubscriptionPayments($this->asaasSubscriptionId),
                );
            } catch (\Throwable $e) {
                // Asaas fora do ar não pode derrubar a tela de assinatura — só o histórico some.
                Log::channel('payments')->warning('Assinatura: falha ao carregar histórico de cobranças do Asaas', [
                    'company_id' => $company->id,
                    'error' => $e->getMessage(),
                ]);
                $this->payments = [];
            }
        }
    }

    public function confirmChangePlan(string $plan): void
    {
        if ($plan === $this->plan) {
            return;
        }

        $this->targetPlan = $plan;
        $this->paymentMethod = 'credit_card';
        $this->cardError = null;
        $this->planChangeError = null;
        $this->cardSuccess = false;
        $this->acceptedTerms = false;
        $this->useSavedCard = app('current.company')->hasSavedAsaasCard();
        $this->confirmingPlanChange = true;
    }

    public function changePlan(AsaasServiceInterface $asaasService): void
    {
        $company = app('current.company');
        $targetPlan = Plan::tryFrom($this->targetPlan);

        if (! $targetPlan) {
            $this->confirmingPlanChange = false;

            return;
        }

        $goingToFree = $targetPlan === Plan::Free;

        if (! $goingToFree && ! $this->acceptedTerms) {
            session()->flash('error', 'Você precisa aceitar os Termos de Responsabilidade para continuar.');

            return;
        }

        $completed = $goingToFree
            ? $this->downgradeToFree($asaasService, $company)
            : $this->upgradeToPaidPlan($asaasService, $company, $targetPlan);

        if (! $completed) {
            return;
        }

        $this->confirmingPlanChange = false;
        $this->targetPlan = '';
    }

    public function submitCardForSubscription(AsaasServiceInterface $asaasService): void
    {
        if (! $this->acceptedTerms) {
            session()->flash('error', 'Você precisa aceitar os Termos de Responsabilidade para continuar.');

            return;
        }

        $company = app('current.company');
        $useSavedCard = $this->useSavedCard && $company->hasSavedAsaasCard();

        if (! $useSavedCard) {
            $this->validateCard();
        }

        $this->cardProcessing = true;
        $this->cardError = null;

        try {
            $targetPlan = $company->pending_plan;

            if (! $targetPlan) {
                $this->cardError = 'Nenhum plano pendente encontrado. Tente novamente.';

                return;
            }

            [$creditCard, $holderInfo, $creditCardToken] = $this->cardPayload($company, $useSavedCard);

            // Upgrade from free includes setup fee; cross-grade between paid plans does not.
            // Sempre a partir do plano gravado na empresa, nunca do estado da tela.
            $isFromFree = $company->plan === Plan::Free;
            $setupFee = $isFromFree ? $targetPlan->setupFee() : 0.0;
            // Módulos PDV/Fiscal/Garçom são cobrados na mesma fatura do plano, não como assinatura separada.
            $extra = AddonModulePricing::extraFor($company);
            $chargeAmount = $setupFee + $targetPlan->monthlyPrice() + $extra['amount'];
            $description = $isFromFree
                ? "Taxa de ativação + 1º mês ({$targetPlan->label()}) — {$company->name}"
                : "1º mês plano {$targetPlan->label()} — {$company->name}";
            if ($extra['description'] !== '') {
                $description .= " + {$extra['description']}";
            }

            // Charge first month (+ activation fee if upgrading from free, + módulos ativos) via transparent checkout
            $charge = $asaasService->createCreditCardCharge(
                customerId: $company->asaas_customer_id,
                amount: $chargeAmount,
                description: $description,
                externalReference: "plan_change_{$company->id}_{$targetPlan->value}",
                creditCard: $creditCard,
                holderInfo: $holderInfo,
                creditCardToken: $creditCardToken,
            );

            if (($charge['status'] ?? '') !== 'CONFIRMED') {
                $this->cardError = $this->declineMessage($charge);

                return;
            }

            $company->saveAsaasCreditCardFromCharge($charge);
            $creditCardToken ??= $charge['creditCard']['creditCardToken'] ?? null;

            // Só agora, com o novo plano já pago, a assinatura antiga sai — antes disso
            // um cartão recusado deixava a empresa no plano pago sem assinatura nenhuma.
            $result = $this->replaceSubscription($asaasService, $company, $targetPlan, $extra, $creditCard, $holderInfo, $creditCardToken);

            $companyUpdates = [
                'plan' => $targetPlan->value,
                'pending_plan' => null,
                'status' => 'ACTIVE',
                'active' => true,
                'asaas_subscription_id' => $result['id'],
                'subscription_payment_method' => 'CREDIT_CARD',
            ];

            if ($isFromFree) {
                $companyUpdates['setup_fee_paid_at'] = now();
            }

            $company->update($companyUpdates);

            $this->persistTermsAcceptance($company);

            // Refresh component state
            $this->plan = $targetPlan->value;
            $this->status = 'ACTIVE';
            $this->applySubscriptionResult($result);
            $this->combinedMonthlyAmount = $targetPlan->monthlyPrice() + $extra['amount'];

            $this->cardSuccess = true;
        } catch (\Throwable $e) {
            $this->cardError = 'Erro ao processar pagamento. Por favor, tente novamente.';
        } finally {
            $this->cardProcessing = false;
            $this->clearCardData();
        }
    }

    public function cancelPlanChange(): void
    {
        $this->confirmingPlanChange = false;
        $this->targetPlan = '';
        $this->acceptedTerms = false;
        $this->planChangeError = null;
    }

    public function confirmPdvActivation(): void
    {
        $this->openActivationConfirmation('pdv');
    }

    public function cancelPdvActivation(): void
    {
        $this->closeActivationConfirmation('pdv');
    }

    public function confirmPdvCancellation(): void
    {
        $this->pdvError = null;
        $this->confirmingPdvCancellation = true;
    }

    public function cancelPdvCancellation(): void
    {
        $this->confirmingPdvCancellation = false;
    }

    public function proceedToPdvCardModal(): void
    {
        $this->proceedToCardModal('pdv');
    }

    public function activatePdvModule(AsaasServiceInterface $asaasService): void
    {
        $this->activateModule('pdv', $asaasService);
    }

    public function cancelPdvModule(AsaasServiceInterface $asaasService): void
    {
        $this->cancelModule('pdv', $asaasService);
    }

    public function confirmFiscalActivation(): void
    {
        $this->openActivationConfirmation('fiscal');
    }

    public function cancelFiscalActivation(): void
    {
        $this->closeActivationConfirmation('fiscal');
    }

    public function confirmFiscalCancellation(): void
    {
        $this->fiscalError = null;
        $this->confirmingFiscalCancellation = true;
    }

    public function cancelFiscalCancellation(): void
    {
        $this->confirmingFiscalCancellation = false;
    }

    public function proceedToFiscalCardModal(): void
    {
        $this->proceedToCardModal('fiscal');
    }

    public function activateFiscalModule(AsaasServiceInterface $asaasService): void
    {
        $this->activateModule('fiscal', $asaasService);
    }

    public function cancelFiscalModule(AsaasServiceInterface $asaasService): void
    {
        $this->cancelModule('fiscal', $asaasService);
    }

    public function confirmWaiterActivation(): void
    {
        if (! app('current.company')->pdv_module_enabled) {
            session()->flash('error', 'Ative o módulo PDV antes de ativar o módulo Garçom.');

            return;
        }

        $this->openActivationConfirmation('waiter');
    }

    public function cancelWaiterActivation(): void
    {
        $this->closeActivationConfirmation('waiter');
    }

    public function confirmWaiterCancellation(): void
    {
        $this->waiterError = null;
        $this->confirmingWaiterCancellation = true;
    }

    public function cancelWaiterCancellation(): void
    {
        $this->confirmingWaiterCancellation = false;
    }

    public function proceedToWaiterCardModal(): void
    {
        $this->proceedToCardModal('waiter');
    }

    public function activateWaiterModule(AsaasServiceInterface $asaasService): void
    {
        $this->activateModule('waiter', $asaasService);
    }

    public function cancelWaiterModule(AsaasServiceInterface $asaasService): void
    {
        $this->cancelModule('waiter', $asaasService);
    }

    public function render(): View
    {
        return view('livewire.admin.settings.billing-settings')
            ->layout('layouts.app', ['title' => 'Assinatura']);
    }

    // ---------------------------------------------------------------------
    // Troca de plano
    // ---------------------------------------------------------------------

    /**
     * @return bool false quando o downgrade foi recusado (o modal continua aberto com o erro)
     */
    private function downgradeToFree(AsaasServiceInterface $asaasService, Company $company): bool
    {
        // Mudar para o gratuito cancela a assinatura e reativaria a empresa — com fatura
        // em atraso isso viraria um jeito de sair do bloqueio sem pagar.
        if ($company->isOverdue() || $company->isBlocked()) {
            $this->planChangeError = 'Regularize a fatura em aberto antes de mudar para o plano gratuito.';

            return false;
        }

        // Downgrade to free: cancel subscription, keep ACTIVE (setup fee already paid)
        if ($company->asaas_subscription_id) {
            $this->cancelCurrentSubscription($asaasService, $company);
        }

        $company->update([
            'plan' => 'free',
            'status' => 'ACTIVE',
            'active' => true,
            'asaas_subscription_id' => null,
        ]);

        // Módulos PDV, Fiscal e Garçom são plano-independentes: se algum estava ativo, recria a
        // cobrança (agora só dos módulos, já que o plano gratuito não tem mensalidade).
        if ($company->pdv_module_enabled || $company->fiscal_notes_enabled || $company->waiter_module_enabled) {
            CreateAsaasSubscription::dispatch($company->fresh());
        }

        $this->plan = 'free';
        $this->status = 'ACTIVE';
        $this->asaasSubscriptionId = null;
        $this->amount = null;
        $this->nextDueDate = null;
        $this->lastPaymentAt = null;
        $this->payments = [];
        $this->combinedMonthlyAmount = AddonModulePricing::extraFor($company)['amount'];

        return true;
    }

    /**
     * @return bool false quando o fluxo não termina aqui: erro (modal continua aberto com
     *              a mensagem) ou cartão (segue no modal do cartão)
     */
    private function upgradeToPaidPlan(AsaasServiceInterface $asaasService, Company $company, Plan $targetPlan): bool
    {
        // Upgrade or cross-grade to a paid plan (Essencial or PRO)
        if (! $company->asaas_customer_id) {
            $this->planChangeError = 'Esta empresa não possui cadastro no Asaas. Entre em contato com o suporte.';

            return false;
        }

        $this->persistTermsAcceptance($company);

        // Plano atual sempre do banco: o estado da tela vem do navegador e decidia se a
        // taxa de ativação era cobrada.
        $isFromFree = $company->plan === Plan::Free;

        $billingType = match ($this->paymentMethod) {
            'credit_card' => 'CREDIT_CARD',
            'boleto' => 'BOLETO',
            default => 'PIX',
        };

        if ($billingType === 'CREDIT_CARD') {
            // A assinatura atual só é cancelada em submitCardForSubscription, depois que o
            // cartão do novo plano for aprovado.
            $company->update([
                'pending_plan' => $targetPlan->value,
                'subscription_payment_method' => $billingType,
            ]);

            $this->confirmingPlanChange = false;
            $this->targetPlan = '';
            $this->dispatch('open-plan-card-modal');

            return false;
        }

        // Cancel existing subscription if switching between paid plans
        if ($company->asaas_subscription_id) {
            $this->cancelCurrentSubscription($asaasService, $company);
        }

        $company->update([
            'pending_plan' => $targetPlan->value,
            'asaas_subscription_id' => null,
            'subscription_payment_method' => $billingType,
        ]);

        $this->asaasSubscriptionId = null;
        $this->amount = null;
        $this->nextDueDate = null;
        $this->lastPaymentAt = null;
        $this->payments = [];

        if ($isFromFree) {
            // Upgrade from free: charge setup fee + first month (+ módulos ativos) as one-time charge.
            // After webhook confirms, ProcessAsaasWebhook will apply pending_plan and create subscription
            // (CreateAsaasSubscription já soma os módulos automaticamente a partir do 2º mês).
            $extra = AddonModulePricing::extraFor($company);
            $firstAmount = $targetPlan->setupFee() + $targetPlan->monthlyPrice() + $extra['amount'];
            $description = "Taxa de ativação + 1º mês ({$targetPlan->label()}) — {$company->name}";
            if ($extra['amount'] > 0) {
                $description .= ' + '.$extra['description'];
            }

            $charge = $asaasService->createCharge(
                $company->asaas_customer_id,
                $firstAmount,
                $description,
                $billingType,
            );

            $company->update(['asaas_setup_charge_id' => $charge['id']]);

            session()->flash('success', 'Cobrança gerada! Você receberá um e-mail com as instruções de pagamento para concluir o upgrade.');
        } else {
            // Cross-grade between paid plans: create subscription directly (no setup fee)
            CreateAsaasSubscription::dispatch($company->fresh());
        }

        return true;
    }

    private function persistTermsAcceptance(Company $company): void
    {
        $company->update([
            'terms_accepted_at' => now(),
            'terms_accepted_by_user_id' => auth()->id(),
            'terms_version' => now()->format('Y-m-d'),
        ]);
    }

    // ---------------------------------------------------------------------
    // Módulos adicionais (pdv, fiscal, waiter) — mesmo fluxo para os três
    // ---------------------------------------------------------------------

    private function openActivationConfirmation(string $module): void
    {
        $this->{"{$module}Error"} = null;
        $this->{"{$module}Success"} = false;
        $this->{"{$module}AcceptedTerms"} = false;
        $this->{'confirming'.ucfirst($module).'Activation'} = true;
    }

    private function closeActivationConfirmation(string $module): void
    {
        $this->{'confirming'.ucfirst($module).'Activation'} = false;
        $this->{"{$module}AcceptedTerms"} = false;
    }

    private function proceedToCardModal(string $module): void
    {
        if (! $this->{"{$module}AcceptedTerms"}) {
            session()->flash('error', 'Você precisa aceitar os Termos de Responsabilidade para continuar.');

            return;
        }

        $this->useSavedCard = app('current.company')->hasSavedAsaasCard();
        $this->{'confirming'.ucfirst($module).'Activation'} = false;
        $this->dispatch("open-{$module}-card-modal");
    }

    private function activateModule(string $module, AsaasServiceInterface $asaasService): void
    {
        $company = app('current.company');

        if ($module === 'waiter' && ! $company->pdv_module_enabled) {
            $this->waiterError = 'Ative o módulo PDV antes de ativar o módulo Garçom.';

            return;
        }

        $useSavedCard = $this->useSavedCard && $company->hasSavedAsaasCard();

        if (! $useSavedCard) {
            $this->validateCard();
        }

        $this->{"{$module}Processing"} = true;
        $this->{"{$module}Error"} = null;

        try {
            if (! $company->asaas_customer_id) {
                $this->{"{$module}Error"} = 'Esta empresa não possui cadastro no Asaas. Entre em contato com o suporte.';

                return;
            }

            $plan = $company->plan;
            [$creditCard, $holderInfo, $creditCardToken] = $this->cardPayload($company, $useSavedCard);

            // O módulo entra na MESMA fatura da assinatura do plano — não é uma assinatura separada.
            // Por isso a assinatura atual é cancelada e recriada já com o valor combinado.
            $extra = AddonModulePricing::extraFor($company, [$module => true]);
            $combinedAmount = $plan->monthlyPrice() + $extra['amount'];

            $charge = $asaasService->createCreditCardCharge(
                customerId: $company->asaas_customer_id,
                amount: $combinedAmount,
                description: "{$plan->asaasDescription()} + {$extra['description']} — {$company->name}",
                externalReference: "{$module}_module_activation_{$company->id}",
                creditCard: $creditCard,
                holderInfo: $holderInfo,
                creditCardToken: $creditCardToken,
            );

            if (($charge['status'] ?? '') !== 'CONFIRMED') {
                $this->{"{$module}Error"} = $this->declineMessage($charge);

                return;
            }

            $company->saveAsaasCreditCardFromCharge($charge);
            $creditCardToken ??= $charge['creditCard']['creditCardToken'] ?? null;

            $result = $this->replaceSubscription($asaasService, $company, $plan, $extra, $creditCard, $holderInfo, $creditCardToken);

            $company->update([
                AddonModulePricing::MODULES[$module]['column'] => true,
                'asaas_subscription_id' => $result['id'],
                'subscription_payment_method' => 'CREDIT_CARD',
            ]);

            if ($module === 'pdv') {
                UserPermissionService::grantPdvPermissions($company->fresh());
            }

            $this->persistTermsAcceptance($company);

            $this->{"{$module}ModuleEnabled"} = true;
            $this->applySubscriptionResult($result);
            $this->combinedMonthlyAmount = (float) $result['value'];
            $this->{"{$module}Success"} = true;
        } catch (\Throwable $e) {
            $this->{"{$module}Error"} = 'Erro ao processar pagamento. Por favor, tente novamente.';
        } finally {
            $this->{"{$module}Processing"} = false;
            $this->clearCardData();
        }
    }

    private function cancelModule(string $module, AsaasServiceInterface $asaasService): void
    {
        $company = app('current.company');
        $plan = $company->plan;

        // Garçom depende do PDV — se o PDV sai, o garçom vai junto (senão fica um addon
        // cobrado sem função, já que ele só existe pra operar dentro do PDV).
        $disabled = $module === 'pdv' ? ['pdv' => false, 'waiter' => false] : [$module => false];
        $extra = AddonModulePricing::extraFor($company, $disabled);
        $planAmount = $plan->monthlyPrice() + $extra['amount'];

        try {
            if ($company->asaas_subscription_id) {
                if ($planAmount > 0) {
                    // Debita o valor do módulo diretamente na assinatura existente — não cancela
                    // nem recria nada, então não depende de dados de cartão. A partir do próximo
                    // vencimento a cobrança volta a ser só o plano (+ outros módulos ativos).
                    $description = $extra['description'] !== ''
                        ? "{$plan->asaasDescription()} + {$extra['description']}"
                        : $plan->asaasDescription();

                    $result = $asaasService->updateSubscriptionValue(
                        $company->asaas_subscription_id,
                        $planAmount,
                        $description,
                    );

                    Subscription::where('company_id', $company->id)
                        ->where('asaas_subscription_id', $company->asaas_subscription_id)
                        ->whereIn('status', ['active', 'pending'])
                        ->update(['amount' => $result['value'] ?? $planAmount]);

                    $this->amount = $result['value'] ?? $planAmount;
                } else {
                    // Plano atual não tem mensalidade (ex.: Free) e nenhum outro módulo ativo — o
                    // módulo era o único valor cobrado nessa assinatura: cancela de fato.
                    $this->cancelCurrentSubscription($asaasService, $company);

                    $company->update(['asaas_subscription_id' => null]);
                    $this->asaasSubscriptionId = null;
                    $this->amount = null;
                }
            } else {
                $this->amount = null;
            }
        } catch (\Throwable $e) {
            $this->{"{$module}Error"} = 'Erro ao cancelar o módulo no Asaas. Tente novamente em alguns instantes.';

            return;
        }

        $updates = [];
        foreach (array_keys($disabled) as $disabledModule) {
            $updates[AddonModulePricing::MODULES[$disabledModule]['column']] = false;
            $this->{"{$disabledModule}ModuleEnabled"} = false;
        }

        $company->update($updates);

        if ($module === 'pdv') {
            UserPermissionService::revokePdvPermissions($company->fresh());
        }

        $this->combinedMonthlyAmount = $planAmount;
        $this->{'confirming'.ucfirst($module).'Cancellation'} = false;

        session()->flash('status', self::CANCELLATION_MESSAGES[$module]);
    }

    // ---------------------------------------------------------------------
    // Assinatura e cartão
    // ---------------------------------------------------------------------

    private function cancelCurrentSubscription(AsaasServiceInterface $asaasService, Company $company): void
    {
        $asaasService->cancelSubscription($company->asaas_subscription_id);

        Subscription::where('company_id', $company->id)
            ->where('asaas_subscription_id', $company->asaas_subscription_id)
            ->whereIn('status', ['active', 'pending'])
            ->update(['status' => 'cancelled']);
    }

    /**
     * Troca a assinatura atual por uma nova no cartão, começando no mês seguinte
     * (o mês corrente acabou de ser cobrado à parte).
     *
     * @param  array{amount: float, description: string}  $extra
     */
    private function replaceSubscription(
        AsaasServiceInterface $asaasService,
        Company $company,
        Plan $plan,
        array $extra,
        ?CreditCardDTO $creditCard,
        ?CreditCardHolderDTO $holderInfo,
        ?string $creditCardToken,
    ): array {
        if ($company->asaas_subscription_id) {
            $this->cancelCurrentSubscription($asaasService, $company);
        }

        $result = $asaasService->createSubscription(
            customerId: $company->asaas_customer_id,
            plan: $plan,
            billingType: 'CREDIT_CARD',
            creditCard: $creditCardToken ? null : $creditCard,
            holderInfo: $creditCardToken ? null : $holderInfo,
            nextDueDate: now()->addMonth()->toDateString(),
            extraAmount: $extra['amount'],
            extraDescription: $extra['description'],
            creditCardToken: $creditCardToken,
        );

        Subscription::create([
            'company_id' => $company->id,
            'asaas_subscription_id' => $result['id'],
            'plan' => $plan->value,
            'status' => 'active',
            'amount' => $result['value'],
            'billing_cycle' => 'MONTHLY',
            'next_due_date' => $result['nextDueDate'],
        ]);

        return $result;
    }

    private function applySubscriptionResult(array $result): void
    {
        $this->asaasSubscriptionId = $result['id'];
        $this->amount = (float) $result['value'];
        $this->nextDueDate = now()->addMonth()->format('d/m/Y');
        $this->lastPaymentAt = now()->format('d/m/Y');
        $this->payments = [];
    }

    private function validateCard(): void
    {
        $this->validate([
            'cardNumber' => ['required', 'string', 'min:13'],
            'cardExpiry' => ['required', 'regex:/^\d{2}\/\d{2}$/', function ($attr, $value, $fail) {
                [$month, $year] = explode('/', $value);
                $month = (int) $month;
                $year = (int) ('20'.$year);
                if ($month < 1 || $month > 12) {
                    $fail('Mês de validade inválido.');

                    return;
                }
                if ($year < now()->year || ($year === now()->year && $month < now()->month)) {
                    $fail('Cartão vencido.');
                }
            }],
            'cardCvv' => ['required', 'digits_between:3,4'],
            'cardHolderName' => ['required', 'string', 'min:3'],
            'cardCpfCnpj' => ['required', 'string', function ($attr, $value, $fail) {
                $digits = preg_replace('/\D/', '', $value);
                if (strlen($digits) !== 11 && strlen($digits) !== 14) {
                    $fail('CPF deve ter 11 dígitos e CNPJ 14 dígitos.');
                }
            }],
            'cardPostalCode' => ['required', 'string', 'min:8'],
            'cardAddressNumber' => ['required', 'string'],
        ], [
            'cardNumber.required' => 'Informe o número do cartão.',
            'cardNumber.min' => 'Número do cartão inválido.',
            'cardExpiry.required' => 'Informe a validade.',
            'cardExpiry.regex' => 'Use o formato MM/AA.',
            'cardCvv.required' => 'Informe o CVV.',
            'cardCvv.digits_between' => 'CVV deve ter 3 ou 4 dígitos.',
            'cardHolderName.required' => 'Informe o nome conforme está no cartão.',
            'cardCpfCnpj.required' => 'Informe o CPF ou CNPJ do titular.',
            'cardPostalCode.required' => 'Informe o CEP de cobrança.',
            'cardPostalCode.min' => 'CEP inválido.',
            'cardAddressNumber.required' => 'Informe o número do endereço.',
        ]);
    }

    /**
     * @return array{0: ?CreditCardDTO, 1: ?CreditCardHolderDTO, 2: ?string}
     */
    private function cardPayload(Company $company, bool $useSavedCard): array
    {
        if ($useSavedCard) {
            return [null, null, $company->asaas_credit_card_token];
        }

        $admin = $company->users()->first();
        $phone = preg_replace('/\D/', '', $company->branches()->withoutGlobalScopes()->value('phone') ?? '');

        [$month, $year] = explode('/', $this->cardExpiry);

        $creditCard = new CreditCardDTO(
            holderName: $this->cardHolderName,
            number: $this->cardNumber,
            expiryMonth: $month,
            expiryYear: '20'.$year,
            ccv: $this->cardCvv,
        );

        $holderInfo = new CreditCardHolderDTO(
            name: $admin?->name ?? $this->cardHolderName,
            email: $admin?->email ?? '',
            cpfCnpj: $this->cardCpfCnpj,
            postalCode: $this->cardPostalCode,
            addressNumber: $this->cardAddressNumber,
            mobilePhone: $phone,
            phone: $phone,
        );

        return [$creditCard, $holderInfo, null];
    }

    private function declineMessage(array $charge): string
    {
        $reason = $charge['creditCard']['declineReason']
            ?? $charge['failReason']
            ?? 'verifique os dados e tente novamente';

        return "Pagamento recusado: {$reason}.";
    }

    /**
     * PAN/CVV não podem ficar no snapshot do Livewire depois da tentativa — o snapshot
     * é assinado, mas não criptografado, e volta pro navegador a cada requisição.
     */
    private function clearCardData(): void
    {
        $this->cardNumber = '';
        $this->cardExpiry = '';
        $this->cardCvv = '';
        $this->cardHolderName = '';
        $this->cardCpfCnpj = '';
        $this->cardPostalCode = '';
        $this->cardAddressNumber = '';
    }
}
