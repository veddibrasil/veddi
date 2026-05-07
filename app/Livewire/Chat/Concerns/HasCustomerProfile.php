<?php

namespace App\Livewire\Chat\Concerns;

use App\Models\Branch;
use App\Models\Customer;
use App\Services\Customer\CustomerService;
use Illuminate\Support\Facades\Log;

trait HasCustomerProfile
{
    public function submitPhone(): void
    {
        $this->phone = preg_replace('/\D/', '', $this->phone);
        $this->validate($this->rules(), $this->messages());

        $service = app(CustomerService::class);
        $customer = $service->findByPhone($this->phone);

        if ($customer) {
            $this->fillCustomerData($customer);
            Log::channel('chat')->info('Cliente identificado pelo telefone', ['customer_id' => $customer->id, 'phone' => $this->phone]);
            $this->addMessage('user', $this->phone);

            if (empty($this->cart)) {
                $recoverableOrder = $this->findRecoverableOrder($customer->id, $this->companyId);
                if ($recoverableOrder) {
                    $this->pendingOrderSummary = [
                        'id' => $recoverableOrder->id,
                        'order_number' => $recoverableOrder->order_number,
                        'total' => (float) $recoverableOrder->total,
                        'status' => $recoverableOrder->status,
                        'payment_method' => strtoupper($recoverableOrder->payment_method),
                        'items_count' => $recoverableOrder->items()->count(),
                    ];
                    $statusLabel = $recoverableOrder->status === 'awaiting_payment' ? 'aguardando pagamento' : 'pendente';
                    $total = 'R$ '.number_format($recoverableOrder->total, 2, ',', '.');
                    $this->addMessage('bot', "Olá, {$customer->name}! Encontrei um pedido em aberto #{$recoverableOrder->order_number} ({$statusLabel}) — {$total}. Deseja continuar?");
                    $this->transitionTo('RECOVER_ORDER');

                    return;
                }
            }

            $nextStep = ! empty($this->cart) ? 'CHECKOUT_COUPON' : 'MENU_BROWSE';
            $this->addMessage('bot', "Que bom te ver de volta, {$customer->name}! Continuando com seu pedido...");
            $this->transitionTo($nextStep);

            return;
        }

        $existing = $service->findFromGlobal($this->phone);

        if ($existing) {
            $customer = $service->createFromGlobal($this->phone, $existing);
            $this->fillCustomerData($customer);
            $this->addMessage('user', $this->phone);

            if (empty($this->cart)) {
                $recoverableOrder = $this->findRecoverableOrder($customer->id, $this->companyId);
                if ($recoverableOrder) {
                    $this->pendingOrderSummary = [
                        'id' => $recoverableOrder->id,
                        'order_number' => $recoverableOrder->order_number,
                        'total' => (float) $recoverableOrder->total,
                        'status' => $recoverableOrder->status,
                        'payment_method' => strtoupper($recoverableOrder->payment_method),
                        'items_count' => $recoverableOrder->items()->count(),
                    ];
                    $statusLabel = $recoverableOrder->status === 'awaiting_payment' ? 'aguardando pagamento' : 'pendente';
                    $total = 'R$ '.number_format($recoverableOrder->total, 2, ',', '.');
                    $this->addMessage('bot', "Olá, {$customer->name}! Encontrei um pedido em aberto #{$recoverableOrder->order_number} ({$statusLabel}) — {$total}. Deseja continuar?");
                    $this->transitionTo('RECOVER_ORDER');

                    return;
                }
            }

            $nextStep = ! empty($this->cart) ? 'CHECKOUT_COUPON' : 'MENU_BROWSE';
            $this->addMessage('bot', "Que bom te ver de novo, {$customer->name}! Continuando com seu pedido...");
            $this->transitionTo($nextStep);

            return;
        }

        Log::channel('chat')->info('Telefone não encontrado — iniciando cadastro', ['phone' => $this->phone]);
        $this->addMessage('user', $this->phone);
        $this->addMessage('bot', 'Não encontrei seu cadastro. Vamos criar um rapidinho! Qual é o seu nome completo?');
        $this->transitionTo('REGISTER_NAME');
    }

    public function submitName(): void
    {
        $this->validate($this->rules(), $this->messages());
        $this->addMessage('user', $this->name);
        $this->addMessage('bot', "Prazer, {$this->name}! Qual é o seu e-mail?");
        $this->transitionTo('REGISTER_EMAIL');
    }

    public function submitEmail(): void
    {
        $this->validate($this->rules(), $this->messages());
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
        $this->validate($this->rules(), $this->messages());

        $normalized = preg_replace('/\D/', '', $this->phone);

        $customer = Customer::updateOrCreate(
            ['company_id' => $this->companyId, 'phone' => $normalized],
            [
                'name' => $this->name,
                'email' => $this->email !== '' ? $this->email : null,
                'address' => $this->address,
                'complement' => $this->complement,
                'neighborhood' => $this->neighborhood,
                'city' => $this->city,
                'number' => $this->number,
                'cep' => preg_replace('/\D/', '', $this->cep),
            ]
        );

        $this->customerId = $customer->id;
        Log::channel('chat')->info('Novo cliente cadastrado', ['customer_id' => $customer->id, 'phone' => $normalized]);

        $addressSummary = $this->address;
        if ($this->complement) {
            $addressSummary .= ", {$this->complement}";
        }
        $addressSummary .= " — {$this->neighborhood}, {$this->number}, {$this->city} — CEP {$this->cep}";
        $this->addMessage('user', $addressSummary);
        $nextStep = ! empty($this->cart) ? 'CHECKOUT_COUPON' : 'MENU_BROWSE';
        $this->addMessage('bot', 'Cadastro criado com sucesso! Continuando com seu pedido...');
        $this->transitionTo($nextStep);
    }

    public function openEditProfile(): void
    {
        $this->previousStep = $this->step;
        $this->transitionTo('EDIT_PROFILE');
    }

    public function submitEditProfile(): void
    {
        $this->validate($this->rules(), $this->messages());

        app(CustomerService::class)->updateProfile($this->customerId, [
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
        $this->taxId = $customer->tax_id ?? '';
    }
}
