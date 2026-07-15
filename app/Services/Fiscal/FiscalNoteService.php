<?php

namespace App\Services\Fiscal;

use App\Contracts\FiscalNoteProviderInterface;
use App\DTOs\FiscalNoteDTO;
use App\Exceptions\FiscalNoteAlreadyIssuedException;
use App\Models\Branch;
use App\Models\CompanyFiscalConfig;
use App\Models\FiscalNote;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FiscalNoteService
{
    public function __construct(
        private readonly FiscalNoteProviderInterface $provider,
    ) {}

    public function issue(Order $order, ?string $customerDocument = null): FiscalNote
    {
        $company = $order->company;

        Log::channel('fiscal')->info('FiscalNoteService: emissão iniciada', [
            'order_id' => $order->id,
            'company_id' => $company->id,
        ]);

        if (! $company->canUseFiscalNotes()) {
            throw new \RuntimeException('Módulo de nota fiscal não habilitado para esta empresa.');
        }

        $companyConfig = $company->fiscalConfig;

        if (! $companyConfig || ! $companyConfig->enabled) {
            throw new \RuntimeException('Configuração fiscal não habilitada para esta empresa.');
        }

        $environment = $companyConfig->environment ?: config('fiscal.environment');
        $effectiveEnvironment = $this->effectiveEnvironment($environment);
        $token = $companyConfig->tokenFor($effectiveEnvironment) ?: config('fiscal.focus_nfe.token');

        if (! $token) {
            throw new \RuntimeException('Token do provedor fiscal não configurado. Contate o suporte.');
        }

        $provider = $this->resolveProvider($companyConfig, $effectiveEnvironment);

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

        $fiscalNote = $this->createPendingNoteExclusively($order, $company->id);

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

            Log::channel('fiscal')->info('Nota fiscal emitida', [
                'order_id' => $order->id,
                'fiscal_note_id' => $fiscalNote->id,
                'status' => $result->status,
            ]);
        } catch (\Throwable $e) {
            $fiscalNote->update([
                'status' => 'error',
                'data' => ['error_message' => $e->getMessage()],
            ]);

            Log::channel('fiscal')->error('Erro ao emitir nota fiscal', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        return $fiscalNote->fresh();
    }

    /**
     * Trava a linha do pedido durante a checagem de nota ativa + criação do registro
     * pending, numa única transação. Sem isso, dois disparos concorrentes (ex.: evento
     * de pagamento duplicado, retry de job) passam ambos pelo "existe nota?" antes que
     * qualquer um grave a sua, e a empresa acaba emitindo duas NFC-e reais pro mesmo pedido.
     */
    private function createPendingNoteExclusively(Order $order, int $companyId): FiscalNote
    {
        return DB::transaction(function () use ($order, $companyId) {
            Order::withoutGlobalScopes()->whereKey($order->id)->lockForUpdate()->first();

            $hasActiveNote = FiscalNote::withoutGlobalScopes()
                ->where('order_id', $order->id)
                ->whereIn('status', ['pending', 'authorized'])
                ->exists();

            if ($hasActiveNote) {
                throw new FiscalNoteAlreadyIssuedException(
                    "Já existe uma nota fiscal ativa para o pedido {$order->id}."
                );
            }

            return FiscalNote::create([
                'company_id' => $companyId,
                'order_id' => $order->id,
                'status' => 'pending',
            ]);
        });
    }

    public function cancel(FiscalNote $note, string $justification): void
    {
        Log::channel('fiscal')->info('FiscalNoteService: cancelamento solicitado', [
            'fiscal_note_id' => $note->id,
            'order_id' => $note->order_id,
        ]);

        if (! $note->provider_reference) {
            throw new \RuntimeException('Nota fiscal sem referência do provider.');
        }

        $companyConfig = $note->company->fiscalConfig;
        $environment = $companyConfig?->environment ?: config('fiscal.environment');
        $effectiveEnvironment = $this->effectiveEnvironment($environment);

        $provider = $companyConfig
            ? $this->resolveProvider($companyConfig, $effectiveEnvironment)
            : $this->provider;

        $result = $provider->cancel($note->provider_reference, $justification);

        $data = $note->data ?? [];
        $data['cancelled_at'] = now()->toIso8601String();
        $data['cancel_raw_response'] = $result->rawResponse;

        $note->update([
            'status' => $result->status === 'cancelled' ? 'cancelled' : 'error',
            'data' => $data,
        ]);

        Log::channel('fiscal')->info('FiscalNoteService: resultado do cancelamento', [
            'fiscal_note_id' => $note->id,
            'status' => $note->status,
        ]);
    }

    /**
     * Empresa com token próprio (dual token da Focus NFe ou o legado provider_token)
     * emite pela conta dela; sem token próprio, cai no provider da plataforma (injetado).
     * Recebe o ambiente já efetivo (pós rebaixamento de segurança) para token e URL
     * nunca ficarem dessincronizados (token de produção batendo em URL de homologação).
     */
    private function resolveProvider(CompanyFiscalConfig $companyConfig, string $effectiveEnvironment): FiscalNoteProviderInterface
    {
        $token = $companyConfig->tokenFor($effectiveEnvironment);

        if (! $token) {
            return $this->provider;
        }

        return new FocusNfeService($token, $this->baseUrlFor($effectiveEnvironment));
    }

    /**
     * Só bate na API de produção da Focus NFe quando o app roda em produção —
     * qualquer outro APP_ENV sempre usa homologação, mesmo que a empresa esteja
     * configurada como "produção", para nunca emitir nota fiscal real fora de produção.
     */
    private function effectiveEnvironment(string $configuredEnvironment): string
    {
        return self::resolveEffectiveEnvironment($configuredEnvironment);
    }

    private function baseUrlFor(string $effectiveEnvironment): string
    {
        return self::resolveBaseUrl($effectiveEnvironment);
    }

    /**
     * Público/estático para ser reutilizado pelo webhook, que recebe callbacks
     * fora do fluxo normal de emissão e precisa da mesma regra host x ambiente
     * para montar URLs absolutas de DANFE/XML.
     */
    public static function resolveEffectiveEnvironment(string $configuredEnvironment): string
    {
        return (app()->isProduction() && $configuredEnvironment === 'producao') ? 'producao' : 'homologacao';
    }

    public static function resolveBaseUrl(string $effectiveEnvironment): string
    {
        return $effectiveEnvironment === 'producao'
            ? config('fiscal.focus_nfe.base_url_producao')
            : config('fiscal.focus_nfe.base_url_homologacao');
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
