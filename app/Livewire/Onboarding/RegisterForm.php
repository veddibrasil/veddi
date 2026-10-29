<?php

namespace App\Livewire\Onboarding;

use App\Contracts\AsaasServiceInterface;
use App\DTOs\CreditCardDTO;
use App\DTOs\CreditCardHolderDTO;
use App\DTOs\OnboardingDTO;
use App\Enums\Plan;
use App\Exceptions\CardPaymentDeclinedException;
use App\Helpers\Validation;
use App\Models\Company;
use App\Models\Subscription;
use App\Rules\ReservedSlug;
use App\Services\Company\AddonModulePricing;
use App\Services\Company\CompanyService;
use App\Services\Company\OnboardingService;
use App\Services\Company\SubscriptionBillingService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class RegisterForm extends Component
{
    /**
     * Empresa criada por este navegador em submit(), aguardando o cartão.
     * submitCard() só aceita o id que estiver aqui e na sessão.
     */
    private const SESSION_PENDING_COMPANY = 'onboarding.pending_company_id';

    // O throttle:5,1 da rota POST /cadastro não vale para /livewire/update,
    // que é por onde esta tela cria a conta e cobra o cartão.
    private const SUBMIT_MAX_ATTEMPTS = 3;

    private const SUBMIT_DECAY_SECONDS = 3600;

    private const CARD_MAX_ATTEMPTS = 5;

    private const CARD_DECAY_SECONDS = 600;

    public int $currentStep = 1;

    // Step 1: Company
    public string $companyName = '';

    public string $slug = '';

    public bool $slugManuallyEdited = false;

    // Step 2: Branch
    public string $branchName = '';

    public string $branchPhone = '';

    public bool $branchPhoneValid = false;

    public ?string $branchPhoneError = null;

    // Step 3: User
    public string $userName = '';

    public string $userEmail = '';

    // Sem wire:model.live: o medidor de força é calculado no navegador
    // (Alpine lendo $wire.userPassword), a senha não vai ao servidor a cada tecla.
    public string $userPassword = '';

    // Aceite de e-mail marketing (LGPD): opcional, desmarcado por padrão.
    public bool $marketingOptIn = false;

    // Step 4: Plan
    public string $plan = 'free';

    public string $paymentMethod = 'CREDIT_CARD';

    public string $asaasCpfCnpj = '';

    public bool $asaasCpfCnpjValid = false;

    public ?string $asaasCpfCnpjError = null;

    public bool $submitting = false;

    public ?string $errorMessage = null;

    // Credit card modal
    // Locked: sem isso, um visitante anônimo trocava o id e cobrava/reativava
    // empresa de terceiro (inclusive BLOCKED) pelo submitCard().
    #[Locked]
    public ?int $pendingCompanyId = null;

    // Número, validade e CVV não são propriedades: chegam só como parâmetro
    // de submitCard() (o modal guarda no Alpine), para não trafegarem no
    // snapshot do Livewire a cada request.
    public string $cardHolderName = '';

    public string $cardCpfCnpj = '';

    public string $cardPostalCode = '';

    public string $cardAddressNumber = '';

    public bool $cardProcessing = false;

    public ?string $cardError = null;

    public bool $cardSuccess = false;

    #[Computed]
    public function firstPaymentTotal(): float
    {
        $planEnum = Plan::tryFrom($this->plan);
        $setupFee = $planEnum?->setupFee() ?? 99.00;
        $monthly = $planEnum?->hasMonthlySubscription() ? ($planEnum?->monthlyPrice() ?? 0.0) : 0.0;

        return $setupFee + $monthly;
    }

    #[Computed]
    public function planMonthlyPrice(): float
    {
        return (float) (Plan::tryFrom($this->plan)?->monthlyPrice() ?? 0.0);
    }

    #[Computed]
    public function planHasMonthly(): bool
    {
        return (bool) (Plan::tryFrom($this->plan)?->hasMonthlySubscription() ?? false);
    }

    public function updatedCompanyName(string $value): void
    {
        if (! $this->slugManuallyEdited) {
            $this->slug = Str::slug($value);
        }
    }

    public function updatedSlug(string $value): void
    {
        $this->slugManuallyEdited = true;
        $this->slug = Str::slug($value);
    }

    public function updatedBranchPhone(string $value): void
    {
        $digits = preg_replace('/\D/', '', $value);

        // Auto-format as (XX) XXXXX-XXXX or (XX) XXXX-XXXX
        $this->branchPhone = $this->formatPhone($digits);

        $len = strlen($digits);

        if ($len === 0) {
            $this->branchPhoneValid = false;
            $this->branchPhoneError = null;

            return;
        }

        if ($len < 10) {
            $this->branchPhoneValid = false;
            $this->branchPhoneError = 'Número incompleto.';

            return;
        }

        if (! Validation::isValidPhone($this->branchPhone)) {
            $this->branchPhoneValid = false;
            $this->branchPhoneError = 'Telefone inválido.';

            return;
        }

        $this->branchPhoneValid = true;
        $this->branchPhoneError = null;
    }

    public function updatedAsaasCpfCnpj(string $value): void
    {
        $digits = preg_replace('/\D/', '', $value);
        $len = strlen($digits);

        // Auto-format CPF (11 digits) or CNPJ (14 digits)
        if ($len <= 11) {
            $this->asaasCpfCnpj = $this->formatCpf($digits);
        } else {
            $this->asaasCpfCnpj = $this->formatCnpj($digits);
        }

        if ($len === 0) {
            $this->asaasCpfCnpjValid = false;
            $this->asaasCpfCnpjError = null;

            return;
        }

        if ($len < 11) {
            $this->asaasCpfCnpjValid = false;
            $this->asaasCpfCnpjError = 'Número incompleto.';

            return;
        }

        if ($len === 11) {
            if (! Validation::isValidCpf($digits)) {
                $this->asaasCpfCnpjValid = false;
                $this->asaasCpfCnpjError = 'CPF inválido.';

                return;
            }
            $this->asaasCpfCnpjValid = true;
            $this->asaasCpfCnpjError = null;

            return;
        }

        if ($len > 11 && $len < 14) {
            $this->asaasCpfCnpjValid = false;
            $this->asaasCpfCnpjError = 'CNPJ incompleto.';

            return;
        }

        if ($len === 14) {
            if (! Validation::isValidCnpj($digits)) {
                $this->asaasCpfCnpjValid = false;
                $this->asaasCpfCnpjError = 'CNPJ inválido.';

                return;
            }
            $this->asaasCpfCnpjValid = true;
            $this->asaasCpfCnpjError = null;

            return;
        }

        // More than 14 digits
        $this->asaasCpfCnpjValid = false;
        $this->asaasCpfCnpjError = 'Número de dígitos inválido.';
    }

    private function formatPhone(string $digits): string
    {
        $digits = substr($digits, 0, 11);
        $len = strlen($digits);

        if ($len === 0) {
            return '';
        }
        if ($len <= 2) {
            return "({$digits}";
        }
        if ($len <= 6) {
            return '('.substr($digits, 0, 2).') '.substr($digits, 2);
        }

        // Celular (11 digits): (XX) XXXXX-XXXX
        if ($len === 11) {
            return '('.substr($digits, 0, 2).') '.substr($digits, 2, 5).'-'.substr($digits, 7, 4);
        }

        // Fixo (10 digits): (XX) XXXX-XXXX
        return '('.substr($digits, 0, 2).') '.substr($digits, 2, 4).'-'.substr($digits, 6, 4);
    }

    private function formatCpf(string $digits): string
    {
        $digits = substr($digits, 0, 11);
        $len = strlen($digits);

        if ($len <= 3) {
            return $digits;
        }
        if ($len <= 6) {
            return substr($digits, 0, 3).'.'.substr($digits, 3);
        }
        if ($len <= 9) {
            return substr($digits, 0, 3).'.'.substr($digits, 3, 3).'.'.substr($digits, 6);
        }

        return substr($digits, 0, 3).'.'.substr($digits, 3, 3).'.'.substr($digits, 6, 3).'-'.substr($digits, 9, 2);
    }

    private function formatCnpj(string $digits): string
    {
        $digits = substr($digits, 0, 14);
        $len = strlen($digits);

        if ($len <= 2) {
            return $digits;
        }
        if ($len <= 5) {
            return substr($digits, 0, 2).'.'.substr($digits, 2);
        }
        if ($len <= 8) {
            return substr($digits, 0, 2).'.'.substr($digits, 2, 3).'.'.substr($digits, 5);
        }
        if ($len <= 12) {
            return substr($digits, 0, 2).'.'.substr($digits, 2, 3).'.'.substr($digits, 5, 3).'/'.substr($digits, 8);
        }

        return substr($digits, 0, 2).'.'.substr($digits, 2, 3).'.'.substr($digits, 5, 3).'/'.substr($digits, 8, 4).'-'.substr($digits, 12, 2);
    }

    protected function rulesForStep(int $step): array
    {
        return match ($step) {
            1 => [
                'companyName' => ['required', 'string', 'max:100'],
                'slug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9\-]+$/', new ReservedSlug, 'unique:companies,slug'],
            ],
            2 => [
                'branchName' => ['required', 'string', 'max:100'],
                'branchPhone' => ['required', Validation::PHONE_RULE],
            ],
            3 => [
                'userName' => ['required', 'string', 'max:100'],
                'userEmail' => ['required', 'email', 'unique:users,email'],
                'userPassword' => ['required', 'min:8'],
            ],
            4 => [
                'plan' => ['required', 'in:'.implode(',', Plan::values())],
                'paymentMethod' => Plan::tryFrom($this->plan) === Plan::Free
                    ? ['nullable']
                    : ['required', 'in:CREDIT_CARD'],
                'asaasCpfCnpj' => ['required', 'string', function (string $attr, mixed $value, \Closure $fail) {
                    $digits = preg_replace('/\D/', '', $value);
                    if (strlen($digits) === 11 && ! Validation::isValidCpf($digits)) {
                        $fail('CPF inválido.');
                    } elseif (strlen($digits) === 14 && ! Validation::isValidCnpj($digits)) {
                        $fail('CNPJ inválido.');
                    } elseif (! in_array(strlen($digits), [11, 14])) {
                        $fail('Informe um CPF (11 dígitos) ou CNPJ (14 dígitos) válido.');
                    }
                }],
            ],
            default => [],
        };
    }

    protected function messagesForStep(int $step): array
    {
        return match ($step) {
            1 => [
                'companyName.required' => 'Informe o nome da empresa.',
                'slug.required' => 'Informe o endereço da empresa.',
                'slug.unique' => 'Este endereço já está em uso.',
                'slug.regex' => 'O endereço só pode conter letras minúsculas, números e hífens.',
            ],
            2 => [
                'branchName.required' => 'Informe o nome da filial.',
                'branchPhone.required' => 'Informe o telefone da filial.',
                'branchPhone.regex' => 'Telefone inválido. Use o formato (XX) XXXXX-XXXX.',
            ],
            3 => [
                'userName.required' => 'Informe seu nome.',
                'userEmail.required' => 'Informe seu e-mail.',
                'userEmail.unique' => 'Este e-mail já está cadastrado.',
                'userPassword.required' => 'Informe uma senha.',
                'userPassword.min' => 'A senha deve ter no mínimo 8 caracteres.',
            ],
            4 => [
                'plan.required' => 'Selecione um plano.',
                'plan.in' => 'Selecione um plano válido.',
                'paymentMethod.required' => 'Selecione uma forma de pagamento.',
                'paymentMethod.in' => 'Forma de pagamento inválida.',
                'asaasCpfCnpj.required' => 'CPF ou CNPJ é obrigatório para a conta de pagamento.',
                'asaasCpfCnpj.min' => 'Informe um CPF (11 dígitos) ou CNPJ (14 dígitos) válido.',
            ],
            default => [],
        };
    }

    public function nextStep(): void
    {
        $this->validate(
            $this->rulesForStep($this->currentStep),
            $this->messagesForStep($this->currentStep),
        );
        $this->currentStep++;
    }

    public function prevStep(): void
    {
        $this->currentStep = max(1, $this->currentStep - 1);
    }

    public function submit(): void
    {
        // submit() é chamável direto por /livewire/update, sem passar pelos
        // nextStep(): revalida todos os passos (inclusive slug, e-mail e senha)
        // antes de chamar o Asaas e volta a tela para o passo com erro.
        foreach ([1, 2, 3, 4] as $step) {
            try {
                $this->validate($this->rulesForStep($step), $this->messagesForStep($step));
            } catch (ValidationException $e) {
                $this->currentStep = $step;

                throw $e;
            }
        }

        $rateLimitKey = 'onboarding-submit:'.request()->ip();

        if (RateLimiter::tooManyAttempts($rateLimitKey, self::SUBMIT_MAX_ATTEMPTS)) {
            $this->errorMessage = 'Muitas tentativas de cadastro em pouco tempo. Aguarde e tente novamente mais tarde.';

            return;
        }

        RateLimiter::hit($rateLimitKey, self::SUBMIT_DECAY_SECONDS);

        $this->submitting = true;
        $this->errorMessage = null;

        try {
            $dto = new OnboardingDTO(
                companyName: $this->companyName,
                slug: $this->slug,
                plan: $this->plan,
                asaasCpfCnpj: $this->asaasCpfCnpj,
                branchName: $this->branchName,
                branchPhone: $this->branchPhone,
                userName: $this->userName,
                userEmail: $this->userEmail,
                userPassword: $this->userPassword,
                paymentMethod: $this->paymentMethod,
                marketingOptIn: $this->marketingOptIn,
            );

            $company = app(OnboardingService::class)->handle($dto);

            // Free plan: activated immediately, no payment required
            if (Plan::tryFrom($this->plan) === Plan::Free) {
                $this->submitting = false;
                $this->redirectRoute('login');

                return;
            }

            // Paid plans: wait for setup fee payment
            if ($this->paymentMethod === 'CREDIT_CARD') {
                $this->pendingCompanyId = $company->id;
                session()->put(self::SESSION_PENDING_COMPANY, $company->id);
                $this->cardCpfCnpj = $this->asaasCpfCnpj;
                $this->submitting = false;
                $this->dispatch('open-card-modal');

                return;
            }

            $this->redirectRoute('login');
        } catch (\Throwable $e) {
            $this->submitting = false;
            $this->errorMessage = 'Ocorreu um erro ao criar sua conta. Por favor, tente novamente.';
            Log::error('Onboarding falhou', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @param  array{number?: string, expiry?: string, cvv?: string}  $card
     */
    public function submitCard(array $card = []): void
    {
        $company = $this->pendingCompany();

        if (! $company) {
            $this->cardError = 'Não foi possível continuar o pagamento. Recarregue a página e faça o cadastro novamente.';

            return;
        }

        // Por IP e por empresa: sem isso o modal virava oráculo de teste de cartão.
        $rateLimitKeys = [
            'onboarding-card:ip:'.request()->ip(),
            'onboarding-card:company:'.$company->id,
        ];

        foreach ($rateLimitKeys as $key) {
            if (RateLimiter::tooManyAttempts($key, self::CARD_MAX_ATTEMPTS)) {
                $this->cardError = 'Muitas tentativas de pagamento em pouco tempo. Aguarde alguns minutos e tente novamente.';

                return;
            }
        }

        $cardNumber = preg_replace('/\D/', '', (string) ($card['number'] ?? ''));
        $cardExpiry = trim((string) ($card['expiry'] ?? ''));
        $cardCvv = trim((string) ($card['cvv'] ?? ''));

        Validator::make([
            'cardNumber' => $cardNumber,
            'cardExpiry' => $cardExpiry,
            'cardCvv' => $cardCvv,
            'cardHolderName' => $this->cardHolderName,
            'cardCpfCnpj' => $this->cardCpfCnpj,
            'cardPostalCode' => $this->cardPostalCode,
            'cardAddressNumber' => $this->cardAddressNumber,
        ], [
            'cardNumber' => ['required', 'digits_between:13,19'],
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
            'cardNumber.digits_between' => 'Número do cartão inválido.',
            'cardExpiry.required' => 'Informe a validade.',
            'cardExpiry.regex' => 'Use o formato MM/AA.',
            'cardCvv.required' => 'Informe o CVV.',
            'cardCvv.digits_between' => 'CVV deve ter 3 ou 4 dígitos.',
            'cardHolderName.required' => 'Informe o nome conforme está no cartão.',
            'cardCpfCnpj.required' => 'Informe o CPF ou CNPJ do titular.',
            'cardPostalCode.required' => 'Informe o CEP de cobrança.',
            'cardPostalCode.min' => 'CEP inválido.',
            'cardAddressNumber.required' => 'Informe o número do endereço.',
        ])->validate();

        foreach ($rateLimitKeys as $key) {
            RateLimiter::hit($key, self::CARD_DECAY_SECONDS);
        }

        $this->cardProcessing = true;
        $this->cardError = null;

        try {
            $admin = $company->users()->first();
            $phone = preg_replace('/\D/', '', $this->branchPhone ?: ($company->branches()->withoutGlobalScopes()->value('phone') ?? ''));

            $plan = $company->plan;
            $setupFee = $plan?->setupFee() ?? 99.00;
            $monthlyPrice = $plan?->hasMonthlySubscription() ? ($plan?->monthlyPrice() ?? 0.0) : 0.0;
            $firstAmount = $setupFee + $monthlyPrice;
            $description = $monthlyPrice > 0
                ? "Ativação + 1º mês ({$plan?->label()}) — {$company->name}"
                : "Taxa de ativação — {$plan?->label()} — {$company->name}";

            [$month, $year] = explode('/', $cardExpiry);

            $creditCard = new CreditCardDTO(
                holderName: $this->cardHolderName,
                number: $cardNumber,
                expiryMonth: $month,
                expiryYear: '20'.$year,
                ccv: $cardCvv,
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

            $subscription = null;

            if ($plan?->hasMonthlySubscription()) {
                // Assinatura antes da cobrança: se o cartão for recusado em qualquer dos dois
                // passos, nada fica cobrado (antes a taxa era cobrada e a assinatura recusada
                // depois, deixando a empresa paga, pendente e sem como tentar de novo).
                ['subscription' => $subscription, 'charge' => $charge] = app(SubscriptionBillingService::class)->subscribeThenCharge(
                    company: $company,
                    plan: $plan,
                    extra: AddonModulePricing::extraFor($company),
                    chargeAmount: $firstAmount,
                    description: $description,
                    externalReference: "setup_fee_{$company->id}",
                    creditCard: $creditCard,
                    holderInfo: $holderInfo,
                );
            } else {
                $charge = app(AsaasServiceInterface::class)->createCreditCardCharge(
                    customerId: $company->asaas_customer_id,
                    amount: $firstAmount,
                    description: $description,
                    externalReference: "setup_fee_{$company->id}",
                    creditCard: $creditCard,
                    holderInfo: $holderInfo,
                );

                if (($charge['status'] ?? '') !== 'CONFIRMED') {
                    throw CardPaymentDeclinedException::fromCharge($charge);
                }
            }

            // Tudo numa gravação só: o webhook da taxa usa asaas_subscription_id para não
            // criar uma segunda assinatura.
            $company->update(array_merge([
                'asaas_setup_charge_id' => $charge['id'],
                'setup_fee_paid_at' => now(),
            ], $subscription ? [
                'asaas_subscription_id' => $subscription['id'],
                'subscription_payment_method' => 'CREDIT_CARD',
            ] : []));
            $company->saveAsaasCreditCardFromCharge($charge);

            if ($subscription) {
                Subscription::create([
                    'company_id' => $company->id,
                    'asaas_subscription_id' => $subscription['id'],
                    'plan' => $plan->value,
                    'status' => 'active',
                    'amount' => $subscription['value'],
                    'billing_cycle' => 'MONTHLY',
                    'next_due_date' => $subscription['nextDueDate'],
                ]);
            }

            app(CompanyService::class)->activate($company);

            session()->forget(self::SESSION_PENDING_COMPANY);

            $this->cardSuccess = true;
            $this->cardProcessing = false;

            $this->redirectRoute('login');
        } catch (CardPaymentDeclinedException $e) {
            // Motivo da recusa só no log: devolvê-lo na tela ajudava a testar cartões.
            Log::channel('payments')->warning('Onboarding: cartão da taxa de ativação recusado', [
                'company_id' => $company->id,
                'decline_reason' => $e->reason,
            ]);

            $this->cardError = 'Pagamento não aprovado. Confira os dados do cartão ou use outro cartão.';
            $this->cardProcessing = false;
        } catch (\Throwable $e) {
            Log::channel('payments')->warning('Onboarding: falha ao processar cartão da taxa de ativação', [
                'company_id' => $company->id,
                'exception' => $e::class,
            ]);

            $this->cardError = 'Erro ao processar pagamento. Por favor, tente novamente.';
            $this->cardProcessing = false;
        }
    }

    /**
     * Empresa que este navegador acabou de criar e que ainda espera a taxa de
     * ativação. O id precisa bater com o da sessão (gravado em submit()) e a
     * empresa precisa seguir PENDING_PAYMENT sem taxa paga: nunca reativa nem
     * troca a assinatura de empresa BLOCKED/ACTIVE.
     */
    private function pendingCompany(): ?Company
    {
        $companyId = $this->pendingCompanyId;

        if (! $companyId || (int) session(self::SESSION_PENDING_COMPANY) !== $companyId) {
            return null;
        }

        return Company::query()
            ->whereKey($companyId)
            ->where('status', 'PENDING_PAYMENT')
            ->whereNull('setup_fee_paid_at')
            ->first();
    }

    public function render()
    {
        return view('livewire.onboarding.register-form')
            ->layout('layouts.auth');
    }
}
