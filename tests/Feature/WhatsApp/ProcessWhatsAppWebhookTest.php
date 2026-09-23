<?php

use App\Jobs\ProcessWhatsAppWebhook;
use App\Models\Company;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppTemplate;
use App\Services\Messaging\WhatsAppWebhookProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.meta.app_id' => '111222333',
        'services.whatsapp.platform.waba_id' => null,
        'services.whatsapp.platform.phone_number_id' => null,
        'services.whatsapp.platform.token' => null,
    ]);
});

function whatsappConn(array $attributes = []): WhatsAppConnection
{
    return WhatsAppConnection::factory()->create(array_merge([
        'waba_id' => 'waba-'.uniqid(),
        'phone_number_id' => 'phone-'.uniqid(),
    ], $attributes));
}

/** Conexão em templates_pending com todos os templates aprovados, exceto os informados. */
function whatsappPendingConn(array $exceptEvents = ['refunded']): WhatsAppConnection
{
    $connection = whatsappConn(['status' => WhatsAppConnection::STATUS_TEMPLATES_PENDING]);

    foreach (array_keys(config('whatsapp_templates.templates')) as $event) {
        WhatsAppTemplate::factory()->forEvent($event)
            ->status(in_array($event, $exceptEvents, true) ? WhatsAppTemplate::STATUS_PENDING : WhatsAppTemplate::STATUS_APPROVED)
            ->create(['whatsapp_connection_id' => $connection->id]);
    }

    return $connection;
}

// ── Estrutura do job ──────────────────────────────────────────────────────────

test('o job roda na fila whatsapp e reprocessar é seguro (tries e backoff)', function () {
    $job = new ProcessWhatsAppWebhook(['object' => 'whatsapp_business_account']);

    expect($job->queue)->toBe('whatsapp')
        ->and($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([10, 60, 300]);
});

// ── statuses ──────────────────────────────────────────────────────────────────

test('status avança de sent para delivered e read gravando os horários', function () {
    $connection = whatsappConn();
    $message = whatsappSentMessage($connection, 'wamid.PROG');
    $t0 = now()->subMinutes(5)->getTimestamp();

    processWhatsAppWebhook(whatsappWebhookPayload($connection->waba_id, [
        whatsappStatusChange($connection->phone_number_id, 'wamid.PROG', 'delivered', $t0 + 10),
    ]));

    $message->refresh();
    expect($message->status)->toBe('delivered')
        ->and($message->delivered_at->getTimestamp())->toBe($t0 + 10);

    processWhatsAppWebhook(whatsappWebhookPayload($connection->waba_id, [
        whatsappStatusChange($connection->phone_number_id, 'wamid.PROG', 'read', $t0 + 60),
    ]));

    $message->refresh();
    expect($message->status)->toBe('read')
        ->and($message->read_at->getTimestamp())->toBe($t0 + 60)
        ->and($message->delivered_at->getTimestamp())->toBe($t0 + 10);
});

test('horários do webhook são gravados no fuso da aplicação', function () {
    config(['app.timezone' => 'America/Sao_Paulo']);

    $connection = whatsappConn();
    $message = whatsappSentMessage($connection, 'wamid.TZ');
    $timestamp = 1790000000;

    processWhatsAppWebhook(whatsappWebhookPayload($connection->waba_id, [
        whatsappStatusChange($connection->phone_number_id, 'wamid.TZ', 'delivered', $timestamp),
    ]));

    // O instante absoluto tem que ser o do webhook, independente do fuso de gravação.
    expect($message->fresh()->delivered_at->getTimestamp())->toBe($timestamp);
});

test('status nunca regride: delivered ou sent atrasados não sobrescrevem read', function (string $late) {
    $connection = whatsappConn();
    $message = whatsappSentMessage($connection, 'wamid.REG');
    $message->update([
        'status' => 'read',
        'delivered_at' => now()->subMinutes(10),
        'read_at' => now()->subMinutes(5),
    ]);
    $readAt = $message->fresh()->read_at;
    $deliveredAt = $message->fresh()->delivered_at;

    processWhatsAppWebhook(whatsappWebhookPayload($connection->waba_id, [
        whatsappStatusChange($connection->phone_number_id, 'wamid.REG', $late, now()->getTimestamp()),
    ]));

    $message->refresh();
    expect($message->status)->toBe('read')
        ->and($message->read_at->equalTo($readAt))->toBeTrue()
        ->and($message->delivered_at->equalTo($deliveredAt))->toBeTrue();
})->with(['sent', 'delivered']);

test('read sem o delivered anterior também preenche delivered_at', function () {
    $connection = whatsappConn();
    $message = whatsappSentMessage($connection, 'wamid.SEMDELIV');

    processWhatsAppWebhook(whatsappWebhookPayload($connection->waba_id, [
        whatsappStatusChange($connection->phone_number_id, 'wamid.SEMDELIV', 'read'),
    ]));

    $message->refresh();
    expect($message->status)->toBe('read')
        ->and($message->delivered_at)->not->toBeNull()
        ->and($message->read_at)->not->toBeNull();
});

test('status é idempotente: o mesmo webhook duas vezes dá o mesmo resultado', function () {
    $connection = whatsappConn();
    $message = whatsappSentMessage($connection, 'wamid.IDEM');
    $payload = whatsappWebhookPayload($connection->waba_id, [
        whatsappStatusChange($connection->phone_number_id, 'wamid.IDEM', 'delivered', 1790000000),
    ]);

    processWhatsAppWebhook($payload);
    $first = $message->fresh()->toArray();

    processWhatsAppWebhook($payload);

    expect($message->fresh()->toArray())->toBe($first);
});

test('failed grava código e motivo da Meta', function () {
    $connection = whatsappConn();
    $message = whatsappSentMessage($connection, 'wamid.FAIL');

    processWhatsAppWebhook(whatsappWebhookPayload($connection->waba_id, [
        whatsappStatusChange($connection->phone_number_id, 'wamid.FAIL', 'failed', null, [
            'errors' => [[
                'code' => 131026,
                'title' => 'Message undeliverable',
                'message' => 'Message undeliverable',
                'error_data' => ['details' => 'Unable to deliver the message. Phone number is not on WhatsApp.'],
            ]],
        ]),
    ]));

    $message->refresh();
    expect($message->status)->toBe('failed')
        ->and($message->error_code)->toBe('131026')
        ->and($message->error_message)->toContain('Message undeliverable')
        ->and($message->error_message)->toContain('not on WhatsApp');
});

test('failed sem detalhes ainda marca a mensagem com texto padrão', function () {
    $connection = whatsappConn();
    $message = whatsappSentMessage($connection, 'wamid.FAILSEM');

    processWhatsAppWebhook(whatsappWebhookPayload($connection->waba_id, [
        whatsappStatusChange($connection->phone_number_id, 'wamid.FAILSEM', 'failed'),
    ]));

    $message->refresh();
    expect($message->status)->toBe('failed')
        ->and($message->error_code)->toBeNull()
        ->and($message->error_message)->not->toBeEmpty();
});

test('failed não sobrescreve mensagem já entregue ou lida', function (string $status) {
    $connection = whatsappConn();
    $message = whatsappSentMessage($connection, 'wamid.FAILATRASADO');
    $message->update(['status' => $status]);

    processWhatsAppWebhook(whatsappWebhookPayload($connection->waba_id, [
        whatsappStatusChange($connection->phone_number_id, 'wamid.FAILATRASADO', 'failed', null, ['errors' => [['code' => 131000]]]),
    ]));

    expect($message->fresh()->status)->toBe($status);
})->with(['delivered', 'read']);

test('failed é terminal: delivered depois de failed não reabre a mensagem', function () {
    $connection = whatsappConn();
    $message = whatsappSentMessage($connection, 'wamid.TERMINAL');

    processWhatsAppWebhook(whatsappWebhookPayload($connection->waba_id, [
        whatsappStatusChange($connection->phone_number_id, 'wamid.TERMINAL', 'failed', null, ['errors' => [['code' => 131026]]]),
        whatsappStatusChange($connection->phone_number_id, 'wamid.TERMINAL', 'delivered'),
    ]));

    expect($message->fresh()->status)->toBe('failed');
});

test('status de mensagem desconhecida é ignorado sem erro', function () {
    $connection = whatsappConn();

    processWhatsAppWebhook(whatsappWebhookPayload($connection->waba_id, [
        whatsappStatusChange($connection->phone_number_id, 'wamid.NAOEXISTE', 'delivered'),
    ]));

    expect(WhatsAppMessage::withoutGlobalScopes()->count())->toBe(0);
});

test('status desconhecido da Meta (ex.: deleted) é ignorado', function () {
    $connection = whatsappConn();
    $message = whatsappSentMessage($connection, 'wamid.ESTRANHO');

    processWhatsAppWebhook(whatsappWebhookPayload($connection->waba_id, [
        whatsappStatusChange($connection->phone_number_id, 'wamid.ESTRANHO', 'deleted'),
    ]));

    expect($message->fresh()->status)->toBe('sent');
});

test('isolamento: status recebido pela conexão A não altera mensagem da empresa B', function () {
    $connectionA = whatsappConn();
    $connectionB = whatsappConn();
    $messageB = whatsappSentMessage($connectionB, 'wamid.DAEMPRESAB');
    $handler = whatsappCaptureLogs();

    processWhatsAppWebhook(whatsappWebhookPayload($connectionA->waba_id, [
        whatsappStatusChange($connectionA->phone_number_id, 'wamid.DAEMPRESAB', 'read'),
    ]));

    expect($messageB->fresh()->status)->toBe('sent')
        ->and($handler->hasWarningThatContains('outra conexão'))->toBeTrue();
});

test('resolve a conexão pelo waba_id quando o evento não traz metadata', function () {
    $connection = whatsappConn();
    $message = whatsappSentMessage($connection, 'wamid.PORWABA');

    $change = whatsappStatusChange('qualquer', 'wamid.PORWABA', 'delivered');
    unset($change['value']['metadata']);

    processWhatsAppWebhook(whatsappWebhookPayload($connection->waba_id, [$change]));

    expect($message->fresh()->status)->toBe('delivered');
});

test('conexão desconectada ainda recebe status das mensagens antigas', function () {
    $connection = whatsappConn(['status' => WhatsAppConnection::STATUS_DISCONNECTED]);
    $message = whatsappSentMessage($connection, 'wamid.ANTIGA');

    processWhatsAppWebhook(whatsappWebhookPayload($connection->waba_id, [
        whatsappStatusChange($connection->phone_number_id, 'wamid.ANTIGA', 'read'),
    ]));

    expect($message->fresh()->status)->toBe('read');
});

// ── número da plataforma ──────────────────────────────────────────────────────

test('plataforma: status do número da plataforma atualiza mensagens sem conexão', function () {
    config([
        'services.whatsapp.platform.waba_id' => 'waba-plataforma',
        'services.whatsapp.platform.phone_number_id' => 'phone-plataforma',
    ]);

    $company = Company::create(['name' => 'Fallback', 'slug' => 'fallback', 'order_prefix' => 'FB', 'active' => true]);
    $message = whatsappSentMessage(null, 'wamid.PLAT', $company->id);

    processWhatsAppWebhook(whatsappWebhookPayload('waba-plataforma', [
        whatsappStatusChange('phone-plataforma', 'wamid.PLAT', 'delivered'),
    ]));

    expect($message->fresh()->status)->toBe('delivered');
});

test('plataforma: webhook do número da plataforma não altera mensagem de conexão de restaurante', function () {
    config([
        'services.whatsapp.platform.waba_id' => 'waba-plataforma',
        'services.whatsapp.platform.phone_number_id' => 'phone-plataforma',
    ]);

    $connection = whatsappConn();
    $message = whatsappSentMessage($connection, 'wamid.DORESTAURANTE');

    processWhatsAppWebhook(whatsappWebhookPayload('waba-plataforma', [
        whatsappStatusChange('phone-plataforma', 'wamid.DORESTAURANTE', 'read'),
    ]));

    expect($message->fresh()->status)->toBe('sent');
});

// ── opt-out ───────────────────────────────────────────────────────────────────

test('PARAR grava o opt-out do cliente da empresa dona da conexão', function () {
    $connection = whatsappConn();
    $customer = whatsappCustomer($connection->company, '11999990001');

    processWhatsAppWebhook(whatsappWebhookPayload($connection->waba_id, [
        whatsappIncomingChange($connection->phone_number_id, '5511999990001', 'PARAR'),
    ]));

    $customer->refresh();
    expect($customer->whatsapp_opt_out_at)->not->toBeNull()
        ->and($customer->canReceiveWhatsApp())->toBeFalse();
});

test('reconhece variações de PARAR, SAIR, STOP e CANCELAR', function (string $text) {
    $connection = whatsappConn();
    $customer = whatsappCustomer($connection->company);

    processWhatsAppWebhook(whatsappWebhookPayload($connection->waba_id, [
        whatsappIncomingChange($connection->phone_number_id, '5511999990001', $text),
    ]));

    expect($customer->fresh()->whatsapp_opt_out_at)->not->toBeNull();
})->with([
    'PARAR', 'parar', 'Parar!', '  sair  ', 'SAIR.', 'stop', 'STOP', 'Stop!!', 'cancelar', 'Cancelar', 'CANCELAR ',
]);

test('outras mensagens não geram opt-out', function (string $text) {
    $connection = whatsappConn();
    $customer = whatsappCustomer($connection->company);

    processWhatsAppWebhook(whatsappWebhookPayload($connection->waba_id, [
        whatsappIncomingChange($connection->phone_number_id, '5511999990001', $text),
    ]));

    expect($customer->fresh()->whatsapp_opt_out_at)->toBeNull();
})->with([
    'Quero 2 coxinhas', 'oi', 'Não quero parar de receber', 'quero cancelar meu pedido', 'pare', 'sair de casa agora', '',
]);

test('mensagem que não é texto nunca gera opt-out', function (string $type) {
    $connection = whatsappConn();
    $customer = whatsappCustomer($connection->company);

    processWhatsAppWebhook(whatsappWebhookPayload($connection->waba_id, [
        whatsappIncomingChange($connection->phone_number_id, '5511999990001', 'PARAR', $type),
    ]));

    expect($customer->fresh()->whatsapp_opt_out_at)->toBeNull();
})->with(['image', 'audio', 'sticker']);

test('opt-out é idempotente e preserva o primeiro registro', function () {
    $connection = whatsappConn();
    $customer = whatsappCustomer($connection->company);
    $primeiro = now()->subDays(3)->startOfSecond();
    $customer->update(['whatsapp_opt_out_at' => $primeiro]);

    processWhatsAppWebhook(whatsappWebhookPayload($connection->waba_id, [
        whatsappIncomingChange($connection->phone_number_id, '5511999990001', 'PARAR'),
    ]));

    expect($customer->fresh()->whatsapp_opt_out_at->equalTo($primeiro))->toBeTrue();
});

test('isolamento: opt-out na empresa A não afeta o mesmo telefone na empresa B', function () {
    $connectionA = whatsappConn();
    $connectionB = whatsappConn();
    $clienteA = whatsappCustomer($connectionA->company, '11999990001');
    $clienteB = whatsappCustomer($connectionB->company, '11999990001');

    processWhatsAppWebhook(whatsappWebhookPayload($connectionA->waba_id, [
        whatsappIncomingChange($connectionA->phone_number_id, '5511999990001', 'PARAR'),
    ]));

    expect($clienteA->fresh()->whatsapp_opt_out_at)->not->toBeNull()
        ->and($clienteB->fresh()->whatsapp_opt_out_at)->toBeNull();
});

test('opt-out só atinge o telefone que respondeu', function () {
    $connection = whatsappConn();
    $quemRespondeu = whatsappCustomer($connection->company, '11999990001');
    $outro = whatsappCustomer($connection->company, '11988887777');

    processWhatsAppWebhook(whatsappWebhookPayload($connection->waba_id, [
        whatsappIncomingChange($connection->phone_number_id, '5511999990001', 'SAIR'),
    ]));

    expect($quemRespondeu->fresh()->whatsapp_opt_out_at)->not->toBeNull()
        ->and($outro->fresh()->whatsapp_opt_out_at)->toBeNull();
});

test('wa_id brasileiro sem o 9º dígito encontra o cliente cadastrado com 9', function () {
    $connection = whatsappConn();
    $customer = whatsappCustomer($connection->company, '11999990001');

    processWhatsAppWebhook(whatsappWebhookPayload($connection->waba_id, [
        whatsappIncomingChange($connection->phone_number_id, '551199990001', 'PARAR'),
    ]));

    expect($customer->fresh()->whatsapp_opt_out_at)->not->toBeNull();
});

test('plataforma: PARAR no número da plataforma descadastra o cliente nas empresas que enviaram por ele', function () {
    config([
        'services.whatsapp.platform.waba_id' => 'waba-plataforma',
        'services.whatsapp.platform.phone_number_id' => 'phone-plataforma',
    ]);

    $empresaQueEnviou = Company::create(['name' => 'Enviou', 'slug' => 'enviou', 'order_prefix' => 'ENV', 'active' => true]);
    $empresaSemEnvio = Company::create(['name' => 'Sem envio', 'slug' => 'sem-envio', 'order_prefix' => 'SEM', 'active' => true]);
    $clienteEnviou = whatsappCustomer($empresaQueEnviou);
    $clienteSemEnvio = whatsappCustomer($empresaSemEnvio);

    whatsappSentMessage(null, 'wamid.PLATAFORMA1', $empresaQueEnviou->id, '5511999990001');

    processWhatsAppWebhook(whatsappWebhookPayload('waba-plataforma', [
        whatsappIncomingChange('phone-plataforma', '5511999990001', 'PARAR'),
    ]));

    expect($clienteEnviou->fresh()->whatsapp_opt_out_at)->not->toBeNull()
        ->and($clienteSemEnvio->fresh()->whatsapp_opt_out_at)->toBeNull();
});

test('plataforma: mensagem enviada por conexão de restaurante não conta para o opt-out da plataforma', function () {
    config([
        'services.whatsapp.platform.waba_id' => 'waba-plataforma',
        'services.whatsapp.platform.phone_number_id' => 'phone-plataforma',
    ]);

    $connection = whatsappConn();
    $cliente = whatsappCustomer($connection->company);
    whatsappSentMessage($connection, 'wamid.DORESTAURANTE2', null, '5511999990001');

    processWhatsAppWebhook(whatsappWebhookPayload('waba-plataforma', [
        whatsappIncomingChange('phone-plataforma', '5511999990001', 'PARAR'),
    ]));

    expect($cliente->fresh()->whatsapp_opt_out_at)->toBeNull();
});

test('mensagem recebida é registrada sem o texto do cliente', function () {
    $handler = whatsappCaptureLogs();
    $connection = whatsappConn();

    processWhatsAppWebhook(whatsappWebhookPayload($connection->waba_id, [
        whatsappIncomingChange($connection->phone_number_id, '5511999990001', 'Quero 2 coxinhas com catupiry'),
    ]));

    $logged = json_encode($handler->getRecords());

    expect($handler->hasInfoThatContains('mensagem recebida do cliente'))->toBeTrue()
        ->and($logged)->not->toContain('coxinhas')
        ->and($logged)->not->toContain('5511999990001');
});

test('opt-out impede notificações seguintes do cliente', function () {
    ['order' => $order, 'connection' => $connection, 'customer' => $customer] = whatsappOrderContext();
    $connection->update(['waba_id' => 'waba-e2e', 'phone_number_id' => 'phone-e2e']);

    expect($customer->fresh()->canReceiveWhatsApp())->toBeTrue();

    processWhatsAppWebhook(whatsappWebhookPayload('waba-e2e', [
        whatsappIncomingChange('phone-e2e', '5511999990001', 'PARAR'),
    ]));

    expect($customer->fresh()->canReceiveWhatsApp())->toBeFalse()
        ->and(app(App\Services\Messaging\WhatsAppService::class)->shouldNotify($order->fresh(), 'preparing'))->toBeFalse();
});

// ── message_template_status_update ────────────────────────────────────────────

test('aprovação do último template ativa a conexão', function () {
    $connection = whatsappPendingConn(['refunded']);

    expect($connection->status)->toBe('templates_pending');

    processWhatsAppWebhook(whatsappWebhookPayload($connection->waba_id, [
        whatsappTemplateChange('APPROVED', 'pedido_reembolsado', 'pt_BR', 'NONE', 555001),
    ]));

    $connection->refresh();
    $template = $connection->templates()->where('event', 'refunded')->first();

    expect($connection->status)->toBe('active')
        ->and($connection->connected_at)->not->toBeNull()
        ->and($connection->last_error)->toBeNull()
        ->and($template->status)->toBe('APPROVED')
        ->and($template->meta_template_id)->toBe('555001')
        ->and($template->rejection_reason)->toBeNull();
});

test('enquanto faltar template aprovado a conexão continua em templates_pending', function () {
    $connection = whatsappPendingConn(['refunded', 'cancelled']);

    processWhatsAppWebhook(whatsappWebhookPayload($connection->waba_id, [
        whatsappTemplateChange('APPROVED', 'pedido_reembolsado'),
    ]));

    expect($connection->fresh()->status)->toBe('templates_pending');
});

test('ativação é idempotente e não mexe em conexão que já está ativa', function () {
    $connection = whatsappPendingConn(['refunded']);
    $payload = whatsappWebhookPayload($connection->waba_id, [whatsappTemplateChange('APPROVED', 'pedido_reembolsado')]);

    processWhatsAppWebhook($payload);
    $connectedAt = $connection->fresh()->connected_at;

    processWhatsAppWebhook($payload);

    expect($connection->fresh()->status)->toBe('active')
        ->and($connection->fresh()->connected_at->equalTo($connectedAt))->toBeTrue();
});

test('aprovação não ativa conexão que não está em templates_pending', function (string $status) {
    $connection = whatsappPendingConn(['refunded']);
    $connection->update(['status' => $status]);

    processWhatsAppWebhook(whatsappWebhookPayload($connection->waba_id, [
        whatsappTemplateChange('APPROVED', 'pedido_reembolsado'),
    ]));

    expect($connection->fresh()->status)->toBe($status)
        ->and($connection->templates()->where('event', 'refunded')->first()->status)->toBe('APPROVED');
})->with(['provisioning', 'error', 'disconnected', 'pending']);

test('rejeição grava o motivo e mantém a conexão aguardando', function () {
    $connection = whatsappPendingConn(['refunded']);

    processWhatsAppWebhook(whatsappWebhookPayload($connection->waba_id, [
        whatsappTemplateChange('REJECTED', 'pedido_reembolsado', 'pt_BR', 'INCORRECT_CATEGORY'),
    ]));

    $template = $connection->templates()->where('event', 'refunded')->first();

    expect($template->status)->toBe('REJECTED')
        ->and($template->rejection_reason)->toBe('INCORRECT_CATEGORY')
        ->and($connection->fresh()->status)->toBe('templates_pending');
});

test('aprovação depois de rejeição limpa o motivo', function () {
    $connection = whatsappPendingConn(['refunded']);

    processWhatsAppWebhook(whatsappWebhookPayload($connection->waba_id, [
        whatsappTemplateChange('REJECTED', 'pedido_reembolsado', 'pt_BR', 'INVALID_FORMAT'),
    ]));
    processWhatsAppWebhook(whatsappWebhookPayload($connection->waba_id, [
        whatsappTemplateChange('APPROVED', 'pedido_reembolsado'),
    ]));

    expect($connection->templates()->where('event', 'refunded')->first()->rejection_reason)->toBeNull();
});

test('eventos de template mapeiam para o status certo', function (string $event, string $expected) {
    $connection = whatsappPendingConn([]);

    processWhatsAppWebhook(whatsappWebhookPayload($connection->waba_id, [
        whatsappTemplateChange($event, 'pedido_em_preparo'),
    ]));

    expect($connection->templates()->where('event', 'preparing')->first()->status)->toBe($expected);
})->with([
    ['PAUSED', 'PAUSED'],
    ['DISABLED', 'DISABLED'],
    ['DELETED', 'DISABLED'],
    ['PENDING_DELETION', 'DISABLED'],
    ['REJECTED', 'REJECTED'],
    ['PENDING', 'PENDING'],
    ['IN_APPEAL', 'PENDING'],
    ['REINSTATED', 'APPROVED'],
]);

test('template aprovado que vira PAUSED deixa de estar aprovado, sem derrubar a conexão', function () {
    $connection = whatsappConn();
    $template = WhatsAppTemplate::factory()->forEvent('preparing')->create(['whatsapp_connection_id' => $connection->id]);

    processWhatsAppWebhook(whatsappWebhookPayload($connection->waba_id, [
        whatsappTemplateChange('PAUSED', 'pedido_em_preparo'),
    ]));

    expect($connection->fresh()->approvedTemplate('preparing'))->toBeNull()
        ->and($connection->fresh()->status)->toBe('active');
});

test('eventos de template sem efeito (FLAGGED) não alteram nada', function () {
    $connection = whatsappPendingConn([]);

    processWhatsAppWebhook(whatsappWebhookPayload($connection->waba_id, [
        whatsappTemplateChange('FLAGGED', 'pedido_em_preparo'),
    ]));

    expect($connection->templates()->where('event', 'preparing')->first()->status)->toBe('APPROVED');
});

test('template que não é nosso não é criado nem alterado', function () {
    $connection = whatsappConn();

    processWhatsAppWebhook(whatsappWebhookPayload($connection->waba_id, [
        whatsappTemplateChange('APPROVED', 'promocao_de_natal'),
    ]));

    expect(WhatsAppTemplate::count())->toBe(0);
});

test('template nosso que ainda não tinha linha é criado (webhook antes do provisionamento)', function () {
    $connection = whatsappConn(['status' => WhatsAppConnection::STATUS_PROVISIONING]);

    processWhatsAppWebhook(whatsappWebhookPayload($connection->waba_id, [
        whatsappTemplateChange('APPROVED', 'pedido_recebido', 'pt_BR', 'NONE', 777),
    ]));

    $template = $connection->templates()->first();

    expect($template->event)->toBe('new_order')
        ->and($template->name)->toBe('pedido_recebido')
        ->and($template->status)->toBe('APPROVED')
        ->and($template->meta_template_id)->toBe('777');
});

test('idioma diferente do cadastrado não altera o template em pt_BR', function () {
    $connection = whatsappPendingConn([]);

    processWhatsAppWebhook(whatsappWebhookPayload($connection->waba_id, [
        whatsappTemplateChange('DISABLED', 'pedido_em_preparo', 'en_US'),
    ]));

    expect($connection->templates()->where('event', 'preparing')->where('language', 'pt_BR')->first()->status)->toBe('APPROVED');
});

test('isolamento: template da WABA A não altera nem ativa a conexão da empresa B', function () {
    $connectionA = whatsappPendingConn(['refunded']);
    $connectionB = whatsappPendingConn(['refunded']);

    processWhatsAppWebhook(whatsappWebhookPayload($connectionA->waba_id, [
        whatsappTemplateChange('APPROVED', 'pedido_reembolsado'),
    ]));

    expect($connectionA->fresh()->status)->toBe('active')
        ->and($connectionB->fresh()->status)->toBe('templates_pending')
        ->and($connectionB->templates()->where('event', 'refunded')->first()->status)->toBe('PENDING');
});

// ── qualidade e limites ───────────────────────────────────────────────────────

test('atualização de limite grava o tier da conexão', function () {
    $connection = whatsappConn(['messaging_limit_tier' => 'TIER_250']);

    processWhatsAppWebhook(whatsappWebhookPayload($connection->waba_id, [
        whatsappWabaChange('phone_number_quality_update', [
            'display_phone_number' => '551199990001',
            'event' => 'UPGRADE',
            'current_limit' => 'TIER_1K',
        ]),
    ]));

    expect($connection->fresh()->messaging_limit_tier)->toBe('TIER_1K');
});

test('qualidade informada explicitamente é gravada em maiúsculas', function () {
    $connection = whatsappConn(['quality_rating' => 'GREEN']);

    processWhatsAppWebhook(whatsappWebhookPayload($connection->waba_id, [
        whatsappWabaChange('phone_number_quality_update', ['event' => 'DOWNGRADE', 'quality_rating' => 'yellow', 'current_limit' => 'TIER_250']),
    ]));

    expect($connection->fresh()->quality_rating)->toBe('YELLOW');
});

test('qualidade inválida é ignorada', function () {
    $connection = whatsappConn(['quality_rating' => 'GREEN']);

    processWhatsAppWebhook(whatsappWebhookPayload($connection->waba_id, [
        whatsappWabaChange('phone_number_quality_update', ['quality_rating' => 'ROXO']),
    ]));

    expect($connection->fresh()->quality_rating)->toBe('GREEN');
});

test('FLAGGED marca qualidade RED e UNFLAGGED tira do vermelho', function () {
    $connection = whatsappConn(['quality_rating' => 'GREEN']);

    processWhatsAppWebhook(whatsappWebhookPayload($connection->waba_id, [
        whatsappWabaChange('phone_number_quality_update', ['event' => 'FLAGGED', 'current_limit' => 'TIER_250']),
    ]));
    expect($connection->fresh()->quality_rating)->toBe('RED');

    processWhatsAppWebhook(whatsappWebhookPayload($connection->waba_id, [
        whatsappWabaChange('phone_number_quality_update', ['event' => 'UNFLAGGED']),
    ]));
    expect($connection->fresh()->quality_rating)->toBe('YELLOW');
});

test('UNFLAGGED não altera qualidade que não estava vermelha', function () {
    $connection = whatsappConn(['quality_rating' => 'GREEN']);

    processWhatsAppWebhook(whatsappWebhookPayload($connection->waba_id, [
        whatsappWabaChange('phone_number_quality_update', ['event' => 'UNFLAGGED']),
    ]));

    expect($connection->fresh()->quality_rating)->toBe('GREEN');
});

test('account_alerts com limite atualiza o tier e não derruba a conexão', function () {
    $connection = whatsappConn();

    processWhatsAppWebhook(whatsappWebhookPayload($connection->waba_id, [
        whatsappWabaChange('account_alerts', [
            'entity_type' => 'WABA',
            'alert_severity' => 'CRITICAL',
            'alert_status' => 'ACTIVE',
            'alert_type' => 'OBA_APPROVED',
            'current_limit' => 'TIER_10K',
        ]),
    ]));

    expect($connection->fresh()->messaging_limit_tier)->toBe('TIER_10K')
        ->and($connection->fresh()->status)->toBe('active');
});

test('isolamento: qualidade da WABA A não altera a conexão B', function () {
    $connectionA = whatsappConn(['quality_rating' => 'GREEN']);
    $connectionB = whatsappConn(['quality_rating' => 'GREEN']);

    processWhatsAppWebhook(whatsappWebhookPayload($connectionA->waba_id, [
        whatsappWabaChange('phone_number_quality_update', ['event' => 'FLAGGED']),
    ]));

    expect($connectionA->fresh()->quality_rating)->toBe('RED')
        ->and($connectionB->fresh()->quality_rating)->toBe('GREEN');
});

// ── account_update: banimento e desconexão ────────────────────────────────────

test('banimento da WABA coloca a conexão em erro com a causa', function () {
    $connection = whatsappConn();

    processWhatsAppWebhook(whatsappWebhookPayload($connection->waba_id, [
        whatsappWabaChange('account_update', [
            'phone_number' => '551199990001',
            'event' => 'DISABLED_UPDATE',
            'ban_info' => ['waba_ban_state' => 'DISABLE', 'waba_ban_date' => '2026-09-23'],
        ]),
    ]));

    $connection->refresh();
    expect($connection->status)->toBe('error')
        ->and($connection->last_error)->toBe(WhatsAppWebhookProcessor::BAN_MESSAGE);
});

test('banimento agendado (SCHEDULE_FOR_DISABLE) não derruba a conexão ainda', function () {
    $connection = whatsappConn();

    processWhatsAppWebhook(whatsappWebhookPayload($connection->waba_id, [
        whatsappWabaChange('account_update', ['event' => 'DISABLED_UPDATE', 'ban_info' => ['waba_ban_state' => 'SCHEDULE_FOR_DISABLE']]),
    ]));

    expect($connection->fresh()->status)->toBe('active');
});

test('reintegração da WABA reverte o erro de banimento', function () {
    $connection = whatsappPendingConn([]);
    $connection->update(['status' => WhatsAppConnection::STATUS_ACTIVE]);

    $ban = whatsappWabaChange('account_update', ['event' => 'DISABLED_UPDATE', 'ban_info' => ['waba_ban_state' => 'DISABLE']]);
    $reinstate = whatsappWabaChange('account_update', ['event' => 'DISABLED_UPDATE', 'ban_info' => ['waba_ban_state' => 'REINSTATE']]);

    processWhatsAppWebhook(whatsappWebhookPayload($connection->waba_id, [$ban]));
    expect($connection->fresh()->status)->toBe('error');

    processWhatsAppWebhook(whatsappWebhookPayload($connection->waba_id, [$reinstate]));

    $connection->refresh();
    expect($connection->status)->toBe('active')
        ->and($connection->last_error)->toBeNull();
});

test('reintegração não apaga um erro que não foi causado pelo banimento', function () {
    $connection = whatsappConn(['status' => WhatsAppConnection::STATUS_ERROR, 'last_error' => 'Autorização do WhatsApp inválida ou expirada (código 190). Reconecte o número.']);

    processWhatsAppWebhook(whatsappWebhookPayload($connection->waba_id, [
        whatsappWabaChange('account_update', ['event' => 'DISABLED_UPDATE', 'ban_info' => ['waba_ban_state' => 'REINSTATE']]),
    ]));

    $connection->refresh();
    expect($connection->status)->toBe('error')
        ->and($connection->last_error)->toContain('190');
});

test('exclusão da conta e remoção do parceiro desconectam a conexão', function (string $event, string $message) {
    $connection = whatsappConn();

    processWhatsAppWebhook(whatsappWebhookPayload($connection->waba_id, [
        whatsappWabaChange('account_update', ['event' => $event]),
    ]));

    $connection->refresh();
    expect($connection->status)->toBe('disconnected')
        ->and($connection->disconnected_at)->not->toBeNull()
        ->and($connection->last_error)->toBe($message);
})->with([
    ['ACCOUNT_DELETED', WhatsAppWebhookProcessor::DELETED_ACCOUNT_MESSAGE],
    ['PARTNER_REMOVED', WhatsAppWebhookProcessor::REMOVED_PARTNER_MESSAGE],
]);

test('remoção de OUTRO parceiro da WABA não desconecta a conexão', function () {
    $connection = whatsappConn();

    processWhatsAppWebhook(whatsappWebhookPayload($connection->waba_id, [
        whatsappWabaChange('account_update', [
            'event' => 'PARTNER_REMOVED',
            'waba_info' => ['waba_id' => $connection->waba_id, 'partner_app_id' => '999888777'],
        ]),
    ]));

    expect($connection->fresh()->status)->toBe('active');
});

test('remoção do parceiro com o app id da Veddi desconecta', function () {
    $connection = whatsappConn();

    processWhatsAppWebhook(whatsappWebhookPayload($connection->waba_id, [
        whatsappWabaChange('account_update', [
            'event' => 'PARTNER_REMOVED',
            'waba_info' => ['waba_id' => $connection->waba_id, 'partner_app_id' => '111222333'],
        ]),
    ]));

    expect($connection->fresh()->status)->toBe('disconnected');
});

test('remoção de número só desconecta se for o número da conexão', function () {
    $connection = whatsappConn(['display_phone_number' => '+55 11 99999-0001']);

    processWhatsAppWebhook(whatsappWebhookPayload($connection->waba_id, [
        whatsappWabaChange('account_update', ['event' => 'PHONE_NUMBER_REMOVED', 'phone_number' => '5511988887777']),
    ]));
    expect($connection->fresh()->status)->toBe('active');

    processWhatsAppWebhook(whatsappWebhookPayload($connection->waba_id, [
        whatsappWabaChange('account_update', ['event' => 'PHONE_NUMBER_REMOVED', 'phone_number' => '5511999990001']),
    ]));
    expect($connection->fresh()->status)->toBe('disconnected');
});

test('eventos de conta sem efeito só são registrados', function () {
    $connection = whatsappConn();

    processWhatsAppWebhook(whatsappWebhookPayload($connection->waba_id, [
        whatsappWabaChange('account_update', ['event' => 'VERIFIED_ACCOUNT']),
    ]));

    expect($connection->fresh()->status)->toBe('active');
});

test('isolamento: banimento da WABA A não afeta a conexão B', function () {
    $connectionA = whatsappConn();
    $connectionB = whatsappConn();

    processWhatsAppWebhook(whatsappWebhookPayload($connectionA->waba_id, [
        whatsappWabaChange('account_update', ['event' => 'DISABLED_UPDATE', 'ban_info' => ['waba_ban_state' => 'DISABLE']]),
    ]));

    expect($connectionA->fresh()->status)->toBe('error')
        ->and($connectionB->fresh()->status)->toBe('active');
});

// ── coexistência ──────────────────────────────────────────────────────────────

test('eco de mensagem do app atualiza last_app_activity_at sem processar o conteúdo', function () {
    $handler = whatsappCaptureLogs();
    $connection = whatsappConn(['onboarding_type' => 'coexistence', 'last_app_activity_at' => now()->subDays(10)]);
    $timestamp = now()->subMinute()->getTimestamp();

    processWhatsAppWebhook(whatsappWebhookPayload($connection->waba_id, [[
        'field' => 'smb_message_echoes',
        'value' => [
            'messaging_product' => 'whatsapp',
            'metadata' => whatsappMetadata($connection->phone_number_id),
            'message_echoes' => [[
                'from' => '551199990001', 'to' => '5511988887777', 'id' => 'wamid.ECO',
                'timestamp' => (string) $timestamp, 'type' => 'text', 'text' => ['body' => 'Segredo do atendente'],
            ]],
        ],
    ]]));

    expect($connection->fresh()->last_app_activity_at->getTimestamp())->toBe($timestamp)
        ->and(json_encode($handler->getRecords()))->not->toContain('Segredo do atendente');
});

test('eco antigo não regride last_app_activity_at', function () {
    $recente = now()->subHour()->startOfSecond();
    $connection = whatsappConn(['last_app_activity_at' => $recente]);

    processWhatsAppWebhook(whatsappWebhookPayload($connection->waba_id, [[
        'field' => 'smb_message_echoes',
        'value' => [
            'metadata' => whatsappMetadata($connection->phone_number_id),
            'message_echoes' => [['timestamp' => (string) now()->subDays(2)->getTimestamp()]],
        ],
    ]]));

    expect($connection->fresh()->last_app_activity_at->equalTo($recente))->toBeTrue();
});

test('isolamento: eco da conexão A não atualiza a conexão B', function () {
    $connectionA = whatsappConn(['last_app_activity_at' => null]);
    $connectionB = whatsappConn(['last_app_activity_at' => null]);

    processWhatsAppWebhook(whatsappWebhookPayload($connectionA->waba_id, [[
        'field' => 'smb_message_echoes',
        'value' => ['metadata' => whatsappMetadata($connectionA->phone_number_id), 'message_echoes' => [['timestamp' => (string) time()]]],
    ]]));

    expect($connectionA->fresh()->last_app_activity_at)->not->toBeNull()
        ->and($connectionB->fresh()->last_app_activity_at)->toBeNull();
});

test('history e smb_app_state_sync só são registrados nesta entrega', function (string $field) {
    $connection = whatsappConn();
    $before = $connection->fresh()->toArray();

    processWhatsAppWebhook(whatsappWebhookPayload($connection->waba_id, [[
        'field' => $field,
        'value' => ['metadata' => whatsappMetadata($connection->phone_number_id), 'history' => [['metadata' => ['phase' => 0]]]],
    ]]));

    expect($connection->fresh()->toArray())->toBe($before)
        ->and(WhatsAppMessage::withoutGlobalScopes()->count())->toBe(0);
})->with(['history', 'smb_app_state_sync']);

test('campo desconhecido é registrado sem erro', function () {
    $connection = whatsappConn();

    processWhatsAppWebhook(whatsappWebhookPayload($connection->waba_id, [
        whatsappWabaChange('campo_inventado_pela_meta', ['qualquer' => 'coisa']),
    ]));

    expect($connection->fresh()->status)->toBe('active');
});

// ── conexão desconhecida ──────────────────────────────────────────────────────

test('payload de WABA desconhecida é descartado com warning e não altera nada', function () {
    $handler = whatsappCaptureLogs();
    $connection = whatsappConn();
    $customer = whatsappCustomer($connection->company);
    $message = whatsappSentMessage($connection, 'wamid.INTOCADA');

    processWhatsAppWebhook(whatsappWebhookPayload('waba-desconhecida', [
        whatsappTemplateChange('REJECTED', 'pedido_em_preparo'),
        whatsappWabaChange('account_update', ['event' => 'ACCOUNT_DELETED']),
        whatsappWabaChange('phone_number_quality_update', ['event' => 'FLAGGED']),
    ]));

    processWhatsAppWebhook(whatsappWebhookPayload('waba-desconhecida', [
        whatsappStatusChange('phone-desconhecido', 'wamid.INTOCADA', 'read'),
        whatsappIncomingChange('phone-desconhecido', '5511999990001', 'PARAR'),
    ]));

    expect($handler->hasWarningThatContains('sem conexão conhecida'))->toBeTrue()
        ->and($connection->fresh()->status)->toBe('active')
        ->and($connection->fresh()->quality_rating)->toBe('GREEN')
        ->and($message->fresh()->status)->toBe('sent')
        ->and($customer->fresh()->whatsapp_opt_out_at)->toBeNull();
});

test('mudanças conhecidas e desconhecidas no mesmo payload: só as conhecidas são aplicadas', function () {
    $connection = whatsappConn();
    $message = whatsappSentMessage($connection, 'wamid.MISTO');

    processWhatsAppWebhook([
        'object' => 'whatsapp_business_account',
        'entry' => [
            ['id' => 'waba-desconhecida', 'changes' => [whatsappStatusChange('phone-desconhecido', 'wamid.MISTO', 'read')]],
            ['id' => $connection->waba_id, 'changes' => [whatsappStatusChange($connection->phone_number_id, 'wamid.MISTO', 'delivered')]],
        ],
    ]);

    expect($message->fresh()->status)->toBe('delivered');
});

test('payload malformado não gera exceção', function (array $payload) {
    processWhatsAppWebhook($payload);

    expect(true)->toBeTrue();
})->with([
    'vazio' => [[]],
    'entry não é lista' => [['entry' => 'x']],
    'entry sem changes' => [['entry' => [['id' => '1']]]],
    'change sem value' => [['entry' => [['id' => '1', 'changes' => [['field' => 'messages']]]]]],
    'change não é array' => [['entry' => [['id' => '1', 'changes' => ['x']]]]],
    'entry não é array' => [['entry' => ['x']]],
]);
