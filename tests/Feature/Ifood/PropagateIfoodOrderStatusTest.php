<?php

use App\Contracts\IfoodGatewayContract;
use App\Events\OrderStatusUpdated;
use App\Jobs\PropagateIfoodStatusJob;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

function makePropagationOrder(array $ctx, string $status, string $channel = 'ifood'): Order
{
    return Order::create([
        'company_id' => $ctx['company']->id,
        'branch_id' => $ctx['branch']->id,
        'customer_id' => \App\Models\Customer::withoutGlobalScopes()->create([
            'company_id' => $ctx['company']->id,
            'name' => 'Cliente',
            'phone' => '11988880000',
        ])->id,
        'subtotal' => 20.00,
        'total' => 20.00,
        'discount' => 0,
        'fee' => 0,
        'net_value' => 20.00,
        'status' => $status,
        'payment_method' => 'ifood',
        'order_type' => 'delivery',
        'channel' => $channel,
        'external_order_id' => $channel === 'ifood' ? 'ifood-order-propag-1' : null,
    ]);
}

test('OrderStatusUpdated de pedido iFood despacha PropagateIfoodStatusJob', function () {
    Bus::fake();
    $ctx = ifoodContext('prop1');
    $order = makePropagationOrder($ctx, 'ready');

    OrderStatusUpdated::dispatch($order);

    Bus::assertDispatched(PropagateIfoodStatusJob::class, fn ($job) => $job->orderId === $order->id && $job->status === 'ready');
});

test('OrderStatusUpdated de pedido não-iFood NUNCA despacha PropagateIfoodStatusJob', function () {
    Bus::fake();
    $ctx = ifoodContext('prop2');
    $order = makePropagationOrder($ctx, 'ready', channel: 'chat');

    OrderStatusUpdated::dispatch($order);

    Bus::assertNotDispatched(PropagateIfoodStatusJob::class);
});

test('PropagateIfoodStatusJob chama updateOrderStatus no gateway pra status ready', function () {
    $ctx = ifoodContext('prop3');
    $order = makePropagationOrder($ctx, 'ready');

    $gateway = Mockery::mock(IfoodGatewayContract::class);
    $gateway->shouldReceive('updateOrderStatus')->once()->with(
        Mockery::on(fn ($integration) => $integration->id === $ctx['integration']->id),
        $order->external_order_id,
        'ready',
    );

    (new PropagateIfoodStatusJob($order->id, 'ready'))->handle($gateway);

    expect(app()->bound('current.company'))->toBeFalse();
});

test('PropagateIfoodStatusJob NÃO propaga status preparing (já coberto por accept/confirmOrder)', function () {
    $ctx = ifoodContext('prop4');
    $order = makePropagationOrder($ctx, 'preparing');

    $gateway = Mockery::mock(IfoodGatewayContract::class);
    $gateway->shouldNotReceive('updateOrderStatus');

    (new PropagateIfoodStatusJob($order->id, 'preparing'))->handle($gateway);
});

test('PropagateIfoodStatusJob propaga out_for_delivery', function () {
    $ctx = ifoodContext('prop5');
    $order = makePropagationOrder($ctx, 'out_for_delivery');

    $gateway = Mockery::mock(IfoodGatewayContract::class);
    $gateway->shouldReceive('updateOrderStatus')->once()->with(
        Mockery::on(fn ($integration) => $integration->id === $ctx['integration']->id),
        $order->external_order_id,
        'out_for_delivery',
    );

    (new PropagateIfoodStatusJob($order->id, 'out_for_delivery'))->handle($gateway);
});
