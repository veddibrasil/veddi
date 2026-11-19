<?php

namespace App\Services\Fiscal;

use App\Contracts\CnpjLookupInterface;
use App\Models\Customer;
use App\Models\FiscalNote;

class FiscalRecipientAutofill
{
    private const FIELDS = ['name', 'email', 'street', 'number', 'complement', 'neighborhood', 'city', 'state', 'zip', 'ie_indicator', 'ie', 'ie_confirmed'];

    public function __construct(private readonly CnpjLookupInterface $cnpjLookup) {}

    /** Só reutiliza dados do mesmo documento e da mesma empresa; o pedido tem prioridade. */
    public function forDocument(int $companyId, string $document, ?Customer $customer = null, array $preferred = [], bool $reuseStoreData = true): array
    {
        $document = FiscalRecipient::normalizeDocument($document);
        $recipient = FiscalRecipient::normalizeDocument($preferred['document'] ?? '') === $document
            ? [...$preferred, 'document' => $document] : ['document' => $document];
        if (! FiscalRecipient::isValidDocument($document)) {
            return ['recipient' => $recipient, 'notice' => null];
        }

        $personal = FiscalRecipient::documentType($document) === FiscalRecipient::TYPE_CPF;
        if ($customer?->company_id === $companyId && ($personal || $customer->fiscal_recipient !== null)) {
            $saved = FiscalRecipient::fromCustomer($customer);
            if (FiscalRecipient::normalizeDocument($saved['document'] ?? '') === $document) {
                $recipient = $this->mergeMissing($recipient, $saved);
            }
        }

        if ($reuseStoreData && ! $this->complete($recipient)) {
            $documents = array_unique([$document, FiscalRecipient::formatDocument($document)]);
            $matches = Customer::withoutGlobalScopes()->where('company_id', $companyId)
                ->where(fn ($query) => $query->whereIn('tax_id', $documents)->orWhereIn('fiscal_recipient->document', $documents))
                ->latest('updated_at')->limit(10)->get();
            foreach ($matches as $match) {
                if (! $personal && $match->fiscal_recipient === null) {
                    continue;
                }
                $saved = FiscalRecipient::fromCustomer($match);
                if (FiscalRecipient::normalizeDocument($saved['document'] ?? '') === $document) {
                    $recipient = $this->mergeMissing($recipient, $saved);
                }
            }
            if (! $this->complete($recipient)) {
                $note = FiscalNote::withoutGlobalScopes()->where('company_id', $companyId)->where('status', 'authorized')
                    ->whereIn('data->recipient->document', $documents)->latest('id')->first();
                if ($note) {
                    $recipient = $this->mergeMissing($recipient, $note->data['recipient'] ?? []);
                }
            }
        }

        $notice = null;
        if (! $personal && ! $this->complete($recipient)) {
            $found = $this->cnpjLookup->find($document);
            $recipient = $this->mergeMissing($recipient, $found ?? []);
            $notice = match (true) {
                $found === null => 'Não foi possível consultar o CNPJ. Complete os dados que faltam.',
                filled($found['status'] ?? null) && mb_strtoupper($found['status']) !== 'ATIVA' => "Situação do CNPJ na Receita: {$found['status']}. Confira antes de emitir.",
                default => null,
            };
        }

        $recipient['ie_indicator'] ??= '9';
        $recipient['ie_confirmed'] ??= false;

        return ['recipient' => $recipient, 'notice' => $notice];
    }

    private function complete(array $recipient): bool
    {
        foreach (['name', 'street', 'number', 'neighborhood', 'city', 'state', 'zip'] as $field) {
            if (blank($recipient[$field] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function mergeMissing(array $recipient, array $source): array
    {
        return FiscalRecipient::fillMissing($recipient, $source, self::FIELDS);
    }
}
