<?php

use App\Jobs\ProcessFood99EventJob;
use App\Models\CompanyNotification;
use App\Models\Food99WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(fn () => Queue::fake());

it('aceita callback assinado, persiste e enfileira respondendo errno 0', function () {
    $ctx = food99Context();
    $payload = food99Callback($ctx['integration'], 'orderConfirm', ['order_id' => 5764607523034934815]);

    postFood99Webhook($this, $payload)->assertOk()->assertExactJson(['errno' => 0, 'errmsg' => 'ok']);

    $event = Food99WebhookEvent::sole();
    expect($event->food99_integration_id)->toBe($ctx['integration']->id)
        ->and($event->event_type)->toBe('orderConfirm')
        ->and($event->external_order_id)->toBe('5764607523034934815')
        ->and($event->status)->toBe('pending')
        ->and($ctx['integration']->fresh()->webhook_status)->toBe('healthy')
        ->and($ctx['integration']->fresh()->last_webhook_received_at)->not->toBeNull();
    Queue::assertPushedOn('critical', ProcessFood99EventJob::class, fn ($job) => $job->eventId === $event->id);
});

it('preserva order_id de 19 dígitos no payload gravado', function () {
    $ctx = food99Context();
    $raw = '{"app_id":'.FOOD99_TEST_APP_ID.',"app_shop_id":"'.$ctx['integration']->app_shop_id.'","type":"orderReady","timestamp":1700000000,"data":{"order_id":9223372036854775807}}';

    postFood99Webhook($this, $raw)->assertOk();

    expect(Food99WebhookEvent::sole()->external_order_id)->toBe('9223372036854775807');
});

it('recusa assinatura inválida com errno 1', function () {
    $ctx = food99Context();

    postFood99Webhook($this, food99Callback($ctx['integration'], 'orderNew', ['order_id' => 1]), 'assinatura-errada')
        ->assertStatus(401)
        ->assertJson(['errno' => 1]);

    expect(Food99WebhookEvent::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('recusa callback de outro app', function () {
    $ctx = food99Context();

    postFood99Webhook($this, food99Callback($ctx['integration'], 'orderNew', ['order_id' => 1], ['app_id' => 1]))
        ->assertStatus(403)
        ->assertJson(['errno' => 1]);

    expect(Food99WebhookEvent::count())->toBe(0);
});

it('responde errno 0 para reentrega sem duplicar o evento', function () {
    $ctx = food99Context();
    $payload = food99Callback($ctx['integration'], 'orderNew', ['order_id' => 777, 'order_info' => []]);

    postFood99Webhook($this, $payload)->assertOk()->assertJson(['errno' => 0]);
    postFood99Webhook($this, array_merge($payload, ['timestamp' => $payload['timestamp'] + 30]))->assertOk()->assertJson(['errno' => 0]);

    expect(Food99WebhookEvent::count())->toBe(1);
    Queue::assertPushed(ProcessFood99EventJob::class, 1);
});

it('entrega o evento só à integração dona do app_shop_id', function () {
    $a = food99Context('A');
    $b = food99Context('B');

    postFood99Webhook($this, food99Callback($b['integration'], 'orderReady', ['order_id' => 55]))->assertOk();

    expect(Food99WebhookEvent::sole()->food99_integration_id)->toBe($b['integration']->id)
        ->and($a['integration']->fresh()->last_webhook_received_at)->toBeNull();
});

it('não cria evento para loja desconhecida, mas responde errno 0', function () {
    food99Context();

    postFood99Webhook($this, food99Callback(null, 'orderNew', ['order_id' => 1], ['app_shop_id' => 'veddi-999-999']))
        ->assertOk()->assertJson(['errno' => 0]);

    expect(Food99WebhookEvent::count())->toBe(0);
});

it('não aceita pedido novo para integração suspensa, mas aceita evento de pedido em andamento', function () {
    $ctx = food99Context();
    $ctx['integration']->update(['status' => 'paused']);

    postFood99Webhook($this, food99Callback($ctx['integration'], 'orderNew', ['order_id' => 10]))->assertOk();
    expect(Food99WebhookEvent::count())->toBe(0);

    postFood99Webhook($this, food99Callback($ctx['integration'], 'orderCancel', ['order_id' => 10]))->assertOk();
    expect(Food99WebhookEvent::sole()->event_type)->toBe('orderCancel');
});

it('persiste tipo desconhecido e responde errno 0', function () {
    $ctx = food99Context();

    postFood99Webhook($this, food99Callback($ctx['integration'], 'novoTipoQualquer', ['x' => 1]))->assertOk()->assertJson(['errno' => 0]);

    expect(Food99WebhookEvent::sole()->event_type)->toBe('novoTipoQualquer');
});

it('recusa JSON inválido com errno 1', function () {
    food99Context();

    postFood99Webhook($this, '{nao-e-json')->assertStatus(400)->assertJson(['errno' => 1]);
});

it('marca integração em erro e avisa quando a 99 desvincula a loja', function () {
    Queue::fake()->except([ProcessFood99EventJob::class]);
    $a = food99Context('A');
    $b = food99Context('B');

    postFood99Webhook($this, food99Callback(null, 'shopBindStatus', [
        'appShopIDList' => [$a['integration']->app_shop_id],
        'bindStatus' => 'unbind',
    ], ['app_shop_id' => null]))->assertOk()->assertJson(['errno' => 0]);

    expect($a['integration']->fresh()->status)->toBe('error')
        ->and($a['integration']->fresh()->auth_token)->toBeNull()
        ->and($b['integration']->fresh()->status)->toBe('active')
        ->and(CompanyNotification::withoutGlobalScopes()->where('company_id', $a['company']->id)->where('type', 'food99_alert')->count())->toBe(1)
        ->and(CompanyNotification::withoutGlobalScopes()->where('company_id', $b['company']->id)->count())->toBe(0)
        ->and(Food99WebhookEvent::sole()->status)->toBe('processed');
});

it('atualiza o status da loja pelo callback shopStatus', function () {
    Queue::fake()->except([ProcessFood99EventJob::class]);
    $ctx = food99Context();

    postFood99Webhook($this, food99Callback($ctx['integration'], 'shopStatus', ['biz_status' => 2, 'sub_biz_status' => 2]))->assertOk();

    expect($ctx['integration']->fresh()->sub_biz_status)->toBe(2)
        ->and(Food99WebhookEvent::sole()->status)->toBe('processed');
});

it('registra como ignorado o tipo que nenhum tratador conhece', function () {
    Queue::fake()->except([ProcessFood99EventJob::class]);
    $ctx = food99Context();

    postFood99Webhook($this, food99Callback($ctx['integration'], 'imageAuditStatusX', []))->assertOk();

    expect(Food99WebhookEvent::sole()->status)->toBe('ignored');
});
