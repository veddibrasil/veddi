<?php

use App\Models\KeetaWebhookEvent;
use App\Models\Order;
use App\Services\Keeta\KeetaOrderPollingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(fn () => RateLimiter::clear('keeta:polling'));

it('busca eventos das lojas sem webhook, grava e só então confirma', function () {
    $ctx = keetaContext(integration: ['webhook_status' => 'unknown']);
    $raw = keetaOrder($ctx);
    $event = keetaEvent('CREATED', $raw['id']);
    fakeKeetaApi($raw, ['*/v1/events:polling' => Http::response([$event], 200), '*/v1/events/acknowledgment' => Http::response('', 202)]);

    expect(app(KeetaOrderPollingService::class)->poll())->toBe(1);

    expect(Order::withoutGlobalScopes()->where('channel', 'keeta')->count())->toBe(1)
        ->and(KeetaWebhookEvent::first()->source)->toBe('polling')
        ->and(KeetaWebhookEvent::first()->acknowledged_at)->not->toBeNull()
        ->and($ctx['integration']->fresh()->last_polled_at)->not->toBeNull();

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/events:polling') && $r->header('x-polling-merchants')[0] === $ctx['integration']->merchant_ref);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/events/acknowledgment') && $r[0]['id'] === $event['eventId'] && $r[0]['eventType'] === 'CREATED');
});

it('evento já recebido pelo webhook não duplica, mas é confirmado', function () {
    $ctx = keetaContext(integration: ['webhook_status' => 'degraded']);
    $raw = keetaOrder($ctx);
    $event = keetaEvent('CREATED', $raw['id']);
    fakeKeetaApi($raw);
    postKeetaWebhook($this, $event, $ctx['integration']->merchant_ref)->assertNoContent();
    \App\Models\KeetaIntegration::withoutGlobalScopes()->whereKey($ctx['integration']->id)->update(['webhook_status' => 'degraded']);

    fakeKeetaApi($raw, ['*/v1/events:polling' => Http::response([$event], 200), '*/v1/events/acknowledgment' => Http::response('', 202)]);
    expect(app(KeetaOrderPollingService::class)->poll())->toBe(0);

    expect(KeetaWebhookEvent::count())->toBe(1)
        ->and(Order::withoutGlobalScopes()->where('channel', 'keeta')->count())->toBe(1);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/events/acknowledgment'));
});

it('com várias lojas no lote, acha a loja pelo merchant.id do pedido', function () {
    $a = keetaContext('A', ['webhook_status' => 'unknown']);
    $b = keetaContext('B', ['webhook_status' => 'unknown']);
    $raw = keetaOrder($b);
    fakeKeetaApi($raw, ['*/v1/events:polling' => Http::response([keetaEvent('CREATED', $raw['id'])], 200), '*/v1/events/acknowledgment' => Http::response('', 202)]);

    app(KeetaOrderPollingService::class)->poll();

    $order = Order::withoutGlobalScopes()->where('channel', 'keeta')->firstOrFail();
    expect($order->company_id)->toBe($b['company']->id)
        ->and(KeetaWebhookEvent::first()->keeta_integration_id)->toBe($b['integration']->id);
});

it('respeita o limite de 2 chamadas por minuto e ignora loja com webhook saudável', function () {
    keetaContext(integration: ['webhook_status' => 'healthy']);
    fakeKeetaApi([], ['*/v1/events:polling' => Http::response([], 200)]);

    expect(app(KeetaOrderPollingService::class)->poll())->toBe(0);
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'events:polling'));

    \App\Models\KeetaIntegration::withoutGlobalScopes()->update(['webhook_status' => 'unknown']);
    app(KeetaOrderPollingService::class)->poll();
    app(KeetaOrderPollingService::class)->poll();
    app(KeetaOrderPollingService::class)->poll();

    expect(collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'events:polling'))->count())->toBe(2);
});

it('marca webhook parado como degradado pra entrar no polling', function () {
    $ctx = keetaContext(integration: ['webhook_status' => 'healthy', 'last_webhook_received_at' => now()->subHour()]);

    (new \App\Jobs\MonitorKeetaWebhookHealthJob)->handle();

    expect($ctx['integration']->fresh()->webhook_status)->toBe('degraded');
});
