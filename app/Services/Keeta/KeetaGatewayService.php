<?php

namespace App\Services\Keeta;

use App\Contracts\KeetaGatewayContract;
use App\Exceptions\KeetaApiException;
use App\Models\KeetaIntegration;
use stdClass;

/**
 * Endpoints da Keeta Open Delivery (v1.0.7+). Tudo passa pelo KeetaClient (token do app,
 * assinatura, log). Datas saem em UTC ISO 8601, como pede o Open Delivery.
 */
class KeetaGatewayService implements KeetaGatewayContract
{
    public function __construct(private readonly KeetaClient $client) {}

    public function authorizationUrl(?string $redirectUri = null): string
    {
        $this->client->ensureConfigured();

        $response = $this->client->request('GET', '/oauth/authorization/url', 'link de autorização', array_filter([
            'clientId' => (string) config('keeta.client_id'),
            'redirectUri' => $redirectUri,
        ], fn ($value) => $value !== null && $value !== ''));

        $url = is_array($response) ? ($response['merchantAuthorizationUrl'] ?? null) : null;
        if (! is_string($url) || ! str_starts_with($url, 'https://')) {
            throw new KeetaApiException('Keeta: resposta sem merchantAuthorizationUrl', 200, null, 'link de autorização');
        }

        return $url;
    }

    public function authorizedShops(string $authId, int $page = 1, int $pageSize = 50): array
    {
        $response = $this->client->request('GET', '/oauth/authorized/'.rawurlencode($authId).'/merchantInfo', 'lojas autorizadas', [
            'pageNum' => $page,
            'pageSize' => $pageSize,
        ]);
        $response = is_array($response) ? $response : [];

        $shops = collect($response['authorizedShops'] ?? [])
            ->filter(fn ($shop) => is_array($shop) && isset($shop['id']))
            ->map(fn (array $shop) => [
                'id' => (string) $shop['id'],
                'name' => (string) ($shop['name'] ?? 'Loja '.$shop['id']),
                'address' => isset($shop['address']) ? mb_substr((string) $shop['address'], 0, 255) : null,
                'latitude' => is_numeric($shop['latitude'] ?? null) ? (float) $shop['latitude'] : null,
                'longitude' => is_numeric($shop['longitude'] ?? null) ? (float) $shop['longitude'] : null,
                'timezone' => isset($shop['timeZone']) ? (string) $shop['timeZone'] : null,
            ])->values()->all();

        return [
            'brand_name' => isset($response['brandName']) ? (string) $response['brandName'] : null,
            'shops' => $shops,
            'total_pages' => max(1, (int) ($response['page']['totalPage'] ?? 1)),
        ];
    }

    public function onboardMerchant(KeetaIntegration $integration, string $ordersWebhookUrl, string $menuUrl, ?string $menuApiKey): array
    {
        $keetaMerchantId = (string) $integration->keeta_merchant_id;
        if (! ctype_digit($keetaMerchantId)) {
            throw new KeetaApiException('Keeta: keeta_merchant_id inválido para onboarding', 400, null, 'onboarding da loja');
        }

        $response = $this->client->request('PUT', '/v1/merchantOnboarding', 'onboarding da loja', ['merchantId' => $integration->merchant_ref], [
            // number no contrato: id int64 cabe em int do PHP 64 bits.
            'keetaMerchantId' => (int) $keetaMerchantId,
            'ordersWebhookURL' => $ordersWebhookUrl,
            'getMerchantURL' => array_filter(['baseURL' => $menuUrl, 'apiKey' => $menuApiKey], fn ($value) => $value !== null && $value !== ''),
        ], $integration);

        return is_array($response) ? $response : [];
    }

    public function merchantStatus(KeetaIntegration $integration): array
    {
        $response = $this->client->request('GET', '/v1/merchant/'.rawurlencode($integration->merchant_ref).'/status', 'status da loja', integration: $integration);

        return is_array($response) ? $response : [];
    }

    public function merchantUpdate(KeetaIntegration $integration, array $body = []): void
    {
        $this->client->request('POST', '/v1/merchantUpdate/'.rawurlencode($integration->merchant_ref), $body === [] ? 'recarregar cardápio' : 'atualizar loja', [], $body === [] ? new stdClass : $body, $integration);
    }

    public function pollEvents(array $merchantRefs): array
    {
        $merchantRefs = array_values(array_unique(array_filter($merchantRefs)));
        if ($merchantRefs === []) {
            return [];
        }

        $response = $this->client->request('GET', '/v1/events:polling', 'polling de eventos', headers: [
            'x-polling-merchants' => implode(',', array_slice($merchantRefs, 0, (int) config('keeta.polling_batch_size', 100))),
        ]);

        return is_array($response) ? array_values(array_filter($response, 'is_array')) : [];
    }

    public function acknowledgeEvents(array $events): void
    {
        if ($events === []) {
            return;
        }

        $this->client->request('POST', '/v1/events/acknowledgment', 'confirmar eventos', [], array_values($events));
    }

    public function getOrder(?KeetaIntegration $integration, string $orderId): array
    {
        $response = $this->client->request('GET', '/v1/orders/'.rawurlencode($orderId), 'detalhe do pedido', integration: $integration);

        if (! is_array($response) || ! isset($response['id'])) {
            throw new KeetaApiException('Keeta: detalhe do pedido sem id', 200, null, 'detalhe do pedido');
        }

        return $response;
    }

    public function confirmOrder(KeetaIntegration $integration, string $orderId, string $orderExternalCode, ?int $preparationMinutes = null, ?string $reason = null): void
    {
        $this->client->request('POST', $this->orderPath($orderId, 'confirm'), 'confirmar pedido', [], array_filter([
            'reason' => $reason,
            'createdAt' => $this->now(),
            'orderExternalCode' => $orderExternalCode,
            'preparationTime' => $preparationMinutes,
        ], fn ($value) => $value !== null), $integration);
    }

    public function readyForPickup(KeetaIntegration $integration, string $orderId): void
    {
        $this->client->request('POST', $this->orderPath($orderId, 'readyForPickup'), 'pedido pronto', integration: $integration);
    }

    public function dispatchOrder(KeetaIntegration $integration, string $orderId, ?array $deliveryTrackingInfo = null): void
    {
        $this->client->request('POST', $this->orderPath($orderId, 'dispatch'), 'despachar pedido', [], $deliveryTrackingInfo !== null ? ['deliveryTrackingInfo' => $deliveryTrackingInfo] : new stdClass, $integration);
    }

    public function markDelivered(KeetaIntegration $integration, string $orderId): void
    {
        $this->client->request('POST', $this->orderPath($orderId, 'delivered'), 'pedido entregue', integration: $integration);
    }

    public function requestCancellation(KeetaIntegration $integration, string $orderId, string $code, string $reason, string $mode = 'MANUAL', array $outOfStockItems = [], array $invalidItems = []): void
    {
        $this->client->request('POST', $this->orderPath($orderId, 'requestCancellation'), 'pedir cancelamento', [], array_filter([
            'reason' => mb_substr($reason, 0, 250),
            'code' => $code,
            'mode' => $mode,
            'outOfStockItems' => $outOfStockItems === [] ? null : array_values($outOfStockItems),
            'invalidItems' => $invalidItems === [] ? null : array_values($invalidItems),
        ], fn ($value) => $value !== null), $integration);
    }

    public function acceptRefund(KeetaIntegration $integration, string $orderId): void
    {
        $this->client->request('POST', $this->orderPath($orderId, 'acceptRefund'), 'aceitar reembolso', integration: $integration);
    }

    public function rejectRefund(KeetaIntegration $integration, string $orderId, string $code, string $reason): void
    {
        $this->client->request('POST', $this->orderPath($orderId, 'rejectRefund'), 'recusar reembolso', [], [
            'reason' => mb_substr($reason, 0, 250),
            'code' => $code,
        ], $integration);
    }

    public function batchDecrypt(KeetaIntegration $integration, array $cipherTexts): array
    {
        $cipherTexts = array_values(array_unique(array_filter($cipherTexts, fn ($text) => is_string($text) && str_starts_with($text, 'ENC_'))));
        $plain = [];

        foreach (array_chunk($cipherTexts, 50) as $chunk) {
            $response = $this->client->request('POST', '/v1/batchDecrypt', 'descriptografar dados', [], [
                'cipherInfos' => array_map(fn (string $text) => ['cipherText' => $text], $chunk),
            ], $integration);

            foreach ((is_array($response) ? ($response['plainInfos'] ?? []) : []) as $info) {
                if (is_array($info) && (int) ($info['errorCode'] ?? 0) === 0 && isset($info['cipherText'], $info['plainText'])) {
                    $plain[(string) $info['cipherText']] = (string) $info['plainText'];
                }
            }
        }

        return $plain;
    }

    private function orderPath(string $orderId, string $action): string
    {
        return '/v1/orders/'.rawurlencode($orderId).'/'.$action;
    }

    private function now(): string
    {
        return now()->utc()->format('Y-m-d\TH:i:s.v\Z');
    }
}
