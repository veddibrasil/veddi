<?php

namespace App\Services\Messaging;

use App\Contracts\WhatsAppManagementInterface;
use App\Contracts\WhatsAppProviderInterface;
use App\DTOs\WhatsAppSender;
use App\Exceptions\WhatsAppPermanentException;

class MetaCloudApiProvider implements WhatsAppManagementInterface, WhatsAppProviderInterface
{
    /** Escopos cujos target_ids são WABAs (business_management também lista o portfólio, que não nos interessa). */
    private const WABA_SCOPES = ['whatsapp_business_management', 'whatsapp_business_messaging'];

    /** Limite de páginas na listagem de templates (100 por página) — protege de loop infinito. */
    private const MAX_TEMPLATE_PAGES = 20;

    public function __construct(private MetaGraphClient $client) {}

    public function sendTemplate(
        WhatsAppSender $sender,
        string $to,
        string $template,
        array $bodyParams,
        string $lang = 'pt_BR',
    ): string {
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'template',
            'template' => [
                'name' => $template,
                'language' => ['code' => $lang],
            ],
        ];

        if ($bodyParams !== []) {
            $payload['template']['components'] = [[
                'type' => 'body',
                'parameters' => array_map(
                    fn ($value) => ['type' => 'text', 'text' => $this->sanitizeParam((string) $value)],
                    array_values($bodyParams),
                ),
            ]];
        }

        $response = $this->client->request('post', "{$sender->phoneNumberId}/messages", $payload, $sender->accessToken);

        $wamid = $response['messages'][0]['id'] ?? null;

        if (! is_string($wamid) || $wamid === '') {
            // 2xx sem wamid: a Meta pode ter aceitado a mensagem. Não retenta para não duplicar.
            throw new WhatsAppPermanentException('Resposta da Graph API sem ID de mensagem (wamid).');
        }

        return $wamid;
    }

    // ── Gestão (onboarding e templates) ───────────────────────────────────────

    public function exchangeCode(string $code): string
    {
        $this->assertAppCredentials();

        // Sem token (ainda não existe) e, portanto, sem appsecret_proof.
        $response = $this->client->request('get', 'oauth/access_token', [
            'client_id' => config('services.meta.app_id'),
            'client_secret' => config('services.meta.app_secret'),
            'code' => $code,
        ]);

        $token = $response['access_token'] ?? null;

        if (! is_string($token) || $token === '') {
            throw new WhatsAppPermanentException('A Meta não devolveu o token de acesso na troca do code.');
        }

        return $token;
    }

    public function debugToken(string $token): array
    {
        $this->assertAppCredentials();

        // Autentica com o token do app (app_id|app_secret), que não usa appsecret_proof.
        $data = $this->client->request(
            'get',
            'debug_token',
            ['input_token' => $token],
            $this->client->appToken(),
            signed: false,
        )['data'] ?? [];

        $granular = collect((array) ($data['granular_scopes'] ?? []))
            ->filter(fn ($item) => is_array($item) && isset($item['scope']))
            ->map(fn (array $item) => [
                'scope' => (string) $item['scope'],
                'target_ids' => array_values(array_map('strval', (array) ($item['target_ids'] ?? []))),
            ])
            ->values();

        return [
            'is_valid' => (bool) ($data['is_valid'] ?? false),
            'app_id' => isset($data['app_id']) ? (string) $data['app_id'] : null,
            'expires_at' => (int) ($data['expires_at'] ?? 0),
            'scopes' => array_values(array_map('strval', (array) ($data['scopes'] ?? []))),
            'granular_scopes' => $granular->all(),
            'waba_ids' => $granular
                ->filter(fn (array $item) => in_array($item['scope'], self::WABA_SCOPES, true))
                ->flatMap(fn (array $item) => $item['target_ids'])
                ->unique()
                ->values()
                ->all(),
        ];
    }

    public function listPhoneNumbers(string $wabaId, string $token): array
    {
        $response = $this->client->request('get', "{$wabaId}/phone_numbers", [
            'fields' => 'id,display_phone_number,verified_name,quality_rating',
        ], $token);

        return collect((array) ($response['data'] ?? []))
            ->filter(fn ($item) => is_array($item) && filled($item['id'] ?? null))
            ->map(fn (array $item) => [
                'id' => (string) $item['id'],
                'display_phone_number' => $item['display_phone_number'] ?? null,
                'verified_name' => $item['verified_name'] ?? null,
                'quality_rating' => $item['quality_rating'] ?? null,
            ])
            ->values()
            ->all();
    }

    public function subscribeApp(string $wabaId, string $token): void
    {
        $response = $this->client->request('post', "{$wabaId}/subscribed_apps", [], $token);

        $this->assertSuccess($response, 'inscrever o app na WABA');
    }

    public function unsubscribeApp(string $wabaId, string $token): void
    {
        $response = $this->client->request('delete', "{$wabaId}/subscribed_apps", [], $token);

        $this->assertSuccess($response, 'remover a inscrição do app na WABA');
    }

    public function registerPhone(string $phoneNumberId, string $token, string $pin): void
    {
        $response = $this->client->request('post', "{$phoneNumberId}/register", [
            'messaging_product' => 'whatsapp',
            'pin' => $pin,
        ], $token);

        $this->assertSuccess($response, 'registrar o número');
    }

    public function getPhoneNumber(string $phoneNumberId, string $token): array
    {
        $response = $this->client->request('get', $phoneNumberId, [
            'fields' => 'verified_name,display_phone_number,quality_rating,messaging_limit_tier',
        ], $token);

        return [
            'verified_name' => $response['verified_name'] ?? null,
            'display_phone_number' => $response['display_phone_number'] ?? null,
            'quality_rating' => $response['quality_rating'] ?? null,
            'messaging_limit_tier' => $response['messaging_limit_tier'] ?? null,
        ];
    }

    public function createTemplate(string $wabaId, string $token, array $definition): array
    {
        $body = ['type' => 'BODY', 'text' => $definition['body']];

        // A Meta exige exemplos quando o corpo tem variáveis.
        if (! empty($definition['example'])) {
            $body['example'] = ['body_text' => [array_values(array_map('strval', $definition['example']))]];
        }

        $response = $this->client->request('post', "{$wabaId}/message_templates", [
            'name' => $definition['name'],
            'language' => $definition['language'],
            'category' => $definition['category'],
            'components' => [$body],
        ], $token);

        return [
            'id' => isset($response['id']) ? (string) $response['id'] : null,
            'status' => strtoupper((string) ($response['status'] ?? 'PENDING')),
            'category' => $response['category'] ?? null,
        ];
    }

    public function listTemplates(string $wabaId, string $token): array
    {
        $templates = [];
        $after = null;

        for ($page = 0; $page < self::MAX_TEMPLATE_PAGES; $page++) {
            $response = $this->client->request('get', "{$wabaId}/message_templates", array_filter([
                'fields' => 'id,name,language,status,category,rejected_reason',
                'limit' => 100,
                'after' => $after,
            ]), $token);

            foreach ((array) ($response['data'] ?? []) as $item) {
                if (! is_array($item) || blank($item['name'] ?? null)) {
                    continue;
                }

                $templates[] = [
                    'id' => (string) ($item['id'] ?? ''),
                    'name' => (string) $item['name'],
                    'language' => (string) ($item['language'] ?? ''),
                    'status' => strtoupper((string) ($item['status'] ?? '')),
                    'category' => $item['category'] ?? null,
                    'rejected_reason' => $item['rejected_reason'] ?? null,
                ];
            }

            $after = data_get($response, 'paging.cursors.after');

            if (! data_get($response, 'paging.next') || blank($after)) {
                break;
            }
        }

        return $templates;
    }

    private function assertAppCredentials(): void
    {
        if (blank(config('services.meta.app_id')) || blank(config('services.meta.app_secret'))) {
            throw new WhatsAppPermanentException('META_APP_ID e META_APP_SECRET não estão configurados.');
        }
    }

    /** @param  array<string, mixed>  $response */
    private function assertSuccess(array $response, string $action): void
    {
        if (($response['success'] ?? false) !== true) {
            throw new WhatsAppPermanentException("A Meta não confirmou a operação: {$action}.");
        }
    }

    /**
     * A Meta rejeita parâmetros de template com quebra de linha, tabulação ou mais de
     * 4 espaços seguidos (erro 132018), e também parâmetros vazios.
     */
    private function sanitizeParam(string $value): string
    {
        $value = preg_replace('/[\r\n\t]+/', ' ', $value) ?? '';
        $value = preg_replace('/ {2,}/', ' ', $value) ?? '';
        $value = trim($value);

        return $value === '' ? '-' : $value;
    }
}
