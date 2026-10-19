<?php

namespace App\Livewire\Concerns;

use App\Models\Customer;
use App\Models\Order;
use App\Services\Fiscal\FiscalRecipient;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

trait HasFiscalRecipient
{
    public bool $showFiscalRecipient = false;

    public array $fiscalRecipientDraft = [];

    public bool $rememberFiscalRecipient = false;

    #[Locked]
    public ?array $savedFiscalRecipient = null;

    #[Locked]
    public ?int $fiscalRecipientCustomerId = null;

    private function fiscalCustomer(): ?Customer
    {
        $companyId = property_exists($this, 'companyId') ? $this->companyId : app('current.company')->id;
        $customerId = isset($this->order) ? $this->order->customer_id : $this->customerId;

        return Customer::withoutGlobalScopes()->where('company_id', $companyId)->find($customerId);
    }

    public function effectiveFiscalRecipient(): array
    {
        $customer = $this->fiscalCustomer();
        if (isset($this->order)) {
            return $this->order->fresh()->fiscal_recipient ?? FiscalRecipient::fromCustomer($customer);
        }
        if ($this->savedFiscalRecipient !== null && $this->fiscalRecipientCustomerId === $customer?->id) {
            return $this->savedFiscalRecipient;
        }

        return FiscalRecipient::fromCustomer($customer);
    }

    public function fiscalRecipientForCheckout(): array
    {
        if ($this->showFiscalRecipient) {
            throw ValidationException::withMessages(['fiscalRecipientDraft.document' => 'Salve os dados fiscais antes de concluir o pedido.']);
        }
        $recipient = $this->effectiveFiscalRecipient();
        $company = property_exists($this, 'companyId')
            ? \App\Models\Company::find($this->companyId) : app('current.company');
        if ($company?->canUseFiscalNotes()) {
            try {
                $recipient = FiscalRecipient::validate($recipient);
            } catch (ValidationException $e) {
                $this->fiscalRecipientDraft = $recipient;
                $this->showFiscalRecipient = true;
                throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($messages, $field) => ['fiscalRecipientDraft.'.$field => $messages])->all());
            }
        }

        return $recipient;
    }

    public function openFiscalRecipient(): void
    {
        if (isset($this->order)) {
            abort_unless(app('current.company')->canUseFiscalNotes() && auth()->user()->hasPermission('fiscal.issue', app('current.company')), 403);
        }
        abort_unless($this->fiscalCustomer(), 422, 'Selecione um cliente para informar os dados fiscais.');
        $this->resetValidation();
        $this->fiscalRecipientDraft = $this->effectiveFiscalRecipient();
        $this->rememberFiscalRecipient = false;
        $this->showFiscalRecipient = true;
    }

    public function saveFiscalRecipient(): void
    {
        $customer = $this->fiscalCustomer();
        abort_unless($customer, 422);
        if (isset($this->order)) {
            abort_unless(app('current.company')->canUseFiscalNotes() && auth()->user()->hasPermission('fiscal.issue', app('current.company')), 403);
        }
        try {
            $recipient = FiscalRecipient::validate($this->fiscalRecipientDraft);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($messages, $field) => ['fiscalRecipientDraft.'.$field => $messages])->all());
        }
        DB::transaction(function () use ($customer, $recipient) {
            if (isset($this->order)) {
                $order = Order::withoutGlobalScopes()->where('company_id', $customer->company_id)->lockForUpdate()->findOrFail($this->order->id);
                if ($order->fiscalNotes()->whereIn('status', ['pending', 'authorized'])->exists()) {
                    throw ValidationException::withMessages(['fiscalRecipientDraft.document' => 'Já existe uma nota ativa. Os dados fiscais deste pedido não podem ser alterados.']);
                }
                $order->update(['fiscal_recipient' => $recipient]);
                $this->order = $order;
            }
            if ($this->rememberFiscalRecipient) {
                $customer->update(['fiscal_recipient' => $recipient]);
            }
        });
        $this->savedFiscalRecipient = $recipient;
        $this->fiscalRecipientCustomerId = $customer->id;
        $this->showFiscalRecipient = false;
        if (method_exists($this, 'saveToSession')) {
            $this->saveToSession();
        }
    }
}
