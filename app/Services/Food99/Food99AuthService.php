<?php

namespace App\Services\Food99;

use App\Contracts\Food99GatewayContract;
use App\Exceptions\Food99ApiException;
use App\Exceptions\Food99RateLimitedException;
use App\Exceptions\Food99ShopAlreadyLinkedException;
use App\Models\Food99Integration;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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

    /** Sem getAuthorizedShops liberado, nem tenta por um tempo: vai direto na lista de vinculadas. */
    private const BOUND_SHOPS_ONLY_KEY = 'food99:shops:bound-only';

    private const ERRNO_PERMISSION_DENIED = 10006;

    private const SHOP_ALREADY_LINKED_MESSAGE = 'Esta loja da 99Food já está conectada em outra filial ou empresa da plataforma. Desconecte a integração anterior antes de conectar aqui.';

    private const SHOP_PAGE_SIZE = 50;

    private const BOUND_SHOPS_PAGE_SIZE = 100;

    private const SHOP_MAX_PAGES = 20;

    private const SHOP_PAGE_CACHE_SECONDS = 60;

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
     * Lojas que autorizaram o app e ainda não estão ligadas a nenhuma integração. A lista é do
     * app inteiro (todas as empresas da plataforma), então nunca vai pra tela do restaurante: a
     * filial informa o ID da própria loja e connectableShop() confere.
     *
     * @return array<int, array{shop_id: string, shop_name: ?string, app_shop_id: ?string, bound: bool}>
     */
    public function authorizedShops(?int $exceptIntegrationId = null): array
    {
        $shops = [];
        foreach ($this->shopPages() as $page) {
            foreach ($page as $shop) {
                $shops[$shop['shop_id']] = $shop;
            }
        }

        $linked = Food99Integration::withoutGlobalScopes()
            ->whereNotNull('shop_id')
            ->when($exceptIntegrationId, fn ($query) => $query->where('id', '!=', $exceptIntegrationId))
            ->pluck('shop_id')
            ->all();

        return array_values(array_filter($shops, fn (array $shop) => ! in_array($shop['shop_id'], $linked, true)));
    }

    /** @return array{shop_id: string, shop_name: ?string, app_shop_id: ?string, bound: bool}|null */
    public function findAuthorizedShop(string $shopId): ?array
    {
        foreach ($this->shopPages() as $page) {
            foreach ($page as $shop) {
                if ($shop['shop_id'] === $shopId) {
                    return $shop;
                }
            }
        }

        return null;
    }

    /**
     * Confere se a loja informada pode ser ligada à integração: autorizou o app e não está em
     * outra filial ou empresa. A própria integração não conta, pra reconectar depois de a 99
     * desfazer o vínculo.
     *
     * @return array{shop_id: string, shop_name: ?string, app_shop_id: ?string, bound: bool}
     */
    public function connectableShop(Food99Integration $integration, string $shopId): array
    {
        $shopId = (string) preg_replace('/\D/', '', $shopId);
        if ($shopId === '') {
            throw new RuntimeException('Informe o ID da loja na 99Food (só números).');
        }

        $shop = $this->findAuthorizedShop($shopId);
        $this->ensureShopAvailable($integration, $shopId, $shop['app_shop_id'] ?? null);

        if ($shop === null) {
            throw new RuntimeException('Esta loja ainda não autorizou o app VEDDI na 99Food. Abra o link de autorização com o usuário superadmin da loja e escolha a loja certa. Se acabou de autorizar, aguarde um minuto e tente de novo.');
        }

        return $shop;
    }

    /**
     * Liga a loja (ID informado pelo restaurante) à filial e ativa. No vínculo self-service a 99
     * gera o app_shop_id e é ele que vale; sem vínculo, usa o nosso pelo shopBind (só produção).
     *
     * @return array<int, string> avisos de configuração que não impediram a ativação
     */
    public function selectShop(Food99Integration $integration, string $shopId): array
    {
        $shop = $this->connectableShop($integration, $shopId);
        $appShopId = $shop['app_shop_id'] ?: $this->ownAppShopId($integration);
        $this->ensureShopAvailable($integration, $shop['shop_id'], $appShopId);
        $this->releaseAppShopId($integration, $appShopId);

        if (! $shop['bound']) {
            $this->bindShop($shop['shop_id'], $appShopId);
        }

        try {
            $integration->forceFill([
                'shop_id' => $shop['shop_id'],
                'shop_name' => $shop['shop_name'],
                'app_shop_id' => $appShopId,
                'available_shops' => null,
                'auth_token' => null,
                'token_expires_at' => null,
            ])->save();
        } catch (UniqueConstraintViolationException) {
            // Duas filiais confirmando a mesma loja ao mesmo tempo: o índice único decide.
            throw new Food99ShopAlreadyLinkedException(self::SHOP_ALREADY_LINKED_MESSAGE);
        }

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
        } catch (Food99RateLimitedException $e) {
            // Limite de frequência não é problema da loja: basta repetir em instantes.
            throw $e;
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
            'activated_at' => now(),
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

    /** Nome, status, horários e disponibilidade atuais da loja (shop/detail). */
    public function syncShopDetail(Food99Integration $integration): array
    {
        $detail = app(Food99GatewayContract::class)->getShopDetail($integration);
        $shop = is_array($detail['shop_info'] ?? null) ? $detail['shop_info'] : $detail;

        $integration->forceFill(array_filter([
            'shop_name' => $shop['shop_name'] ?? $shop['name'] ?? null,
            'store_status' => isset($shop['biz_status']) ? (int) $shop['biz_status'] : null,
            'sub_biz_status' => isset($shop['sub_biz_status']) ? (int) $shop['sub_biz_status'] : null,
            'store_status_updated_at' => isset($shop['sub_biz_status']) || isset($shop['biz_status']) ? now() : null,
            'auto_switch' => isset($shop['auto_switch']) ? (int) $shop['auto_switch'] : null,
            'promise_produce_time' => isset($shop['promise_produce_time']) ? (int) $shop['promise_produce_time'] : null,
            'business_hours' => is_array($shop['biz_day_time'] ?? null) ? $shop['biz_day_time'] : null,
            'busy_mode' => is_array($shop['busy_mode_info'] ?? null) ? $this->busyModeFrom($shop['busy_mode_info'], $integration) : null,
        ], fn ($value) => $value !== null) + Food99Integration::availabilityAttributes($shop))->save();

        return $detail;
    }

    /** shop/detail não diz quando o modo ocupado acaba: mantém o fim calculado ao ativar. */
    private function busyModeFrom(array $info, Food99Integration $integration): array
    {
        $until = $integration->busy_mode['until'] ?? null;

        return (int) ($info['busy_state'] ?? 0) === 1 && $until > now()->getTimestamp() ? $info + ['until' => $until] : $info;
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
            'app_shop_id' => $this->ownAppShopId($integration),
            'available_shops' => null,
            'auth_token' => null,
            'token_expires_at' => null,
            'authorization_url' => null,
            'authorization_url_expires_at' => null,
            'status' => Food99Integration::STATUS_DISCONNECTED,
            'activated_at' => null,
            'store_status' => null,
            'sub_biz_status' => null,
            'auto_switch' => null,
            'promise_produce_time' => null,
            'business_hours' => null,
            'busy_mode' => null,
            'store_unavailable_reason' => null,
            'store_unavailable_until' => null,
            'catalog_published_at' => null,
            'catalog_sync_status' => null,
            'catalog_sync_error' => null,
            'catalog_queued_at' => null,
            'catalog_task_id' => null,
            'catalog_synced_at' => null,
            'alerts_sent' => null,
        ])->save();

        // A lista guardada ainda traz a loja como vinculada a esta filial.
        $this->forgetShopPages();

        Log::channel('food99')->info('99Food: loja desconectada', [
            'food99_integration_id' => $integration->id,
            'company_id' => $integration->company_id,
        ]);
    }

    /**
     * Uma loja da 99 só pode estar ligada a uma integração (de qualquer empresa): com duas, o
     * callback entregaria pedidos de uma empresa na outra. Vale também pro app_shop_id de quem
     * tem loja; linha sem loja não recebe nada e tem o id liberado em releaseAppShopId().
     */
    public function ensureShopAvailable(Food99Integration $integration, string $shopId, ?string $appShopId): void
    {
        $conflict = Food99Integration::withoutGlobalScopes()
            ->where('id', '!=', $integration->id)
            ->where(fn ($q) => $q->where('shop_id', $shopId)
                ->when($appShopId, fn ($q) => $q->orWhere(fn ($q) => $q->where('app_shop_id', $appShopId)->whereNotNull('shop_id'))))
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

        throw new Food99ShopAlreadyLinkedException(self::SHOP_ALREADY_LINKED_MESSAGE);
    }

    /** Id próprio da filial; se outra linha ficou com ele (releaseAppShopId), gera outro. */
    private function ownAppShopId(Food99Integration $integration): string
    {
        $appShopId = Food99Integration::appShopIdFor($integration->company_id, $integration->branch_id);
        $taken = Food99Integration::withoutGlobalScopes()
            ->where('id', '!=', $integration->id)
            ->where('app_shop_id', $appShopId)
            ->exists();

        return $taken ? $appShopId.'-'.Str::lower(Str::random(8)) : $appShopId;
    }

    /**
     * Desconexão com unbind que falhou deixa a linha antiga com o app_shop_id da loja. Como a
     * coluna é única, ela precisa soltar o id pra loja ser ligada em outra filial.
     */
    private function releaseAppShopId(Food99Integration $integration, string $appShopId): void
    {
        Food99Integration::withoutGlobalScopes()
            ->where('id', '!=', $integration->id)
            ->where('app_shop_id', $appShopId)
            ->whereNull('shop_id')
            ->get()
            ->each(fn (Food99Integration $free) => $free->forceFill(['app_shop_id' => "{$appShopId}-livre-{$free->id}"])->save());
    }

    /** shopBind responde errno 0 mesmo quando a loja cai na failure_list. */
    private function bindShop(string $shopId, string $appShopId): void
    {
        $result = app(Food99GatewayContract::class)->bindShops([['shop_id' => Food99GatewayService::id($shopId), 'app_shop_id' => $appShopId]]);

        if (! empty($result['failure_list'])) {
            Log::channel('food99')->warning('99Food: shopBind recusou a loja', ['falhas' => count($result['failure_list'])]);

            throw new RuntimeException('A 99Food não aceitou vincular esta loja. Confira se a loja autorizou o app e tente de novo.');
        }
    }

    /** A 99 avisou que uma loja autorizou ou saiu do app: a lista guardada ficou velha. */
    public function forgetShopPages(): void
    {
        foreach (range(1, self::SHOP_MAX_PAGES) as $page) {
            Cache::forget("food99:shops:page:{$page}");
        }
    }

    /**
     * Lojas do app página a página, pra busca de uma loja parar assim que achar. Cada página fica
     * um minuto em cache: shop/list só aceita 1 req/20s, então a busca interrompida pelo limite
     * continua de onde parou na próxima tentativa.
     *
     * @return \Generator<int, array<int, array{shop_id: string, shop_name: ?string, app_shop_id: ?string, bound: bool}>>
     */
    private function shopPages(): \Generator
    {
        $fetched = false;

        for ($page = 1; $page <= self::SHOP_MAX_PAGES; $page++) {
            $key = "food99:shops:page:{$page}";
            $result = Cache::get($key);

            if (! is_array($result)) {
                if ($fetched) {
                    usleep(1_100_000); // getAuthorizedShops: 1 req/s
                }

                $result = $this->fetchShopPage($page);
                $fetched = true;
                Cache::put($key, $result, self::SHOP_PAGE_CACHE_SECONDS);
            }

            yield $result['shops'];

            if ($result['last']) {
                return;
            }
        }
    }

    /** @return array{shops: array<int, array{shop_id: string, shop_name: ?string, app_shop_id: ?string, bound: bool}>, last: bool} */
    private function fetchShopPage(int $page): array
    {
        $gateway = app(Food99GatewayContract::class);
        $data = null;

        if (! Cache::has(self::BOUND_SHOPS_ONLY_KEY)) {
            try {
                $data = $gateway->getAuthorizedShops($page);
            } catch (Food99RateLimitedException $e) {
                throw $e;
            } catch (Food99ApiException $e) {
                // Falha no meio da paginação: a outra lista tem outro tamanho de página e pularia lojas.
                if ($page > 1 && $e->errno !== self::ERRNO_PERMISSION_DENIED) {
                    throw $e;
                }

                // getAuthorizedShops é de acesso restrito: sem liberação, cai na lista de vinculadas.
                Log::channel('food99')->info('99Food: getAuthorizedShops indisponível, usando shop/list', ['errno' => $e->errno]);

                if ($e->errno === self::ERRNO_PERMISSION_DENIED) {
                    Cache::put(self::BOUND_SHOPS_ONLY_KEY, true, now()->addHour());
                }
            }
        }

        $data ??= $gateway->listBoundShops($page, self::BOUND_SHOPS_PAGE_SIZE);
        $list = $data['shops'] ?? $data['list'] ?? $data['shop_list'] ?? (array_is_list($data) ? $data : []);
        $totalPages = (int) ($data['total_page'] ?? 0);

        return [
            'shops' => array_values(array_filter(array_map(fn ($shop) => is_array($shop) ? $this->normalizeShop($shop) : null, $list))),
            'last' => $totalPages > 0 ? $page >= $totalPages : count($list) < self::SHOP_PAGE_SIZE,
        ];
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
        $bound = $boundFlag === null ? $appShopId !== null : in_array($boundFlag, [1, '1', true, 'bind'], true);

        return [
            'shop_id' => $shopId,
            'shop_name' => isset($shop['shop_name']) ? (string) $shop['shop_name'] : (isset($shop['name']) ? (string) $shop['name'] : null),
            'app_shop_id' => $bound ? $appShopId : null,
            'bound' => $bound,
        ];
    }

    private function expirationFrom(array $data): ?CarbonInterface
    {
        $value = $data['expire_time'] ?? $data['expiration_time'] ?? $data['expired_time'] ?? null;

        return is_numeric($value) && (int) $value > 0 ? Carbon::createFromTimestamp((int) $value, config('app.timezone')) : null;
    }
}
