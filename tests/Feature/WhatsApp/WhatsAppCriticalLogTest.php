<?php

use App\Models\WhatsAppConnection;
use App\Services\Messaging\WhatsAppOnboardingService;
use App\Services\Messaging\WhatsAppTemplateProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Monolog\Level;
use Monolog\LogRecord;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.meta.app_id' => '111222333',
        'services.whatsapp.platform.waba_id' => null,
        'services.whatsapp.platform.phone_number_id' => null,
        'services.whatsapp.platform.token' => null,
    ]);
});

/** @return array<int, LogRecord> */
function whatsappDiscordRecords(\Monolog\Handler\TestHandler $handler): array
{
    return $handler->getRecords();
}

function whatsappCriticalConnection(array $attributes = []): WhatsAppConnection
{
    return WhatsAppConnection::factory()->create(array_merge([
        'waba_id' => '1000000000001',
        'phone_number_id' => '2000000000001',
        'access_token' => 'EAAB-token-ultra-secreto',
    ], $attributes));
}

// ── WhatsAppAuthException ─────────────────────────────────────────────────────

test('token recusado no envio de notificação vai para o canal discord com ids e código, sem segredos', function () {
    $discord = whatsappCaptureDiscord();
    ['order' => $order, 'connection' => $connection] = whatsappOrderContext();
    $connection->update(['access_token' => 'EAAB-token-ultra-secreto']);

    Http::fake(['graph.facebook.com/*' => whatsappGraphError(190, 401, 'Error validating access token')]);

    (new \App\Jobs\SendWhatsAppOrderNotificationJob($order->id, 'preparing'))
        ->handle(app(\App\Services\Messaging\WhatsAppService::class), app(\App\Contracts\WhatsAppProviderInterface::class));

    $records = whatsappDiscordRecords($discord);

    expect($records)->toHaveCount(1)
        ->and($records[0]->level)->toBe(Level::Error)
        ->and($records[0]->message)->toBe('WhatsApp: token recusado pela Meta')
        ->and($records[0]->context)->toMatchArray([
            'origin' => 'envio de notificação',
            'company_id' => $order->company_id,
            'connection_id' => $connection->id,
            'meta_code' => 190,
            'http_status' => 401,
        ])
        ->and(json_encode($records[0]->context))->not->toContain('EAAB-token-ultra-secreto');
});

test('token recusado no provisionamento de templates vai para o canal discord', function () {
    $discord = whatsappCaptureDiscord();
    whatsappFakeMeta(['GET message_templates' => whatsappGraphError(190, 401, 'Error validating access token')]);
    $connection = whatsappCriticalConnection(['status' => 'provisioning', 'connected_at' => null]);

    app(WhatsAppTemplateProvisioner::class)->provision($connection);

    $records = whatsappDiscordRecords($discord);

    expect($records)->toHaveCount(1)
        ->and($records[0]->context['origin'])->toBe('provisionamento de templates')
        ->and($records[0]->context['connection_id'])->toBe($connection->id)
        ->and($records[0]->context['company_id'])->toBe($connection->company_id)
        ->and(json_encode($records[0]->context))->not->toContain('EAAB-token-ultra-secreto');
});

test('token recusado no onboarding vai para o canal discord', function () {
    $discord = whatsappCaptureDiscord();
    whatsappFakeMeta(['POST subscribed_apps' => whatsappGraphError(190, 401, 'Error validating access token')]);
    $connection = whatsappCriticalConnection([
        'status' => WhatsAppConnection::STATUS_PENDING,
        'access_token' => 'EAAB-token-ultra-secreto',
        'connected_at' => null,
    ]);

    app(WhatsAppOnboardingService::class)->complete($connection, 'CODE');

    $records = whatsappDiscordRecords($discord);

    expect($records)->toHaveCount(1)
        ->and($records[0]->context['origin'])->toBe('onboarding')
        ->and($records[0]->context['connection_id'])->toBe($connection->id)
        ->and(json_encode($records[0]->context))->not->toContain('EAAB-token-ultra-secreto');
});

test('falhas comuns de envio não poluem o canal discord', function (int $code, string $message) {
    $discord = whatsappCaptureDiscord();
    ['order' => $order] = whatsappOrderContext();

    Http::fake(['graph.facebook.com/*' => whatsappGraphError($code, 400, $message)]);

    (new \App\Jobs\SendWhatsAppOrderNotificationJob($order->id, 'preparing'))
        ->handle(app(\App\Services\Messaging\WhatsAppService::class), app(\App\Contracts\WhatsAppProviderInterface::class));

    expect(whatsappDiscordRecords($discord))->toBe([]);
})->with([
    'número sem WhatsApp (131026)' => [131026, 'Message undeliverable'],
    'problema de cobrança (131042)' => [131042, 'Business eligibility payment issue'],
]);

test('envio com sucesso não escreve nada no canal discord', function () {
    $discord = whatsappCaptureDiscord();
    ['order' => $order] = whatsappOrderContext();

    Http::fake(['graph.facebook.com/*' => whatsappSendSuccess()]);

    (new \App\Jobs\SendWhatsAppOrderNotificationJob($order->id, 'preparing'))
        ->handle(app(\App\Services\Messaging\WhatsAppService::class), app(\App\Contracts\WhatsAppProviderInterface::class));

    expect(whatsappDiscordRecords($discord))->toBe([]);
});

// ── Banimento ─────────────────────────────────────────────────────────────────

function whatsappBanPayload(string $wabaId, string $state = 'DISABLE'): array
{
    return whatsappWebhookPayload($wabaId, [
        whatsappWabaChange('account_update', [
            'phone_number' => '551199990001',
            'event' => 'DISABLED_UPDATE',
            'ban_info' => ['waba_ban_state' => $state, 'waba_ban_date' => '2026-09-23'],
        ]),
    ]);
}

test('banimento da conta de um restaurante gera log critical no discord, com ids apenas', function () {
    $discord = whatsappCaptureDiscord();
    $connection = whatsappCriticalConnection();

    processWhatsAppWebhook(whatsappBanPayload($connection->waba_id));

    $records = whatsappDiscordRecords($discord);

    expect($records)->toHaveCount(1)
        ->and($records[0]->level)->toBe(Level::Critical)
        ->and($records[0]->message)->toBe('WhatsApp: a Meta desativou a conta de um restaurante (banimento)')
        ->and($records[0]->context)->toBe([
            'company_id' => $connection->company_id,
            'connection_id' => $connection->id,
            'waba_id' => '1000000000001',
        ])
        ->and($connection->fresh()->status)->toBe(WhatsAppConnection::STATUS_ERROR);
});

test('o banimento reenviado pela Meta não repete o alerta no discord', function () {
    $discord = whatsappCaptureDiscord();
    $connection = whatsappCriticalConnection();

    processWhatsAppWebhook(whatsappBanPayload($connection->waba_id));
    processWhatsAppWebhook(whatsappBanPayload($connection->waba_id));

    expect(whatsappDiscordRecords($discord))->toHaveCount(1);
});

test('banimento depois de reativação alerta de novo', function () {
    $discord = whatsappCaptureDiscord();
    $connection = whatsappCriticalConnection();

    processWhatsAppWebhook(whatsappBanPayload($connection->waba_id));
    processWhatsAppWebhook(whatsappBanPayload($connection->waba_id, 'REINSTATE'));
    processWhatsAppWebhook(whatsappBanPayload($connection->waba_id));

    expect(whatsappDiscordRecords($discord))->toHaveCount(2);
});

test('banimento agendado, reativação e outros eventos de conta não vão para o discord', function (string $state) {
    $discord = whatsappCaptureDiscord();
    $connection = whatsappCriticalConnection();

    processWhatsAppWebhook(whatsappBanPayload($connection->waba_id, $state));

    expect(whatsappDiscordRecords($discord))->toBe([]);
})->with(['SCHEDULE_FOR_DISABLE', 'REINSTATE']);

test('banimento da conta da plataforma gera log critical sem empresa', function () {
    $discord = whatsappCaptureDiscord();
    config([
        'services.whatsapp.platform.waba_id' => 'waba-plataforma',
        'services.whatsapp.platform.phone_number_id' => 'phone-plataforma',
    ]);

    processWhatsAppWebhook(whatsappBanPayload('waba-plataforma'));

    $records = whatsappDiscordRecords($discord);

    expect($records)->toHaveCount(1)
        ->and($records[0]->level)->toBe(Level::Critical)
        ->and($records[0]->message)->toBe('WhatsApp: a Meta desativou a conta da PLATAFORMA (banimento)')
        ->and($records[0]->context)->toBe(['company_id' => null, 'connection_id' => null, 'waba_id' => 'waba-plataforma']);
});

test('desconexão e remoção de número não são tratadas como banimento no discord', function () {
    $discord = whatsappCaptureDiscord();
    $connection = whatsappCriticalConnection();

    processWhatsAppWebhook(whatsappWebhookPayload($connection->waba_id, [
        whatsappWabaChange('account_update', ['event' => 'PARTNER_REMOVED']),
    ]));

    expect(whatsappDiscordRecords($discord))->toBe([])
        ->and($connection->fresh()->status)->toBe(WhatsAppConnection::STATUS_DISCONNECTED);
});
