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

    Livewire::test(OrdersShow::class, ['order' => $order])
        ->call('updateStatus', 'preparing');

    expect($order->fresh()->status)->toBe('preparing');
});

test('botao cancelado na tela do pedido NAO cancela direto — abre modal de motivo', function () {
    $ctx = ifoodContext('sh2');
    $order = ifoodKanbanOrder($ctx, 'paid', 'ifood-show-2');
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

    Livewire::test(OrdersShow::class, ['order' => $order])
        ->call('updateStatus', 'cancelled')
        ->assertSet('showIfoodCancelModal', true);

    expect($order->fresh()->status)->toBe('paid');
});

test('confirmIfoodCancel na tela do pedido antes de aceito chama reject e aguarda confirmação', function () {
    $ctx = ifoodContext('sh3');
    $order = ifoodKanbanOrder($ctx, 'paid', 'ifood-show-3');
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
        '505',
    );
    app()->instance(IfoodGatewayContract::class, $gateway);

    $this->actingAs($admin);

    Livewire::test(OrdersShow::class, ['order' => $order])
        ->call('openIfoodCancelModal')
        ->set('ifoodCancelReason', '505')
        ->call('confirmIfoodCancel')
        ->assertSet('showIfoodCancelModal', false);

    expect($order->fresh()->status)->toBe('paid');
});

test('confirmIfoodCancel na tela do pedido apos aceito chama requestCancellation e NAO muda status local', function () {
    $ctx = ifoodContext('sh4');
    $order = ifoodKanbanOrder($ctx, 'ready', 'ifood-show-4');
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
        '501',
    );
    app()->instance(IfoodGatewayContract::class, $gateway);

    $this->actingAs($admin);

    Livewire::test(OrdersShow::class, ['order' => $order])
        ->call('openIfoodCancelModal')
        ->set('ifoodCancelReason', '501')
        ->call('confirmIfoodCancel');

    expect($order->fresh()->status)->toBe('ready');
});

test('superadmin visualiza conclusão e identificador iFood mesmo com outra empresa no contexto', function () {
    $ctx = ifoodContext('show-concluded');
    $order = ifoodKanbanOrder($ctx, 'delivered', 'ifood-concluded');
    $order->update(['external_metadata' => ['display_id' => '7360']]);
    ifoodContext('other-company');
    $this->actingAs(User::factory()->create(['is_super_admin' => true]));
    Livewire::test(OrdersShow::class, ['order' => $order->fresh()->withoutRelations()])
        ->assertSee('7360')->assertSee('ifood-concluded')->assertSee('Concluído')
        ->call('$refresh')->assertSee('Concluído');
});
