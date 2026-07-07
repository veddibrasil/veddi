<?php

namespace App\Services\Fiscal;

use App\Contracts\FiscalNoteProviderInterface;
use App\DTOs\FiscalNoteDTO;
use App\Models\Branch;
use App\Models\FiscalNote;
use App\Models\Order;
use Illuminate\Support\Facades\Log;

class FiscalNoteService
{
    public function __construct(
        private readonly FiscalNoteProviderInterface $provider,
    ) {}

    public function issue(Order $order, ?string $customerDocument = null): FiscalNote
    {
        $company = $order->company;

        if (! $company->canUseFiscalNotes()) {
            throw new \RuntimeException('Módulo de nota fiscal não habilitado para esta empresa.');
        }

        $companyConfig = $company->fiscalConfig;

        if (! $companyConfig || ! $companyConfig->enabled) {
            throw new \RuntimeException('Configuração fiscal não habilitada para esta empresa.');
        }

        $token = $companyConfig->provider_token ?: config('fiscal.focus_nfe.token');

        if (! $token) {
            throw new \RuntimeException('Token do provedor fiscal não configurado. Contate o suporte.');
        }

        $environment = $companyConfig->environment ?: config('fiscal.environment');

        // Empresa com token próprio (conta Focus NFe dela) emite pela conta dela;
        // sem token próprio, cai no token da plataforma (via provider injetado).
        $provider = $companyConfig->provider_token
            ? new FocusNfeService($companyConfig->provider_token, $this->baseUrlFor($environment))
            : $this->provider;

        $branch = Branch::withoutGlobalScopes()->find($order->branch_id);

        $dto = new FiscalNoteDTO(
            emitenteCnpj: $company->owner_cpf_cnpj ?? '',
            emitenteNome: $company->name,
            emitenteLogradouro: $branch?->address ?? '',
            emitenteNumero: $branch?->number ?? 'S/N',
            emitenteBairro: $branch?->neighborhood ?? '',
            emitenteMunicipio: $branch?->city ?? '',
            emitenteUf: $branch?->state ?? '',
            emitenteCep: $branch?->cep ?? '',
            emitenteCrt: $companyConfig->crt ?: config('fiscal.crt'),
            emitenteInscricaoEstadual: $companyConfig->inscricao_estadual,
            nfceSerie: (string) ($companyConfig->nfce_serie ?: config('fiscal.nfce_serie')),
            orderId: $order->id,
            total: (float) $order->total,
            paymentMethod: $order->payment_method ?? 'other',
            items: $this->buildItems($order),
            customerDocument: $customerDocument,
            environment: $environment,
            orderType: $order->order_type,
        );

        $fiscalNote = FiscalNote::create([
            'company_id' => $company->id,
            'order_id' => $order->id,
            'status' => 'pending',
        ]);

        try {
            $result = $provider->issue($dto);

            $fiscalNote->update([
                'status' => $result->status,
                'provider_reference' => $result->providerReference,
                'access_key' => $result->accessKey,
                'data' => [
                    'xml_url' => $result->xmlUrl,
                    'danfe_url' => $result->danfeUrl,
                    'error_message' => $result->errorMessage,
                    'issued_at' => now()->toIso8601String(),
                    'raw_response' => $result->rawResponse,
                    'customer_cpf_cnpj' => $customerDocument,
                ],
            ]);

            Log::channel('webhook')->info('Nota fiscal emitida', [
                'order_id' => $order->id,
                'fiscal_note_id' => $fiscalNote->id,
                'status' => $result->status,
            ]);
        } catch (\Throwable $e) {
            $fiscalNote->update([
                'status' => 'error',
                'data' => ['error_message' => $e->getMessage()],
            ]);

            Log::channel('webhook')->error('Erro ao emitir nota fiscal', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        return $fiscalNote->fresh();
    }

    public function cancel(FiscalNote $note, string $justification): void
    {
        if (! $note->provider_reference) {
            throw new \RuntimeException('Nota fiscal sem referência do provider.');
        }

        $companyConfig = $note->company->fiscalConfig;
        $environment = $companyConfig?->environment ?: config('fiscal.environment');

        $provider = $companyConfig?->provider_token
            ? new FocusNfeService($companyConfig->provider_token, $this->baseUrlFor($environment))
            : $this->provider;

        $result = $provider->cancel($note->provider_reference, $justification);

        $data = $note->data ?? [];
        $data['cancelled_at'] = now()->toIso8601String();
        $data['cancel_raw_response'] = $result->rawResponse;

        $note->update([
            'status' => $result->status === 'cancelled' ? 'cancelled' : 'error',
            'data' => $data,
        ]);
    }

    private function baseUrlFor(string $environment): string
    {
        // Só bate na API de produção da Focus NFe quando o app roda em produção —
        // qualquer outro APP_ENV sempre usa homologação, mesmo que a empresa esteja
        // configurada como "produção", para nunca emitir nota fiscal real fora de produção.
        if (app()->isProduction() && $environment === 'producao') {
            return config('fiscal.focus_nfe.base_url_producao');
        }

        return config('fiscal.focus_nfe.base_url_homologacao');
    }

    private function buildItems(Order $order): array
    {
        $order->loadMissing('items.product');

        return $order->items->map(function ($item) {
            $fiscal = $item->product?->fiscal_data ?? [];

            return [
                'name' => $item->product_name,
                'ncm' => $fiscal['ncm'] ?? '21069090',
                'cfop' => $fiscal['cfop'] ?? '5102',
                'icms_situation' => $fiscal['icms_situation'] ?? '400',
                'cest' => $fiscal['cest'] ?? null,
                'quantity' => (float) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'subtotal' => (float) $item->subtotal,
            ];
        })->toArray();
    }
}
