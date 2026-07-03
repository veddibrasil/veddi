<?php

namespace App\Services\Portal;

use App\Contracts\PortalAvailabilityGatewayInterface;
use App\Contracts\PortalOrderGatewayInterface;
use App\DTOs\PortalOrderDTO;
use App\Models\Portal;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class IfoodService implements PortalAvailabilityGatewayInterface, PortalOrderGatewayInterface
{
    private const AUTH_BASE_URL = 'https://merchant-api.ifood.com.br/authentication/v1.0/oauth';

    private const ORDER_BASE_URL = 'https://merchant-api.ifood.com.br/order/v1.0';

    private const MERCHANT_BASE_URL = 'https://merchant-api.ifood.com.br/merchant/v1.0';

    public function __construct(private readonly ?Portal $portal = null) {}

    /**
     * Inicia o fluxo de autorização (aplicativo distribuído): gera o
     * código que o lojista deve inserir no Portal do Parceiro iFood.
     *
     * @return array{userCode: string, authorizationCodeVerifier: string, verificationUrlComplete: string, expiresIn: int}
     */
    public function requestUserCode(): array
    {
        $response = Http::asForm()->post(self::AUTH_BASE_URL.'/userCode', [
            'clientId' => config('services.ifood.client_id'),
        ]);

        if ($response->failed()) {
            throw new RuntimeException("iFood API error {$response->status()}: {$response->body()}");
        }

        return $response->json();
    }

    /**
     * Troca o código autorizado pelo lojista por um access/refresh token.
     *
     * @return array{accessToken: string, refreshToken: string, expiresIn: int}
     */
    public function exchangeAuthorizationCode(string $authorizationCode, string $authorizationCodeVerifier): array
    {
        $response = Http::asForm()->post(self::AUTH_BASE_URL.'/token', [
            'grantType' => 'authorization_code',
            'clientId' => config('services.ifood.client_id'),
            'clientSecret' => config('services.ifood.client_secret'),
            'authorizationCode' => $authorizationCode,
            'authorizationCodeVerifier' => $authorizationCodeVerifier,
        ]);

        if ($response->failed()) {
            throw new RuntimeException("iFood API error {$response->status()}: {$response->body()}");
        }

        return $response->json();
    }

    /**
     * Renova o access token do portal usando o refresh token armazenado.
     *
     * @return array{accessToken: string, refreshToken: string, expiresIn: int}
     */
    public function refreshAccessToken(): array
    {
        $response = Http::asForm()->post(self::AUTH_BASE_URL.'/token', [
            'grantType' => 'refresh_token',
            'clientId' => config('services.ifood.client_id'),
            'clientSecret' => config('services.ifood.client_secret'),
            'refreshToken' => $this->requirePortal()->credentials['refresh_token'] ?? null,
        ]);

        if ($response->failed()) {
            throw new RuntimeException("iFood API error {$response->status()}: {$response->body()}");
        }

        return $response->json();
    }

    public function fetchOrder(string $externalOrderId): PortalOrderDTO
    {
        $response = $this->http()->get("/orders/{$externalOrderId}");

        if ($response->failed()) {
            throw new RuntimeException("iFood API error {$response->status()}: {$response->body()}");
        }

        return $this->mapOrderPayload($response->json());
    }

    public function confirmOrder(string $externalOrderId): void
    {
        $this->postAction($externalOrderId, 'confirm');
    }

    public function rejectOrder(string $externalOrderId, string $reasonCode): void
    {
        // iFood não documenta endpoint de "recusa" separado do de cancelamento
        // no fluxo de aplicativo distribuído — reaproveita requestCancellation.
        $this->requestCancellation($externalOrderId, $reasonCode);
    }

    public function requestCancellation(string $externalOrderId, string $reasonCode): void
    {
        $response = $this->http()->post("/orders/{$externalOrderId}/requestCancellation", [
            'reason' => $reasonCode,
            'cancellationCode' => $reasonCode,
        ]);

        if ($response->failed()) {
            throw new RuntimeException("iFood API error {$response->status()}: {$response->body()}");
        }
    }

    /**
     * @param  string  $status  Nome da ação iFood: 'readyToPickup' ou 'dispatch'.
     */
    public function updateOrderStatus(string $externalOrderId, string $status): void
    {
        $this->postAction($externalOrderId, $status);
    }

    /**
     * ATENÇÃO: nomes de campo do payload de interrupção (start/end/description)
     * inferidos da doc pública — validar contra sandbox antes de produção.
     */
    public function pauseReceivingOrders(?int $minutes = null, ?string $reason = null): void
    {
        $portal = $this->requirePortal();
        $duration = $minutes ?? 60;

        $response = $this->merchantHttp()->post(
            "/merchants/{$portal->external_merchant_id}/interruptions",
            [
                'description' => $reason ?? 'Pausa temporária',
                'start' => now()->toIso8601String(),
                'end' => now()->addMinutes($duration)->toIso8601String(),
            ]
        );

        if ($response->failed()) {
            throw new RuntimeException("iFood API error {$response->status()}: {$response->body()}");
        }

        $portal->update([
            'active_interruption_id' => $response->json('id'),
            'paused_until' => now()->addMinutes($duration),
        ]);
    }

    public function resumeReceivingOrders(): void
    {
        $portal = $this->requirePortal();

        if (! $portal->active_interruption_id) {
            return;
        }

        $response = $this->merchantHttp()->delete(
            "/merchants/{$portal->external_merchant_id}/interruptions/{$portal->active_interruption_id}"
        );

        if ($response->failed()) {
            throw new RuntimeException("iFood API error {$response->status()}: {$response->body()}");
        }

        $portal->update(['active_interruption_id' => null, 'paused_until' => null]);
    }

    private function postAction(string $externalOrderId, string $action): void
    {
        $response = $this->http()->post("/orders/{$externalOrderId}/{$action}");

        if ($response->failed()) {
            throw new RuntimeException("iFood API error {$response->status()}: {$response->body()}");
        }
    }

    /**
     * ATENÇÃO: nomes de campo e formato monetário (assumido em centavos)
     * baseados na doc pública do Order API v2.0. Validar contra payload
     * real do sandbox iFood antes de operar em produção.
     */
    private function mapOrderPayload(array $payload): PortalOrderDTO
    {
        $items = collect($payload['items'] ?? [])->map(fn (array $item) => [
            'external_item_id' => (string) ($item['id'] ?? $item['uniqueId'] ?? ''),
            'name' => (string) ($item['name'] ?? ''),
            'quantity' => (int) ($item['quantity'] ?? 1),
            'unit_price' => (float) ($item['unitPrice']['value'] ?? $item['unitPrice'] ?? 0) / 100,
        ])->all();

        $total = $payload['total'] ?? [];
        $customer = $payload['customer'] ?? [];

        return new PortalOrderDTO(
            externalOrderId: (string) $payload['id'],
            externalMerchantId: (string) ($payload['merchant']['id'] ?? ''),
            orderType: strtoupper($payload['orderType'] ?? '') === 'TAKEOUT' ? 'pickup' : 'delivery',
            subtotal: (float) ($total['subTotal'] ?? 0) / 100,
            deliveryFee: (float) ($total['deliveryFee'] ?? 0) / 100,
            total: (float) ($total['orderAmount'] ?? 0) / 100,
            paymentMethod: 'external_portal',
            isPaid: (bool) ($payload['payments']['prepaid'] ?? true),
            items: $items,
            customerName: $customer['name'] ?? null,
            customerPhone: $customer['phone']['number'] ?? null,
        );
    }

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::baseUrl(self::ORDER_BASE_URL)
            ->withToken($this->accessToken())
            ->acceptJson();
    }

    private function merchantHttp(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::baseUrl(self::MERCHANT_BASE_URL)
            ->withToken($this->accessToken())
            ->acceptJson();
    }

    private function accessToken(): string
    {
        $portal = $this->requirePortal();
        $credentials = $portal->credentials ?? [];

        $expiresAt = isset($credentials['expires_at']) ? \Carbon\Carbon::parse($credentials['expires_at']) : null;

        if (! $expiresAt || $expiresAt->isPast()) {
            try {
                $token = $this->refreshAccessToken();
            } catch (RuntimeException $e) {
                Log::channel('discord')->critical('iFood: falha ao renovar access token — integração vai parar de receber/atualizar pedidos', [
                    'portal_id' => $portal->id,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }

            $portal->update([
                'credentials' => array_merge($credentials, [
                    'access_token' => $token['accessToken'],
                    'refresh_token' => $token['refreshToken'],
                    'expires_at' => now()->addSeconds((int) $token['expiresIn'])->toIso8601String(),
                ]),
            ]);

            Log::channel('webhook')->info('iFood: access token renovado', ['portal_id' => $portal->id]);

            return $token['accessToken'];
        }

        return $credentials['access_token'];
    }

    private function requirePortal(): Portal
    {
        return $this->portal ?? throw new RuntimeException('IfoodService: nenhum Portal associado à instância.');
    }
}
