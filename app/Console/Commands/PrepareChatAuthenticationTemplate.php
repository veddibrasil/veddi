<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\WhatsAppConnection;
use App\Services\Messaging\MetaGraphClient;
use App\Services\Messaging\WhatsAppService;
use Illuminate\Console\Command;

class PrepareChatAuthenticationTemplate extends Command
{
    protected $signature = 'chat:whatsapp-auth-template {company : ID da empresa} {--submit : Enviar template para aprovação na Meta}';

    protected $description = 'Prepara template WhatsApp AUTHENTICATION/COPY_CODE; sem --submit apenas exibe a definição';

    public function handle(MetaGraphClient $client, WhatsAppService $whatsapp): int
    {
        $definition = [
            'name' => config('chat_security.whatsapp_auth_template'),
            'language' => config('chat_security.whatsapp_auth_language'),
            'category' => 'AUTHENTICATION',
            'message_send_ttl_seconds' => 300,
            'components' => [
                ['type' => 'BODY', 'add_security_recommendation' => true],
                ['type' => 'FOOTER', 'code_expiration_minutes' => 5],
                ['type' => 'BUTTONS', 'buttons' => [['type' => 'OTP', 'otp_type' => 'COPY_CODE', 'text' => 'Copiar código']]],
            ],
        ];
        if (! $this->option('submit')) {
            $this->line(json_encode($definition, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }
        $company = Company::findOrFail($this->argument('company'));
        $sender = $whatsapp->resolveSender($company);
        $wabaId = $sender?->connectionId
            ? WhatsAppConnection::withoutGlobalScopes()->where('company_id', $company->id)->whereKey($sender->connectionId)->value('waba_id')
            : config('services.whatsapp.platform.waba_id');
        if (! $sender || ! $wabaId) {
            $this->error('Configure uma conexão ativa ou o remetente da plataforma com WABA.');

            return self::FAILURE;
        }
        $templates = $client->request('get', $wabaId.'/message_templates', [
            'name' => $definition['name'], 'fields' => 'name,language,status,category', 'limit' => 100,
        ], $sender->accessToken);
        $existing = collect($templates['data'] ?? [])->first(fn ($t) => ($t['name'] ?? '') === $definition['name'] && ($t['language'] ?? '') === $definition['language']);
        if ($existing) {
            if (($existing['category'] ?? '') !== 'AUTHENTICATION') {
                $this->error('Nome existente em outra categoria. Configure um nome novo.');

                return self::FAILURE;
            }
            $this->info('Template existente: '.($existing['status'] ?? 'desconhecido').'. Nenhuma alteração enviada.');

            return self::SUCCESS;
        }
        $result = $client->request('post', $wabaId.'/message_templates', $definition, $sender->accessToken);
        $this->info('Template enviado. Status: '.($result['status'] ?? 'PENDING').'. Habilite o envio somente após aprovação e validação de custo.');

        return self::SUCCESS;
    }
}
