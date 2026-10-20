<?php

require_once __DIR__.'/flows_helpers.php';

use App\Events\NewOrderPlaced;
use App\Events\OrderItemsUpdated;
use App\Events\OrderStatusUpdated;
use App\Livewire\Admin\Pdv\TabTerminal;
use App\Models\Order;
use App\Models\PdvCashSession;
use App\Models\User;
use App\Services\Order\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * AUDITORIA FLUXOS — comanda do PDV: remover/diminuir item devolve o estoque
 * (StockService::restoreQuantity grava movimento TYPE_ORDER_RESTORE), mas cancelar a
 * comanda depois chama StockService::restoreForOrder(), que soma TODAS as baixas
 * (TYPE_ORDER_DEDUCTION) do pedido e devolve de novo, sem descontar o que já voltou.
 * Resultado: estoque inflado (fantasma) a cada item tirado de comanda que depois é cancelada.
 */

beforeEach(function () {
    Event::fake([OrderItemsUpdated::class, OrderStatusUpdated::class, NewOrderPlaced::class]);
});

test('cancelar comanda depois de remover item não devolve o estoque duas vezes (serviço)', function () {
    $ctx = flowAuditContext(stock: 10);
    $service = app(OrderService::class);

    $order = $service->createOrder(
        customerId: $ctx['customer']->id,
        branchId: $ctx['branch']->id,
        cart: [(string) $ctx['product']->id => ['product_id' => $ctx['product']->id, 'qty' => 1]],
        notes: '',
        paymentMethod: '',
        orderType: 'pdv',
        status: 'pending',
    );
    $order->update(['is_open_tab' => true]);
    expect(flowAuditStock($ctx['branch'], $ctx['product']))->toBe(9);

    $service->addOrIncrementItem($order->fresh(), $ctx['product']);   // 2 un. na comanda
    expect(flowAuditStock($ctx['branch'], $ctx['product']))->toBe(8);

    $service->removeItemFromOrder($order->fresh(), $order->items()->first()); // garçom tira o item
    expect(flowAuditStock($ctx['branch'], $ctx['product']))->toBe(10);

    $service->cancelOrderAsAdmin($order->fresh(), null, 'Cliente foi embora');

    expect(flowAuditStock($ctx['branch'], $ctx['product']))->toBe(10);
});

test('cancelar comanda depois de diminuir a quantidade não infla o estoque (serviço)', function () {
    $ctx = flowAuditContext(stock: 10);
    $service = app(OrderService::class);

    $order = $service->createOrder(
        customerId: $ctx['customer']->id,
        branchId: $ctx['branch']->id,
        cart: [(string) $ctx['product']->id => ['product_id' => $ctx['product']->id, 'qty' => 3]],
        notes: '',
        paymentMethod: '',
        orderType: 'pdv',
        status: 'pending',
    );
    expect(flowAuditStock($ctx['branch'], $ctx['product']))->toBe(7);

    $service->updateOrderItemQuantity($order->fresh(), $order->items()->first(), 1);
    expect(flowAuditStock($ctx['branch'], $ctx['product']))->toBe(9);

    $service->cancelOrderAsAdmin($order->fresh(), null, 'Comanda aberta por engano');

    expect(flowAuditStock($ctx['branch'], $ctx['product']))->toBe(10);
});

test('fluxo real: garçom tira item na tela de comandas e admin cancela a comanda no painel — estoque não infla', function () {
    $ctx = flowAuditContext(['pdv_module_enabled' => true, 'waiter_module_enabled' => true], stock: 10);
    $admin = User::factory()->create(['is_super_admin' => true]);
    PdvCashSession::withoutGlobalScopes()->create([
        'company_id' => $ctx['company']->id,
        'branch_id' => $ctx['branch']->id,
        'user_id' => $admin->id,
        'opening_amount' => 0,
    ]);
    $table = \App\Models\RestaurantTable::create([
        'company_id' => $ctx['company']->id,
        'branch_id' => $ctx['branch']->id,
        'number' => 7,
        'active' => true,
    ]);

    $component = Livewire::actingAs($admin)
        ->test(TabTerminal::class)
        ->set('selectedBranchId', $ctx['branch']->id)
        ->set('selectedTableId', $table->id)
        ->call('sendProductToComanda', $ctx['product']->id)
        ->call('sendProductToComanda', $ctx['product']->id);

    $order = Order::withoutGlobalScopes()->where('company_id', $ctx['company']->id)->latest('id')->firstOrFail();
    expect(flowAuditStock($ctx['branch'], $ctx['product']))->toBe(8);

    $component->call('removeTabItem', $order->items()->first()->id);
    expect(flowAuditStock($ctx['branch'], $ctx['product']))->toBe(10);

    // Comanda é cancelada pelo painel de pedidos (a tela de comandas não tem cancelamento).
    Livewire::actingAs($admin)
        ->test(\App\Livewire\Admin\Orders\Show::class, ['order' => $order->fresh()])
        ->set('cancelReason', 'Mesa desistiu do pedido')
        ->call('confirmCancel');

    expect($order->fresh()->status)->toBe('cancelled');
    expect(flowAuditStock($ctx['branch'], $ctx['product']))->toBe(10);
});
