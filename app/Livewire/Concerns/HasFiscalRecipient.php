<?php

namespace App\Livewire\Concerns;

use App\Contracts\CnpjLookupInterface;
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
    }

    /** Ao digitar um CNPJ válido no formulário, preenche razão social e endereço pela consulta pública. */
    public function updatedFiscalRecipientDraft(mixed $value, ?string $key = null): void
    {
        if ($key !== 'document') {
            return;
        }
        $digits = FiscalRecipient::normalizeDocument((string) $value);
        if (FiscalRecipient::documentType($digits) !== FiscalRecipient::TYPE_CNPJ || ! FiscalRecipient::isValidDocument($digits)) {
            $this->fiscalRecipientNotice = null;

            return;
        }
        $found = app(CnpjLookupInterface::class)->find($digits);
        $this->fiscalRecipientNotice = match (true) {
            $found === null => 'Não foi possível consultar o CNPJ. Preencha os dados da empresa.',
            filled($found['status'] ?? null) && mb_strtoupper($found['status']) !== 'ATIVA' => "Situação do CNPJ na Receita: {$found['status']}. Confira antes de salvar.",
            default => null,
        };
        if ($found === null) {
            return;
        }
        $draft = $this->fiscalRecipientDraft;
        foreach (['name', 'email', 'street', 'number', 'complement', 'neighborhood', 'city', 'state', 'zip'] as $field) {
            $draft[$field] = $found[$field] ?? '';
        }
        // Sugestão: venda pra empresa costuma ser consumo final sem ICMS a recolher. Fica "a conferir"
        // (ie_confirmed = false) até alguém salvar o formulário — a consulta pública não informa a IE.
        $draft['ie_indicator'] = '9';
        $draft['ie'] = '';
        $draft['ie_confirmed'] = false;
        $this->fiscalRecipientDraft = $draft;
    }

    public function saveFiscalRecipient(): void
    {
        $customer = $this->fiscalCustomer();
        abort_unless($customer || isset($this->order), 422);
        if (isset($this->order)) {
            abort_unless(app('current.company')->canUseFiscalNotes() && auth()->user()->hasPermission('fiscal.issue', app('current.company')), 403);
        }
        try {
            // Salvar o formulário é a conferência da situação da IE (reaproveitada nas próximas compras).
            $recipient = FiscalRecipient::validate([...$this->fiscalRecipientDraft, 'ie_confirmed' => true]);
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
