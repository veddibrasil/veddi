<?php

use App\Contracts\IfoodGatewayContract;
use App\Livewire\Admin\Orders\Index as OrdersIndex;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('drag pra preparing chama confirmOrder no iFood via kanban', function () {
    $ctx = ifoodContext('kb1');
    $order = ifoodKanbanOrder($ctx);
    $admin = User::factory()->create(['is_super_admin' => true]);

    $gateway = Mockery::mock(IfoodGatewayContract::class);
    $gateway->shouldReceive('getCancellationReasons')->andReturn([
        ['code' => '503', 'description' => 'Item indisponível'],
        ['code' => '508', 'description' => 'Fora do horário'],
        ['code' => '505', 'description' => 'Cardápio desatualizado'],
        ['code' => '501', 'description' => 'Problemas de sistema'],
    ]);
    $gateway->shouldReceive('confirmOrder')->once()->with(
        Mockery::on(fn ($integration) => $integration->id === $ctx['integration']->id),
        $order->external_order_id,
    );
    app()->instance(IfoodGatewayContract::class, $gateway);

    $this->actingAs($admin);

    Livewire::test(OrdersIndex::class)
        ->call('updateOrderStatus', $order->id, 'preparing');

    expect($order->fresh()->status)->toBe('preparing');
});

test('drag pra cancelled NAO cancela direto — abre modal de motivo', function () {
    $ctx = ifoodContext('kb2');
    $order = ifoodKanbanOrder($ctx);
    $admin = User::factory()->create(['is_super_admin' => true]);

    $gateway = Mockery::mock(IfoodGatewayContract::class);
    $gateway->shouldReceive('getCancellationReasons')->andReturn([
        ['code' => '503', 'description' => 'Item indisponível'],
        ['code' => '508', 'description' => 'Fora do horário'],
        ['code' => '505', 'description' => 'Cardápio desatualizado'],
        ['code' => '501', 'description' => 'Problemas de sistema'],
    ]);
    $gateway->shouldNotReceive('rejectOrder');
    $gateway->shouldNotReceive('requestCancellation');
    app()->instance(IfoodGatewayContract::class, $gateway);

    $this->actingAs($admin);

    Livewire::test(OrdersIndex::class)
        ->call('updateOrderStatus', $order->id, 'cancelled')
        ->assertSet('ifoodCancelOrderId', $order->id);

    expect($order->fresh()->status)->toBe('paid');
});

test('confirmIfoodCancel antes de aceito chama reject e aguarda confirmação', function () {
    $ctx = ifoodContext('kb3');
    $order = ifoodKanbanOrder($ctx);
    $admin = User::factory()->create(['is_super_admin' => true]);

    $gateway = Mockery::mock(IfoodGatewayContract::class);
    $gateway->shouldReceive('getCancellationReasons')->andReturn([
        ['code' => '503', 'description' => 'Item indisponível'],
        ['code' => '508', 'description' => 'Fora do horário'],
        ['code' => '505', 'description' => 'Cardápio desatualizado'],
        ['code' => '501', 'description' => 'Problemas de sistema'],
    ]);
    $gateway->shouldReceive('rejectOrder')->once()->with(
        Mockery::on(fn ($integration) => $integration->id === $ctx['integration']->id),
        $order->external_order_id,
        '508',
    );
    app()->instance(IfoodGatewayContract::class, $gateway);

    $this->actingAs($admin);

    Livewire::test(OrdersIndex::class)
        ->call('openIfoodCancelModal', $order->id)
        ->set('ifoodCancelReason', '508')
        ->call('confirmIfoodCancel')
        ->assertSet('ifoodCancelOrderId', null);

    expect($order->fresh()->status)->toBe('paid');
});

test('confirmIfoodCancel apos aceito chama requestCancellation e NAO muda status local', function () {
    $ctx = ifoodContext('kb4');
    $order = ifoodKanbanOrder($ctx, 'preparing');
    $admin = User::factory()->create(['is_super_admin' => true]);

    $gateway = Mockery::mock(IfoodGatewayContract::class);
    $gateway->shouldReceive('getCancellationReasons')->andReturn([
        ['code' => '503', 'description' => 'Item indisponível'],
        ['code' => '508', 'description' => 'Fora do horário'],
        ['code' => '505', 'description' => 'Cardápio desatualizado'],
        ['code' => '501', 'description' => 'Problemas de sistema'],
    ]);
    $gateway->shouldReceive('requestCancellation')->once()->with(
        Mockery::on(fn ($integration) => $integration->id === $ctx['integration']->id),
        $order->external_order_id,
        '503',
    );
    app()->instance(IfoodGatewayContract::class, $gateway);

    $this->actingAs($admin);

    Livewire::test(OrdersIndex::class)
        ->call('openIfoodCancelModal', $order->id)
        ->set('ifoodCancelReason', '503')
        ->call('confirmIfoodCancel');

    expect($order->fresh()->status)->toBe('preparing');
});
