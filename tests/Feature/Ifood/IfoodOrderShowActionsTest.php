<?php

use App\Contracts\IfoodGatewayContract;
use App\Livewire\Admin\Orders\Show as OrdersShow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('botao de status preparing na tela do pedido chama confirmOrder no iFood', function () {
    $ctx = ifoodContext('sh1');
    $order = ifoodKanbanOrder($ctx, 'paid', 'ifood-show-1');
    $admin = User::factory()->create(['is_super_admin' => true]);

    $gateway = Mockery::mock(IfoodGatewayContract::class);
    $gateway->shouldReceive('confirmOrder')->once()->with(
        Mockery::on(fn ($integration) => $integration->id === $ctx['integration']->id),
        $order->external_order_id,
    );
    app()->instance(IfoodGatewayContract::class, $gateway);

    $this->actingAs($admin);

    Livewire::test(OrdersShow::class, ['order' => $order])
        ->call('updateStatus', 'preparing');

    expect($order->fresh()->status)->toBe('preparing');
});

test('botao cancelado na tela do pedido NAO cancela direto — abre modal de motivo', function () {
    $ctx = ifoodContext('sh2');
    $order = ifoodKanbanOrder($ctx, 'paid', 'ifood-show-2');
    $admin = User::factory()->create(['is_super_admin' => true]);

    $gateway = Mockery::mock(IfoodGatewayContract::class);
    $gateway->shouldNotReceive('rejectOrder');
    $gateway->shouldNotReceive('requestCancellation');
    app()->instance(IfoodGatewayContract::class, $gateway);

    $this->actingAs($admin);

    Livewire::test(OrdersShow::class, ['order' => $order])
        ->call('updateStatus', 'cancelled')
        ->assertSet('showIfoodCancelModal', true);

    expect($order->fresh()->status)->toBe('paid');
});

test('confirmIfoodCancel na tela do pedido antes de aceito chama reject e cancela local', function () {
    $ctx = ifoodContext('sh3');
    $order = ifoodKanbanOrder($ctx, 'paid', 'ifood-show-3');
    $admin = User::factory()->create(['is_super_admin' => true]);

    $gateway = Mockery::mock(IfoodGatewayContract::class);
    $gateway->shouldReceive('rejectOrder')->once()->with(
        Mockery::on(fn ($integration) => $integration->id === $ctx['integration']->id),
        $order->external_order_id,
        'PRICE_DIVERGENCE',
    );
    app()->instance(IfoodGatewayContract::class, $gateway);

    $this->actingAs($admin);

    Livewire::test(OrdersShow::class, ['order' => $order])
        ->call('openIfoodCancelModal')
        ->set('ifoodCancelReason', 'PRICE_DIVERGENCE')
        ->call('confirmIfoodCancel')
        ->assertSet('showIfoodCancelModal', false);

    expect($order->fresh()->status)->toBe('cancelled');
});

test('confirmIfoodCancel na tela do pedido apos aceito chama requestCancellation e NAO muda status local', function () {
    $ctx = ifoodContext('sh4');
    $order = ifoodKanbanOrder($ctx, 'ready', 'ifood-show-4');
    $admin = User::factory()->create(['is_super_admin' => true]);

    $gateway = Mockery::mock(IfoodGatewayContract::class);
    $gateway->shouldReceive('requestCancellation')->once()->with(
        Mockery::on(fn ($integration) => $integration->id === $ctx['integration']->id),
        $order->external_order_id,
        'RESTAURANT_OUT_OF_OPERATION',
    );
    app()->instance(IfoodGatewayContract::class, $gateway);

    $this->actingAs($admin);

    Livewire::test(OrdersShow::class, ['order' => $order])
        ->call('openIfoodCancelModal')
        ->set('ifoodCancelReason', 'RESTAURANT_OUT_OF_OPERATION')
        ->call('confirmIfoodCancel');

    expect($order->fresh()->status)->toBe('ready');
});
