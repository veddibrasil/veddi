<?php

use App\Contracts\WhatsAppProviderInterface;
use App\Exceptions\WhatsAppRetryableException;
use App\Jobs\SendWhatsAppOrderNotificationJob;
use App\Models\Order;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Services\Messaging\WhatsAppService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();

    config([
        'services.meta.app_secret' => 'app-secret-teste',
        'services.meta.graph_version' => 'v25.0',
        'services.whatsapp.fallback_to_platform' => false,
    ]);
});

function runWhatsAppJob(Order $order, string $event = 'preparing'): void
{
    (new SendWhatsAppOrderNotificationJob($order->id, $event))
        ->handle(app(WhatsAppService::class), app(WhatsAppProviderInterface::class));
}

function whatsappMessageFor(Order $order, string $event = 'preparing'): ?WhatsAppMessage
{
    return WhatsAppMessage::withoutGlobalScopes()->where('order_id', $order->id)->where('event', $event)->first();
}

test('envio com sucesso grava wamid, status sent e usa a conexão do restaurante', function () {
    ['order' => $order, 'connection' => $connection] = whatsappOrderContext();

    Http::fake(['graph.facebook.com/*' => whatsappSendSuccess('wamid.SUCESSO')]);

    runWhatsAppJob($order);

    $message = whatsappMessageFor($order);

    expect($message)->not->toBeNull()
        ->and($message->status)->toBe('sent')
        ->and($message->wamid)->toBe('wamid.SUCESSO')
        ->and($message->sent_at)->not->toBeNull()
        ->and($message->to_phone)->toBe('5511999990001')
        ->and($message->template)->toBe('pedido_em_preparo')
        ->and($message->whatsapp_connection_id)->toBe($connection->id)
        ->and($message->company_id)->toBe($order->company_id);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), "/{$connection->phone_number_id}/messages")
        && $request->hasHeader('Authorization', 'Bearer '.$connection->access_token)
        && $request->data()['to'] === '5511999990001'
        && $request->data()['template']['name'] === 'pedido_em_preparo'
        && $request->data()['template']['components'][0]['parameters'] === [['type' => 'text', 'text' => $order->order_number]]);
});

test('falha temporária relança a exceção para retry e mantém a mensagem na fila', function () {
    ['order' => $order] = whatsappOrderContext();

    Http::fake(['graph.facebook.com/*' => whatsappGraphError(131000, 500)]);

    expect(fn () => runWhatsAppJob($order))->toThrow(WhatsAppRetryableException::class);

    expect(whatsappMessageFor($order)->status)->toBe('queued');
});

test('retry após falha temporária envia uma única vez quando a Meta volta', function () {
    ['order' => $order] = whatsappOrderContext();

    Http::fake([
        'graph.facebook.com/*' => Http::sequence()
            ->push(['error' => ['message' => 'indisponível', 'code' => 2]], 503)
            ->push(['messages' => [['id' => 'wamid.RETRY']]], 200),
    ]);

    expect(fn () => runWhatsAppJob($order))->toThrow(WhatsAppRetryableException::class);

    runWhatsAppJob($order);

    $message = whatsappMessageFor($order);

    expect($message->status)->toBe('sent')
        ->and($message->wamid)->toBe('wamid.RETRY')
        ->and(WhatsAppMessage::withoutGlobalScopes()->where('order_id', $order->id)->count())->toBe(1);

    Http::assertSentCount(2);
});

test('failed() marca a mensagem como failed depois de esgotar as tentativas', function () {
    ['order' => $order] = whatsappOrderContext();

    Http::fake(['graph.facebook.com/*' => whatsappGraphError(130429, 429)]);

    $job = new SendWhatsAppOrderNotificationJob($order->id, 'preparing');

    try {
        $job->handle(app(WhatsAppService::class), app(WhatsAppProviderInterface::class));
    } catch (WhatsAppRetryableException $e) {
        $job->failed($e);
    }

    $message = whatsappMessageFor($order);

    expect($message->status)->toBe('failed')
        ->and($message->error_code)->toBe('130429');
});

test('failed() não sobrescreve mensagem já aceita pela Meta', function () {
    ['order' => $order, 'connection' => $connection] = whatsappOrderContext();

    WhatsAppMessage::factory()->sent()->create([
        'company_id' => $order->company_id,
        'whatsapp_connection_id' => $connection->id,
        'order_id' => $order->id,
        'event' => 'preparing',
    ]);

    (new SendWhatsAppOrderNotificationJob($order->id, 'preparing'))->failed(new RuntimeException('boom'));

    expect(whatsappMessageFor($order)->status)->toBe('sent');
});

test('failed() com erro interno não vaza a mensagem da exceção', function () {
    ['order' => $order] = whatsappOrderContext();

    WhatsAppMessage::factory()->create([
        'company_id' => $order->company_id,
        'order_id' => $order->id,
        'event' => 'preparing',
    ]);

    (new SendWhatsAppOrderNotificationJob($order->id, 'preparing'))->failed(new RuntimeException('SQLSTATE[HY000] segredo interno'));

    $message = whatsappMessageFor($order);

    expect($message->status)->toBe('failed')
        ->and($message->error_message)->not->toContain('SQLSTATE');
});

test('falha permanente marca failed com o código e não retenta', function () {
    ['order' => $order, 'connection' => $connection] = whatsappOrderContext();

    Http::fake(['graph.facebook.com/*' => whatsappGraphError(131026, 400, 'Message undeliverable')]);

    runWhatsAppJob($order);

    $message = whatsappMessageFor($order);

    expect($message->status)->toBe('failed')
        ->and($message->error_code)->toBe('131026')
        ->and($message->error_message)->toContain('Message undeliverable')
        ->and($message->wamid)->toBeNull();

    // Falha do destinatário não é problema da conexão.
    expect($connection->fresh()->status)->toBe('active')
        ->and($connection->fresh()->last_error)->toBeNull();

    Http::assertSentCount(1);
});

test('mensagem com falha terminal não é reenviada por novo despacho', function () {
    ['order' => $order] = whatsappOrderContext();

    Http::fake(['graph.facebook.com/*' => whatsappGraphError(131026)]);

    runWhatsAppJob($order);
    runWhatsAppJob($order);

    Http::assertSentCount(1);
});

test('erro de autenticação marca a mensagem como failed e a conexão como error', function () {
    ['order' => $order, 'connection' => $connection] = whatsappOrderContext();

    Http::fake(['graph.facebook.com/*' => whatsappGraphError(190, 401, 'Error validating access token')]);

    runWhatsAppJob($order);

    $message = whatsappMessageFor($order);
    $connection->refresh();

    expect($message->status)->toBe('failed')
        ->and($message->error_code)->toBe('190')
        ->and($connection->status)->toBe('error')
        ->and($connection->last_error)->toContain('190')
        ->and($connection->last_error)->toContain('Reconecte');

    Http::assertSentCount(1);
});

test('erro de cobrança marca a mensagem como failed e registra last_error sem derrubar a conexão', function () {
    ['order' => $order, 'connection' => $connection] = whatsappOrderContext();

    Http::fake(['graph.facebook.com/*' => whatsappGraphError(131042, 400, 'Business eligibility payment issue')]);

    runWhatsAppJob($order);

    $message = whatsappMessageFor($order);
    $connection->refresh();

    expect($message->status)->toBe('failed')
        ->and($message->error_code)->toBe('131042')
        ->and($connection->status)->toBe('active')
        ->and($connection->last_error)->toContain('131042');
});

test('idempotência: mesmo pedido e evento duas vezes geram uma única chamada HTTP', function () {
    ['order' => $order] = whatsappOrderContext();

    Http::fake(['graph.facebook.com/*' => whatsappSendSuccess()]);

    runWhatsAppJob($order);
    runWhatsAppJob($order);

    Http::assertSentCount(1);

    expect(WhatsAppMessage::withoutGlobalScopes()->where('order_id', $order->id)->count())->toBe(1);
});

test('mensagem já entregue ou lida nunca é reenviada', function (string $status) {
    ['order' => $order, 'connection' => $connection] = whatsappOrderContext();

    WhatsAppMessage::factory()->sent()->create([
        'company_id' => $order->company_id,
        'whatsapp_connection_id' => $connection->id,
        'order_id' => $order->id,
        'event' => 'preparing',
        'status' => $status,
    ]);

    Http::fake();

    runWhatsAppJob($order);

    Http::assertNothingSent();
})->with(['sent', 'delivered', 'read']);

test('reaproveita a linha em fila criada por outra tentativa e envia uma vez', function () {
    ['order' => $order, 'connection' => $connection] = whatsappOrderContext();

    $queued = WhatsAppMessage::factory()->create([
        'company_id' => $order->company_id,
        'whatsapp_connection_id' => $connection->id,
        'order_id' => $order->id,
        'event' => 'preparing',
        'template' => 'pedido_em_preparo',
        'to_phone' => '5511999990001',
    ]);

    Http::fake(['graph.facebook.com/*' => whatsappSendSuccess('wamid.REUSO')]);

    runWhatsAppJob($order);

    expect($queued->fresh()->status)->toBe('sent')
        ->and($queued->fresh()->wamid)->toBe('wamid.REUSO')
        ->and(WhatsAppMessage::withoutGlobalScopes()->where('order_id', $order->id)->count())->toBe(1);
});

test('o banco impede duas notificações para o mesmo pedido e evento', function () {
    ['order' => $order] = whatsappOrderContext();

    $attributes = ['company_id' => $order->company_id, 'order_id' => $order->id, 'event' => 'preparing'];

    WhatsAppMessage::factory()->create($attributes);

    expect(fn () => WhatsAppMessage::factory()->create($attributes))->toThrow(UniqueConstraintViolationException::class);
});

test('revalida o opt-out no momento do envio', function () {
    ['order' => $order, 'customer' => $customer] = whatsappOrderContext();

    $customer->update(['whatsapp_opt_out_at' => now()]);

    Http::fake();

    runWhatsAppJob($order);

    Http::assertNothingSent();
    expect(whatsappMessageFor($order))->toBeNull();
});

test('opt-out entre tentativas cancela a mensagem que estava na fila', function () {
    ['order' => $order, 'customer' => $customer, 'connection' => $connection] = whatsappOrderContext();

    WhatsAppMessage::factory()->create([
        'company_id' => $order->company_id,
        'whatsapp_connection_id' => $connection->id,
        'order_id' => $order->id,
        'event' => 'preparing',
    ]);

    $customer->update(['whatsapp_opt_out_at' => now()]);

    Http::fake();

    runWhatsAppJob($order);

    Http::assertNothingSent();
    expect(whatsappMessageFor($order)->status)->toBe('failed');
});

test('conexão desconectada antes do envio não envia nada', function () {
    ['order' => $order, 'connection' => $connection] = whatsappOrderContext();

    $connection->update(['status' => WhatsAppConnection::STATUS_DISCONNECTED]);

    Http::fake();

    runWhatsAppJob($order);

    Http::assertNothingSent();
});

test('pedido inexistente é ignorado sem erro', function () {
    Http::fake();

    (new SendWhatsAppOrderNotificationJob(999999, 'preparing'))
        ->handle(app(WhatsAppService::class), app(WhatsAppProviderInterface::class));

    Http::assertNothingSent();
});

test('fallback: sem conexão própria envia pelo número da plataforma', function () {
    config([
        'services.whatsapp.fallback_to_platform' => true,
        'services.whatsapp.platform.phone_number_id' => '777000111',
        'services.whatsapp.platform.token' => 'token-plataforma',
    ]);

    ['order' => $order] = whatsappOrderContext(withConnection: false);

    Http::fake(['graph.facebook.com/*' => whatsappSendSuccess('wamid.PLATAFORMA')]);

    runWhatsAppJob($order);

    $message = whatsappMessageFor($order);

    expect($message->status)->toBe('sent')
        ->and($message->whatsapp_connection_id)->toBeNull();

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/777000111/messages')
        && $request->hasHeader('Authorization', 'Bearer token-plataforma'));
});

test('erro de autenticação no número da plataforma não mexe em nenhuma conexão', function () {
    config([
        'services.whatsapp.fallback_to_platform' => true,
        'services.whatsapp.platform.phone_number_id' => '777000111',
        'services.whatsapp.platform.token' => 'token-plataforma',
    ]);

    ['order' => $order] = whatsappOrderContext(withConnection: false);
    $outra = WhatsAppConnection::factory()->create();

    Http::fake(['graph.facebook.com/*' => whatsappGraphError(190, 401)]);

    runWhatsAppJob($order);

    expect(whatsappMessageFor($order)->status)->toBe('failed')
        ->and($outra->fresh()->status)->toBe('active');
});

test('isolamento: pedido da empresa A nunca usa a conexão da empresa B', function () {
    ['order' => $orderA, 'connection' => $connectionA] = whatsappOrderContext();
    ['order' => $orderB, 'connection' => $connectionB] = whatsappOrderContext();

    expect($connectionA->phone_number_id)->not->toBe($connectionB->phone_number_id);

    Http::fake(['graph.facebook.com/*' => whatsappSendSuccess()]);

    // current.company aponta para a empresa B (a última criada) enquanto o job processa o pedido da A.
    runWhatsAppJob($orderA);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), "/{$connectionA->phone_number_id}/messages")
        && ! str_contains($request->url(), (string) $connectionB->phone_number_id)
        && $request->hasHeader('Authorization', 'Bearer '.$connectionA->access_token));

    expect(whatsappMessageFor($orderA)->whatsapp_connection_id)->toBe($connectionA->id)
        ->and(whatsappMessageFor($orderA)->company_id)->toBe($orderA->company_id)
        ->and(whatsappMessageFor($orderB))->toBeNull();
});

test('isolamento: empresa sem conexão não usa a conexão de outra empresa', function () {
    ['order' => $orderSemConexao] = whatsappOrderContext(withConnection: false);
    whatsappOrderContext(); // empresa B com conexão ativa

    Http::fake();

    runWhatsAppJob($orderSemConexao);

    Http::assertNothingSent();
});

test('restaura o current.company do chamador ao terminar', function () {
    ['order' => $orderA] = whatsappOrderContext();
    ['company' => $companyB] = whatsappOrderContext();

    Http::fake(['graph.facebook.com/*' => whatsappSendSuccess()]);

    runWhatsAppJob($orderA);

    expect(app('current.company')->id)->toBe($companyB->id);
});

test('sem current.company prévio, não deixa tenant preso após o job', function () {
    ['order' => $order] = whatsappOrderContext();

    app()->forgetInstance('current.company');

    Http::fake(['graph.facebook.com/*' => whatsappSendSuccess()]);

    runWhatsAppJob($order);

    expect(app()->bound('current.company'))->toBeFalse();
});

test('o job é único por pedido e evento, roda na fila whatsapp e só depois do commit', function () {
    $job = new SendWhatsAppOrderNotificationJob(42, 'ready');

    expect($job->uniqueId())->toBe('42:ready')
        ->and($job->queue)->toBe('whatsapp')
        ->and($job->afterCommit)->toBeTrue()
        ->and($job->tries)->toBe(5)
        ->and($job->backoff())->toBe([30, 120, 300, 900]);
});
