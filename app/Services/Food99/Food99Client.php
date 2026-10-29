<?php

namespace App\Services\Food99;

use App\Exceptions\Food99ApiException;
use App\Exceptions\Food99RateLimitedException;
use App\Models\Food99Integration;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Transporte do 99Food Protocol: envelope {errno, errmsg, requestId, data}, ids int64 como
 * string (JSON_BIGINT_AS_STRING), limite de frequência por endpoint e log de toda chamada
 * no canal food99 com requestId (o suporte da 99 pede o requestId pra abrir chamado).
 *
 * Nunca loga parâmetros: levam auth_token e, nos endpoints de token, o app_secret.
 */
class Food99Client
{
    /**
     * Limites documentados por endpoint: [chamadas, janela em segundos]. Valem por loja nos
     * endpoints com token e pelo app nos assinados; o limitador segura a chamada antes de
     * gastar a cota da 99. As listagens paginadas de lojas (getAuthorizedShops 1/s,
     * shop/list 1/20s) ficam de fora: Food99AuthService espaça as páginas.
     */
    public const LIMITS = [
        '/v1/auth/authtoken/get' => [1, 30],
        '/v1/auth/authtoken/refresh' => [1, 30],
        '/v1/auth/authorizationpage/getUrl' => [100, 60],
        '/v3/auth/authorization/shopBind' => [1, 1],
        '/v1/shop/shop/unbind' => [1, 60],
        '/v1/shop/shop/detail' => [1, 60],
        '/v1/shop/shop/setconfirmmethod' => [1, 60],
        '/v1/shop/shop/setStatus' => [1, 60],
        '/v1/shop/apply/set' => [1, 60],
        '/v3/item/item/list' => [1, 60],
        '/v3/item/item/upload' => [1, 60],
        '/v1/item/item/getMenuTaskInfo' => [100, 60],
        '/v3/item/item/updateItem' => [10, 5],
        '/v3/item/item/updateItemStatus' => [10, 1],
        '/v3/item/item/updateModifierGroup' => [10, 5],
        '/v1/order/order/detail' => [10, 10],
        '/v1/order/order/confirm' => [5, 1],
        '/v1/order/order/ready' => [5, 1],
        '/v1/order/order/cancel' => [5, 1],
        '/v1/order/apply/cancel' => [5, 1],
        '/v1/order/apply/refund' => [5, 1],
        '/v1/order/order/payConfirm' => [5, 1],
    ];

    /** Aceite tem prazo de 5 min: timeout curto pra a tela não travar. */
    private const FAST_PATHS = ['/v1/order/order/confirm'];

    public function __construct(private readonly Food99Signer $signer) {}

    /**
     * Chamada a endpoint com auth_token (já incluso em $params) ou com app_id/app_secret.
     *
     * @return array{data: mixed, request_id: ?string}
     */
    public function request(string $method, string $path, array $params, string $operation, ?Food99Integration $integration = null, ?string $limiterScope = null, bool $throttle = true): array
    {
        if ($throttle) {
            $this->throttle($path, $limiterScope ?? ($integration ? 'i'.$integration->id : 'app'), $operation);
        }

        $started = microtime(true);
        $client = Http::baseUrl((string) config('food99.api_base_url'))
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(in_array($path, self::FAST_PATHS, true) ? 5 : 10)
            ->retry(2, 200, fn ($e) => $e instanceof ConnectionException, throw: false);

        try {
            $response = strtoupper($method) === 'GET' ? $client->get($path, $params) : $client->post($path, $params);
        } catch (ConnectionException $e) {
            $this->log('error', $operation, $integration, $started, ['erro' => 'conexão']);

            throw new Food99ApiException("99Food: falha de conexão em {$operation}", operation: $operation);
        }

        return $this->unwrap($response, $operation, $integration, $started);
    }

    /**
     * Endpoint de app assinado (lojas autorizadas, vínculo, lista de lojas): app_id, timestamp
     * e sign entram no corpo.
     *
     * @return array{data: mixed, request_id: ?string}
     */
    public function signed(string $path, array $params, string $operation): array
    {
        $params = array_merge($params, [
            'app_id' => (int) config('food99.app_id'),
            'timestamp' => now()->getTimestamp(),
        ]);
        $params['sign'] = $this->signer->appSign($params);

        return $this->request('POST', $path, $params, $operation, limiterScope: 'app');
    }

    /**
     * Credenciais do app pros endpoints de token (vão na query, por isso a URL nunca é logada).
     *
     * @return array{app_id: string, app_secret: string}
     */
    public function appCredentials(): array
    {
        return ['app_id' => (string) config('food99.app_id'), 'app_secret' => (string) config('food99.app_secret')];
    }

    /** @return array{data: mixed, request_id: ?string} */
    private function unwrap(Response $response, string $operation, ?Food99Integration $integration, float $started): array
    {
        $body = json_decode($response->body(), true, 512, JSON_BIGINT_AS_STRING);
        $requestId = is_array($body) ? $this->requestIdFrom($body) : null;

        if (! is_array($body) || ! array_key_exists('errno', $body)) {
            $this->log('error', $operation, $integration, $started, ['http_status' => $response->status(), 'request_id' => $requestId]);

            throw new Food99ApiException("99Food: resposta sem envelope em {$operation} (HTTP {$response->status()})", requestId: $requestId, operation: $operation);
        }

        $errno = (int) $body['errno'];
        $errmsg = isset($body['errmsg']) ? (string) $body['errmsg'] : null;

        if ($errno !== 0) {
            $this->log('warning', $operation, $integration, $started, ['errno' => $errno, 'errmsg' => $errmsg, 'request_id' => $requestId]);

            throw Food99ApiException::fromEnvelope($operation, $errno, $errmsg, $requestId, $integration?->id);
        }

        $this->log('info', $operation, $integration, $started, ['errno' => 0, 'request_id' => $requestId]);

        if ($integration && $requestId) {
            $integration->forceFill(['last_request_id' => $requestId])->saveQuietly();
        }

        return ['data' => $body['data'] ?? null, 'request_id' => $requestId];
    }

    private function requestIdFrom(array $body): ?string
    {
        $id = $body['requestId'] ?? $body['request_id'] ?? null;

        return $id === null ? null : (string) $id;
    }

    private function throttle(string $path, string $scope, string $operation): void
    {
        if (! isset(self::LIMITS[$path])) {
            return;
        }

        [$max, $window] = self::LIMITS[$path];
        $key = "food99:rl:{$scope}:{$path}";

        if (RateLimiter::tooManyAttempts($key, $max)) {
            $retryAfter = max(1, RateLimiter::availableIn($key));

            throw new Food99RateLimitedException(
                "99Food: limite local de {$operation} ({$max}/{$window}s), tente em {$retryAfter}s",
                errno: 10005,
                operation: $operation,
                retryAfter: $retryAfter,
            );
        }

        RateLimiter::hit($key, $window);
    }

    private function log(string $level, string $operation, ?Food99Integration $integration, float $started, array $context): void
    {
        Log::channel('food99')->{$level}("99Food: {$operation}", array_filter([
            'operation' => $operation,
            'food99_integration_id' => $integration?->id,
            'company_id' => $integration?->company_id,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ...$context,
        ], fn ($value) => $value !== null));
    }
}
