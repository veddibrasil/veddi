<?php

namespace App\Services\Messaging;

use App\Models\Customer;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppTemplate;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Processa o payload do webhook da WhatsApp Cloud API. Um único webhook recebe eventos de
 * todas as WABAs (uma por restaurante, mais a da plataforma), então cada mudança primeiro
 * é resolvida para a(s) conexão(ões) dona(s) e só então aplicada — nunca entre empresas.
 *
 * Tudo é idempotente: a Meta reenvia eventos e não garante a ordem de chegada.
 * Nunca loga texto de mensagem de cliente nem telefone (só ids e contagens).
 */
class WhatsAppWebhookProcessor
{
    /** last_error gravado num banimento; é o que permite reverter só o erro que nós mesmos marcamos. */
    public const BAN_MESSAGE = 'A Meta desativou a conta do WhatsApp Business (violação de política). Verifique o WhatsApp Manager.';

    public const REMOVED_PARTNER_MESSAGE = 'O acesso da Veddi à conta do WhatsApp Business foi removido. Reconecte o número.';

    public const DELETED_ACCOUNT_MESSAGE = 'A conta do WhatsApp Business foi excluída na Meta. Conecte novamente.';

    public const REMOVED_NUMBER_MESSAGE = 'O número foi removido da conta do WhatsApp Business. Conecte novamente.';

    /** last_error de ACCOUNT_OFFBOARDED; o ACCOUNT_RECONNECTED só reverte este erro. */
    public const OFFBOARDED_MESSAGE = 'O número foi registrado em outro aparelho e a Meta suspendeu a integração. Abra o app WhatsApp Business no celular do número; se não voltar sozinho, reconecte.';

    /** Respostas do cliente que encerram as notificações (comparadas sem acento, caixa ou pontuação). */
    private const OPT_OUT_KEYWORDS = ['PARAR', 'SAIR', 'STOP', 'CANCELAR'];

    /** Progressão do status de entrega: nunca regride (read > delivered > sent). */
    private const STATUS_RANK = [
        WhatsAppMessage::STATUS_QUEUED => 0,
        WhatsAppMessage::STATUS_SENT => 1,
        WhatsAppMessage::STATUS_DELIVERED => 2,
        WhatsAppMessage::STATUS_READ => 3,
    ];

    private const QUALITY_RATINGS = ['GREEN', 'YELLOW', 'RED', 'UNKNOWN'];

    public function __construct(private WhatsAppService $whatsApp) {}

    /** @param  array<string, mixed>  $payload */
    public function process(array $payload): void
    {
        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $entryId = (string) ($entry['id'] ?? '');

            foreach ((array) ($entry['changes'] ?? []) as $change) {
                if (! is_array($change)) {
                    continue;
                }

                $this->handleChange($entryId, (string) ($change['field'] ?? ''), (array) ($change['value'] ?? []));
            }
        }
    }

    /** @param  array<string, mixed>  $value */
    private function handleChange(string $entryId, string $field, array $value): void
    {
        $target = $this->resolveTarget($entryId, $value);

        if ($target === null) {
            Log::channel('whatsapp')->warning('Webhook WhatsApp: WABA/número sem conexão conhecida, evento descartado', [
                'field' => $field,
                'waba_id' => $entryId,
                'phone_number_id' => data_get($value, 'metadata.phone_number_id'),
            ]);

            return;
        }

        match ($field) {
            'messages' => $this->handleMessages($target, $value),
            'message_template_status_update' => $this->handleTemplateStatus($target, $value),
            'phone_number_quality_update' => $this->handleQualityUpdate($target, $value, $field),
            'account_alerts' => $this->handleAccountAlert($target, $value),
            'account_update' => $this->handleAccountUpdate($target, $value),
            'smb_message_echoes' => $this->handleEchoes($target, $value),
            'template_category_update' => $this->handleCategoryUpdate($target, $value),
            // history e smb_app_state_sync (sincronização da coexistência) ficam para uma próxima
            // entrega: por ora só registram que chegaram, assim como qualquer campo desconhecido.
            default => $this->logOnly($target, $field),
        };
    }

    /**
     * Quem é o dono do evento: a plataforma (reconhecida pelo config) ou conexões de restaurantes.
     * O phone_number_id é único no banco e tem prioridade; eventos de nível WABA usam o entry.id.
     * Resolve mesmo conexões desconectadas/com erro: status e templates antigos ainda chegam.
     *
     * @param  array<string, mixed>  $value
     * @return array{platform: bool, connections: Collection<int, WhatsAppConnection>}|null
     */
    private function resolveTarget(string $entryId, array $value): ?array
    {
        $platform = (array) config('services.whatsapp.platform');
        $phoneNumberId = (string) data_get($value, 'metadata.phone_number_id', '');

        if ($phoneNumberId !== '') {
            if (filled($platform['phone_number_id'] ?? null) && $phoneNumberId === (string) $platform['phone_number_id']) {
                return ['platform' => true, 'connections' => collect()];
            }

            $connection = WhatsAppConnection::withoutGlobalScopes()->where('phone_number_id', $phoneNumberId)->first();

            return $connection ? ['platform' => false, 'connections' => collect([$connection])] : null;
        }

        // Eventos de parceiro (PARTNER_REMOVED, PARTNER_APP_UNINSTALLED...) chegam com o id do
        // business no entry.id; a WABA afetada vem em waba_info.waba_id.
        $wabaId = (string) data_get($value, 'waba_info.waba_id', '') ?: $entryId;

        if ($wabaId === '') {
            return null;
        }

        if (filled($platform['waba_id'] ?? null) && $wabaId === (string) $platform['waba_id']) {
            return ['platform' => true, 'connections' => collect()];
        }

        $connections = WhatsAppConnection::withoutGlobalScopes()->where('waba_id', $wabaId)->get();

        return $connections->isEmpty() ? null : ['platform' => false, 'connections' => $connections];
    }

    // ── messages ──────────────────────────────────────────────────────────────

    /**
     * @param  array{platform: bool, connections: Collection<int, WhatsAppConnection>}  $target
     * @param  array<string, mixed>  $value
     */
    private function handleMessages(array $target, array $value): void
    {
        foreach ((array) ($value['statuses'] ?? []) as $status) {
            if (is_array($status)) {
                $this->handleStatus($target, $status);
            }
        }

        foreach ((array) ($value['messages'] ?? []) as $message) {
            if (is_array($message)) {
                $this->handleIncomingMessage($target, $message);
            }
        }
    }

    /**
     * @param  array{platform: bool, connections: Collection<int, WhatsAppConnection>}  $target
     * @param  array<string, mixed>  $status
     */
    private function handleStatus(array $target, array $status): void
    {
        $wamid = (string) ($status['id'] ?? '');
        $new = strtolower((string) ($status['status'] ?? ''));

        if ($wamid === '' || ! in_array($new, ['sent', 'delivered', 'read', 'failed'], true)) {
            return;
        }

        // Isolamento: o status só vale para mensagem enviada por ESTA conexão (ou pelo número
        // da plataforma). Um evento da WABA A nunca altera mensagem da empresa B.
        $query = WhatsAppMessage::withoutGlobalScopes()->where('wamid', $wamid);

        if ($target['platform']) {
            $query->whereNull('whatsapp_connection_id');
        } else {
            $query->whereIn('whatsapp_connection_id', $target['connections']->pluck('id'));
        }

        $message = $query->first();

        if (! $message) {
            if (WhatsAppMessage::withoutGlobalScopes()->where('wamid', $wamid)->exists()) {
                Log::channel('whatsapp')->warning('Webhook WhatsApp: status de mensagem de outra conexão, descartado', [
                    'wamid' => $wamid,
                    'status' => $new,
                ]);
            } else {
                Log::channel('whatsapp')->debug('Webhook WhatsApp: status de mensagem desconhecida (não enviada por este sistema)', [
                    'wamid' => $wamid,
                    'status' => $new,
                ]);
            }

            return;
        }

        $at = isset($status['timestamp']) ? $this->fromUnix((int) $status['timestamp']) : Date::now();

        if ($new === 'failed') {
            $this->applyFailedStatus($message, $status);

            return;
        }

        // failed é terminal; e nunca regride (um "sent" atrasado não sobrescreve "read").
        if ($message->status === WhatsAppMessage::STATUS_FAILED
            || self::STATUS_RANK[$new] <= (self::STATUS_RANK[$message->status] ?? 0)) {
            return;
        }

        $attributes = ['status' => $new, 'sent_at' => $message->sent_at ?? $at];

        if ($new === WhatsAppMessage::STATUS_DELIVERED) {
            $attributes['delivered_at'] = $at;
        }

        if ($new === WhatsAppMessage::STATUS_READ) {
            // Lida implica entregue, mesmo que o webhook "delivered" tenha se perdido.
            $attributes['delivered_at'] = $message->delivered_at ?? $at;
            $attributes['read_at'] = $at;
        }

        $message->update($attributes);
    }

    /** @param  array<string, mixed>  $status */
    private function applyFailedStatus(WhatsAppMessage $message, array $status): void
    {
        if (in_array($message->status, [WhatsAppMessage::STATUS_DELIVERED, WhatsAppMessage::STATUS_READ, WhatsAppMessage::STATUS_FAILED], true)) {
            return;
        }

        $error = (array) data_get($status, 'errors.0', []);
        $description = trim(implode(' — ', array_filter([
            $error['title'] ?? null,
            data_get($error, 'error_data.details') ?? $error['message'] ?? null,
        ])));

        $message->update([
            'status' => WhatsAppMessage::STATUS_FAILED,
            'error_code' => isset($error['code']) ? (string) $error['code'] : null,
            'error_message' => $description !== '' ? Str::limit($description, 500) : 'Falha na entrega informada pela Meta.',
        ]);
    }

    /**
     * Mensagem recebida do cliente. Só reage a pedido de parada; o texto nunca é logado.
     *
     * @param  array{platform: bool, connections: Collection<int, WhatsAppConnection>}  $target
     * @param  array<string, mixed>  $message
     */
    private function handleIncomingMessage(array $target, array $message): void
    {
        Log::channel('whatsapp')->info('Webhook WhatsApp: mensagem recebida do cliente', [
            'message_id' => $message['id'] ?? null,
            'type' => $message['type'] ?? null,
            'connection_ids' => $target['connections']->pluck('id')->all(),
            'platform' => $target['platform'],
        ]);

        if (($message['type'] ?? null) !== 'text' || ! $this->isOptOutKeyword((string) data_get($message, 'text.body', ''))) {
            return;
        }

        $from = (string) ($message['from'] ?? '');

        if ($from !== '') {
            $this->registerOptOut($target, $from);
        }
    }

    /** Timestamp unix da Meta no fuso da aplicação (o Eloquent grava o horário como está, sem converter). */
    private function fromUnix(int $timestamp): CarbonInterface
    {
        return Date::createFromTimestamp($timestamp, config('app.timezone'));
    }

    private function isOptOutKeyword(string $text): bool
    {
        $normalized = Str::of($text)->ascii()->upper()->replaceMatches('/[^A-Z]/', '')->toString();

        return in_array($normalized, self::OPT_OUT_KEYWORDS, true);
    }

    /**
     * Grava o opt-out do cliente na empresa dona da conexão. No número da plataforma (fallback)
     * a resposta vale para toda empresa que já enviou mensagem àquele telefone por ele.
     *
     * @param  array{platform: bool, connections: Collection<int, WhatsAppConnection>}  $target
     */
    private function registerOptOut(array $target, string $from): void
    {
        $phones = $this->whatsApp->phoneCandidates($from);

        $companyIds = $target['platform']
            ? WhatsAppMessage::withoutGlobalScopes()
                ->whereNull('whatsapp_connection_id')
                ->whereIn('to_phone', $phones)
                ->distinct()
                ->pluck('company_id')
            : $target['connections']->pluck('company_id');

        // Mantém o primeiro registro de opt-out (idempotente diante de reenvios do webhook).
        $updated = Customer::withoutGlobalScopes()
            ->whereIn('company_id', $companyIds)
            ->whereIn('phone', $phones)
            ->whereNull('whatsapp_opt_out_at')
            ->update(['whatsapp_opt_out_at' => Date::now()]);

        Log::channel('whatsapp')->info('Webhook WhatsApp: opt-out registrado', [
            'company_ids' => $companyIds->values()->all(),
            'customers_updated' => $updated,
        ]);
    }

    // ── message_template_status_update ────────────────────────────────────────

    /**
     * @param  array{platform: bool, connections: Collection<int, WhatsAppConnection>}  $target
     * @param  array<string, mixed>  $value
     */
    private function handleTemplateStatus(array $target, array $value): void
    {
        $event = strtoupper((string) ($value['event'] ?? ''));
        $name = (string) ($value['message_template_name'] ?? '');
        $language = $this->normalizeLanguage((string) ($value['message_template_language'] ?? ''));
        $metaTemplateId = isset($value['message_template_id']) ? (string) $value['message_template_id'] : null;
        $status = WhatsAppTemplate::mapMetaStatus($event);

        if ($name === '' || $status === null) {
            Log::channel('whatsapp')->info('Webhook WhatsApp: evento de template sem efeito', ['event' => $event, 'template' => $name]);

            return;
        }

        $reason = strtoupper((string) ($value['reason'] ?? 'NONE'));

        $this->warnIfNotUtility($target, $name, (string) ($value['message_template_category'] ?? ''));

        foreach ($target['connections'] as $connection) {
            $template = ($metaTemplateId !== null
                ? WhatsAppTemplate::where('whatsapp_connection_id', $connection->id)->where('meta_template_id', $metaTemplateId)->first()
                : null)
                ?? WhatsAppTemplate::where('whatsapp_connection_id', $connection->id)
                    ->where('name', $name)
                    ->where('language', $language)
                    ->first();

            // O webhook pode chegar antes de o provisionamento gravar a linha: cria só se o
            // nome for de um template nosso (config) no nosso idioma, nunca de template alheio da WABA.
            if (! $template) {
                $templateEvent = $this->templateEventForName($name);

                if ($templateEvent === null || $language !== config('whatsapp_templates.language')) {
                    continue;
                }

                $template = new WhatsAppTemplate([
                    'whatsapp_connection_id' => $connection->id,
                    'event' => $templateEvent,
                    'name' => $name,
                    'language' => $language,
                ]);
            }

            $template->fill([
                'status' => $status,
                'meta_template_id' => $metaTemplateId ?? $template->meta_template_id,
                'rejection_reason' => $reason !== 'NONE' && $reason !== '' && $status !== WhatsAppTemplate::STATUS_APPROVED ? $reason : null,
            ])->save();

            $this->activateIfReady($connection);
        }
    }

    /**
     * A Meta manda o idioma do template como "pt-BR" (ou só "pt" em alguns eventos); o nosso
     * padrão (o mesmo da criação) é "pt_BR".
     */
    private function normalizeLanguage(string $language): string
    {
        $configured = (string) config('whatsapp_templates.language');
        $language = str_replace('-', '_', trim($language));

        if ($language === '' || strcasecmp($language, $configured) === 0 || str_starts_with(strtolower($configured), strtolower($language).'_')) {
            return $configured;
        }

        return $language;
    }

    /**
     * Desde abril/2025 a Meta aprova como MARKETING (em vez de rejeitar) um template enviado como
     * UTILITY que ela julgue promocional: a mensagem passa a custar mais e a ter limite de
     * frequência. Não bloqueia o uso, mas o suporte precisa saber.
     *
     * @param  array{platform: bool, connections: Collection<int, WhatsAppConnection>}  $target
     */
    private function warnIfNotUtility(array $target, string $name, string $category): void
    {
        if ($category === '' || strtoupper($category) === strtoupper((string) config('whatsapp_templates.category'))) {
            return;
        }

        Log::channel('whatsapp')->warning('Webhook WhatsApp: template com categoria diferente da enviada', [
            'template' => $name,
            'category' => strtoupper($category),
            'platform' => $target['platform'],
            'connection_ids' => $target['connections']->pluck('id')->all(),
        ]);
    }

    /**
     * @param  array{platform: bool, connections: Collection<int, WhatsAppConnection>}  $target
     * @param  array<string, mixed>  $value
     */
    private function handleCategoryUpdate(array $target, array $value): void
    {
        $this->warnIfNotUtility(
            $target,
            (string) ($value['message_template_name'] ?? ''),
            (string) ($value['new_category'] ?? $value['correct_category'] ?? ''),
        );
    }

    private function templateEventForName(string $name): ?string
    {
        foreach ((array) config('whatsapp_templates.templates') as $event => $definition) {
            if ($definition['name'] === $name) {
                return $event;
            }
        }

        return null;
    }

    private function activateIfReady(WhatsAppConnection $connection): void
    {
        $connection->refresh();

        if ($connection->status !== WhatsAppConnection::STATUS_TEMPLATES_PENDING || ! $connection->hasAllTemplatesApproved()) {
            return;
        }

        $connection->update([
            'status' => WhatsAppConnection::STATUS_ACTIVE,
            'connected_at' => $connection->connected_at ?? Date::now(),
            'last_error' => null,
        ]);

        Log::channel('whatsapp')->info('Conexão WhatsApp ativada: todos os templates aprovados', ['connection_id' => $connection->id]);
    }

    // ── qualidade, limites e conta ────────────────────────────────────────────

    /**
     * @param  array{platform: bool, connections: Collection<int, WhatsAppConnection>}  $target
     * @param  array<string, mixed>  $value
     */
    private function handleQualityUpdate(array $target, array $value, string $field): void
    {
        Log::channel('whatsapp')->info('Webhook WhatsApp: atualização de qualidade/limite', [
            'field' => $field,
            'event' => $value['event'] ?? null,
            'platform' => $target['platform'],
            'connection_ids' => $target['connections']->pluck('id')->all(),
        ]);

        foreach ($target['connections'] as $connection) {
            $attributes = [];

            // current_limit sai em fev/2026; o substituto é max_daily_conversations_per_business.
            $limit = $value['max_daily_conversations_per_business'] ?? $value['current_limit'] ?? null;

            if (is_scalar($limit) && filled($limit)) {
                $attributes['messaging_limit_tier'] = (string) $limit;
            }

            $quality = strtoupper((string) ($value['quality_rating'] ?? ''));
            $event = strtoupper((string) ($value['event'] ?? ''));

            if (in_array($quality, self::QUALITY_RATINGS, true)) {
                $attributes['quality_rating'] = $quality;
            } elseif ($event === 'FLAGGED') {
                // "Flagged" da Meta = qualidade baixa (vermelha) sustentada.
                $attributes['quality_rating'] = 'RED';
            } elseif ($event === 'UNFLAGGED' && $connection->quality_rating === 'RED') {
                // Aproximação até a próxima consulta à Graph API, que traz o valor exato.
                $attributes['quality_rating'] = 'YELLOW';
            }

            if ($attributes !== []) {
                $connection->update($attributes);
            }
        }
    }

    /**
     * @param  array{platform: bool, connections: Collection<int, WhatsAppConnection>}  $target
     * @param  array<string, mixed>  $value
     */
    private function handleAccountAlert(array $target, array $value): void
    {
        // Os dados do alerta vêm em alert_info; o payload não traz qualidade nem limite.
        Log::channel('whatsapp')->warning('Webhook WhatsApp: alerta de conta', [
            'severity' => data_get($value, 'alert_info.alert_severity'),
            'status' => data_get($value, 'alert_info.alert_status'),
            'type' => data_get($value, 'alert_info.alert_type'),
            'entity_type' => $value['entity_type'] ?? null,
            'entity_id' => $value['entity_id'] ?? null,
            'platform' => $target['platform'],
            'connection_ids' => $target['connections']->pluck('id')->all(),
        ]);
    }

    /**
     * @param  array{platform: bool, connections: Collection<int, WhatsAppConnection>}  $target
     * @param  array<string, mixed>  $value
     */
    private function handleAccountUpdate(array $target, array $value): void
    {
        $event = strtoupper((string) ($value['event'] ?? ''));

        // Partner events de OUTRO app parceiro da mesma WABA não nos dizem respeito.
        $partnerAppId = (string) data_get($value, 'waba_info.partner_app_id', '');
        if ($partnerAppId !== '' && filled(config('services.meta.app_id')) && $partnerAppId !== (string) config('services.meta.app_id')) {
            return;
        }

        $level = in_array($event, ['DISABLED_UPDATE', 'ACCOUNT_DELETED', 'PARTNER_REMOVED', 'PARTNER_APP_UNINSTALLED', 'PHONE_NUMBER_REMOVED', 'ACCOUNT_VIOLATION', 'ACCOUNT_OFFBOARDED'], true)
            ? 'error'
            : 'info';

        Log::channel('whatsapp')->{$level}('Webhook WhatsApp: atualização de conta', [
            'event' => $event,
            'ban_state' => data_get($value, 'ban_info.waba_ban_state'),
            // PRIMARY_INACTIVITY = app WhatsApp Business sem uso por ~14 dias (coexistência).
            'disconnection_reason' => data_get($value, 'disconnection_info.reason'),
            'platform' => $target['platform'],
            'connection_ids' => $target['connections']->pluck('id')->all(),
        ]);

        // Conta da plataforma banida não tem conexão a marcar, mas é o alerta mais grave de todos.
        if ($target['platform'] && $event === 'DISABLED_UPDATE' && strtoupper((string) data_get($value, 'ban_info.waba_ban_state', '')) === 'DISABLE') {
            WhatsAppCriticalLog::accountBanned(null, null, (string) config('services.whatsapp.platform.waba_id'));
        }

        foreach ($target['connections'] as $connection) {
            match ($event) {
                'DISABLED_UPDATE' => $this->handleBanState($connection, strtoupper((string) data_get($value, 'ban_info.waba_ban_state', ''))),
                'ACCOUNT_DELETED' => $this->markDisconnected($connection, self::DELETED_ACCOUNT_MESSAGE),
                'PARTNER_REMOVED', 'PARTNER_APP_UNINSTALLED' => $this->markDisconnected($connection, self::REMOVED_PARTNER_MESSAGE),
                'PHONE_NUMBER_REMOVED' => $this->handleNumberRemoved($connection, (string) ($value['phone_number'] ?? '')),
                'ACCOUNT_OFFBOARDED' => $this->markOffboarded($connection),
                'ACCOUNT_RECONNECTED' => $this->restoreFromError($connection, self::OFFBOARDED_MESSAGE),
                default => null,
            };
        }
    }

    private function handleBanState(WhatsAppConnection $connection, string $state): void
    {
        if ($state === 'DISABLE') {
            // A Meta reenvia o evento: só alerta a equipe na primeira vez que o banimento é registrado.
            $alreadyBanned = $connection->status === WhatsAppConnection::STATUS_ERROR && $connection->last_error === self::BAN_MESSAGE;

            $connection->update([
                'status' => WhatsAppConnection::STATUS_ERROR,
                'last_error' => self::BAN_MESSAGE,
            ]);

            if (! $alreadyBanned) {
                WhatsAppCriticalLog::accountBanned($connection->id, $connection->company_id, (string) $connection->waba_id);
            }

            return;
        }

        if ($state === 'REINSTATE') {
            $this->restoreFromError($connection, self::BAN_MESSAGE);
        }
    }

    /**
     * Número registrado em outro aparelho (ou re-registrado): a Meta suspende a integração até o
     * ACCOUNT_RECONNECTED. Fica em erro (e não desconectada) para voltar sozinha e para o monitor
     * avisar o restaurante.
     */
    private function markOffboarded(WhatsAppConnection $connection): void
    {
        if (! in_array($connection->status, [WhatsAppConnection::STATUS_ACTIVE, WhatsAppConnection::STATUS_TEMPLATES_PENDING], true)) {
            return;
        }

        $connection->update([
            'status' => WhatsAppConnection::STATUS_ERROR,
            'last_error' => self::OFFBOARDED_MESSAGE,
        ]);
    }

    /** Reverte só o erro que um webhook marcou; um erro de token (por exemplo) continua. */
    private function restoreFromError(WhatsAppConnection $connection, string $markedError): void
    {
        if ($connection->status !== WhatsAppConnection::STATUS_ERROR || $connection->last_error !== $markedError) {
            return;
        }

        $connection->update([
            'status' => $connection->hasAllTemplatesApproved()
                ? WhatsAppConnection::STATUS_ACTIVE
                : WhatsAppConnection::STATUS_TEMPLATES_PENDING,
            'last_error' => null,
        ]);
    }

    private function handleNumberRemoved(WhatsAppConnection $connection, string $removedNumber): void
    {
        $removed = preg_replace('/\D/', '', $removedNumber);
        $own = preg_replace('/\D/', '', (string) $connection->display_phone_number);

        // A WABA pode ter outros números; só desconecta se for o desta conexão.
        if ($removed === '' || $own === '' || ! (str_ends_with($own, $removed) || str_ends_with($removed, $own))) {
            return;
        }

        $this->markDisconnected($connection, self::REMOVED_NUMBER_MESSAGE);
    }

    private function markDisconnected(WhatsAppConnection $connection, string $reason): void
    {
        if ($connection->status === WhatsAppConnection::STATUS_DISCONNECTED) {
            return;
        }

        $connection->update([
            'status' => WhatsAppConnection::STATUS_DISCONNECTED,
            'disconnected_at' => Date::now(),
            'last_error' => $reason,
        ]);
    }

    // ── coexistência ──────────────────────────────────────────────────────────

    /**
     * Eco de mensagem enviada pelo app WhatsApp Business: prova de que o app segue em uso
     * (a Meta desconecta a coexistência após ~14 dias sem uso). O conteúdo não é processado.
     *
     * @param  array{platform: bool, connections: Collection<int, WhatsAppConnection>}  $target
     * @param  array<string, mixed>  $value
     */
    private function handleEchoes(array $target, array $value): void
    {
        $timestamps = collect((array) ($value['message_echoes'] ?? []))
            ->map(fn ($echo) => is_array($echo) && isset($echo['timestamp']) ? (int) $echo['timestamp'] : null)
            ->filter();

        $at = $timestamps->isNotEmpty() ? $this->fromUnix((int) $timestamps->max()) : Date::now();

        foreach ($target['connections'] as $connection) {
            if ($connection->last_app_activity_at === null || $connection->last_app_activity_at->lt($at)) {
                $connection->update(['last_app_activity_at' => $at]);
            }
        }
    }

    /**
     * @param  array{platform: bool, connections: Collection<int, WhatsAppConnection>}  $target
     */
    private function logOnly(array $target, string $field): void
    {
        Log::channel('whatsapp')->info('Webhook WhatsApp: evento recebido sem processamento nesta versão', [
            'field' => $field,
            'platform' => $target['platform'],
            'connection_ids' => $target['connections']->pluck('id')->all(),
        ]);
    }
}
