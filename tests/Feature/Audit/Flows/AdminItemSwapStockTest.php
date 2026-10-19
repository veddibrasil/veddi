<?php

require_once __DIR__.'/flows_helpers.php';

use App\Events\OrderItemsUpdated;
use App\Events\OrderStatusUpdated;
use App\Livewire\Admin\Orders\Show;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * AUDITORIA FLUXOS — "Trocar item" no detalhe do pedido (Admin\Orders\Show::applySwapItem +
 * saveItems) troca o produto do OrderItem sem mexer no estoque: o produto que saiu continua
 * baixado e o que entrou nunca é baixado. Um cancelamento depois ainda devolve o produto
 * errado (restoreForOrder segue os movimentos originais).
 */

beforeEach(function () {
    Event::fake([OrderItemsUpdated::class, OrderStatusUpdated::class]);
});

test('trocar item do pedido por outro de mesmo preço ajusta o estoque dos dois produtos', function () {
    $ctx = flowAuditContext(stock: 10, price: 20.0);
    $kibe = flowAuditProduct($ctx['company'], $ctx['branch'], $ctx['category'], 'Kibe', 20.0, 10);
    $admin = User::factory()->create(['is_super_admin' => true]);

    $order = flowAuditChatOrder($ctx, qty: 2, status: 'paid'); // 2 coxinhas
    expect(flowAuditStock($ctx['branch'], $ctx['product']))->toBe(8);

    Livewire::actingAs($admin)
        ->test(Show::class, ['order' => $order])
        ->call('startEditItems')
        ->call('openSwapItem', 0)
        ->call('selectSwapProduct', $kibe->id)
        ->set('swapQuantity', 2) // troca as duas unidades (openSwapItem começa em 1)
        ->call('applySwapItem')
        ->call('saveItems')
        ->assertHasNoErrors();

    expect($order->fresh()->items()->value('product_id'))->toBe($kibe->id);

    // Esperado: coxinha volta para 10, kibe cai para 8. Hoje: coxinha 8, kibe 10.
    expect(flowAuditStock($ctx['branch'], $ctx['product']))->toBe(10);
    expect(flowAuditStock($ctx['branch'], $kibe))->toBe(8);
});
