<?php

namespace App\Livewire\Admin\Settings;

use App\Contracts\AsaasServiceInterface;
use App\DTOs\CreditCardDTO;
use App\DTOs\CreditCardHolderDTO;
use App\Enums\Plan;
use App\Jobs\CreateAsaasSubscription;
use App\Models\Subscription;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Livewire\Component;

class BillingSettings extends Component
{
    public string $plan = 'free';

    public string $status = 'ACTIVE';

    public ?string $nextDueDate = null;

    public ?string $lastPaymentAt = null;

    public ?string $setupFeePaidAt = null;

    public ?float $amount = null;

    public ?string $asaasSubscriptionId = null;

    public array $payments = [];

    public bool $confirmingPlanChange = false;

    public string $targetPlan = '';

    public string $paymentMethod = 'credit_card';

    // Credit card fields
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

    public ?string $planChangeError = null;

    public function mount(AsaasServiceInterface $asaasService): void
    {
        $company = app('current.company');

        $this->plan = $company->plan?->value ?? 'free';
        $this->status = $company->status ?? 'ACTIVE';
        $this->asaasSubscriptionId = $company->asaas_subscription_id;
        $this->setupFeePaidAt = $company->setup_fee_paid_at?->format('d/m/Y');

        /** @var Subscription|null $subscription */
        $subscription = $company->subscriptions()->latest()->first();

        if ($subscription) {
            $this->amount = (float) $subscription->amount;
            $this->nextDueDate = $subscription->next_due_date?->format('d/m/Y');
            $this->lastPaymentAt = $subscription->last_payment_at?->format('d/m/Y');
        }

        if ($this->asaasSubscriptionId) {
            $this->payments = Cache::remember(
                "asaas_payments_{$this->asaasSubscriptionId}",
                now()->addMinutes(5),
                fn () => $asaasService->getSubscriptionPayments($this->asaasSubscriptionId),
            );
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

        if (! $goingToFree) {
            $this->persistTermsAcceptance($company);
        }

        if ($goingToFree) {
            // Downgrade to free: cancel subscription, keep ACTIVE (setup fee already paid)
            if ($company->asaas_subscription_id) {
                $asaasService->cancelSubscription($company->asaas_subscription_id);

                Subscription::where('company_id', $company->id)
                    ->where('asaas_subscription_id', $company->asaas_subscription_id)
                    ->whereIn('status', ['active', 'pending'])
                    ->update(['status' => 'cancelled']);
            }

            $company->update([
                'plan' => 'free',
                'status' => 'ACTIVE',
                'active' => true,
                'asaas_subscription_id' => null,
            ]);

            $this->plan = 'free';
            $this->status = 'ACTIVE';
            $this->asaasSubscriptionId = null;
            $this->amount = null;
            $this->nextDueDate = null;
            $this->lastPaymentAt = null;
            $this->payments = [];
        } else {
            // Upgrade or cross-grade to a paid plan (Essencial or PRO)
            if (! $company->asaas_customer_id) {
                $this->planChangeError = 'Esta empresa não possui cadastro no Asaas. Entre em contato com o suporte.';

                return;
            }

            $currentPlan = Plan::tryFrom($this->plan);
            $isFromFree = $currentPlan === Plan::Free;

            // Cancel existing subscription if switching between paid plans
            if ($company->asaas_subscription_id) {
                $asaasService->cancelSubscription($company->asaas_subscription_id);

                Subscription::where('company_id', $company->id)
                    ->where('asaas_subscription_id', $company->asaas_subscription_id)
                    ->whereIn('status', ['active', 'pending'])
                    ->update(['status' => 'cancelled']);
            }

            $billingType = match ($this->paymentMethod) {
                'credit_card' => 'CREDIT_CARD',
                'boleto' => 'BOLETO',
                default => 'PIX',
            };

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

            if ($this->paymentMethod === 'credit_card') {
                $this->confirmingPlanChange = false;
                $this->targetPlan = '';
                $this->dispatch('open-plan-card-modal');

                return;
            }

            if ($isFromFree) {
                // Upgrade from free: charge setup fee + first month as one-time charge.
                // After webhook confirms, ProcessAsaasWebhook will apply pending_plan and create subscription.
                $setupFee = $targetPlan->setupFee();
                $firstAmount = $setupFee + $targetPlan->monthlyPrice();
                $description = "Taxa de ativação + 1º mês ({$targetPlan->label()}) — {$company->name}";

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

        $this->cardProcessing = true;
        $this->cardError = null;

        try {
            $company = app('current.company');
            $targetPlan = $company->pending_plan;

            if (! $targetPlan) {
                $this->cardError = 'Nenhum plano pendente encontrado. Tente novamente.';
                $this->cardProcessing = false;

                return;
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

            // Upgrade from free includes setup fee; cross-grade between paid plans does not
            $isFromFree = $company->plan === Plan::Free;
            $setupFee = $isFromFree ? $targetPlan->setupFee() : 0.0;
            $chargeAmount = $setupFee + $targetPlan->monthlyPrice();
            $description = $isFromFree
                ? "Taxa de ativação + 1º mês ({$targetPlan->label()}) — {$company->name}"
                : "1º mês plano {$targetPlan->label()} — {$company->name}";

            // Charge first month (+ activation fee if upgrading from free) via transparent checkout
            $charge = $asaasService->createCreditCardCharge(
                customerId: $company->asaas_customer_id,
                amount: $chargeAmount,
                description: $description,
                externalReference: "plan_change_{$company->id}_{$targetPlan->value}",
                creditCard: $creditCard,
                holderInfo: $holderInfo,
            );

            if (($charge['status'] ?? '') !== 'CONFIRMED') {
                $reason = $charge['creditCard']['declineReason']
                    ?? $charge['failReason']
                    ?? 'verifique os dados e tente novamente';

                $this->cardError = "Pagamento recusado: {$reason}.";
                $this->cardProcessing = false;

                return;
            }

            // Create recurring subscription starting next month (avoids double-charging)
            $result = $asaasService->createSubscription(
                customerId: $company->asaas_customer_id,
                plan: $targetPlan,
                billingType: 'CREDIT_CARD',
                creditCard: $creditCard,
                holderInfo: $holderInfo,
                nextDueDate: now()->addMonth()->toDateString(),
            );

            Subscription::create([
                'company_id' => $company->id,
                'asaas_subscription_id' => $result['id'],
                'plan' => $targetPlan->value,
                'status' => 'active',
                'amount' => $result['value'],
                'billing_cycle' => 'MONTHLY',
                'next_due_date' => $result['nextDueDate'],
            ]);

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
            $this->asaasSubscriptionId = $result['id'];
            $this->amount = $result['value'];
            $this->nextDueDate = now()->addMonth()->format('d/m/Y');
            $this->lastPaymentAt = now()->format('d/m/Y');
            $this->payments = [];

            $this->cardSuccess = true;
            $this->cardProcessing = false;
        } catch (\Throwable $e) {
            $this->cardError = 'Erro ao processar pagamento. Por favor, tente novamente.';
            $this->cardProcessing = false;
        }
    }

    private function persistTermsAcceptance(\App\Models\Company $company): void
    {
        $company->update([
            'terms_accepted_at' => now(),
            'terms_accepted_by_user_id' => auth()->id(),
            'terms_version' => now()->format('Y-m-d'),
        ]);
    }

    public function cancelPlanChange(): void
    {
        $this->confirmingPlanChange = false;
        $this->targetPlan = '';
        $this->acceptedTerms = false;
        $this->planChangeError = null;
    }

    public function render(): View
    {
        return view('livewire.admin.settings.billing-settings')
            ->layout('layouts.app', ['title' => 'Assinatura']);
    }
}
