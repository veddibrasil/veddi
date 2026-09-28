<?php

namespace App\Services\Ifood;

use App\Contracts\IfoodGatewayContract;
use App\Models\IfoodIntegration;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Endpoints baseados na doc pública da Order API do iFood (integração direta).
 * Confirmar campo a campo / path a path contra o sandbox real antes de produção
 * (ver Fase 8 do plano de integração) — em especial confirmOrder/rejectOrder/
 * updateOrderStatus/requestCancellation, que dependem de fluxo de homologação
 * que ainda não foi validado.
 */
class IfoodGatewayService implements IfoodGatewayContract
{
    public function __construct(private readonly IfoodAuthService $auth) {}

    public function authenticate(IfoodIntegration $integration): string
    {
        return $this->auth->getAccessToken($integration);
    }

    public function refreshToken(IfoodIntegration $integration): string
    {
        return $this->auth->refreshToken($integration);
    }

    public function pollEvents(IfoodIntegration $integration): array
    {
        $response = $this->client($integration)
            ->withHeaders(['x-polling-merchants' => $integration->merchant_id])
            ->get('/order/v1.0/events:polling');

        if ($response->failed()) {
            $this->logAndThrow($integration, 'pollEvents', $response);
        }

        return $response->json() ?? [];
    }

    public function acknowledgeEvents(IfoodIntegration $integration, array $eventIds): void
    {
        if ($eventIds === []) {
            return;
        }

        $response = $this->client($integration)->post(
            '/order/v1.0/events/acknowledgment',
            array_map(fn (string $id) => ['id' => $id], $eventIds)
        );

        if ($response->failed()) {
            $this->logAndThrow($integration, 'acknowledgeEvents', $response);
        }

        Log::channel('ifood')->info('iFood: acknowledgement aceito pela API', [
            'ifood_integration_id' => $integration->id,
            'event_ids' => $eventIds,
            'http_status' => $response->status(),
        ]);
    }

    public function getOrderDetails(IfoodIntegration $integration, string $ifoodOrderId): array
    {
        $response = $this->client($integration)->get("/order/v1.0/orders/{$ifoodOrderId}");

        if ($response->failed()) {
            $this->logAndThrow($integration, 'getOrderDetails', $response);
        }

        return $response->json();
    }

    public function confirmOrder(IfoodIntegration $integration, string $ifoodOrderId): void
    {
        $response = $this->client($integration)->post("/order/v1.0/orders/{$ifoodOrderId}/confirm");

        if ($response->failed()) {
            $this->logAndThrow($integration, 'confirmOrder', $response);
        }

        Log::channel('ifood')->info('iFood: confirmação de pedido aceita pela API', [
            'ifood_integration_id' => $integration->id,
            'ifood_order_id' => $ifoodOrderId,
            'http_status' => $response->status(),
        ]);
    }

    public function rejectOrder(IfoodIntegration $integration, string $ifoodOrderId, string $reasonCode): void
    {
        $response = $this->client($integration)->post("/order/v1.0/orders/{$ifoodOrderId}/requestCancellation", [
            'reason' => $reasonCode,
            'cancellationCode' => $reasonCode,
        ]);

        if ($response->failed()) {
            $this->logAndThrow($integration, 'rejectOrder', $response);
        }
    }

    /**
     * Não existe endpoint pra "delivered": o iFood conclui o pedido sozinho e manda o
     * evento CONCLUDED. dispatch só vale pra entrega própria (deliveredBy=MERCHANT); a
     * entrega feita pelo iFood é despachada quando o entregador retira (quem decide é
     * IfoodOrderDetails::canDispatch). Confirmado na loja de teste em 21/09/2026: o
     * evento DISPATCHED volta com deliveredBy=MERCHANT e origem ORDER_API.
     */
    public function updateOrderStatus(IfoodIntegration $integration, string $ifoodOrderId, string $status): void
    {
        [$endpoint, $body] = match ($status) {
            'preparing' => ['startPreparation', []],
            'ready' => ['readyToPickup', []],
            'out_for_delivery' => ['dispatch', ['deliveredBy' => 'MERCHANT']],
            default => throw new RuntimeException("iFood: status interno '{$status}' não tem endpoint correspondente."),
        };

        $response = $this->client($integration)->post("/order/v1.0/orders/{$ifoodOrderId}/{$endpoint}", $body);

        if ($response->failed()) {
            $this->logAndThrow($integration, 'updateOrderStatus', $response);
        }

        Log::channel('ifood')->info("iFood: {$endpoint} aceito pela API", [
            'ifood_integration_id' => $integration->id,
            'ifood_order_id' => $ifoodOrderId,
            'http_status' => $response->status(),
        ]);
    }

    public function getCancellationReasons(IfoodIntegration $integration, string $ifoodOrderId): array
    {
        $response = $this->client($integration)->get("/order/v1.0/orders/{$ifoodOrderId}/cancellationReasons");
        if ($response->failed()) {
            $this->logAndThrow($integration, 'getCancellationReasons', $response);
        }
        $body = $response->json() ?? [];
        $reasons = $body['reasons'] ?? $body;
        Log::channel('ifood')->info('iFood: motivos de cancelamento consultados', [
            'ifood_order_id' => $ifoodOrderId,
            'http_status' => $response->status(),
            'reasons' => $reasons,
        ]);

        return array_map(fn ($reason) => [
            'code' => (string) ($reason['cancelCodeId'] ?? $reason['code'] ?? ''),
            'description' => (string) ($reason['description'] ?? ''),
        ], $reasons);
    }

    public function requestCancellation(IfoodIntegration $integration, string $ifoodOrderId, string $reasonCode): void
    {
        $response = $this->client($integration)->post("/order/v1.0/orders/{$ifoodOrderId}/requestCancellation", [
            'reason' => $reasonCode,
            'cancellationCode' => $reasonCode,
        ]);

        if ($response->failed()) {
            $this->logAndThrow($integration, 'requestCancellation', $response);
        }
        Log::channel('ifood')->info('iFood: solicitação de cancelamento aceita pela API', [
            'ifood_order_id' => $ifoodOrderId,
            'cancellation_code' => $reasonCode,
            'http_status' => $response->status(),
        ]);
    }

    public function acceptDispute(IfoodIntegration $integration, string $disputeId, ?string $reason = null): void
    {
        $this->disputeRequest($integration, 'acceptDispute', "/order/v1.0/disputes/{$disputeId}/accept", $reason ? ['reason' => $reason] : []);
    }

    public function rejectDispute(IfoodIntegration $integration, string $disputeId, string $reason): void
    {
        $this->disputeRequest($integration, 'rejectDispute', "/order/v1.0/disputes/{$disputeId}/reject", ['reason' => $reason]);
    }

    public function proposeDisputeAlternative(IfoodIntegration $integration, string $disputeId, string $alternativeId, array $body): void
    {
        $this->disputeRequest($integration, 'proposeDisputeAlternative', "/order/v1.0/disputes/{$disputeId}/alternatives/{$alternativeId}", $body);
    }

    private function disputeRequest(IfoodIntegration $integration, string $operation, string $path, array $body): void
    {
        $response = $this->client($integration)->post($path, $body);

        if ($response->failed()) {
            $this->logAndThrow($integration, $operation, $response);
        }

        Log::channel('ifood')->info("iFood: {$operation} aceito pela API", [
            'ifood_integration_id' => $integration->id,
            'path' => $path,
            'http_status' => $response->status(),
        ]);
    }

    public function listMerchants(IfoodIntegration $integration): array
    {
        $merchants = [];
        for ($page = 1; ; $page++) {
            $rows = $this->requestData($integration, 'get', '/merchant/v1.0/merchants', ['page' => $page, 'size' => 100]);
            $merchants = array_merge($merchants, $rows);
            if (count($rows) < 100) {
                return $merchants;
            }
        }
    }

    public function getMerchantDetails(IfoodIntegration $integration): array
    {
        return $this->requestData($integration, 'get', $this->merchantPath($integration));
    }

    public function getMerchantStatus(IfoodIntegration $integration): array
    {
        return $this->requestData($integration, 'get', $this->merchantPath($integration).'/status');
    }

    public function listInterruptions(IfoodIntegration $integration): array
    {
        return $this->requestData($integration, 'get', $this->merchantPath($integration).'/interruptions');
    }

    public function createInterruption(IfoodIntegration $integration, array $data): array
    {
        return $this->requestData($integration, 'post', $this->merchantPath($integration).'/interruptions', $data);
    }

    public function deleteInterruption(IfoodIntegration $integration, string $id): void
    {
        $this->requestData($integration, 'delete', $this->merchantPath($integration).'/interruptions/'.rawurlencode($id));
    }

    public function getOpeningHours(IfoodIntegration $integration): array
    {
        return $this->requestData($integration, 'get', $this->merchantPath($integration).'/opening-hours');
    }

    public function setOpeningHours(IfoodIntegration $integration, array $shifts): void
    {
        $this->requestData($integration, 'put', $this->merchantPath($integration).'/opening-hours', [
            'storeId' => $integration->merchant_id, 'shifts' => $shifts,
        ]);
    }

    public function getCatalogItem(IfoodIntegration $integration, string $id): ?array
    {
        $response = $this->client($integration)->get($this->catalogPath($integration).'/items/'.rawurlencode($id).'/flat');
        if ($response->status() === 404) {
            return null;
        }
        if ($response->failed()) {
            $this->logAndThrow($integration, 'getCatalogItem', $response);
        }
        $body = $response->json();
        if (! is_array($body) || ! isset($body['item']['id'], $body['item']['price'], $body['item']['status'])) {
            throw new RuntimeException('iFood: resposta de item incompleta; sincronização interrompida.');
        }

        return $body;
    }

    public function uploadCatalogImage(IfoodIntegration $integration, string $image): string
    {
        $body = $this->requestData($integration, 'post', $this->catalogPath($integration).'/image/upload', ['image' => $image]);

        if (! is_string($body['imagePath'] ?? null) || $body['imagePath'] === '') {
            throw new RuntimeException('iFood: upload sem imagePath.');
        }

        return $body['imagePath'];
    }

    // A API aceita um item por PATCH ({itemId, price}); o corpo em lote ({prices: [...]})
    // é recusado com "PatchItemPriceDto.itemId must be a UUID".
    public function updateItemPrices(IfoodIntegration $integration, array $prices): void
    {
        foreach ($prices as $price) {
            $this->catalogPatch($integration, 'items/price', ['itemId' => $price['itemId'], 'price' => ['value' => $price['price']]]);
        }
    }

    public function updateItemStatuses(IfoodIntegration $integration, array $items): void
    {
        foreach ($items as $item) {
            $this->catalogPatch($integration, 'items/status', ['itemId' => $item['id'], 'status' => $item['status']]);
        }
    }

    public function updateOptionPrice(IfoodIntegration $integration, string $id, array $price): void
    {
        $this->catalogPatch($integration, 'options/price', ['optionId' => $id, 'price' => $price]);
    }

    public function updateOptionStatus(IfoodIntegration $integration, string $id, string $status): void
    {
        $this->catalogPatch($integration, 'options/status', ['optionId' => $id, 'status' => $status]);
    }

    private function catalogPatch(IfoodIntegration $integration, string $endpoint, array $data): void
    {
        $result = $this->requestData($integration, 'patch', $this->catalogPath($integration).'/'.$endpoint, $data);
        if (isset($result['batchId'])) {
            // Não considerar a aceitação do lote como sucesso de todos os recursos.
            for ($attempt = 0; $attempt < 10; $attempt++) {
                $batch = $this->requestData($integration, 'get', $this->catalogPath($integration).'/batch/'.rawurlencode($result['batchId']));
                $status = $batch['batchStatus'] ?? $batch['status'] ?? null;
                if ($status === 'COMPLETED') {
                    if (empty($batch['results']) && ! isset($batch['successCount'], $batch['failureCount'])) {
                        throw new RuntimeException('iFood: lote sem resultados de processamento.');
                    }
                    if (($batch['failureCount'] ?? 0) > 0 || collect($batch['results'] ?? [])->contains(fn ($row) => ($row['result'] ?? null) !== 'SUCCESS')) {
                        throw new RuntimeException('iFood: lote concluído com falhas. Consulte o catálogo e tente novamente.');
                    }

                    return;
                }
                if (in_array($status, ['FAILED', 'CANCELLED'], true)) {
                    throw new RuntimeException('iFood: falha ao processar lote.');
                }
                usleep(500000);
            }
            throw new RuntimeException('iFood: lote ainda em processamento. Consulte o catálogo antes de tentar novamente.');
        }
    }

    private function merchantPath(IfoodIntegration $integration): string
    {
        return '/merchant/v1.0/merchants/'.rawurlencode($integration->merchant_id);
    }

    private function catalogPath(IfoodIntegration $integration): string
    {
        return '/catalog/v2.0/merchants/'.rawurlencode($integration->merchant_id);
    }

    private function requestData(IfoodIntegration $integration, string $method, string $path, array $data = []): array
    {
        $response = $this->client($integration)->{$method}($path, $data);
        if ($response->failed()) {
            $this->logAndThrow($integration, $method.' '.$path, $response);
        }
        if ($method === 'patch' && $response->status() === 202 && ! $response->json('batchId')) {
            throw new RuntimeException('iFood: atualização aceita sem identificador para acompanhamento.');
        }
        Log::channel('ifood')->info('iFood: operação aceita pela API', ['ifood_integration_id' => $integration->id, 'method' => strtoupper($method), 'path' => $path, 'http_status' => $response->status()]);

        return $response->json() ?? [];
    }

    public function createCategory(IfoodIntegration $integration, string $name): string
    {
        $catalogId = $this->resolveCatalogId($integration);

        $response = $this->client($integration)->post("/catalog/v2.0/merchants/{$integration->merchant_id}/catalogs/{$catalogId}/categories", [
            'name' => $name,
            'status' => 'AVAILABLE',
            'template' => 'DEFAULT',
            'sequence' => 0,
        ]);

        if ($response->status() === 409) {
            $conflictingId = $response->json('error.conflictingResources.0');

            if ($conflictingId) {
                Log::channel('ifood')->warning('iFood: categoria já existia no merchant, reaproveitando id do conflito', [
                    'ifood_integration_id' => $integration->id,
                    'name' => $name,
                    'ifood_category_id' => $conflictingId,
                ]);

                return $conflictingId;
            }
        }

        if ($response->failed()) {
            $this->logAndThrow($integration, 'createCategory', $response);
        }

        $categoryId = $response->json('id');

        if (! $categoryId) {
            Log::channel('ifood')->error('iFood: resposta de createCategory sem id', [
                'ifood_integration_id' => $integration->id,
                'body' => $response->json(),
            ]);

            throw new RuntimeException("iFood: resposta de createCategory sem id (integration_id={$integration->id})");
        }

        return $categoryId;
    }

    public function syncCatalog(IfoodIntegration $integration, array $itemPayload): void
    {
        $response = $this->client($integration)->put("/catalog/v2.0/merchants/{$integration->merchant_id}/items", $itemPayload);

        if ($response->failed()) {
            $this->logAndThrow($integration, 'syncCatalog', $response);
        }
    }

    public function getSettlements(IfoodIntegration $integration, CarbonInterface $from, CarbonInterface $to): array
    {
        // Endpoint/payload especulativo — Financial API do iFood não confirmada em
        // sandbox ainda. Ajustar path e formato de resposta antes de produção.
        $response = $this->client($integration)->get("/financial/v1.0/merchants/{$integration->merchant_id}/settlements", [
            'beginSettlementDate' => $from->toDateString(),
            'endSettlementDate' => $to->toDateString(),
        ]);

        if ($response->failed()) {
            $this->logAndThrow($integration, 'getSettlements', $response);
        }

        return $response->json() ?? [];
    }

    private function client(IfoodIntegration $integration): PendingRequest
    {
        return Http::baseUrl(config('ifood.api_base_url'))
            ->withToken($this->auth->getAccessToken($integration))
            ->acceptJson()->connectTimeout(10)->timeout(30);
    }

    /**
     * Confirmado contra sandbox real: toda loja já tem um catalogId por padrão,
     * obtido via GET /catalogs. Persistido em IfoodIntegration::catalog_id na
     * primeira chamada pra não buscar de novo depois.
     */
    private function resolveCatalogId(IfoodIntegration $integration): string
    {
        if ($integration->catalog_id) {
            return $integration->catalog_id;
        }

        $response = $this->client($integration)->get("/catalog/v2.0/merchants/{$integration->merchant_id}/catalogs");

        if ($response->failed()) {
            $this->logAndThrow($integration, 'resolveCatalogId', $response);
        }

        $catalogId = $response->json('0.catalogId');

        if (! $catalogId) {
            throw new RuntimeException("iFood: nenhum catalogId encontrado pro merchant (integration_id={$integration->id})");
        }

        $integration->update(['catalog_id' => $catalogId]);

        return $catalogId;
    }

    private function logAndThrow(IfoodIntegration $integration, string $operation, Response $response): never
    {
        Log::channel('ifood')->error("iFood: falha em {$operation}", [
            'ifood_integration_id' => $integration->id,
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        throw new RuntimeException("iFood: falha em {$operation} (integration_id={$integration->id}, status {$response->status()})");
    }
}
