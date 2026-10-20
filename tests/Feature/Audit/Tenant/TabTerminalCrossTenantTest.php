<?php

/*
 * Auditoria 15/10/2026 — PDV Comandas (TabTerminal): openTabOrderId é público e
 * selectOpenTab(int $orderId) aceita qualquer id. removeTabItem/updateTabItemQuantity/
 * finalizeOrder/sendProductToComanda buscam a comanda com
 * Order::withoutGlobalScopes()->where('is_open_tab', true)->find($id) — sem company_id
 * nem branch_id. activeTabItems lê OrderItem (sem escopo) de qualquer pedido.
 * Os testes afirmam o comportamento seguro e devem FALHAR hoje.
 */

use App\Livewire\Admin\Pdv\TabTerminal;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

require_once __DIR__.'/TenantAuditHelpers.php';

uses(RefreshDatabase::class);

/** Empresa B com uma comanda aberta (2 unidades) criada pelo fluxo real do PDV. */
function atnForeignOpenTab(): array
{
    ['admin' => $adminB, 'product' => $productB, 'branch' => $branchB, 'company' => $companyB] = pdvContext();
    $tableB = openTable($companyB, $branchB, 7);

    test()->actingAs($adminB);
    Livewire::test(TabTerminal::class)
        ->set('selectedTableId', $tableB->id)
        ->call('addProduct', $productB->id)
        ->call('addProduct', $productB->id)
        ->assertHasNoErrors();

    $orderB = Order::withoutGlobalScopes()->where('company_id', $companyB->id)->firstOrFail();
    $itemB = OrderItem::where('order_id', $orderB->id)->firstOrFail();

    return compact('companyB', 'orderB', 'itemB');
}

test('garçom da empresa A não pode alterar/remover itens da comanda aberta da empresa B', function () {
    ['orderB' => $orderB, 'itemB' => $itemB] = atnForeignOpenTab();

    ['company' => $companyA, 'branch' => $branchA] = pdvContext(); // rebinda current.company = A
    $waiterA = makeWaiter($companyA, $branchA);

    atnAttempt(fn () => Livewire::actingAs($waiterA)
        ->test(TabTerminal::class)
        ->call('selectOpenTab', $orderB->id)
        ->call('updateTabItemQuantity', $itemB->id, 50));

    atnAttempt(fn () => Livewire::actingAs($waiterA)
        ->test(TabTerminal::class)
        ->call('selectOpenTab', $orderB->id)
        ->call('removeTabItem', $itemB->id));

    expect(OrderItem::find($itemB->id))->not->toBeNull()
        ->and(OrderItem::find($itemB->id)?->quantity)->toBe(2)
        ->and((float) $orderB->fresh()->total)->toBe(16.0);
});

test('garçom da empresa A não pode ler os itens da comanda da empresa B (activeTabItems)', function () {
    ['orderB' => $orderB, 'itemB' => $itemB] = atnForeignOpenTab();
    $itemB->update(['product_name' => 'Item Sigiloso da Empresa B']);

    ['company' => $companyA, 'branch' => $branchA] = pdvContext();
    $waiterA = makeWaiter($companyA, $branchA);

    $html = atnAttempt(fn () => Livewire::actingAs($waiterA)
        ->test(TabTerminal::class)
        ->call('selectOpenTab', $orderB->id)
        ->html());

    expect((string) $html)->not->toContain('Item Sigiloso da Empresa B');
});
