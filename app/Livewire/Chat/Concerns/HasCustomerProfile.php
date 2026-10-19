<?php

namespace App\Livewire\Chat\Concerns;

use App\Models\Branch;
use App\Models\Customer;
use App\Services\Customer\CustomerService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

trait HasCustomerProfile
{
    public function submitPhone(): void
    {
        $this->phone = preg_replace('/\D/', '', $this->phone);
        $this->validate($this->rulesFor('IDENTIFY_PHONE'), $this->messages());

        // Limita abuso de entrada no checkout; consultar telefone nunca carrega um perfil.
        $rateLimitKey = "chat-phone-lookup:{$this->companyId}:".request()->ip();

        if (RateLimiter::tooManyAttempts($rateLimitKey, 30)) {
            Log::channel('chat')->warning('submitPhone rate limitado', ['company_id' => $this->companyId]);
            $this->addMessage('bot', 'Recebemos muitas consultas em pouco tempo. Aguarde alguns minutos e tente novamente.');

            return;
        }

        RateLimiter::hit($rateLimitKey, 600);

        $this->clearCheckoutIdentity();
        $this->addMessage('user', $this->phone);
        $this->addMessage('bot', 'Você pode preencher os dados desta compra ou confirmar seu telefone para usar dados salvos.');
        $this->transitionTo('CUSTOMER_OPTIONS');
    }

    private function clearCheckoutIdentity(): void
    {
        $this->customerId = null;
        $this->reset('name', 'email', 'address', 'number', 'complement', 'neighborhood', 'city', 'cep', 'taxId', 'customer_latitude', 'customer_longitude', 'customerCards', 'selectedCardId', 'orderHistory', 'pendingOrderSummary', 'verificationCode', 'challengeId');
        $this->checkoutReady = false;
        $this->useSavedProfile = false;
    }

    public function continueAsGuest(): void
    {
        $this->clearCheckoutIdentity();
        $this->addMessage('bot', 'Qual é o seu nome completo?');
        $this->transitionTo('REGISTER_NAME');
    }

    public function useSavedData(): void
    {
        $customer = $this->verifiedCustomer();
        if ($customer && $customer->phone === $this->phone) {
            $this->useSavedProfile = true;
            $this->fillCustomerData($customer);
            $this->checkoutReady = true;
            $this->transitionTo(empty($this->cart) ? 'MENU_BROWSE' : 'CHECKOUT_COUPON');

            return;
        }
        $this->requestVerificationCode();
    }

    public function requestVerificationCode(): void
    {
        $this->validate(['phone' => ['required', 'regex:/^\d{10,11}$/']]);
        try {
            $this->challengeId = app(\App\Services\Chat\PhoneVerification::class)->send($this->companyId, $this->phone);
            $this->verificationCode = '';
            $this->transitionTo('VERIFY_PHONE');
        } catch (\RuntimeException $e) {
            $this->addError('verificationCode', $e->getMessage());
        }
    }

    public function submitVerificationCode(): void
    {
        $this->validate(['verificationCode' => ['required', 'regex:/^\d{6}$/']]);
        if (! $this->challengeId || ! app(\App\Services\Chat\PhoneVerification::class)->consume($this->challengeId, $this->companyId, $this->phone, $this->verificationCode)) {
            $this->addError('verificationCode', 'Código inválido ou expirado. Solicite outro ou preencha seus dados.');

            return;
        }
        $this->reset('verificationCode', 'challengeId');
        $customer = app(\App\Services\Chat\ChatAccess::class)->verify($this->companyId, $this->phone);
        if (! $customer) {
            $this->continueAsGuest();

            return;
        }
        $this->useSavedProfile = true;
        $this->fillCustomerData($customer);
        $this->checkoutReady = true;
        if ($this->rememberDevice) {
            app(\App\Services\Chat\RememberedDevice::class)->remember($customer);
        }
        $this->addMessage('bot', 'Telefone confirmado. Confira os dados para esta compra.');
        $recoverable = $this->findRecoverableOrder($customer->id, $this->companyId);
        if (empty($this->cart) && $recoverable) {
            $this->pendingOrderSummary = [
                'id' => $recoverable->id, 'order_number' => $recoverable->order_number,
                'total' => (float) $recoverable->total, 'status' => $recoverable->status,
                'payment_method' => strtoupper($recoverable->payment_method), 'items_count' => $recoverable->items()->count(),
            ];
            $this->transitionTo('RECOVER_ORDER');

            return;
        }
        $this->transitionTo(empty($this->cart) ? 'MENU_BROWSE' : 'CHECKOUT_COUPON');
    }

    public function forgetDevice(): void
    {
        app(\App\Services\Chat\RememberedDevice::class)->forget($this->companyId);
        $this->resetState();
        $this->initialize();
    }

    public function submitName(): void
    {
        $this->validate($this->rulesFor('REGISTER_NAME'), $this->messages());
        $this->addMessage('user', $this->name);
        $this->checkoutReady = true;
        $this->addMessage('bot', 'Dados desta compra preenchidos. Continuando com seu pedido...');
        $this->transitionTo(empty($this->cart) ? 'MENU_BROWSE' : 'CHECKOUT_COUPON');
    }

    public function submitEmail(): void
    {
        $this->validate($this->rulesFor('REGISTER_EMAIL'), $this->messages());
        if ($this->email) {
            $this->addMessage('user', $this->email);
        }
        $this->addMessage('bot', 'Agora me passe seu endereço de entrega.');
        $this->transitionTo('REGISTER_ADDRESS');
    }

    public function skipEmail(): void
    {
        $this->email = '';
        $this->addMessage('bot', 'Tudo bem! Agora me passe seu endereço de entrega.');
        $this->transitionTo('REGISTER_ADDRESS');
    }

    public function submitAddress(): void
    {
        $this->validate($this->rulesFor('REGISTER_ADDRESS'), $this->messages());

        $addressSummary = $this->address;
        if ($this->complement) {
            $addressSummary .= ", {$this->complement}";
        }
        $addressSummary .= " — {$this->neighborhood}, {$this->number}, {$this->city} — CEP {$this->cep}";
        $this->addMessage('user', $addressSummary);
        $nextStep = ! empty($this->cart) ? 'CHECKOUT_COUPON' : 'MENU_BROWSE';
        $this->addMessage('bot', 'Dados desta compra preenchidos! Continuando com seu pedido...');
        $this->transitionTo($nextStep);
    }

    public function openEditProfile(): void
    {
        if (! $this->verifiedCustomer()) {
            return;
        }

        $this->previousStep = $this->step;
        $this->transitionTo('EDIT_PROFILE');
    }

    public function submitEditProfile(): void
    {
        if (! $this->verifiedCustomer()) {
            return;
        }

        $this->validate($this->rulesFor('EDIT_PROFILE'), $this->messages());

        app(CustomerService::class)->updateProfile($this->verifiedCustomer()->id, [
            'name' => $this->name,
            'address' => $this->address,
            'complement' => $this->complement,
            'neighborhood' => $this->neighborhood,
            'number' => $this->number,
            'city' => $this->city,
            'cep' => $this->cep,
        ]);

        $this->addMessage('bot', 'Cadastro atualizado com sucesso!');
        $this->transitionTo($this->previousStep ?? 'BRANCH_SELECT');
        $this->previousStep = null;
    }

    public function cancelEditProfile(): void
    {
        $this->transitionTo($this->previousStep ?? 'BRANCH_SELECT');
        $this->previousStep = null;
    }

    public function selectBranch(int $branchId): void
    {
        $branch = Branch::withoutGlobalScopes()
            ->where('id', $branchId)
            ->where('company_id', $this->companyId)
            ->where('active', true)
            ->firstOrFail();

        if ($pause = $branch->activePauseAt()) {
            $reasonText = $pause->reason ? " Motivo: {$pause->reason}." : '';
            $this->addMessage('bot', "A filial {$branch->name} está temporariamente fechada.{$reasonText} Escolha outra filial ou tente mais tarde.");

            return;
        }

        if (! $branch->isOpen()) {
            $this->addMessage('bot', "A filial {$branch->name} está fechada no momento. Horário: {$branch->opens_at} às {$branch->closes_at}. Escolha outra filial ou tente mais tarde.");

            return;
        }

        if ($this->selectedBranchId !== null && $this->selectedBranchId !== $branch->id) {
            $this->cart = [];
        }

        $this->selectedBranchId = $branch->id;
        Log::channel('chat')->info('Filial selecionada', ['customer_id' => $this->customerId, 'branch_id' => $branch->id, 'branch_name' => $branch->name]);
        $this->addMessage('user', $branch->name);
        $this->addMessage('bot', "Ótimo! Aqui está o cardápio da {$branch->name}. Adicione os itens que quiser!");
        $this->transitionTo('MENU_BROWSE');
    }

    private function fillCustomerData(Customer $customer): void
    {
        $this->customerId = $customer->id;
        $this->name = $customer->name;
        $this->email = $customer->email ?? '';
        $this->address = $customer->address ?? '';
        $this->complement = $customer->complement ?? '';
        $this->neighborhood = $customer->neighborhood ?? '';
        $this->number = $customer->number ?? '';
        $this->city = $customer->city ?? '';
        $this->cep = $customer->cep ?? '';
        $this->taxId = strlen(preg_replace('/\D/', '', $customer->tax_id ?? '')) === 11 ? $customer->tax_id : '';
    }
}
