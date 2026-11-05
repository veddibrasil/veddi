<?php

namespace App\Services\Keeta;

use App\Exceptions\KeetaApiException;
use App\Exceptions\KeetaRateLimitedException;
use App\Models\KeetaIntegration;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Transporte da Keeta Open Delivery: token do app (grant app_level_token, um só para todas as
 * lojas autorizadas, guardado criptografado no cache), assinatura X-App-Signature em toda
 * chamada, ids int64 como string (JSON_BIGINT_AS_STRING) e log de toda chamada no canal keeta.
 *
 * Nunca loga corpo, query, token nem client_secret: o corpo leva dados do cliente e a chamada
 * de token leva o segredo.
 */
class KeetaClient
{
    private const TOKEN_CACHE_KEY = 'keeta:app_token';

    private const TOKEN_LOCK_KEY = 'keeta:app_token:lock';

    /** Aceite tem prazo de 5 min: timeout curto pra a tela não travar. */
    private const FAST_OPERATIONS = ['confirmar pedido'];

    public function __construct(private readonly KeetaSigner $signer) {}

    /**
     * Chamada autenticada. 401 renova o token uma vez e repete (o token do app pode ter sido
     * trocado por outro processo ou expirado antes da hora).
     *
     * @param  array<string, scalar|null>  $query
     * @param  array<mixed>|\stdClass|null  $body  null = sem corpo
     * @param  array<string, string>  $headers
     */
    public function request(string $method, string $path, string $operation, array $query = [], array|\stdClass|null $body = null, ?KeetaIntegration $integration = null, array $headers = []): mixed
    {
        try {
            return $this->send($method, $path, $operation, $query, $body, $integration, $headers, $this->accessToken());
        } catch (KeetaApiException $e) {
            if ($e->httpStatus !== 401) {
                throw $e;
            }

            return $this->send($method, $path, $operation, $query, $body, $integration, $headers, $this->accessToken(force: true));
        }
    }

    /**
     * Token do app. Renova sob lock quando falta menos que a margem; quem chega durante a
     * renovação espera e lê o token novo do cache.
     */
    public function accessToken(bool $force = false): string
    {
        if (! $force && ($token = $this->cachedToken()) !== null) {
            return $token;
        }

        return Cache::lock(self::TOKEN_LOCK_KEY, 15)->block(10, function () use ($force) {
            if (! $force && ($token = $this->cachedToken()) !== null) {
                return $token;
            }

            $this->ensureConfigured();

            $response = $this->send('POST', '/oauth/token', 'obter token', [], [
                'client_id' => (string) config('keeta.client_id'),
                'client_secret' => (string) config('keeta.client_secret'),
                'grant_type' => 'app_level_token',
            ], null, [], null);

            $token = is_array($response) ? ($response['access_token'] ?? null) : null;
            if (! is_string($token) || $token === '') {
                throw new KeetaApiException('Keeta: resposta de token sem access_token', 200, null, 'obter token');
            }

            $expiresIn = max(60, (int) ($response['expires_in'] ?? 3600));
            $ttl = max(30, $expiresIn - (int) config('keeta.token_refresh_margin_seconds', 300));
            Cache::put(self::TOKEN_CACHE_KEY, Crypt::encryptString($token), $ttl);

            return $token;
        });
    }

    public function forgetToken(): void
    {
        Cache::forget(self::TOKEN_CACHE_KEY);
    }

    /** Sem o app criado no Dev Portal da Keeta, nenhuma chamada tem como funcionar. */
    public function ensureConfigured(): void
    {
        if (blank(config('keeta.client_id')) || blank(config('keeta.client_secret'))) {
            throw new RuntimeException('A Keeta ainda não foi configurada na plataforma. Fale com o suporte do VEDDI.');
        }
    }

    public function url(string $path): string
    {
        return config('keeta.api_base_url').'/'.ltrim($path, '/');
    }

    private function cachedToken(): ?string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);

        if (! is_string($cached) || $cached === '') {
            return null;
        }

        try {
            return Crypt::decryptString($cached);
        } catch (Throwable) {
            Cache::forget(self::TOKEN_CACHE_KEY);

            return null;
        }
    }

    private function send(string $method, string $path, string $operation, array $query, array|\stdClass|null $body, ?KeetaIntegration $integration, array $headers, ?string $token): mixed
    {
        $url = $this->url($path);
        $json = $body === null ? '' : $this->signer->canonicalize($body);
        $started = microtime(true);

        $client = Http::acceptJson()
            ->connectTimeout(5)
            ->timeout(in_array($operation, self::FAST_OPERATIONS, true) ? 8 : 15)
            ->withHeaders(array_merge($headers, ['X-App-Signature' => $this->signer->sign($url, $query, $json)]))
            ->retry(2, 200, fn ($e) => $e instanceof ConnectionException, throw: false);

        if ($token !== null) {
            $client = $client->withToken($token);
        }
        if ($json !== '') {
            // Envia exatamente o JSON canônico que foi assinado.
            $client = $client->withBody($json, 'application/json');
        }

        $target = $query === [] ? $url : $url.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        try {
            $response = $client->send(strtoupper($method), $target);
        } catch (ConnectionException) {
            $this->log('error', $operation, $integration, $started, ['erro' => 'conexão']);

            throw new KeetaApiException("Keeta: falha de conexão em {$operation}", operation: $operation);
        }

        return $this->unwrap($response, $operation, $integration, $started);
    }

    private function unwrap(Response $response, string $operation, ?KeetaIntegration $integration, float $started): mixed
    {
        $status = $response->status();
        $raw = $response->body();
        $decoded = $raw === '' ? null : json_decode($raw, true, 512, JSON_BIGINT_AS_STRING);

        if ($status === 429) {
            $retryAfter = max(1, (int) ($response->header('Retry-After') ?: 30));
            $this->log('warning', $operation, $integration, $started, ['http_status' => $status]);

            throw new KeetaRateLimitedException("Keeta: limite de frequência em {$operation}", 429, null, $operation, $retryAfter);
        }

        if ($status < 200 || $status >= 300) {
            $title = is_array($decoded) ? ($decoded['title'] ?? $decoded['message'] ?? $decoded['error_description'] ?? $decoded['error'] ?? null) : null;
            $this->log($status >= 500 ? 'error' : 'warning', $operation, $integration, $started, ['http_status' => $status]);

            throw KeetaApiException::fromResponse($operation, $status, is_scalar($title) ? (string) $title : null, $integration?->id);
        }

        $this->log('info', $operation, $integration, $started, ['http_status' => $status]);

        return $decoded;
    }

    private function log(string $level, string $operation, ?KeetaIntegration $integration, float $started, array $context): void
    {
        Log::channel('keeta')->{$level}("Keeta: {$operation}", array_filter([
            'operation' => $operation,
            'keeta_integration_id' => $integration?->id,
            'company_id' => $integration?->company_id,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ...$context,
        ], fn ($value) => $value !== null));
    }
}
