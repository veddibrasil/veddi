<?php

use App\Models\KeetaWebhookEvent;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('recusa assinatura inválida sem gravar nada', function () {
    $ctx = keetaContext();

    postKeetaWebhook($this, keetaEvent('CREATED', '1'), $ctx['integration']->merchant_ref, 'assinatura-errada')->assertStatus(401);

    expect(KeetaWebhookEvent::count())->toBe(0);
});

it('recusa X-App-Id diferente do configurado', function () {
    $ctx = keetaContext();
    $payload = keetaEvent('CREATED', '1');
    $raw = json_encode($payload);

    $this->call('POST', '/webhooks/keeta/orders', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_APP_ID' => 'outro-app',
        'HTTP_X_APP_MERCHANTID' => $ctx['integration']->merchant_ref,
        'HTTP_X_APP_SIGNATURE' => base64_encode(hash_hmac('sha256', $raw, KEETA_TEST_CLIENT_SECRET, true)),
    ], $raw)->assertStatus(403);

    expect(KeetaWebhookEvent::count())->toBe(0);
});

it('responde 204 sem gravar para loja desconhecida', function () {
    keetaContext();

    postKeetaWebhook($this, keetaEvent('CREATED', '1'), 'veddi-999-999-'.str_repeat('a', 36))->assertNoContent();

    expect(KeetaWebhookEvent::count())->toBe(0);
});

it('grava um evento só por eventId (reentrega)', function () {
    $ctx = keetaContext();
    $raw = keetaOrder($ctx);
    fakeKeetaApi($raw);
    $event = keetaEvent('CREATED', $raw['id']);

    postKeetaWebhook($this, $event, $ctx['integration']->merchant_ref)->assertNoContent();
    postKeetaWebhook($this, $event, $ctx['integration']->merchant_ref)->assertNoContent();

    expect(KeetaWebhookEvent::where('event_id', $event['eventId'])->count())->toBe(1)
        ->and(Order::withoutGlobalScopes()->where('channel', 'keeta')->count())->toBe(1)
        ->and($ctx['integration']->fresh()->webhook_status)->toBe('healthy')
        ->and($ctx['integration']->fresh()->last_webhook_received_at)->not->toBeNull();
});

it('rejeita payload sem eventId ou orderId', function () {
    $ctx = keetaContext();

    postKeetaWebhook($this, ['eventType' => 'CREATED'], $ctx['integration']->merchant_ref)->assertStatus(400);
});

it('integração suspensa não recebe pedido novo mas recebe evento de pedido em andamento', function () {
    $ctx = keetaContext(integration: ['status' => 'paused']);
    fakeKeetaApi(keetaOrder($ctx));

    postKeetaWebhook($this, keetaEvent('CREATED', '111'), $ctx['integration']->merchant_ref)->assertNoContent();
    expect(KeetaWebhookEvent::count())->toBe(0);

    postKeetaWebhook($this, keetaEvent('CANCELLED', '111'), $ctx['integration']->merchant_ref)->assertNoContent();
    expect(KeetaWebhookEvent::where('event_type', 'CANCELLED')->count())->toBe(1);
});

it('pedido com merchant.id de outra loja não entra', function () {
    $ctx = keetaContext();
    $raw = keetaOrder($ctx, ['merchant' => ['id' => 'veddi-0-0-'.str_repeat('b', 36)]]);
    fakeKeetaApi($raw);
    $event = KeetaWebhookEvent::create([
        'event_id' => 'x-1', 'keeta_integration_id' => $ctx['integration']->id, 'event_type' => 'CREATED',
        'external_order_id' => $raw['id'], 'source' => 'webhook', 'payload' => keetaEvent('CREATED', $raw['id']), 'status' => 'pending',
    ]);

    expect(fn () => (new \App\Jobs\ProcessKeetaEventJob($event->id))->handle())->toThrow(RuntimeException::class, 'não pertence');
    expect(Order::withoutGlobalScopes()->where('channel', 'keeta')->count())->toBe(0);
});

it('registra autorização e revogação do Dev Portal', function () {
    $ctx = keetaContext();
    $payload = ['clientId' => (int) KEETA_TEST_CLIENT_ID, 'authId' => '41008', 'opType' => 2, 'shopId' => (int) $ctx['integration']->keeta_merchant_id, 'shopName' => 'Loja', 'createTime' => 1753151456973];

    postKeetaWebhook($this, $payload, null, null, '/webhooks/keeta/authorization')->assertOk()->assertJson(['code' => 0]);
    postKeetaWebhook($this, $payload, null, null, '/webhooks/keeta/authorization')->assertOk();

    expect(KeetaWebhookEvent::where('source', 'authorization')->count())->toBe(1)
        ->and($ctx['integration']->fresh()->status)->toBe('revoked')
        ->and(\App\Models\CompanyNotification::where('company_id', $ctx['company']->id)->where('title', 'like', '%revogada%')->exists())->toBeTrue();

    // Nova autorização da mesma loja reativa (onboarding de novo).
    fakeKeetaApi();
    postKeetaWebhook($this, array_merge($payload, ['opType' => 1, 'authId' => '41009']), null, null, '/webhooks/keeta/authorization')->assertOk();

    expect($ctx['integration']->fresh()->status)->toBe('active');
    \Illuminate\Support\Facades\Http::assertSent(fn ($r) => str_contains($r->url(), '/v1/merchantOnboarding?merchantId='));
});
