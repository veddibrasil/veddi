<?php

namespace App\Services\Keeta;

use App\Contracts\KeetaGatewayContract;
use App\Exceptions\KeetaApiException;
use App\Models\CompanyNotification;
use App\Models\KeetaIntegration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Cardápio da filial na Keeta. A Keeta busca o cardápio no nosso GET merchant
 * (KeetaMerchantController); daqui só se avisa que mudou:
 *  - notifyMenuChanged: merchantUpdate vazio → a Keeta busca tudo de novo;
 *  - setItemsStatus: ITEM_OFFER com status, pra pausar/reativar produto sem recarregar o cardápio.
 *
 * Publicação é opt-in (catalog_published_at): sem ela o GET merchant responde 404 e nada é avisado.
 * Com o cardápio pela API, a edição manual no portal da Keeta fica bloqueada.
 */
class KeetaCatalogSyncService
{
    /** Cache curto do JSON servido à Keeta (ela pode buscar várias vezes seguidas). */
    private const PAYLOAD_TTL_SECONDS = 60;

    public function __construct(
        private readonly KeetaGatewayContract $gateway,
        private readonly KeetaCatalogBuilder $builder,
    ) {}

    /**
     * Merchant pronto pra Keeta, com cache por filial. Monta dentro da empresa da integração
     * (a rota é pública, sem tenant).
     */
    public function merchantPayload(KeetaIntegration $integration): array
    {
        return Cache::remember($this->cacheKey($integration), self::PAYLOAD_TTL_SECONDS, function () use ($integration) {
            return $this->withinCompany($integration, function () use ($integration) {
                $result = $this->builder->build($integration);
                $this->recordIssues($integration, $result['issues'], $result['products']);

                return $result['merchant'];
            });
        });
    }

    /** Prévia (tela da integração): o mesmo cardápio que a Keeta recebe, com as pendências. */
    public function preview(KeetaIntegration $integration): array
    {
        return $this->withinCompany($integration, fn () => $this->builder->build($integration));
    }

    /** Avisa a Keeta que o cardápio mudou (ela busca o GET merchant de novo). */
    public function notifyMenuChanged(KeetaIntegration $integration): void
    {
        if (! $integration->isOperational() || ! $integration->hasPublishedCatalog()) {
            throw new RuntimeException('Publique o cardápio e mantenha a integração ativa antes de enviar.');
        }

        Cache::forget($this->cacheKey($integration));
        $integration->forceFill(['catalog_sync_status' => 'running', 'catalog_sync_error' => null])->save();

        try {
            $this->gateway->merchantUpdate($integration);
        } catch (Throwable $e) {
            $integration->forceFill([
                'catalog_sync_status' => 'failed',
                'catalog_sync_error' => mb_substr(KeetaApiException::messageFor($e), 0, 500),
            ])->save();

            $this->alertCatalogFailed($integration);

            throw $e;
        }

        $integration->forceFill(['catalog_sync_status' => 'synced', 'catalog_synced_at' => now()])->save();

        Log::channel('keeta')->info('Keeta: cardápio atualizado', ['keeta_integration_id' => $integration->id]);
    }

    /**
     * Pausa/reativa produtos sem recarregar o cardápio (ITEM_OFFER).
     *
     * @param  array<int, int>  $productIds
     */
    public function setItemsStatus(KeetaIntegration $integration, array $productIds, bool $available): void
    {
        if ($productIds === [] || ! $integration->isOperational() || ! $integration->hasPublishedCatalog()) {
            return;
        }

        Cache::forget($this->cacheKey($integration));

        foreach (array_chunk(array_values(array_unique($productIds)), 50) as $chunk) {
            $this->gateway->merchantUpdate($integration, [
                'entityType' => 'ITEM_OFFER',
                'updatedObjects' => array_map(fn (int $productId) => [
                    'id' => 'veddi-po'.$productId,
                    'status' => $available ? 'AVAILABLE' : 'UNAVAILABLE',
                ], $chunk),
            ]);
        }
    }

    public function forget(KeetaIntegration $integration): void
    {
        Cache::forget($this->cacheKey($integration));
    }

    /** Resultado por produto na filial (aparece no cadastro do produto). */
    private function recordIssues(KeetaIntegration $integration, array $issues, array $included): void
    {
        $now = now();

        if ($included !== []) {
            DB::table('branch_product')->where('branch_id', $integration->branch_id)->whereIn('product_id', $included)
                ->update(['keeta_sync_status' => 'synced', 'keeta_sync_error' => null, 'keeta_synced_at' => $now]);
        }

        foreach ($issues as $productId => $message) {
            DB::table('branch_product')->where('branch_id', $integration->branch_id)->where('product_id', $productId)
                ->update(['keeta_sync_status' => 'failed', 'keeta_sync_error' => mb_substr($message, 0, 255), 'keeta_synced_at' => $now]);
        }
    }

    private function alertCatalogFailed(KeetaIntegration $integration): void
    {
        CompanyNotification::create([
            'company_id' => $integration->company_id,
            'type' => 'keeta_alert',
            'title' => 'Keeta: cardápio não atualizado',
            'subtitle' => 'A Keeta não aceitou o aviso de cardápio novo. Tente de novo na tela da integração.',
            'link' => route('admin.settings.keeta', ['aba' => 'cardapio']),
        ]);
    }

    private function withinCompany(KeetaIntegration $integration, callable $callback): mixed
    {
        $previous = app()->bound('current.company') ? app('current.company') : null;
        app()->instance('current.company', $integration->company);

        try {
            return $callback();
        } finally {
            $previous ? app()->instance('current.company', $previous) : app()->forgetInstance('current.company');
        }
    }

    private function cacheKey(KeetaIntegration $integration): string
    {
        return "keeta:merchant:{$integration->id}";
    }
}
