<?php

namespace App\Services\Ifood;

use App\Contracts\IfoodGatewayContract;
use App\Models\IfoodIntegration;
use Illuminate\Http\Client\PendingRequest;
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
        $response = $this->client($integration)->get('/order/v1.0/events:polling');

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
     * Não existe endpoint pra "delivered": dispatch com deliveredBy=MERCHANT (entrega
     * própria do restaurante, único modo suportado aqui) já faz o iFood concluir o
     * pedido sozinho e gerar o evento CONCLUDED automaticamente — não fica nenhum
     * status local sem correspondente por falta de chamada. Confirmado via doc
     * pública do iFood (developer.ifood.com.br), não validado ainda em sandbox real.
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

    public function getSettlements(IfoodIntegration $integration, \Carbon\CarbonInterface $from, \Carbon\CarbonInterface $to): array
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
            ->acceptJson();
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

    private function logAndThrow(IfoodIntegration $integration, string $operation, \Illuminate\Http\Client\Response $response): never
    {
        Log::channel('ifood')->error("iFood: falha em {$operation}", [
            'ifood_integration_id' => $integration->id,
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        throw new RuntimeException("iFood: falha em {$operation} (integration_id={$integration->id}, status {$response->status()})");
    }
}
