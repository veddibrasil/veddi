<?php

use App\Contracts\IfoodGatewayContract;
use App\Jobs\PollIfoodEventsJob;
use App\Models\IfoodOrderEvent;
use App\Models\Order;
use App\Services\Ifood\IfoodOrderPollingService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('falha numa empresa durante o polling não impede o processamento das demais, e current.company não vaza', function () {
    ['company' => $companyOk, 'integration' => $integrationOk] = ifoodContext('pollok');
    ['company' => $companyFail, 'integration' => $integrationFail] = ifoodContext('pollfail');
    $integrationOk->refresh();
    $integrationFail->refresh();

    // Ambas ficam com webhook_status padrão ('unknown') — cobertas pelo fallback de polling.
    expect($integrationOk->webhook_status)->toBe('unknown')
        ->and($integrationFail->webhook_status)->toBe('unknown');

    $gateway = Mockery::mock(IfoodGatewayContract::class);

    $gateway->shouldReceive('pollEvents')->andReturnUsing(function ($integration) use ($integrationFail) {
        if ($integration->id === $integrationFail->id) {
            throw new RuntimeException('falha simulada na API do iFood');
        }

        return [[
            'id' => 'evt-poll-ok-1',
            'code' => 'PLC',
            'orderId' => 'ifood-order-poll-ok',
            'merchantId' => $integration->merchant_id,
        ]];
    });

    $gateway->shouldReceive('acknowledgeEvents')->andReturnNull();

    $gateway->shouldReceive('getOrderDetails')->andReturnUsing(
        fn ($integration, $ifoodOrderId) => ifoodOrderDetailsPayload($ifoodOrderId, $integration->merchant_id, 'ifood-item-coxinha-pollok')
    );

    app()->instance(IfoodGatewayContract::class, $gateway);

    (new PollIfoodEventsJob)->handle(app(IfoodOrderPollingService::class));

    $ordersOk = Order::withoutGlobalScopes()->where('company_id', $companyOk->id)->count();
    $ordersFail = Order::withoutGlobalScopes()->where('company_id', $companyFail->id)->count();

    expect($ordersOk)->toBe(1)
        ->and($ordersFail)->toBe(0);

    // Crítico: depois do job rodar (inclusive após a iteração que falhou), o contexto
    // de empresa não pode ficar vazando pra fora do job.
    expect(app()->bound('current.company'))->toBeFalse();
});

test('polling mantém presença mesmo depois de receber webhook saudável', function () {
    ['integration' => $integration] = ifoodContext('pollhealthy');
    $integration->update(['webhook_status' => 'healthy']);
    $gateway = Mockery::mock(IfoodGatewayContract::class);
    $gateway->shouldReceive('pollEvents')->once()
        ->withArgs(fn ($candidate) => $candidate->id === $integration->id)->andReturn([]);

    (new PollIfoodEventsJob)->handle(new IfoodOrderPollingService($gateway));
    expect($integration->fresh()->last_synced_at)->not->toBeNull();
});

test('registra acknowledgement somente após a API aceitar todos os eventos recebidos', function () {
    ['integration' => $integration] = ifoodContext('ack-audit');
    $gateway = Mockery::mock(IfoodGatewayContract::class);
    $raw = ['id' => 'ack-audit-1', 'code' => 'CFM', 'orderId' => 'order-audit', 'merchantId' => $integration->merchant_id];
    $gateway->shouldReceive('pollEvents')->twice()->andReturn([$raw]);
    $gateway->shouldReceive('acknowledgeEvents')->once()->andThrow(new RuntimeException('API indisponível'));
    $service = new IfoodOrderPollingService($gateway);
    expect(fn () => $service->pollFor($integration))->toThrow(RuntimeException::class);
    $event = IfoodOrderEvent::where('event_id', 'ack-audit-1')->firstOrFail();
    expect($event->acknowledged_at)->toBeNull();

    $gateway->shouldReceive('acknowledgeEvents')->once()->withArgs(fn ($i, $ids) => $ids === ['ack-audit-1'])->andReturnNull();
    $service->pollFor($integration);
    expect($event->fresh()->acknowledged_at)->not->toBeNull();
});
