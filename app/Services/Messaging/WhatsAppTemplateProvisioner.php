<?php

namespace App\Services\Messaging;

use App\Contracts\WhatsAppManagementInterface;
use App\Exceptions\WhatsAppAuthException;
use App\Exceptions\WhatsAppPermanentException;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppTemplate;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;

/**
 * Garante que a WABA do restaurante tenha os templates de config/whatsapp_templates.php e
 * espelha o status deles em whatsapp_templates. Idempotente: relê a WABA a cada execução, então
 * repetir (retry, comando de suporte) nunca duplica template.
 */
class WhatsAppTemplateProvisioner
{
    public const MSG_AUTH = 'A Meta recusou a autorização do WhatsApp ao criar os templates. Refaça a conexão.';

    public function __construct(private WhatsAppManagementInterface $meta) {}

    /**
     * @return array{created: int, synced: int, failed: array<string, string>, status: string}
     *
     * @throws \App\Exceptions\WhatsAppRetryableException Falha temporária: quem chama retenta (nada se perde).
     */
    public function provision(WhatsAppConnection $connection): array
    {
        $connection->refresh();

        if (blank($connection->waba_id) || blank($connection->access_token)) {
            return ['created' => 0, 'synced' => 0, 'failed' => [], 'status' => $connection->status];
        }

        $wabaId = (string) $connection->waba_id;
        $token = (string) $connection->access_token;
        $language = (string) config('whatsapp_templates.language');
        $created = 0;
        $synced = 0;
        $failed = [];

        try {
            $remote = collect($this->meta->listTemplates($wabaId, $token))
                ->filter(fn (array $template) => $template['language'] === $language)
                ->keyBy('name');

            foreach ((array) config('whatsapp_templates.templates') as $event => $definition) {
                $existing = $remote->get($definition['name']);

                try {
                    if ($existing === null) {
                        $result = $this->meta->createTemplate($wabaId, $token, [
                            'name' => $definition['name'],
                            'language' => $language,
                            'category' => (string) config('whatsapp_templates.category'),
                            'body' => $definition['body'],
                            'example' => $definition['example'],
                        ]);

                        $existing = ['id' => (string) ($result['id'] ?? ''), 'status' => $result['status'], 'rejected_reason' => null, 'just_created' => true];
                        $created++;

                        $this->warnIfNotUtility($connection->id, $definition['name'], (string) ($result['category'] ?? ''));
                    } else {
                        $synced++;
                    }
                } catch (WhatsAppPermanentException $e) {
                    // Um template recusado não impede os demais. Fica registrado para o suporte.
                    $failed[$definition['name']] = $e->getMessage();

                    Log::channel('whatsapp')->warning('Provisionamento: não foi possível criar o template', [
                        'connection_id' => $connection->id,
                        'template' => $definition['name'],
                        'code' => $e->getCode(),
                        'error' => $e->getMessage(),
                    ]);

                    continue;
                }

                $this->storeTemplate($connection, $event, $definition['name'], $language, $existing);
            }
        } catch (WhatsAppAuthException $e) {
            $connection->update([
                'status' => WhatsAppConnection::STATUS_ERROR,
                'last_error' => self::MSG_AUTH,
            ]);

            Log::channel('whatsapp')->error('Provisionamento: token recusado pela Meta', [
                'connection_id' => $connection->id,
                'code' => $e->getCode(),
            ]);

            WhatsAppCriticalLog::authRefused('provisionamento de templates', $connection->id, $connection->company_id, $e);

            return ['created' => $created, 'synced' => $synced, 'failed' => $failed, 'status' => WhatsAppConnection::STATUS_ERROR];
        }

        $status = $this->finalize($connection, $failed);

        Log::channel('whatsapp')->info('Provisionamento de templates concluído', [
            'connection_id' => $connection->id,
            'created' => $created,
            'synced' => $synced,
            'failed' => array_keys($failed),
            'status' => $status,
        ]);

        return ['created' => $created, 'synced' => $synced, 'failed' => $failed, 'status' => $status];
    }

    /**
     * Número da plataforma (homologação e fallback): inscreve o app na WABA da Veddi e cria os
     * templates que faltam. Não há linha em whatsapp_connections, então nada é gravado: o
     * resultado só é exibido.
     *
     * @return array{created: int, templates: array<string, string>, failed: array<string, string>}|null null = plataforma sem WABA/token configurados
     *
     * @throws \App\Exceptions\WhatsAppApiException
     */
    public function provisionPlatform(): ?array
    {
        $wabaId = (string) config('services.whatsapp.platform.waba_id');
        $token = (string) config('services.whatsapp.platform.token');

        if ($wabaId === '' || $token === '') {
            return null;
        }

        $language = (string) config('whatsapp_templates.language');
        $created = 0;
        $templates = [];
        $failed = [];

        // Sem a inscrição do app na WABA, status de entrega e respostas (opt-out) do número da
        // plataforma não chegam ao webhook. A chamada é idempotente.
        $this->meta->subscribeApp($wabaId, $token);

        $remote = collect($this->meta->listTemplates($wabaId, $token))
            ->filter(fn (array $template) => $template['language'] === $language)
            ->keyBy('name');

        foreach ((array) config('whatsapp_templates.templates') as $definition) {
            $existing = $remote->get($definition['name']);

            if ($existing !== null) {
                $templates[$definition['name']] = (string) $existing['status'];

                continue;
            }

            try {
                $result = $this->meta->createTemplate($wabaId, $token, [
                    'name' => $definition['name'],
                    'language' => $language,
                    'category' => (string) config('whatsapp_templates.category'),
                    'body' => $definition['body'],
                    'example' => $definition['example'],
                ]);

                $templates[$definition['name']] = (string) $result['status'];
                $created++;
            } catch (WhatsAppPermanentException $e) {
                $failed[$definition['name']] = $e->getMessage();
            }
        }

        Log::channel('whatsapp')->info('Provisionamento de templates da plataforma concluído', [
            'created' => $created,
            'failed' => array_keys($failed),
        ]);

        return ['created' => $created, 'templates' => $templates, 'failed' => $failed];
    }

    /** @param  array{id: string, status: string, rejected_reason: ?string, just_created?: bool}  $remote */
    private function storeTemplate(WhatsAppConnection $connection, string $event, string $name, string $language, array $remote): void
    {
        $template = WhatsAppTemplate::firstOrNew([
            'whatsapp_connection_id' => $connection->id,
            'name' => $name,
            'language' => $language,
        ]);

        // O webhook de aprovação pode chegar entre o POST de criação e esta gravação: o PENDING
        // da resposta da criação não pode sobrescrever o status que o webhook já gravou.
        if (($remote['just_created'] ?? false) && $template->exists && $template->status !== WhatsAppTemplate::STATUS_PENDING) {
            $remote['status'] = $template->status;
        }

        // Status que não muda o uso (FLAGGED etc.) mantém o atual; template novo começa PENDING.
        $status = WhatsAppTemplate::mapMetaStatus($remote['status']) ?? $template->status ?? WhatsAppTemplate::STATUS_PENDING;

        $reason = strtoupper((string) ($remote['rejected_reason'] ?? 'NONE'));

        $template->fill([
            'event' => $event,
            'meta_template_id' => filled($remote['id']) ? $remote['id'] : $template->meta_template_id,
            'status' => $status,
            'rejection_reason' => $status !== WhatsAppTemplate::STATUS_APPROVED && $reason !== 'NONE' && $reason !== '' ? $reason : null,
        ])->save();
    }

    /**
     * A Meta aprova como MARKETING (em vez de rejeitar) um template UTILITY que julgue
     * promocional: funciona, mas custa mais e tem limite de frequência. Só avisa o suporte.
     */
    private function warnIfNotUtility(int $connectionId, string $name, string $category): void
    {
        if ($category === '' || strtoupper($category) === strtoupper((string) config('whatsapp_templates.category'))) {
            return;
        }

        Log::channel('whatsapp')->warning('Provisionamento: template criado com categoria diferente da enviada', [
            'connection_id' => $connectionId,
            'template' => $name,
            'category' => strtoupper($category),
        ]);
    }

    /**
     * Só promove conexões em provisioning/templates_pending. Uma conexão já ativa nunca é
     * rebaixada aqui: um template pausado não pode desligar as notificações dos outros eventos
     * (o envio já confere o template de cada evento).
     *
     * @param  array<string, string>  $failed
     */
    private function finalize(WhatsAppConnection $connection, array $failed): string
    {
        $connection->refresh();

        if (! in_array($connection->status, [WhatsAppConnection::STATUS_PROVISIONING, WhatsAppConnection::STATUS_TEMPLATES_PENDING], true)) {
            return $connection->status;
        }

        $ready = $connection->hasAllTemplatesApproved();

        $connection->update([
            'status' => $ready ? WhatsAppConnection::STATUS_ACTIVE : WhatsAppConnection::STATUS_TEMPLATES_PENDING,
            'connected_at' => $ready ? ($connection->connected_at ?? Date::now()) : $connection->connected_at,
            'last_error' => $failed === []
                ? null
                : 'Não foi possível criar alguns templates de mensagem ('.implode(', ', array_keys($failed)).'). A sincronização será refeita; se persistir, fale com o suporte.',
        ]);

        return $connection->status;
    }
}
