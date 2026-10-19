<?php

namespace App\Services\Chat;

use App\Contracts\ChatCodeSender;
use App\Models\Company;
use App\Services\Messaging\MetaGraphClient;
use App\Services\Messaging\WhatsAppService;
use RuntimeException;

class WhatsAppCodeSender implements ChatCodeSender
{
    public function send(int $companyId, string $phone, #[\SensitiveParameter] string $code): void
    {
        if (! config('chat_security.whatsapp_auth_enabled')) {
            throw new RuntimeException('Autenticação pelo WhatsApp ainda não habilitada.');
        }
        $company = Company::findOrFail($companyId);
        $sender = app(WhatsAppService::class)->resolveSender($company);
        // Do not add/remove the ninth digit for authentication: verify exactly the submitted phone.
        if (! $sender || ! preg_match('/^\d{10,11}$/', $phone)) {
            throw new RuntimeException('Remetente indisponível.');
        }
        $client = app(MetaGraphClient::class);
        $name = config('chat_security.whatsapp_auth_template');
        $language = config('chat_security.whatsapp_auth_language');
        // Validate category and approval remotely; notification templates cannot authenticate.
        // WABA comes from the active tenant connection, or explicit platform configuration.
        $wabaId = $sender->connectionId
            ? \App\Models\WhatsAppConnection::withoutGlobalScopes()->where('company_id', $companyId)->whereKey($sender->connectionId)->value('waba_id')
            : config('services.whatsapp.platform.waba_id');
        if (! $wabaId) {
            throw new RuntimeException('WABA de autenticação não configurada.');
        }
        $templates = $client->request('get', $wabaId.'/message_templates', [
            'name' => $name, 'fields' => 'name,language,status,category,components', 'limit' => 100,
        ], $sender->accessToken);
        $approved = collect($templates['data'] ?? [])->first(fn ($t) => ($t['name'] ?? '') === $name
            && ($t['language'] ?? '') === $language && ($t['status'] ?? '') === 'APPROVED'
            && ($t['category'] ?? '') === 'AUTHENTICATION');
        if (! $approved) {
            throw new RuntimeException('Template de autenticação ainda não aprovado.');
        }
        $response = $client->request('post', $sender->phoneNumberId.'/messages', [
            'messaging_product' => 'whatsapp', 'to' => '55'.$phone, 'type' => 'template',
            'template' => ['name' => $name, 'language' => ['code' => $language], 'components' => [
                ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $code]]],
                ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => $code]]],
            ]],
        ], $sender->accessToken, sensitiveResponse: true);
        if (empty($response['messages'][0]['id'])) {
            throw new RuntimeException('Envio não confirmado.');
        }
    }
}
