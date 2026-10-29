<?php

namespace App\Services\Food99;

use App\Contracts\Food99GatewayContract;
use App\Exceptions\Food99ApiException;
use App\Exceptions\Food99RateLimitedException;
use App\Exceptions\Food99ShopAlreadyLinkedException;
use App\Models\Food99Integration;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Token por loja e ciclo de conexão (autorização → escolha da loja → ativação → desconexão).
 *
 * Token: o auth_token fica gravado na própria integração (criptografado), nunca em cache
 * compartilhado. Renovação proativa pela margem (config food99.token_refresh_margin_seconds) e
 * reativa no errno 10102; a 99 limita get/refresh a 1 req/30s e refresh tem cooldown de 2 min.
 */
class Food99AuthService
{
    public const REFRESH_COOLDOWN_SECONDS = 120;

    /** Validade documentada da URL de autorização self-service. */
    public const AUTHORIZATION_URL_DAYS = 7;

    private const AUTHORIZATION_URL_CACHE_KEY = 'food99:authorization-url';

    public function __construct(private readonly Food99Client $client) {}

    public function getToken(Food99Integration $integration): string
    {
        if (! $integration->tokenNeedsRefresh()) {
            return $integration->auth_token;
        }

        return Cache::lock("food99:token:{$integration->id}", 30)->block(10, function () use ($integration) {
            // Outro worker pode ter renovado enquanto este esperava o lock.
            $integration->refresh();

            if (! $integration->tokenNeedsRefresh()) {
                return $integration->auth_token;
            }

            // Token que está pra vencer: pede um novo antes do get, senão o get devolve o mesmo.
            if ($integration->auth_token !== null) {
                $this->requestRefresh($integration);
            }

            return $this->fetchToken($integration);
        });
    }

    /** Renovação depois de errno 10102 numa chamada feita com $expiredToken. */
    public function renewExpiredToken(Food99Integration $integration, ?string $expiredToken): string
    {
        return Cache::lock("food99:token:{$integration->id}", 30)->block(10, function () use ($integration, $expiredToken) {
            $integration->refresh();

            if ($integration->auth_token !== null && $integration->auth_token !== $expiredToken && ! $integration->tokenNeedsRefresh(0)) {
                return $integration->auth_token;
            }

            $this->requestRefresh($integration);

            return $this->fetchToken($integration);
        });
    }

    /** Renovação agendada (RefreshFood99TokensJob): só quando vence dentro de $withinSeconds. */
    public function refreshIfExpiring(Food99Integration $integration, int $withinSeconds = 1800): bool
    {
        if (! $integration->tokenNeedsRefresh($withinSeconds)) {
            return false;
        }

        Cache::lock("food99:token:{$integration->id}", 30)->block(10, function () use ($integration, $withinSeconds) {
            $integration->refresh();

            if (! $integration->tokenNeedsRefresh($withinSeconds)) {
                return;
            }

            if ($integration->auth_token !== null) {
                $this->requestRefresh($integration);
            }

            $this->fetchToken($integration);
        });

        return true;
    }

    private function fetchToken(Food99Integration $integration): string
    {
        $data = $this->client->request('GET', '/v1/auth/authtoken/get', $this->client->appCredentials() + [
            'app_shop_id' => $integration->app_shop_id,
        ], 'authtoken.get', $integration, 'shop:'.$integration->app_shop_id)['data'];

        $token = is_array($data) ? ($data['auth_token'] ?? null) : null;

        if (! is_string($token) || $token === '') {
            throw new Food99ApiException("99Food: authtoken/get sem auth_token (integration_id={$integration->id})", 10100, operation: 'authtoken.get');
        }

        $expiresAt = isset($data['token_expiration_time']) && (int) $data['token_expiration_time'] > 0
            ? Carbon::createFromTimestamp((int) $data['token_expiration_time'], config('app.timezone'))
            : now()->addHour();

        $integration->forceFill([
            'auth_token' => $token,
            'token_expires_at' => $expiresAt,
            'last_token_refreshed_at' => now(),
        ])->save();

        return $token;
    }

    /** Pede à 99 um token novo (o get seguinte devolve ele). Respeita o cooldown de 2 min. */
    private function requestRefresh(Food99Integration $integration): void
    {
        $cooldown = "food99:token-refresh-cooldown:{$integration->id}";

        if (Cache::has($cooldown)) {
            return;
        }

        $this->client->request('GET', '/v1/auth/authtoken/refresh', $this->client->appCredentials() + [
            'app_shop_id' => $integration->app_shop_id,
        ], 'authtoken.refresh', $integration, 'shop-refresh:'.$integration->app_shop_id);

        Cache::put($cooldown, true, self::REFRESH_COOLDOWN_SECONDS);
    }

    /**
     * URL de autorização self-service do app (uma pra plataforma, vale 7 dias). O superadmin da
     * loja na 99 abre, escolhe a loja e autoriza o app VEDDI.
     */
    public function authorizationUrl(Food99Integration $integration, bool $force = false): string
    {
        if (! $force && $integration->isAuthorizationUrlValid()) {
            return $integration->authorization_url;
        }

        $cached = $force ? null : Cache::get(self::AUTHORIZATION_URL_CACHE_KEY);

        if (! is_array($cached)) {
            // [VALIDAR 99Food] doc lista só app_id; vai assinado (timestamp + sign) por garantia.
            $data = $this->client->signed('/v1/auth/authorizationpage/getUrl', [], 'authorizationpage.getUrl')['data'];
            $url = is_string($data) ? $data : ($data['url'] ?? $data['authorization_url'] ?? $data['auth_url'] ?? null);

            if (! is_string($url) || $url === '') {
                throw new Food99ApiException('99Food: getUrl sem URL de autorização', 0, operation: 'authorizationpage.getUrl');
            }

            $expiresAt = $this->expirationFrom(is_array($data) ? $data : []) ?? now()->addDays(self::AUTHORIZATION_URL_DAYS);
            $cached = ['url' => $url, 'expires_at' => $expiresAt->getTimestamp()];
            Cache::put(self::AUTHORIZATION_URL_CACHE_KEY, $cached, $expiresAt->copy()->subHour());
        }

        $integration->update([
            'authorization_url' => $cached['url'],
            'authorization_url_expires_at' => Carbon::createFromTimestamp($cached['expires_at'], config('app.timezone')),
            'status' => $integration->shop_id ? $integration->status : Food99Integration::STATUS_PENDING_AUTHORIZATION,
        ]);

        return $cached['url'];
    }

    /**
     * Lojas que autorizaram o app. A lista é do app inteiro (todas as empresas da plataforma),
     * então nunca vai pra tela do restaurante: a filial informa o ID da própria loja e
     * findAuthorizedShop() confere aqui. Lojas já ligadas a alguma integração ficam de fora.
     *
     * @return array<int, array{shop_id: string, shop_name: ?string, app_shop_id: ?string, bound: bool}>
     */
    public function authorizedShops(): array
    {
        try {
            $shops = $this->collectPages(fn (int $page) => app(Food99GatewayContract::class)->getAuthorizedShops($page));
        } catch (Food99RateLimitedException $e) {
            throw $e;
        } catch (Food99ApiException $e) {
            // getAuthorizedShops é de acesso restrito: sem liberação, cai na lista de vinculadas.
            Log::channel('food99')->info('99Food: getAuthorizedShops indisponível, usando shop/list', ['errno' => $e->errno]);
            $shops = $this->collectPages(fn (int $page) => app(Food99GatewayContract::class)->listBoundShops($page));
        }

        $linked = Food99Integration::withoutGlobalScopes()->whereNotNull('shop_id')->pluck('shop_id')->all();

        return array_values(array_filter($shops, fn (array $shop) => ! in_array($shop['shop_id'], $linked, true)));
    }

    public function findAuthorizedShop(string $shopId): ?array
    {
        return collect($this->authorizedShops())->firstWhere('shop_id', $shopId);
    }

    /**
     * Liga a loja (ID informado pelo restaurante) à filial e ativa. Se a 99 já vinculou a loja ao
     * app com um app_shop_id próprio (fluxo self-service), usa o dela; senão vincula com o nosso
     * pelo shopBind (só produção).
     *
     * @return array<int, string> avisos de configuração que não impediram a ativação
     */
    public function selectShop(Food99Integration $integration, string $shopId): array
    {
        $shopId = preg_replace('/\D/', '', $shopId);
        if ($shopId === '') {
            throw new RuntimeException('Informe o ID da loja na 99Food (só números).');
        }

        $shop = $this->findAuthorizedShop($shopId);
        if ($shop === null) {
            $this->ensureShopAvailable($integration, $shopId, null);

            throw new RuntimeException('Esta loja ainda não autorizou o app VEDDI na 99Food. Abra o link de autorização com o usuário superadmin da loja, escolha a loja certa e tente de novo.');
        }

        $appShopId = $shop['app_shop_id'] ?: Food99Integration::appShopIdFor($integration->company_id, $integration->branch_id);
        $this->ensureShopAvailable($integration, $shopId, $appShopId);

        if (! $shop['bound']) {
            // [VALIDAR 99Food] como o app_shop_id é definido no vínculo self-service.
            app(Food99GatewayContract::class)->bindShops([['shop_id' => Food99GatewayService::id($shopId), 'app_shop_id' => $appShopId]]);
        }

        $integration->forceFill([
            'shop_id' => $shopId,
            'shop_name' => $shop['shop_name'],
            'app_shop_id' => $appShopId,
            'available_shops' => null,
            'auth_token' => null,
            'token_expires_at' => null,
        ])->save();

        return $this->activate($integration);
    }

    /**
     * Pega o token e aplica a configuração da loja: quem confirma pedido (só API, por padrão),
     * recebimento de pedidos de cancelamento/reembolso e o nome/status atual.
     *
     * @return array<int, string>
     */
    public function activate(Food99Integration $integration): array
    {
        $gateway = app(Food99GatewayContract::class);

        try {
            $this->getToken($integration);
        } catch (Food99ApiException $e) {
            $integration->update(['status' => Food99Integration::STATUS_ERROR]);

            throw $e;
        }

        $warnings = [];
        $confirmMethod = (int) config('food99.order_confirm_method', 2);
        $steps = [
            'quem confirma os pedidos' => fn () => $gateway->setConfirmMethod($integration, $confirmMethod),
            'solicitações de cancelamento/reembolso' => fn () => $gateway->setApplyConfig($integration, (bool) $integration->receive_cancel_apply, (bool) $integration->receive_refund_apply),
            'dados da loja' => fn () => $this->syncShopDetail($integration),
        ];

        foreach ($steps as $label => $step) {
            try {
                $step();
            } catch (Food99ApiException $e) {
                $warnings[] = "Não foi possível configurar {$label} agora: ".$e->userMessage();
            }
        }

        $integration->update([
            'status' => Food99Integration::STATUS_ACTIVE,
            'order_confirm_method' => $confirmMethod,
            'authorization_url' => null,
            'authorization_url_expires_at' => null,
        ]);

        Log::channel('food99')->info('99Food: loja ativada', [
            'food99_integration_id' => $integration->id,
            'company_id' => $integration->company_id,
            'branch_id' => $integration->branch_id,
            'avisos' => count($warnings),
        ]);

        return $warnings;
    }

    /** Nome, status e disponibilidade atuais da loja (shop/detail). */
    public function syncShopDetail(Food99Integration $integration): array
    {
        $detail = app(Food99GatewayContract::class)->getShopDetail($integration);
        $shop = is_array($detail['shop_info'] ?? null) ? $detail['shop_info'] : $detail;

        $integration->forceFill(array_filter([
            'shop_name' => $shop['shop_name'] ?? $shop['name'] ?? null,
            'store_status' => isset($shop['biz_status']) ? (int) $shop['biz_status'] : null,
            'sub_biz_status' => isset($shop['sub_biz_status']) ? (int) $shop['sub_biz_status'] : null,
            'store_status_updated_at' => isset($shop['sub_biz_status']) || isset($shop['biz_status']) ? now() : null,
        ], fn ($value) => $value !== null))->save();

        return $detail;
    }

    /**
     * Desfaz o vínculo na 99 (unbind) e limpa token, loja e estado do cardápio. A falha no unbind
     * não impede a desconexão local: sem integração ativa nenhum callback é processado.
     */
    public function disconnect(Food99Integration $integration): void
    {
        if ($integration->shop_id !== null && $integration->auth_token !== null) {
            try {
                app(Food99GatewayContract::class)->unbindShop($integration);
            } catch (\Throwable $e) {
                Log::channel('food99')->warning('99Food: unbind falhou, desconectando só no VEDDI', [
                    'food99_integration_id' => $integration->id,
                    'erro' => $e instanceof Food99ApiException ? $e->errno : class_basename($e),
                ]);
            }
        }

        DB::table('branch_product')->where('branch_id', $integration->branch_id)->update([
            'food99_sync_status' => null,
            'food99_sync_error' => null,
            'food99_synced_at' => null,
        ]);

        $integration->forceFill([
            'shop_id' => null,
            'shop_name' => null,
            'app_shop_id' => Food99Integration::appShopIdFor($integration->company_id, $integration->branch_id),
            'available_shops' => null,
            'auth_token' => null,
            'token_expires_at' => null,
            'authorization_url' => null,
            'authorization_url_expires_at' => null,
            'status' => Food99Integration::STATUS_DISCONNECTED,
            'store_status' => null,
            'sub_biz_status' => null,
            'catalog_published_at' => null,
            'catalog_sync_status' => null,
            'catalog_sync_error' => null,
            'catalog_task_id' => null,
            'catalog_synced_at' => null,
            'alerts_sent' => null,
        ])->save();

        Log::channel('food99')->info('99Food: loja desconectada', [
            'food99_integration_id' => $integration->id,
            'company_id' => $integration->company_id,
        ]);
    }

    /**
     * Uma loja da 99 só pode estar ligada a uma integração (de qualquer empresa): com duas, o
     * callback entregaria pedidos de uma empresa na outra. Vale também pro app_shop_id.
     */
    public function ensureShopAvailable(Food99Integration $integration, string $shopId, ?string $appShopId): void
    {
        $conflict = Food99Integration::withoutGlobalScopes()
            ->where('id', '!=', $integration->id)
            ->where(fn ($q) => $q->where('shop_id', $shopId)->when($appShopId, fn ($q) => $q->orWhere('app_shop_id', $appShopId)))
            ->first();

        if (! $conflict) {
            return;
        }

        Log::channel('food99')->error('99Food: loja já conectada em outra integração, bloqueando ativação', [
            'food99_integration_id' => $integration->id,
            'company_id' => $integration->company_id,
            'conflicting_food99_integration_id' => $conflict->id,
            'conflicting_company_id' => $conflict->company_id,
        ]);

        throw new Food99ShopAlreadyLinkedException('Esta loja da 99Food já está conectada em outra filial ou empresa da plataforma. Desconecte a integração anterior antes de conectar aqui.');
    }

    /**
     * @param  callable(int): array  $fetch
     * @return array<int, array{shop_id: string, shop_name: ?string, app_shop_id: ?string, bound: bool}>
     */
    private function collectPages(callable $fetch): array
    {
        $shops = [];

        for ($page = 1; $page <= 20; $page++) {
            if ($page > 1) {
                usleep(1_100_000); // getAuthorizedShops: 1 req/s
            }

            $data = $fetch($page);
            $list = $data['list'] ?? $data['shop_list'] ?? $data['shops'] ?? (array_is_list($data) ? $data : []);

            foreach ($list as $shop) {
                if (is_array($shop) && ($normalized = $this->normalizeShop($shop))) {
                    $shops[$normalized['shop_id']] = $normalized;
                }
            }

            $total = (int) ($data['total'] ?? 0);
            if (count($list) < 50 || ($total > 0 && count($shops) >= $total)) {
                break;
            }
        }

        return array_values($shops);
    }

    /** @return array{shop_id: string, shop_name: ?string, app_shop_id: ?string, bound: bool}|null */
    private function normalizeShop(array $shop): ?array
    {
        $shopId = (string) ($shop['shop_id'] ?? $shop['shopId'] ?? '');
        if ($shopId === '') {
            return null;
        }

        $appShopId = $shop['app_shop_id'] ?? $shop['appShopId'] ?? null;
        $appShopId = is_scalar($appShopId) && (string) $appShopId !== '' ? (string) $appShopId : null;
        $boundFlag = $shop['bound_flag'] ?? $shop['bind_status'] ?? null;

        return [
            'shop_id' => $shopId,
            'shop_name' => isset($shop['shop_name']) ? (string) $shop['shop_name'] : (isset($shop['name']) ? (string) $shop['name'] : null),
            'app_shop_id' => $appShopId,
            'bound' => $appShopId !== null || in_array($boundFlag, [1, '1', true, 'bind'], true),
        ];
    }

    private function expirationFrom(array $data): ?CarbonInterface
    {
        $value = $data['expire_time'] ?? $data['expiration_time'] ?? $data['expired_time'] ?? null;

        return is_numeric($value) && (int) $value > 0 ? Carbon::createFromTimestamp((int) $value, config('app.timezone')) : null;
    }
}
