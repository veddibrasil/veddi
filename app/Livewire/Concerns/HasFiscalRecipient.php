<?php

namespace App\Livewire\Concerns;

use App\Models\Customer;
use App\Models\Order;
use App\Services\Fiscal\FiscalPostalCodeLookup;
use App\Services\Fiscal\FiscalRecipient;
use App\Services\Fiscal\FiscalRecipientAutofill;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

trait HasFiscalRecipient
{
    public bool $showFiscalRecipient = false;

    public array $fiscalRecipientDraft = [];

    public bool $rememberFiscalRecipient = false;

    public ?string $fiscalRecipientNotice = null;

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
        // No pedido o destinatário é do pedido: balcão sem cliente cadastrado também pode ter nota com CNPJ.
        abort_unless($this->fiscalCustomer() || isset($this->order), 422, 'Selecione um cliente para informar os dados fiscais.');
        $this->resetValidation();
        $this->fiscalRecipientDraft = $this->effectiveFiscalRecipient();
        $this->fiscalRecipientNotice = null;
        $this->rememberFiscalRecipient = false;
        $this->showFiscalRecipient = true;
        $this->autofillFiscalRecipient();
    }

    /** Modelo em conferência no pedido; o chat apenas cadastra os dados, sem emitir. */
    private function fiscalRecipientValidationModel(): ?string
    {
        if (! isset($this->order)) {
            return null;
        }

        $order = clone $this->order;
        $order->fiscal_recipient = $this->fiscalRecipientDraft;

        return app(\App\Services\Fiscal\FiscalEmissionPolicy::class)->forOrder(
            $order, preference: $this->fiscalDocumentChoice ?: null, usePreference: false,
        )->model;
    }

    /** Trocar o documento no formulário não pode transferir dados pessoais de outro comprador. */
    public function updatingFiscalRecipientDraft(mixed $value, ?string $key = null): void
    {
        if ($key === 'document' && FiscalRecipient::normalizeDocument((string) $value)
            !== FiscalRecipient::normalizeDocument($this->fiscalRecipientDraft['document'] ?? '')) {
            $this->fiscalRecipientDraft = [];
        }
    }

    /** Ao digitar um CNPJ válido no formulário, preenche razão social e endereço pela consulta pública. */
    public function updatedFiscalRecipientDraft(mixed $value, ?string $key = null): void
    {
        if ($key === 'zip') {
            $postalCode = preg_replace('/\D/', '', (string) $value);
            if (strlen($postalCode) === 8) {
                $address = app(FiscalPostalCodeLookup::class)->find($postalCode);
                $this->fiscalRecipientNotice = $address === null ? 'CEP não encontrado ou consulta indisponível. Preencha o endereço manualmente.' : null;
                $this->fiscalRecipientDraft = array_replace($this->fiscalRecipientDraft, $address ?? []);
            }

            return;
        }
        if ($key !== 'document') {
            return;
        }
        $this->autofillFiscalRecipient();
    }

    private function autofillFiscalRecipient(): void
    {
        $companyId = isset($this->order) ? $this->order->company_id : $this->companyId;
        $result = app(FiscalRecipientAutofill::class)->forDocument(
            $companyId, $this->fiscalRecipientDraft['document'] ?? '', $this->fiscalCustomer(),
            $this->fiscalRecipientDraft, reuseStoreData: isset($this->order),
        );
        $this->fiscalRecipientDraft = FiscalRecipient::draft($result['recipient']);
        $this->fiscalRecipientNotice = $result['notice'];
    }

    public function saveFiscalRecipient(): void
    {
        $this->resetValidation('fiscalRecipientDraft.*');
        $customer = $this->fiscalCustomer();
        abort_unless($customer || isset($this->order), 422);
        if (isset($this->order)) {
            abort_unless(app('current.company')->canUseFiscalNotes() && auth()->user()->hasPermission('fiscal.issue', app('current.company')), 403);
        }
        try {
            // Salvar o formulário é a conferência da situação da IE (reaproveitada nas próximas compras).
            $recipient = FiscalRecipient::validate([...$this->fiscalRecipientDraft, 'ie_confirmed' => true], $this->fiscalRecipientValidationModel());
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($messages, $field) => ['fiscalRecipientDraft.'.$field => $messages])->all());
        }
        DB::transaction(function () use ($customer, $recipient) {
            if (isset($this->order)) {
                $order = Order::withoutGlobalScopes()->where('company_id', $this->order->company_id)->lockForUpdate()->findOrFail($this->order->id);
                if ($order->fiscalNotes()->whereIn('status', ['pending', 'authorized'])->exists()) {
                    throw ValidationException::withMessages(['fiscalRecipientDraft.document' => 'Já existe uma nota ativa. Os dados fiscais deste pedido não podem ser alterados.']);
                }
                // Escolha de modelo feita para outro comprador não vale para o novo documento.
                $sameDocument = FiscalRecipient::normalizeDocument($order->fiscal_recipient['document'] ?? '') === $recipient['document'];
                $order->update(['fiscal_recipient' => $recipient] + ($sameDocument ? [] : ['fiscal_document_preference' => null]));
                $this->order = $order;
            }
            if ($this->rememberFiscalRecipient && $customer) {
                $customer->update(['fiscal_recipient' => $recipient]);
            }
        });
        $this->savedFiscalRecipient = $recipient;
        $this->fiscalRecipientCustomerId = $customer?->id;
        $this->showFiscalRecipient = false;
        if (method_exists($this, 'saveToSession')) {
            $this->saveToSession();
        }
    }
}
