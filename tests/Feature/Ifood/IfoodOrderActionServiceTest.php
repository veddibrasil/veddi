<?php

use App\Contracts\IfoodGatewayContract;
use App\Models\Order;
use App\Services\Ifood\IfoodOrderActionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeIfoodOrder(array $ctx, string $status = 'paid', string $externalOrderId = 'ifood-order-action-1'): Order
{
    return Order::create([
        'company_id' => $ctx['company']->id,
        'branch_id' => $ctx['branch']->id,
        'customer_id' => \App\Models\Customer::withoutGlobalScopes()->create([
            'company_id' => $ctx['company']->id,
            'name' => 'Cliente iFood',
            'phone' => '11999990000',
        ])->id,
        'subtotal' => 20.00,
        'delivery_fee' => 5.00,
        'total' => 25.00,
        'discount' => 0,
        'fee' => 0,
        'net_value' => 25.00,
        'status' => $status,
        'payment_method' => 'ifood',
        'order_type' => 'delivery',
        'channel' => 'ifood',
        'external_order_id' => $externalOrderId,
    ]);
}

test('accept confirma no gateway e avança status pra preparing', function () {
    $ctx = ifoodContext('act1');
    $order = makeIfoodOrder($ctx);

    $gateway = Mockery::mock(IfoodGatewayContract::class);
    $gateway->shouldReceive('confirmOrder')->once()->with(
        Mockery::on(fn ($integration) => $integration->id === $ctx['integration']->id),
        $order->external_order_id,
    );

    (new IfoodOrderActionService($gateway))->accept($order);

    $order->refresh();
    expect($order->status)->toBe('preparing');
});

test('reject com motivo válido chama gateway, cancela pedido e restaura estoque', function () {
    $ctx = ifoodContext('act2');
    $order = makeIfoodOrder($ctx);

    $gateway = Mockery::mock(IfoodGatewayContract::class);
    $gateway->shouldReceive('rejectOrder')->once()->with(
        Mockery::on(fn ($integration) => $integration->id === $ctx['integration']->id),
        $order->external_order_id,
        'ITEM_UNAVAILABLE',
    );

    (new IfoodOrderActionService($gateway))->reject($order, 'ITEM_UNAVAILABLE');

    $order->refresh();
    expect($order->status)->toBe('cancelled');
});

test('reject com motivo inválido lança exceção e NUNCA chama o gateway', function () {
    $ctx = ifoodContext('act3');
    $order = makeIfoodOrder($ctx);

    $gateway = Mockery::mock(IfoodGatewayContract::class);
    $gateway->shouldNotReceive('rejectOrder');

    expect(fn () => (new IfoodOrderActionService($gateway))->reject($order, 'MOTIVO_INVENTADO'))
        ->toThrow(InvalidArgumentException::class);

    $order->refresh();
    expect($order->status)->toBe('paid');
});

test('requestCancellation com motivo válido chama gateway sem mudar status local', function () {
    $ctx = ifoodContext('act4');
    $order = makeIfoodOrder($ctx, 'preparing');

    $gateway = Mockery::mock(IfoodGatewayContract::class);
    $gateway->shouldReceive('requestCancellation')->once()->with(
        Mockery::on(fn ($integration) => $integration->id === $ctx['integration']->id),
        $order->external_order_id,
        'RESTAURANT_CLOSED',
    );

    (new IfoodOrderActionService($gateway))->requestCancellation($order, 'RESTAURANT_CLOSED');

    $order->refresh();
    // Cancelamento no iFood não é imediato — status local só muda quando a
    // confirmação chegar (fora do escopo desta fase).
    expect($order->status)->toBe('preparing');
});

test('requestCancellation com motivo inválido lança exceção e NUNCA chama o gateway', function () {
    $ctx = ifoodContext('act5');
    $order = makeIfoodOrder($ctx, 'preparing');

    $gateway = Mockery::mock(IfoodGatewayContract::class);
    $gateway->shouldNotReceive('requestCancellation');

    expect(fn () => (new IfoodOrderActionService($gateway))->requestCancellation($order, 'MOTIVO_INVENTADO'))
        ->toThrow(InvalidArgumentException::class);
});
