<?php

use App\Jobs\ProcessIfoodOrderJob;
use App\Models\IfoodOrderEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['ifood.partner_client_secret' => 'platform-webhook-secret']);
});

function ifoodWebhookPayload(string $merchantId, string $eventId = 'evt-001', string $code = 'PLC'): array
{
    return [
        'id' => $eventId,
        'code' => $code,
        'merchantId' => $merchantId,
        'orderId' => 'ifood-order-001',
        'createdAt' => now()->toIso8601String(),
    ];
}

function signIfoodPayload(array $payload, string $secret): string
{
    return hash_hmac('sha256', json_encode($payload), $secret);
}

test('webhook iFood rejeita assinatura inválida', function () {
    ['integration' => $integration] = ifoodContext('wh1');
    $payload = ifoodWebhookPayload($integration->merchant_id);

    $response = $this->postJson('/webhooks/ifood', $payload, [
        'X-IFood-Signature' => 'assinatura-forjada',
    ]);

    $response->assertStatus(401);
    expect(IfoodOrderEvent::count())->toBe(0);
});

test('webhook iFood rejeita quando header de assinatura está ausente', function () {
    ['integration' => $integration] = ifoodContext('wh2');
    $payload = ifoodWebhookPayload($integration->merchant_id);

    $response = $this->postJson('/webhooks/ifood', $payload);

    $response->assertStatus(401);
});

test('webhook iFood rejeita merchantId desconhecido', function () {
    ifoodContext('wh3');
    $payload = ifoodWebhookPayload('merchant-inexistente');

    $response = $this->postJson('/webhooks/ifood', $payload, [
        'X-IFood-Signature' => signIfoodPayload($payload, 'qualquer-coisa'),
    ]);

    $response->assertStatus(404);
});

test('webhook iFood aceita assinatura válida, persiste evento e enfileira job', function () {
    Bus::fake();
    ['integration' => $integration] = ifoodContext('wh4');
    $payload = ifoodWebhookPayload($integration->merchant_id, 'evt-valid-001');

    $response = $this->postJson('/webhooks/ifood', $payload, [
        'X-IFood-Signature' => signIfoodPayload($payload, config('ifood.partner_client_secret')),
    ]);

    $response->assertStatus(200)->assertJson(['status' => 'queued']);

    $event = IfoodOrderEvent::where('event_id', 'evt-valid-001')->first();
    expect($event)->not->toBeNull()
        ->and($event->source)->toBe('webhook')
        ->and($event->ifood_integration_id)->toBe($integration->id)
        ->and($event->status)->toBe('pending');

    $integration->refresh();
    expect($integration->webhook_status)->toBe('healthy')
        ->and($integration->last_webhook_received_at)->not->toBeNull();

    Bus::assertDispatched(ProcessIfoodOrderJob::class, fn ($job) => $job->ifoodOrderEventId === $event->id);
});

test('webhook iFood duplicado (mesmo event_id) não redespacha nem duplica evento', function () {
    Bus::fake();
    ['integration' => $integration] = ifoodContext('wh5');
    $payload = ifoodWebhookPayload($integration->merchant_id, 'evt-dup-001');
    $signature = signIfoodPayload($payload, config('ifood.partner_client_secret'));

    $first = $this->postJson('/webhooks/ifood', $payload, ['X-IFood-Signature' => $signature]);
    $first->assertStatus(200)->assertJson(['status' => 'queued']);

    $second = $this->postJson('/webhooks/ifood', $payload, ['X-IFood-Signature' => $signature]);
    $second->assertStatus(200)->assertJson(['status' => 'duplicate']);

    expect(IfoodOrderEvent::where('event_id', 'evt-dup-001')->count())->toBe(1);
    Bus::assertDispatchedTimes(ProcessIfoodOrderJob::class, 1);
});
