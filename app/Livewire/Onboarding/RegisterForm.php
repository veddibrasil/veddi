<?php

namespace App\Livewire\Onboarding;

use App\DTOs\OnboardingDTO;
use App\Enums\Plan;
use App\Helpers\Validation;
use App\Services\OnboardingService;
use Illuminate\Support\Str;
use Livewire\Component;

class RegisterForm extends Component
{
    public int $currentStep = 1;

    // Step 1: Company
    public string $companyName          = '';
    public string $slug                 = '';
    public bool   $slugManuallyEdited   = false;

    // Step 2: Branch
    public string $branchName       = '';
    public string $branchPhone      = '';
    public bool   $branchPhoneValid = false;
    public ?string $branchPhoneError = null;

    // Step 3: User
    public string $userName     = '';
    public string $userEmail    = '';
    public string $userPassword = '';

    // Password strength: 0 = empty, 1 = fraca, 2 = média, 3 = forte
    public int  $passwordStrength = 0;
    public bool $passwordHasMin   = false;
    public bool $passwordHasLetter = false;
    public bool $passwordHasNumber = false;
    public bool $passwordHasSpecial = false;

    // Step 4: Plan
    public string  $plan                = 'free';
    public string  $paymentMethod       = 'PIX';
    public string  $asaasCpfCnpj        = '';
    public bool    $asaasCpfCnpjValid   = false;
    public ?string $asaasCpfCnpjError   = null;

    public bool    $submitting   = false;
    public ?string $errorMessage = null;

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
        $len    = strlen($digits);

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

    public function updatedUserPassword(string $value): void
    {
        $this->passwordHasMin     = strlen($value) >= 8;
        $this->passwordHasLetter  = (bool) preg_match('/[a-zA-Z]/', $value);
        $this->passwordHasNumber  = (bool) preg_match('/[0-9]/', $value);
        $this->passwordHasSpecial = (bool) preg_match('/[\W_]/', $value);

        $score = array_sum([
            $this->passwordHasMin,
            $this->passwordHasLetter,
            $this->passwordHasNumber,
            $this->passwordHasSpecial,
        ]);

        $this->passwordStrength = match (true) {
            strlen($value) === 0 => 0,
            $score <= 2          => 1,
            $score === 3         => 2,
            default              => 3,
        };
    }

    private function formatPhone(string $digits): string
    {
        $digits = substr($digits, 0, 11);
        $len    = strlen($digits);

        if ($len === 0) return '';
        if ($len <= 2) return "({$digits}";
        if ($len <= 6) return '(' . substr($digits, 0, 2) . ') ' . substr($digits, 2);

        // Celular (11 digits): (XX) XXXXX-XXXX
        if ($len === 11) {
            return '(' . substr($digits, 0, 2) . ') ' . substr($digits, 2, 5) . '-' . substr($digits, 7, 4);
        }

        // Fixo (10 digits): (XX) XXXX-XXXX
        return '(' . substr($digits, 0, 2) . ') ' . substr($digits, 2, 4) . '-' . substr($digits, 6, 4);
    }

    private function formatCpf(string $digits): string
    {
        $digits = substr($digits, 0, 11);
        $len    = strlen($digits);

        if ($len <= 3) return $digits;
        if ($len <= 6) return substr($digits, 0, 3) . '.' . substr($digits, 3);
        if ($len <= 9) return substr($digits, 0, 3) . '.' . substr($digits, 3, 3) . '.' . substr($digits, 6);

        return substr($digits, 0, 3) . '.' . substr($digits, 3, 3) . '.' . substr($digits, 6, 3) . '-' . substr($digits, 9, 2);
    }

    private function formatCnpj(string $digits): string
    {
        $digits = substr($digits, 0, 14);
        $len    = strlen($digits);

        if ($len <= 2)  return $digits;
        if ($len <= 5)  return substr($digits, 0, 2) . '.' . substr($digits, 2);
        if ($len <= 8)  return substr($digits, 0, 2) . '.' . substr($digits, 2, 3) . '.' . substr($digits, 5);
        if ($len <= 12) return substr($digits, 0, 2) . '.' . substr($digits, 2, 3) . '.' . substr($digits, 5, 3) . '/' . substr($digits, 8);

        return substr($digits, 0, 2) . '.' . substr($digits, 2, 3) . '.' . substr($digits, 5, 3) . '/' . substr($digits, 8, 4) . '-' . substr($digits, 12, 2);
    }

    protected function rulesForStep(int $step): array
    {
        return match ($step) {
            1 => [
                'companyName' => ['required', 'string', 'max:100'],
                'slug'        => ['required', 'string', 'max:100', 'regex:/^[a-z0-9\-]+$/', 'unique:companies,slug'],
            ],
            2 => [
                'branchName'  => ['required', 'string', 'max:100'],
                'branchPhone' => ['required', Validation::PHONE_RULE],
            ],
            3 => [
                'userName'     => ['required', 'string', 'max:100'],
                'userEmail'    => ['required', 'email', 'unique:users,email'],
                'userPassword' => ['required', 'min:8'],
            ],
            4 => [
                'plan'          => ['required', 'in:' . implode(',', Plan::values())],
                'paymentMethod' => ['required', 'in:PIX,BOLETO,CREDIT_CARD'],
                'asaasCpfCnpj'  => ['required', 'string', function (string $attr, mixed $value, \Closure $fail) {
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
                'slug.required'        => 'Informe o endereço da empresa.',
                'slug.unique'          => 'Este endereço já está em uso.',
                'slug.regex'           => 'O endereço só pode conter letras minúsculas, números e hífens.',
            ],
            2 => [
                'branchName.required'  => 'Informe o nome da filial.',
                'branchPhone.required' => 'Informe o telefone da filial.',
                'branchPhone.regex'    => 'Telefone inválido. Use o formato (XX) XXXXX-XXXX.',
            ],
            3 => [
                'userName.required'     => 'Informe seu nome.',
                'userEmail.required'    => 'Informe seu e-mail.',
                'userEmail.unique'      => 'Este e-mail já está cadastrado.',
                'userPassword.required' => 'Informe uma senha.',
                'userPassword.min'      => 'A senha deve ter no mínimo 8 caracteres.',
            ],
            4 => [
                'plan.required'          => 'Selecione um plano.',
                'plan.in'                => 'Selecione um plano válido.',
                'paymentMethod.required' => 'Selecione uma forma de pagamento.',
                'paymentMethod.in'       => 'Forma de pagamento inválida.',
                'asaasCpfCnpj.required'  => 'CPF ou CNPJ é obrigatório para a conta de pagamento.',
                'asaasCpfCnpj.min'       => 'Informe um CPF (11 dígitos) ou CNPJ (14 dígitos) válido.',
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
        $this->validate(
            $this->rulesForStep(4),
            $this->messagesForStep(4),
        );

        $this->submitting    = true;
        $this->errorMessage  = null;

        try {
            $dto = new OnboardingDTO(
                companyName:   $this->companyName,
                slug:          $this->slug,
                plan:          $this->plan,
                asaasCpfCnpj:  $this->asaasCpfCnpj,
                branchName:    $this->branchName,
                branchPhone:   $this->branchPhone,
                userName:      $this->userName,
                userEmail:     $this->userEmail,
                userPassword:  $this->userPassword,
                paymentMethod: $this->paymentMethod,
            );

            app(OnboardingService::class)->handle($dto);

            // All plans start as PENDING_PAYMENT (setup fee required)
            session()->flash('payment_method', $this->paymentMethod);
            $this->redirectRoute('register.pending');
        } catch (\Throwable $e) {
            $this->submitting   = false;
            $this->errorMessage = 'Ocorreu um erro ao criar sua conta. Por favor, tente novamente.';
            \Illuminate\Support\Facades\Log::error('Onboarding falhou', ['error' => $e->getMessage()]);
        }
    }

    public function render()
    {
        return view('livewire.onboarding.register-form')
            ->layout('layouts.auth');
    }
}
