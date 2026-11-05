<?php

namespace App\Services\Keeta;

use App\Contracts\KeetaGatewayContract;
use App\Exceptions\KeetaApiException;
use App\Models\KeetaIntegration;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Vínculo de uma filial com uma loja da Keeta:
 *
 *  1. startAuthorization: link do portal da Keeta (com um state nosso) e integração em
 *     pending_authorization;
 *  2. o lojista autoriza e a Keeta redireciona pra KeetaAuthorizationCallbackController com
 *     authId + state; completeAuthorization lê as lojas DAQUELA autorização (merchantInfo);
 *  3. linkShop: o admin escolhe a loja da filial e o VEDDI registra o mapeamento
 *     (merchantOnboarding) com o webhook de pedidos e o endpoint de cardápio da filial.
 *
 * O token é do app (KeetaClient); aqui não há token por loja pra renovar.
 */
class KeetaAuthService
{
    public function __construct(private readonly KeetaGatewayContract $gateway) {}

    public function startAuthorization(KeetaIntegration $integration): string
    {
        $state = Str::random(40);
        $url = $this->gateway->authorizationUrl(route('admin.settings.keeta.callback'));

        $integration->forceFill([
            'authorization_state' => $state,
            'status' => $integration->isLinked() && $integration->status !== KeetaIntegration::STATUS_REVOKED
                ? $integration->status
                : KeetaIntegration::STATUS_PENDING_AUTHORIZATION,
        ])->save();

        Log::channel('keeta')->info('Keeta: link de autorização gerado', ['keeta_integration_id' => $integration->id, 'company_id' => $integration->company_id]);

        return $this->withState($url, $state);
    }

    /**
     * Volta do portal da Keeta. O state amarra o redirect à filial que pediu o link (e à
     * empresa logada): authId de outra sessão nunca entra aqui.
     */
    public function completeAuthorization(int $companyId, string $state, string $authId): KeetaIntegration
    {
        if ($state === '' || $authId === '' || strlen($authId) > 64) {
            throw new InvalidArgumentException('Autorização da Keeta incompleta. Gere o link de novo.');
        }

        $integration = KeetaIntegration::where('company_id', $companyId)
            ->where('authorization_state', $state)
            ->first();

        if (! $integration) {
            throw new InvalidArgumentException('Este retorno da Keeta não corresponde a nenhuma conexão em andamento. Gere o link de novo.');
        }

        $shops = $this->collectShops($authId);

        $integration->forceFill([
            'auth_id' => $authId,
            'available_shops' => $shops,
            'authorization_state' => null,
            'status' => $integration->isLinked() && $integration->status === KeetaIntegration::STATUS_ACTIVE
                ? $integration->status
                : KeetaIntegration::STATUS_PENDING_MAPPING,
        ])->save();

        Log::channel('keeta')->info('Keeta: autorização concluída', [
            'keeta_integration_id' => $integration->id,
            'company_id' => $integration->company_id,
            'shops' => count($shops),
        ]);

        return $integration;
    }

    /** Relê as lojas da autorização já recebida (o lojista pode ter incluído outra loja). */
    public function refreshShops(KeetaIntegration $integration): array
    {
        if (! $integration->auth_id) {
            throw new RuntimeException('Autorize o app VEDDI na Keeta antes de escolher a loja.');
        }

        $shops = $this->collectShops($integration->auth_id);
        $integration->forceFill(['available_shops' => $shops])->save();

        return $shops;
    }

    /**
     * Liga a loja escolhida à filial e registra o mapeamento na Keeta. A loja precisa estar na
     * lista da autorização desta integração; loja já ligada a outra filial (de qualquer empresa)
     * é recusada.
     */
    public function linkShop(KeetaIntegration $integration, string $keetaMerchantId): KeetaIntegration
    {
        $shop = collect($integration->available_shops ?? [])->firstWhere('id', $keetaMerchantId);
        if (! $shop) {
            throw new InvalidArgumentException('Esta loja não está na autorização recebida da Keeta. Autorize de novo incluindo a loja.');
        }

        $takenElsewhere = KeetaIntegration::withoutGlobalScopes()
            ->where('keeta_merchant_id', $keetaMerchantId)
            ->whereKeyNot($integration->id)
            ->exists();
        if ($takenElsewhere) {
            throw new RuntimeException('Esta loja da Keeta já está conectada a outra filial. Desconecte lá antes de ligar aqui.');
        }

        try {
            DB::transaction(function () use ($integration, $keetaMerchantId, $shop) {
                $integration->forceFill([
                    'keeta_merchant_id' => $keetaMerchantId,
                    'shop_name' => mb_substr((string) $shop['name'], 0, 255),
                    'shop_address' => isset($shop['address']) ? mb_substr((string) $shop['address'], 0, 255) : null,
                    'menu_api_key' => $integration->menu_api_key ?: Str::random(48),
                ])->save();
            });
        } catch (UniqueConstraintViolationException) {
            throw new RuntimeException('Esta loja da Keeta já está conectada a outra filial. Desconecte lá antes de ligar aqui.');
        }

        return $this->onboard($integration);
    }

    /**
     * merchantOnboarding com o webhook e o endpoint de cardápio atuais. Também serve pra
     * reaplicar o vínculo (troca de domínio, nova autorização depois de revogada).
     */
    public function onboard(KeetaIntegration $integration): KeetaIntegration
    {
        if (! $integration->isLinked()) {
            throw new RuntimeException('Escolha a loja da Keeta desta filial antes de ativar.');
        }

        try {
            $this->gateway->onboardMerchant($integration, route('webhook.keeta.orders'), $integration->menuUrl(), $integration->menu_api_key);
        } catch (Throwable $e) {
            $integration->forceFill(['status' => KeetaIntegration::STATUS_ERROR])->save();

            Log::channel('keeta')->error('Keeta: onboarding da loja falhou', [
                'keeta_integration_id' => $integration->id,
                'company_id' => $integration->company_id,
                'error' => KeetaApiException::forLog($e),
            ]);

            throw $e;
        }

        $integration->forceFill([
            'status' => $integration->status === KeetaIntegration::STATUS_PAUSED ? KeetaIntegration::STATUS_PAUSED : KeetaIntegration::STATUS_ACTIVE,
            'activated_at' => $integration->activated_at ?? now(),
            'webhook_status' => 'unknown',
            'alerts_sent' => null,
        ])->save();

        Log::channel('keeta')->info('Keeta: loja vinculada', [
            'keeta_integration_id' => $integration->id,
            'company_id' => $integration->company_id,
            'branch_id' => $integration->branch_id,
        ]);

        return $integration;
    }

    /**
     * Desliga a filial. A Keeta não tem desvínculo pela API (a autorização é revogada pelo
     * lojista no portal): fecha a loja lá, por garantia, e troca o merchant_ref pra que
     * qualquer evento do mapeamento antigo caia em "integração não encontrada".
     */
    public function disconnect(KeetaIntegration $integration): void
    {
        if ($integration->isLinked() && $integration->status === KeetaIntegration::STATUS_ACTIVE) {
            try {
                $this->gateway->merchantUpdate($integration, ['merchantStatus' => 'UNAVAILABLE']);
            } catch (Throwable $e) {
                Log::channel('keeta')->warning('Keeta: não foi possível fechar a loja ao desconectar', [
                    'keeta_integration_id' => $integration->id,
                    'error' => KeetaApiException::forLog($e),
                ]);
            }
        }

        $integration->forceFill([
            'merchant_ref' => KeetaIntegration::merchantRefFor($integration->company_id, $integration->branch_id),
            'keeta_merchant_id' => null,
            'shop_name' => null,
            'shop_address' => null,
            'auth_id' => null,
            'available_shops' => null,
            'authorization_state' => null,
            'menu_api_key' => null,
            'status' => KeetaIntegration::STATUS_DISCONNECTED,
            'activated_at' => null,
            'store_status' => null,
            'webhook_status' => 'unknown',
            'catalog_published_at' => null,
            'catalog_sync_status' => null,
            'catalog_sync_error' => null,
            'alerts_sent' => null,
        ])->save();

        Log::channel('keeta')->info('Keeta: integração desconectada', [
            'keeta_integration_id' => $integration->id,
            'company_id' => $integration->company_id,
            'user_id' => auth()->id(),
        ]);
    }

    /** @return array<int, array{id: string, name: string, address: ?string}> */
    private function collectShops(string $authId): array
    {
        $shops = [];
        $page = 1;

        do {
            $result = $this->gateway->authorizedShops($authId, $page);
            foreach ($result['shops'] as $shop) {
                $shops[$shop['id']] = ['id' => $shop['id'], 'name' => $shop['name'], 'address' => $shop['address']];
            }
            $page++;
        } while ($page <= min($result['total_pages'], 20));

        return array_values($shops);
    }

    /** Põe o nosso state no link (a Keeta devolve o state no redirect). */
    private function withState(string $url, string $state): string
    {
        $fragment = '';
        if (($hash = strpos($url, '#')) !== false) {
            $fragment = substr($url, $hash);
            $url = substr($url, 0, $hash);
        }

        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $query);
        $query['state'] = $state;

        $base = strtok($url, '?');

        return $base.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986).$fragment;
    }
}
