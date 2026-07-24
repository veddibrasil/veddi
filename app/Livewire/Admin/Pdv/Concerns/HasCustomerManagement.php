<?php

namespace App\Livewire\Admin\Pdv\Concerns;

use App\Models\Company;
use App\Models\Customer;
use Livewire\Attributes\Computed;

trait HasCustomerManagement
{
    public function updatedCustomerQuery(): void
    {
        $this->lookupCustomer();
    }

    public function lookupCustomer(): void
    {
        $this->customerFound = false;
        $this->customerId = null;
        $this->customerResults = [];

        $query = trim($this->customerQuery);

        if (blank($query)) {
            return;
        }

        $company = app('current.company');
        $normalized = preg_replace('/\D/', '', $query);

        $results = Customer::where('company_id', $company->id)
            ->where('phone', '!=', 'pdv-guest')
            ->where(function ($q) use ($query, $normalized) {
                $q->where('name', 'like', '%'.$query.'%');
                if (filled($normalized)) {
                    $q->orWhere('phone', 'like', '%'.$normalized.'%')
                        ->orWhere('tax_id', 'like', '%'.$normalized.'%');
                }
            })
            ->limit(5)
            ->get(['id', 'name', 'phone', 'tax_id', 'address_id']);

        if ($results->isEmpty()) {
            return;
        }

        if ($results->count() === 1) {
            $customer = $results->first();
            $this->customerId = $customer->id;
            $this->customerName = $customer->name ?? '';
            $this->customerFound = true;
            $this->fillDeliveryAddressFromCustomer($customer);

            return;
        }

        $this->customerResults = $results->map(fn ($c) => [
            'id' => $c->id,
            'name' => $c->name ?? '—',
            'phone' => $c->phone,
            'tax_id' => $c->tax_id,
        ])->toArray();
    }

    public function selectCustomer(int $customerId): void
    {
        $company = app('current.company');

        $customer = Customer::where('company_id', $company->id)->find($customerId);

        if (! $customer) {
            return;
        }

        $this->customerId = $customer->id;
        $this->customerName = $customer->name ?? '';
        $this->customerFound = true;
        $this->customerResults = [];
        $this->customerQuery = $customer->name ?? '';
        $this->fillDeliveryAddressFromCustomer($customer);
    }

    /** Preenche os campos de entrega com o endereço cadastrado do cliente, quando houver. */
    private function fillDeliveryAddressFromCustomer(Customer $customer): void
    {
        if ($this->deliveryType !== 'entrega' || blank($customer->address)) {
            return;
        }

        $this->deliveryAddress = $customer->address ?? '';
        $this->deliveryNumber = $customer->number ?? '';
        $this->deliveryComplement = $customer->complement ?? '';
        $this->deliveryNeighborhood = $customer->neighborhood ?? '';
        $this->deliveryCity = $customer->city ?? '';
        $this->deliveryCep = $customer->cep ?? '';

        $this->maybeAutoCalculateDeliveryFee();
    }

    public function showCreateCustomerForm(): void
    {
        $this->showCreateCustomer = true;
        $this->newCustomerName = trim($this->customerQuery);
        $this->newCustomerPhone = '';
        $this->createCustomerError = null;
    }

    public function cancelCreateCustomer(): void
    {
        $this->showCreateCustomer = false;
        $this->newCustomerName = '';
        $this->newCustomerPhone = '';
        $this->createCustomerError = null;
    }

    public function createCustomer(): void
    {
        $this->createCustomerError = null;
        $name = trim($this->newCustomerName);
        $phone = preg_replace('/\D/', '', $this->newCustomerPhone);

        if (blank($name)) {
            $this->createCustomerError = 'Nome obrigatório.';

            return;
        }

        if (blank($phone)) {
            $this->createCustomerError = 'Telefone obrigatório.';

            return;
        }

        $company = app('current.company');

        $existing = Customer::where('company_id', $company->id)
            ->where('phone', $phone)
            ->first();

        if ($existing) {
            $this->createCustomerError = 'Já existe um cliente com esse telefone.';

            return;
        }

        $customer = Customer::create([
            'company_id' => $company->id,
            'name' => $name,
            'phone' => $phone,
        ]);

        $this->customerId = $customer->id;
        $this->customerName = $customer->name;
        $this->customerFound = true;
        $this->customerQuery = $customer->name;
        $this->customerResults = [];
        $this->showCreateCustomer = false;
        $this->newCustomerName = '';
        $this->newCustomerPhone = '';
    }

    private function resolveCustomerId(Company $company): int
    {
        if ($this->customerId) {
            return $this->customerId;
        }

        // Cliente balcão anônimo reusado por empresa
        $guest = Customer::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('phone', 'pdv-guest')
            ->first();

        if (! $guest) {
            $guest = Customer::forceCreate([
                'company_id' => $company->id,
                'phone' => 'pdv-guest',
                'name' => 'Cliente Balcão',
            ]);
        }

        return $guest->id;
    }

    #[Computed]
    public function needsCustomerForDelivery(): bool
    {
        return $this->deliveryType === 'entrega' && ! $this->customerId;
    }
}
