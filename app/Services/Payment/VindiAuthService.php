<?php

namespace App\Services\Payment;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VindiAuthService
{
    private const ACCESS_TOKEN_KEY = 'vindi:access_token';

    private const REFRESH_TOKEN_KEY = 'vindi:refresh_token';

    private const DEFAULT_TTL_SECONDS = 86400; // 24h fallback when API doesn't return an expiration

    private string $baseUrl;

    public function __construct()
    {
        $sandbox = config('app.env') !== 'production';
        $this->baseUrl = $sandbox
            ? 'https://api.intermediador.sandbox.yapay.com.br/api'
            : 'https://api.intermediador.yapay.com.br/api';
    }

    public function getAccessToken(): string
    {
        $cached = Cache::get(self::ACCESS_TOKEN_KEY);

        if ($cached) {
            return $cached;
        }

        $refreshToken = Cache::get(self::REFRESH_TOKEN_KEY);

        $authorization = $refreshToken
            ? $this->refreshAccessToken($refreshToken)
            : $this->generateAccessToken();

        $this->storeAuthorization($authorization);

        return $authorization['access_token'];
    }

    private function generateAccessToken(): array
    {
        $response = Http::asForm()->post("{$this->baseUrl}/authorizations/access_token", [
            'code' => config('payments.vindi_authorization_code'),
            'consumer_key' => config('payments.vindi_consumer_key'),
            'consumer_secret' => config('payments.vindi_consumer_secret'),
            'type_response' => 'J',
        ]);

        $data = $response->json();
        $authorization = $data['data_response']['authorization'] ?? null;

        if (! $authorization || empty($authorization['access_token'])) {
            Log::channel('payments')->error('Vindi: falha ao gerar access_token', [
                'status' => $response->status(),
                'body' => $data ?? $response->body(),
            ]);

            throw new \RuntimeException('Vindi: resposta sem access_token: '.json_encode($data ?? $response->body()));
        }

        return $authorization;
    }

    private function refreshAccessToken(string $refreshToken): array
    {
        $response = Http::asForm()->post("{$this->baseUrl}/v1/authorizations/refresh", [
            'access_token' => Cache::get(self::ACCESS_TOKEN_KEY),
            'refresh_token' => $refreshToken,
            'type_response' => 'J',
        ]);

        $data = $response->json();
        $authorization = $data['data_response']['authorization'] ?? null;

        if (! $authorization || empty($authorization['access_token'])) {
            Log::channel('payments')->warning('Vindi: falha ao renovar access_token via refresh_token, tentando gerar novo', [
                'status' => $response->status(),
                'body' => $data ?? $response->body(),
            ]);

            return $this->generateAccessToken();
        }

        return $authorization;
    }

    private function storeAuthorization(array $authorization): void
    {
        $ttl = self::DEFAULT_TTL_SECONDS;

        if (! empty($authorization['access_token_expiration'])) {
            $expiresAt = \Illuminate\Support\Carbon::parse($authorization['access_token_expiration']);
            $ttl = max(60, now()->diffInSeconds($expiresAt, false));
        }

        Cache::put(self::ACCESS_TOKEN_KEY, $authorization['access_token'], $ttl);

        if (! empty($authorization['refresh_token'])) {
            $refreshTtl = self::DEFAULT_TTL_SECONDS * 7;

            if (! empty($authorization['refresh_token_expiration'])) {
                $refreshExpiresAt = \Illuminate\Support\Carbon::parse($authorization['refresh_token_expiration']);
                $refreshTtl = max(60, now()->diffInSeconds($refreshExpiresAt, false));
            }

            Cache::put(self::REFRESH_TOKEN_KEY, $authorization['refresh_token'], $refreshTtl);
        }
    }
}
