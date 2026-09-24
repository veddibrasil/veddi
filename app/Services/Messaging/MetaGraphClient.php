<?php

namespace App\Services\Messaging;

use App\Exceptions\WhatsAppApiException;
use App\Exceptions\WhatsAppAuthException;
use App\Exceptions\WhatsAppBillingException;
use App\Exceptions\WhatsAppPermanentException;
use App\Exceptions\WhatsAppRetryableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Único ponto de saída para a Graph API da Meta (mesmo padrão de AsaasService::request()).
 *
 * Regras:
 * - versão e credenciais vêm de config('services.meta.*'), nunca hardcoded;
 * - com token de restaurante, envia Bearer + appsecret_proof;
 * - nunca loga token, app secret, code, PIN nem o corpo das requisições.
 */
class MetaGraphClient
{
    private const BASE_URL = 'https://graph.facebook.com';

    /** Falhas transitórias: throughput, erro genérico, rate limit da WABA e do app. */
    private const RETRYABLE_CODES = [130429, 131000, 80007, 4];

    /** Token inválido/expirado (190) ou permissão negada (200, 10). */
    private const AUTH_CODES = [190, 200, 10];

    private const BILLING_CODES = [131042];

    /**
     * @param  array<string, mixed>  $data  Query string (GET) ou corpo JSON (demais métodos).
     * @param  string|null  $token  Token de acesso. Nulo = chamada sem Bearer (ex.: troca do code).
     * @param  bool  $signed  Envia appsecret_proof junto do token. Desligue para o token do app (debug_token).
     * @return array<string, mixed> Corpo JSON decodificado.
     *
     * @throws WhatsAppApiException
     */
    public function request(
        string $method,
        string $endpoint,
        array $data = [],
        #[\SensitiveParameter]
        ?string $token = null,
        int $timeout = 15,
        bool $signed = true,
    ): array {
        $method = strtolower($method);
        $url = $this->url($endpoint);

        $pending = Http::acceptJson()->timeout($timeout);

        if ($token !== null && $token !== '') {
            $pending = $pending->withToken($token);

            if ($signed && $proof = $this->appSecretProof($token)) {
                // GET: o Guzzle substitui a query da URL pelo array $data, então o proof entra
                // nele. Demais métodos: o corpo fica exclusivo do payload, o proof vai na query.
                if ($method === 'get') {
                    $data['appsecret_proof'] = $proof;
                } else {
                    $url .= '?'.http_build_query(['appsecret_proof' => $proof]);
                }
            }
        }

        try {
            $response = $method === 'get'
                ? $pending->get($url, $data)
                : $pending->{$method}($url, $data);
        } catch (ConnectionException $e) {
            // A mensagem do cURL traz a URL inteira, inclusive query com client_secret/code/proof
            // (troca do code é um GET). Redige antes de logar e não encadeia $e como previous,
            // senão o handler de exceções reportaria a mensagem original.
            $error = WhatsAppCriticalLog::scrub($e->getMessage());

            Log::channel('whatsapp')->warning('Graph API: falha de conexão', [
                'method' => strtoupper($method),
                'endpoint' => $endpoint,
                'error' => $error,
            ]);

            throw new WhatsAppRetryableException('Falha de conexão com a Graph API: '.$error);
        }

        if ($response->failed()) {
            throw $this->exceptionFor($method, $endpoint, $response);
        }

        Log::channel('whatsapp')->debug('Graph API: requisição concluída', [
            'method' => strtoupper($method),
            'endpoint' => $endpoint,
            'status' => $response->status(),
        ]);

        return $response->json() ?? [];
    }

    /** Token de app (app_id|app_secret), usado em /debug_token. */
    public function appToken(): string
    {
        return config('services.meta.app_id').'|'.config('services.meta.app_secret');
    }

    private function url(string $endpoint): string
    {
        $version = trim((string) config('services.meta.graph_version'), '/');

        return self::BASE_URL.'/'.$version.'/'.ltrim($endpoint, '/');
    }

    private function appSecretProof(string $token): ?string
    {
        $secret = config('services.meta.app_secret');

        if (blank($secret)) {
            return null;
        }

        return hash_hmac('sha256', $token, (string) $secret);
    }

    private function exceptionFor(string $method, string $endpoint, Response $response): WhatsAppApiException
    {
        $status = $response->status();
        $error = $response->json('error') ?? [];
        $code = (int) ($error['code'] ?? 0);
        $message = (string) ($error['message'] ?? 'Erro desconhecido da Graph API');

        // error_data.details costuma trazer o motivo real (ex.: "Parameter of type text is missing...").
        if ($details = data_get($error, 'error_data.details')) {
            $message .= ' — '.$details;
        }

        Log::channel('whatsapp')->warning('Graph API: erro na requisição', [
            'method' => strtoupper($method),
            'endpoint' => $endpoint,
            'http_status' => $status,
            'code' => $code,
            'subcode' => $error['error_subcode'] ?? null,
            'message' => $message,
            'fbtrace_id' => $error['fbtrace_id'] ?? null,
        ]);

        if (in_array($code, self::AUTH_CODES, true)) {
            return new WhatsAppAuthException($message, $code, $status);
        }

        if (in_array($code, self::BILLING_CODES, true)) {
            return new WhatsAppBillingException($message, $code, $status);
        }

        if (in_array($code, self::RETRYABLE_CODES, true) || $status >= 500 || $status === 429) {
            return new WhatsAppRetryableException($message, $code, $status);
        }

        if ($status === 401) {
            return new WhatsAppAuthException($message, $code, $status);
        }

        return new WhatsAppPermanentException($message, $code, $status);
    }
}
