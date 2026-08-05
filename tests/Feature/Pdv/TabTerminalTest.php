<?php

use App\Events\TabOrderSentToProduction;
use App\Jobs\IssueFiscalNote;
use App\Livewire\Admin\Pdv\TabTerminal;
use App\Models\BranchServiceCharge;
use App\Models\CompanyFiscalConfig;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PdvCashSession;
use App\Models\Product;
use App\Models\RestaurantTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// Helpers pdvContext(), makeWaiter() e openTable() ficam em tests/Pest.php —
// compartilhados com TerminalTest.php.

// ─── Mesa/Comanda ─────────────────────────────────────────────────────────────

test('abrir comanda cria pedido pendente sem pagamento e deduz estoque', function () {
    ['admin' => $admin, 'product' => $product, 'branch' => $branch, 'company' => $company] = pdvContext();
    $table = openTable($company, $branch);

    DB::table('branch_product')
        ->where('branch_id', $branch->id)
        ->where('product_id', $product->id)
        ->update(['track_stock' => true, 'quantity' => 10]);

    $this->actingAs($admin);

    Livewire::test(TabTerminal::class)
        ->assertSet('branchUsesRegisteredTables', true)
        ->set('selectedTableId', $table->id)
        ->call('addProduct', $product->id)
        ->assertHasNoErrors()
        ->assertSet('cart', []);

    $order = Order::withoutGlobalScopes()->first();
    expect($order->status)->toBe('pending');
    expect($order->is_open_tab)->toBeTrue();
    expect($order->table_label)->toBe('Mesa 5');
    expect($order->restaurant_table_id)->toBe($table->id);
    expect((float) $order->total)->toBe(8.0);
    expect(Payment::where('order_id', $order->id)->exists())->toBeFalse();

    $stock = DB::table('branch_product')->where('product_id', $product->id)->first();
    expect((int) $stock->quantity)->toBe(9);
});

test('filial sem mesas cadastradas não permite abrir comanda', function () {
    ['admin' => $admin, 'product' => $product] = pdvContext();

    $this->actingAs($admin);

    Livewire::test(TabTerminal::class)
        ->assertSet('branchUsesRegisteredTables', false)
        ->assertCount('availableTables', 0)
        ->call('addProduct', $product->id)
        ->assertHasErrors('selectedTableId');

    expect(Order::withoutGlobalScopes()->count())->toBe(0);
});

test('operador cadastra mesa direto do terminal e já consegue selecioná-la', function () {
    ['admin' => $admin, 'product' => $product] = pdvContext();

    $this->actingAs($admin);

    $component = Livewire::test(TabTerminal::class)
        ->assertSet('branchUsesRegisteredTables', false)
        ->set('newTableNumber', '12')
        ->call('registerTable')
        ->assertHasNoErrors()
        ->assertSet('branchUsesRegisteredTables', true);

    $table = RestaurantTable::where('number', 12)->first();
    expect($table)->not->toBeNull();
    $component->assertSet('selectedTableId', $table->id);

    $component
        ->call('addProduct', $product->id)
        ->assertHasNoErrors();

    $order = Order::withoutGlobalScopes()->first();
    expect($order->table_label)->toBe('Mesa 12');
    expect($order->restaurant_table_id)->toBe($table->id);
});

test('cadastro de mesa rejeita número duplicado', function () {
    ['admin' => $admin, 'branch' => $branch, 'company' => $company] = pdvContext();
    openTable($company, $branch, 5);

    $this->actingAs($admin);

    Livewire::test(TabTerminal::class)
        ->set('newTableNumber', '5')
        ->call('registerTable')
        ->assertHasErrors('newTableNumber');

    expect(RestaurantTable::where('number', 5)->count())->toBe(1);
});

test('garçom não consegue cadastrar mesa', function () {
    ['company' => $company, 'branch' => $branch] = pdvContext();
    $waiter = makeWaiter($company, $branch);

    Livewire::actingAs($waiter)
        ->test(TabTerminal::class)
        ->set('newTableNumber', '3')
        ->call('registerTable')
        ->assertForbidden();

    expect(RestaurantTable::where('number', 3)->count())->toBe(0);
});

test('mesa ocupada some da lista de mesas disponíveis e não pode ser reaberta', function () {
    ['admin' => $admin, 'product' => $product, 'branch' => $branch, 'company' => $company] = pdvContext();

    $table5 = RestaurantTable::create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'number' => 5,
        'active' => true,
    ]);

    $this->actingAs($admin);

    $component = Livewire::test(TabTerminal::class)
        ->set('selectedTableId', $table5->id)
        ->call('addProduct', $product->id)
        ->assertHasNoErrors();

    expect($component->get('availableTables'))->toHaveCount(0);

    $component
        ->call('deselectOpenTab')
        ->set('selectedTableId', $table5->id)
        ->call('addProduct', $product->id)
        ->assertHasErrors('selectedTableId');

    expect(Order::withoutGlobalScopes()->count())->toBe(1);
});

test('mesa desativada não aparece disponível no PDV', function () {
    ['admin' => $admin, 'product' => $product, 'branch' => $branch, 'company' => $company] = pdvContext();

    RestaurantTable::create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'number' => 5,
        'active' => false,
    ]);

    $this->actingAs($admin);

    Livewire::test(TabTerminal::class)
        ->call('addProduct', $product->id)
        ->assertSet('branchUsesRegisteredTables', true)
        ->assertCount('availableTables', 0);
});

test('primeiro clique num produto já abre a comanda e mantém selecionada pra continuar lançando itens', function () {
    ['admin' => $admin, 'product' => $product, 'branch' => $branch, 'company' => $company] = pdvContext();
    $table = openTable($company, $branch);

    $this->actingAs($admin);

    $component = Livewire::test(TabTerminal::class)
        ->set('selectedTableId', $table->id)
        ->call('addProduct', $product->id)
        ->assertHasNoErrors();

    $orderId = Order::withoutGlobalScopes()->first()->id;
    $component->assertSet('openTabOrderId', $orderId);
});

test('itens da comanda selecionada aparecem automaticamente, sem precisar clicar', function () {
    ['admin' => $admin, 'product' => $product, 'branch' => $branch, 'company' => $company] = pdvContext();
    $table = openTable($company, $branch);

    $this->actingAs($admin);

    $component = Livewire::test(TabTerminal::class)
        ->set('selectedTableId', $table->id)
        ->call('addProduct', $product->id)
        ->assertHasNoErrors();

    $items = $component->get('activeTabItems');
    expect($items)->toHaveCount(1);
    expect($items->first()->product_name)->toBe('Coxinha');
});

test('atendente alterna visualização de itens de uma comanda na lista com um clique', function () {
    ['admin' => $admin, 'product' => $product, 'branch' => $branch, 'company' => $company] = pdvContext();
    $table = openTable($company, $branch);

    $this->actingAs($admin);

    $component = Livewire::test(TabTerminal::class)
        ->set('selectedTableId', $table->id)
        ->call('addProduct', $product->id)
        ->call('deselectOpenTab')
        ->assertHasNoErrors();

    $order = Order::withoutGlobalScopes()->first();

    $component
        ->call('toggleTabItems', $order->id)
        ->assertSet('viewingTabItemsOrderId', $order->id);

    $items = $component->get('viewingTabItems');
    expect($items)->toHaveCount(1);
    expect($items->first()->product_name)->toBe('Coxinha');

    $component
        ->call('toggleTabItems', $order->id)
        ->assertSet('viewingTabItemsOrderId', null);
});

test('clicar de novo no mesmo produto soma na mesma linha da comanda e deduz estoque de novo', function () {
    ['admin' => $admin, 'product' => $product, 'branch' => $branch, 'company' => $company] = pdvContext();
    $table = openTable($company, $branch);

    DB::table('branch_product')
        ->where('branch_id', $branch->id)
        ->where('product_id', $product->id)
        ->update(['track_stock' => true, 'quantity' => 10]);

    $this->actingAs($admin);

    $component = Livewire::test(TabTerminal::class)
        ->set('selectedTableId', $table->id)
        ->call('addProduct', $product->id)
        ->assertHasNoErrors();

    $orderId = Order::withoutGlobalScopes()->first()->id;
    $component->assertSet('openTabOrderId', $orderId);

    $component
        ->call('addProduct', $product->id)
        ->assertHasNoErrors()
        ->assertSet('openTabOrderId', $orderId);

    $order = Order::withoutGlobalScopes()->find($orderId);
    expect((float) $order->total)->toBe(16.0);
    expect($order->items()->count())->toBe(1);
    expect((int) $order->items()->first()->quantity)->toBe(2);

    $stock = DB::table('branch_product')->where('product_id', $product->id)->first();
    expect((int) $stock->quantity)->toBe(8);
});

test('operador aumenta e diminui quantidade de um item já lançado na comanda, ajustando estoque e total', function () {
    ['admin' => $admin, 'product' => $product, 'branch' => $branch, 'company' => $company] = pdvContext();
    $table = openTable($company, $branch);

    DB::table('branch_product')
        ->where('branch_id', $branch->id)
        ->where('product_id', $product->id)
        ->update(['track_stock' => true, 'quantity' => 10]);

    $this->actingAs($admin);

    $component = Livewire::test(TabTerminal::class)
        ->set('selectedTableId', $table->id)
        ->call('addProduct', $product->id)
        ->assertHasNoErrors();

    $orderId = Order::withoutGlobalScopes()->first()->id;
    $item = Order::withoutGlobalScopes()->find($orderId)->items()->first();

    $component
        ->call('updateTabItemQuantity', $item->id, 3)
        ->assertHasNoErrors();

    $order = Order::withoutGlobalScopes()->find($orderId);
    expect((float) $order->total)->toBe(24.0);
    expect($order->items()->count())->toBe(1);

    $stock = DB::table('branch_product')->where('product_id', $product->id)->first();
    expect((int) $stock->quantity)->toBe(7);

    $component->call('updateTabItemQuantity', $item->id, 1)->assertHasNoErrors();

    $order = Order::withoutGlobalScopes()->find($orderId);
    expect((float) $order->total)->toBe(8.0);

    $stock = DB::table('branch_product')->where('product_id', $product->id)->first();
    expect((int) $stock->quantity)->toBe(9);
});

test('operador remove um item da comanda, restaura estoque e recalcula o total', function () {
    ['admin' => $admin, 'product' => $product, 'branch' => $branch, 'company' => $company] = pdvContext();
    $table = openTable($company, $branch);

    DB::table('branch_product')
        ->where('branch_id', $branch->id)
        ->where('product_id', $product->id)
        ->update(['track_stock' => true, 'quantity' => 10]);

    $this->actingAs($admin);

    $component = Livewire::test(TabTerminal::class)
        ->set('selectedTableId', $table->id)
        ->call('addProduct', $product->id)
        ->call('addProduct', $product->id)
        ->assertHasNoErrors();

    $orderId = Order::withoutGlobalScopes()->first()->id;
    $item = Order::withoutGlobalScopes()->find($orderId)->items()->first();

    $component
        ->call('removeTabItem', $item->id)
        ->assertHasNoErrors();

    $order = Order::withoutGlobalScopes()->find($orderId);
    expect($order->items()->count())->toBe(0);
    expect((float) $order->total)->toBe(0.0);

    $stock = DB::table('branch_product')->where('product_id', $product->id)->first();
    expect((int) $stock->quantity)->toBe(10);
});

test('reduzir quantidade a zero remove o item da comanda', function () {
    ['admin' => $admin, 'product' => $product, 'branch' => $branch, 'company' => $company] = pdvContext();
    $table = openTable($company, $branch);

    $this->actingAs($admin);

    $component = Livewire::test(TabTerminal::class)
        ->set('selectedTableId', $table->id)
        ->call('addProduct', $product->id)
        ->assertHasNoErrors();

    $orderId = Order::withoutGlobalScopes()->first()->id;
    $item = Order::withoutGlobalScopes()->find($orderId)->items()->first();

    $component
        ->call('updateTabItemQuantity', $item->id, 0)
        ->assertHasNoErrors();

    $order = Order::withoutGlobalScopes()->find($orderId);
    expect($order->items()->count())->toBe(0);
});

test('garçom edita quantidade e remove item de uma comanda aberta, sem precisar do caixa', function () {
    ['company' => $company, 'branch' => $branch, 'product' => $product] = pdvContext();
    $waiter = makeWaiter($company, $branch);
    $table = openTable($company, $branch, 9);

    $product2 = Product::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'product_category_id' => $product->product_category_id,
        'name' => 'Refrigerante',
        'price' => 6.00,
        'active' => true,
        'sort_order' => 2,
    ]);
    DB::table('branch_product')->insert([
        'branch_id' => $branch->id,
        'product_id' => $product2->id,
        'available' => 1,
    ]);

    $component = Livewire::actingAs($waiter)
        ->test(TabTerminal::class)
        ->set('selectedTableId', $table->id)
        ->call('addProduct', $product->id)
        ->call('addProduct', $product2->id)
        ->assertHasNoErrors();

    $orderId = Order::withoutGlobalScopes()->first()->id;
    $items = Order::withoutGlobalScopes()->find($orderId)->items;

    $component
        ->call('updateTabItemQuantity', $items[0]->id, 5)
        ->assertHasNoErrors()
        ->call('removeTabItem', $items[1]->id)
        ->assertHasNoErrors();

    $order = Order::withoutGlobalScopes()->find($orderId);
    expect($order->items()->count())->toBe(1);
    expect((int) $order->items()->first()->quantity)->toBe(5);
});

test('editar item de comanda já fechada não é permitido', function () {
    ['admin' => $admin, 'product' => $product, 'branch' => $branch, 'company' => $company] = pdvContext();
    $table = openTable($company, $branch);

    $this->actingAs($admin);

    $component = Livewire::test(TabTerminal::class)
        ->set('selectedTableId', $table->id)
        ->call('addProduct', $product->id)
        ->assertHasNoErrors();

    $orderId = Order::withoutGlobalScopes()->first()->id;
    $item = Order::withoutGlobalScopes()->find($orderId)->items()->first();

    $component
        ->call('proceedToCloseTab', $orderId)
        ->set('paymentMethod', 'cash')
        ->set('cashReceivedInput', '10.00')
        ->call('confirmCloseTab')
        ->assertSet('step', 'catalog');

    Livewire::test(TabTerminal::class)
        ->set('openTabOrderId', $orderId)
        ->call('updateTabItemQuantity', $item->id, 5)
        ->assertHasErrors('order');

    $order = Order::withoutGlobalScopes()->find($orderId);
    expect((int) $order->items()->first()->quantity)->toBe(1);
});

test('fechar comanda com dinheiro cobra e marca como paga', function () {
    ['admin' => $admin, 'product' => $product, 'branch' => $branch, 'company' => $company] = pdvContext();
    $table = openTable($company, $branch);

    $this->actingAs($admin);

    $component = Livewire::test(TabTerminal::class)
        ->set('selectedTableId', $table->id)
        ->call('addProduct', $product->id)
        ->assertHasNoErrors();

    $orderId = Order::withoutGlobalScopes()->first()->id;

    $component
        ->call('proceedToCloseTab', $orderId)
        ->assertSet('step', 'payment')
        ->set('paymentMethod', 'cash')
        ->assertDontSee('Troco: R$')
        ->set('cashReceivedInput', '10.00')
        // Preview de troco calculado assim que o valor recebido é informado, antes de confirmar.
        ->assertSee('Troco: R$ 2,00')
        ->call('confirmCloseTab')
        // Mesmo comportamento do Terminal (caixa livre): não trava em tela de sucesso —
        // volta direto pra seleção de comandas (selectedTableId também limpo) com o
        // resultado só no card flutuante do topo, sem bloquear o próximo atendimento.
        ->assertSet('step', 'catalog')
        ->assertSet('selectedTableId', null)
        ->assertSet('openTabOrderId', null)
        ->assertSet('lastOrderId', $orderId)
        ->assertSee('fechada');

    $order = Order::withoutGlobalScopes()->find($orderId);
    expect($order->is_open_tab)->toBeFalse();
    expect($order->status)->toBe('paid');
    expect($order->payment_method)->toBe('cash');

    $payment = Payment::where('order_id', $orderId)->first();
    expect($payment)->not->toBeNull();
    expect($payment->status)->toBe('paid');
});

test('fechar comanda vinculando cliente informado pelo mesário salva o cliente no pedido', function () {
    ['admin' => $admin, 'product' => $product, 'company' => $company, 'branch' => $branch] = pdvContext();
    $table = openTable($company, $branch);

    $customer = Customer::create([
        'company_id' => $company->id,
        'name' => 'Maria Cliente',
        'phone' => '11999998888',
    ]);

    $this->actingAs($admin);

    $component = Livewire::test(TabTerminal::class)
        ->set('selectedTableId', $table->id)
        ->call('addProduct', $product->id)
        ->assertHasNoErrors();

    $orderId = Order::withoutGlobalScopes()->first()->id;

    // Comanda abre sem cliente vinculado (guest); o mesário só identifica o cliente no fechamento.
    expect(Order::withoutGlobalScopes()->find($orderId)->customer->phone)->toBe('pdv-guest');

    $component
        ->call('proceedToCloseTab', $orderId)
        ->assertSet('step', 'payment')
        ->call('selectCustomer', $customer->id)
        ->set('paymentMethod', 'cash')
        ->set('cashReceivedInput', '10.00')
        ->call('confirmCloseTab')
        ->assertSet('step', 'catalog');

    $order = Order::withoutGlobalScopes()->find($orderId);
    expect($order->customer_id)->toBe($customer->id);
});

test('fechar comanda com dinheiro dispara emissão automática de nota fiscal', function () {
    ['admin' => $admin, 'product' => $product, 'company' => $company, 'branch' => $branch] = pdvContext();
    $table = openTable($company, $branch);

    $company->update(['fiscal_notes_enabled' => true]);
    CompanyFiscalConfig::create(['company_id' => $company->id, 'enabled' => true]);

    $this->actingAs($admin);

    $component = Livewire::test(TabTerminal::class)
        ->set('selectedTableId', $table->id)
        ->call('addProduct', $product->id)
        ->assertHasNoErrors();

    $orderId = Order::withoutGlobalScopes()->first()->id;

    Bus::fake();

    $component
        ->call('proceedToCloseTab', $orderId)
        ->set('paymentMethod', 'cash')
        ->set('cashReceivedInput', '10.00')
        ->call('confirmCloseTab')
        ->assertSet('step', 'catalog');

    Bus::assertDispatched(IssueFiscalNote::class, fn ($job) => $job->orderId === $orderId);
});

test('botão Finalizar Pedido sempre avisa o operador, com ou sem impressora configurada', function () {
    ['admin' => $admin, 'product' => $product, 'branch' => $branch, 'company' => $company] = pdvContext();
    $table = openTable($company, $branch);

    $this->actingAs($admin);

    $component = Livewire::test(TabTerminal::class)
        ->set('selectedTableId', $table->id)
        ->call('addProduct', $product->id)
        ->assertHasNoErrors();

    Event::fake([TabOrderSentToProduction::class]);

    $component->call('finalizeOrder')
        ->assertDispatched('pdv-toast', message: 'Pedido de Mesa 5 enviado pra produção.');

    // Sem impressora cadastrada na filial não há o que mandar pra produção — evento
    // de broadcast não dispara à toa.
    Event::assertNotDispatched(TabOrderSentToProduction::class);
});

test('Finalizar Pedido broadcasta pra TODAS as impressoras configuradas (geral + cozinha), ignorando entrega e inativa', function () {
    ['admin' => $admin, 'product' => $product, 'branch' => $branch, 'company' => $company] = pdvContext();
    $table = openTable($company, $branch);

    \App\Models\BranchPrinter::create([
        'company_id' => $company->id, 'branch_id' => $branch->id, 'station' => 'geral',
        'ip_address' => '192.168.0.10', 'port' => 9100, 'paper_width' => 80, 'active' => true,
    ]);
    \App\Models\BranchPrinter::create([
        'company_id' => $company->id, 'branch_id' => $branch->id, 'station' => 'cozinha',
        'ip_address' => '192.168.0.11', 'port' => 9100, 'paper_width' => 80, 'active' => true,
    ]);
    \App\Models\BranchPrinter::create([
        'company_id' => $company->id, 'branch_id' => $branch->id, 'station' => 'entrega',
        'ip_address' => '192.168.0.12', 'port' => 9100, 'paper_width' => 80, 'active' => true,
    ]);
    \App\Models\BranchPrinter::create([
        'company_id' => $company->id, 'branch_id' => $branch->id, 'station' => 'bar',
        'ip_address' => '192.168.0.13', 'port' => 9100, 'paper_width' => 80, 'active' => false,
    ]);

    $this->actingAs($admin);

    $component = Livewire::test(TabTerminal::class)
        ->set('selectedTableId', $table->id)
        ->call('addProduct', $product->id)
        ->assertHasNoErrors();

    $orderId = Order::withoutGlobalScopes()->first()->id;

    Event::fake([TabOrderSentToProduction::class]);

    $component->call('finalizeOrder');

    Event::assertDispatched(TabOrderSentToProduction::class, fn ($event) => $event->order->id === $orderId
        && collect($event->stations)->sort()->values()->all() === ['cozinha', 'geral']);
});

test('Finalizar Pedido sem comanda selecionada não dispara nada', function () {
    ['admin' => $admin] = pdvContext();

    $this->actingAs($admin);

    Event::fake([TabOrderSentToProduction::class]);

    Livewire::test(TabTerminal::class)
        ->call('finalizeOrder')
        ->assertNotDispatched('pdv-toast');

    Event::assertNotDispatched(TabOrderSentToProduction::class);
});

test('garçom também pode usar o Finalizar Pedido (é o passo dele, não do caixa)', function () {
    ['company' => $company, 'branch' => $branch, 'product' => $product] = pdvContext();
    $table = openTable($company, $branch);
    $waiter = makeWaiter($company, $branch);

    \App\Models\BranchPrinter::create([
        'company_id' => $company->id, 'branch_id' => $branch->id, 'station' => 'cozinha',
        'ip_address' => '192.168.0.11', 'port' => 9100, 'paper_width' => 80, 'active' => true,
    ]);

    $this->actingAs($waiter);

    $component = Livewire::test(TabTerminal::class)
        ->set('selectedTableId', $table->id)
        ->call('addProduct', $product->id)
        ->assertHasNoErrors();

    $orderId = Order::withoutGlobalScopes()->first()->id;

    Event::fake([TabOrderSentToProduction::class]);

    $component->call('finalizeOrder')
        ->assertDispatched('pdv-toast');

    Event::assertDispatched(TabOrderSentToProduction::class, fn ($event) => $event->order->id === $orderId);
});

// ─── Impressão automática pra quem clicou "Finalizar Pedido" longe da impressora ──

test('broadcast de comanda finalizada dispara tab-order-finalized na tela de PDV com a filial certa', function () {
    ['admin' => $admin, 'branch' => $branch] = pdvContext();

    $this->actingAs($admin);

    Livewire::test(TabTerminal::class)
        ->call('onTabOrderSentToProductionBroadcast', [
            'order_id' => 555,
            'branch_id' => $branch->id,
            'stations' => ['cozinha', 'geral'],
        ])
        ->assertDispatched('tab-order-finalized', orderId: 555);
});

test('broadcast de comanda finalizada de outra filial não dispara nada nesta tela', function () {
    ['admin' => $admin, 'branch' => $branch] = pdvContext();

    $this->actingAs($admin);

    Livewire::test(TabTerminal::class)
        ->call('onTabOrderSentToProductionBroadcast', [
            'order_id' => 554,
            'branch_id' => $branch->id + 999,
            'stations' => ['cozinha'],
        ])
        ->assertNotDispatched('tab-order-finalized');
});

test('checkbox de imprimir nota fiscal só aparece com o módulo fiscal habilitado na empresa', function () {
    ['admin' => $admin] = pdvContext();

    $this->actingAs($admin);

    Livewire::test(TabTerminal::class)
        ->assertSet('canUseFiscalNotes', false)
        ->assertSet('printFiscalNote', false);
});

test('fechar comanda com checkbox de nota fiscal marcado dispara order-paid com printFiscalNote, mesmo sem impressora de auto-print', function () {
    ['admin' => $admin, 'product' => $product, 'company' => $company, 'branch' => $branch] = pdvContext();
    $table = openTable($company, $branch);

    $company->update(['fiscal_notes_enabled' => true]);

    $this->actingAs($admin);

    $component = Livewire::test(TabTerminal::class)
        ->assertSet('canUseFiscalNotes', true)
        ->set('selectedTableId', $table->id)
        ->call('addProduct', $product->id)
        ->assertHasNoErrors();

    $orderId = Order::withoutGlobalScopes()->first()->id;

    $component
        ->call('proceedToCloseTab', $orderId)
        ->set('paymentMethod', 'cash')
        ->set('cashReceivedInput', '10.00')
        ->set('printFiscalNote', true)
        ->call('confirmCloseTab')
        ->assertSet('step', 'catalog')
        ->assertDispatched('order-paid', printFiscalNote: true, stations: []);
});

test('fechar comanda NÃO reimprime cupom mesmo com impressora de auto-print configurada — cupom já saiu no Finalizar Pedido', function () {
    ['admin' => $admin, 'product' => $product, 'company' => $company, 'branch' => $branch] = pdvContext();
    $table = openTable($company, $branch);

    $company->update(['fiscal_notes_enabled' => true]);

    \App\Models\BranchPrinter::create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'station' => 'geral',
        'ip_address' => '192.168.0.50',
        'port' => 9100,
        'paper_width' => 80,
        'auto_print' => true,
        'active' => true,
    ]);

    $this->actingAs($admin);

    $component = Livewire::test(TabTerminal::class)
        ->set('selectedTableId', $table->id)
        ->call('addProduct', $product->id)
        ->assertHasNoErrors();

    $orderId = Order::withoutGlobalScopes()->first()->id;

    $component
        ->call('proceedToCloseTab', $orderId)
        ->set('paymentMethod', 'cash')
        ->set('cashReceivedInput', '10.00')
        ->set('printFiscalNote', true)
        ->call('confirmCloseTab')
        ->assertSet('step', 'catalog')
        ->assertDispatched('order-paid', printFiscalNote: true, stations: []);
});

test('fechar comanda sem marcar o checkbox e sem impressora de auto-print não dispara order-paid', function () {
    ['admin' => $admin, 'product' => $product, 'company' => $company, 'branch' => $branch] = pdvContext();
    $table = openTable($company, $branch);

    $this->actingAs($admin);

    $component = Livewire::test(TabTerminal::class)
        ->set('selectedTableId', $table->id)
        ->call('addProduct', $product->id)
        ->assertHasNoErrors();

    $orderId = Order::withoutGlobalScopes()->first()->id;

    $component
        ->call('proceedToCloseTab', $orderId)
        ->set('paymentMethod', 'cash')
        ->set('cashReceivedInput', '10.00')
        ->call('confirmCloseTab')
        ->assertSet('step', 'catalog')
        ->assertNotDispatched('order-paid');
});

test('comanda aberta não aparece em shiftStats nem sessionOrders', function () {
    ['admin' => $admin, 'product' => $product, 'branch' => $branch, 'company' => $company] = pdvContext();
    $table = openTable($company, $branch);

    $this->actingAs($admin);

    $component = Livewire::test(TabTerminal::class)
        ->set('selectedTableId', $table->id)
        ->call('addProduct', $product->id)
        ->assertHasNoErrors();

    expect($component->instance()->shiftStats()['orders'])->toBe(0);
    expect($component->instance()->sessionOrders()->count())->toBe(0);
    expect($component->instance()->openTabs()->count())->toBe(1);
});

test('comanda aberta individualmente aparece no painel de comandas com o total correto no mesmo request', function () {
    ['admin' => $admin, 'product' => $product, 'branch' => $branch, 'company' => $company] = pdvContext();
    $table = openTable($company, $branch, 9);

    $this->actingAs($admin);

    $component = Livewire::test(TabTerminal::class)
        ->set('selectedTableId', $table->id)
        ->call('addProduct', $product->id)
        ->assertHasNoErrors();

    $tabs = $component->get('openTabs');
    expect($tabs)->toHaveCount(1);
    expect((float) $tabs->first()->total)->toBe(8.0);
});

test('taxa de serviço e couvert acompanham comanda de mesa ao longo de novas rodadas', function () {
    ['admin' => $admin, 'product' => $product, 'branch' => $branch, 'company' => $company] = pdvContext();
    $table = openTable($company, $branch, 3);

    BranchServiceCharge::create([
        'branch_id' => $branch->id,
        'company_id' => $branch->company_id,
        'service_fee_enabled' => true,
        'service_fee_type' => 'percent',
        'service_fee_value' => 10,
        'couvert_enabled' => true,
        'couvert_type' => 'fixed',
        'couvert_value' => 5,
    ]);

    $this->actingAs($admin);

    $component = Livewire::test(TabTerminal::class)
        ->set('selectedTableId', $table->id)
        ->call('addProduct', $product->id)
        ->assertHasNoErrors();

    $orderId = Order::withoutGlobalScopes()->first()->id;
    $order = Order::withoutGlobalScopes()->find($orderId);
    expect((float) $order->service_fee)->toBe(0.8);
    expect((float) $order->couvert_fee)->toBe(5.0);
    expect((float) $order->total)->toBe(13.8);

    $component
        ->call('addProduct', $product->id)
        ->assertHasNoErrors();

    $order = Order::withoutGlobalScopes()->find($orderId);
    expect((float) $order->subtotal)->toBe(16.0);
    expect((float) $order->service_fee)->toBe(1.6);
    expect((float) $order->couvert_fee)->toBe(5.0);
    expect((float) $order->total)->toBe(22.6);
});

test('operador remove taxa de serviço só na hora de fechar a comanda, sem afetar rodadas anteriores', function () {
    ['admin' => $admin, 'product' => $product, 'branch' => $branch, 'company' => $company] = pdvContext();
    $table = openTable($company, $branch, 4);

    BranchServiceCharge::create([
        'branch_id' => $branch->id,
        'company_id' => $branch->company_id,
        'service_fee_enabled' => true,
        'service_fee_type' => 'percent',
        'service_fee_value' => 10,
        'couvert_enabled' => true,
        'couvert_type' => 'fixed',
        'couvert_value' => 5,
    ]);

    $this->actingAs($admin);

    $component = Livewire::test(TabTerminal::class)
        ->set('selectedTableId', $table->id)
        ->call('addProduct', $product->id)
        ->assertHasNoErrors();

    $orderId = Order::withoutGlobalScopes()->first()->id;

    $component
        ->call('proceedToCloseTab', $orderId)
        ->set('serviceFeeWaived', true)
        ->set('couvertFeeWaived', true)
        ->set('paymentMethod', 'cash')
        ->call('confirmCloseTab')
        ->assertHasNoErrors();

    $order = Order::withoutGlobalScopes()->find($orderId);
    expect((float) $order->subtotal)->toBe(8.0);
    expect((float) $order->service_fee)->toBe(0.0);
    expect((float) $order->couvert_fee)->toBe(0.0);
    expect((float) $order->total)->toBe(8.0);
});

// ─── Garçom ───────────────────────────────────────────────────────────────────

test('garçom carrega terminal sem sessão de caixa, direto no catálogo em modo mesa', function () {
    ['company' => $company, 'branch' => $branch] = pdvContext();
    $waiter = makeWaiter($company, $branch);

    Livewire::actingAs($waiter)
        ->test(TabTerminal::class)
        ->assertSet('isWaiter', true)
        ->assertSet('step', 'catalog')
        ->assertSet('cashSessionId', null);
});

test('garçom abre comanda e lança itens sem precisar de caixa', function () {
    ['company' => $company, 'branch' => $branch, 'product' => $product] = pdvContext();
    $waiter = makeWaiter($company, $branch);
    $table = openTable($company, $branch, 7);

    Livewire::actingAs($waiter)
        ->test(TabTerminal::class)
        ->set('selectedTableId', $table->id)
        ->call('addProduct', $product->id)
        ->assertHasNoErrors();

    $order = Order::withoutGlobalScopes()->first();
    expect($order->table_label)->toBe('Mesa 7');
    expect($order->is_open_tab)->toBeTrue();
    expect($order->pdv_cash_session_id)->toBeNull();
});

test('garçom vê comanda aberta por outro operador da mesma filial', function () {
    ['company' => $company, 'branch' => $branch, 'product' => $product, 'admin' => $admin] = pdvContext();
    $waiter = makeWaiter($company, $branch);
    $table = openTable($company, $branch, 2);

    // Caixa (admin) abre uma comanda na própria sessão.
    Livewire::actingAs($admin)
        ->test(TabTerminal::class)
        ->set('selectedTableId', $table->id)
        ->call('addProduct', $product->id)
        ->assertHasNoErrors();

    $tabs = Livewire::actingAs($waiter)
        ->test(TabTerminal::class)
        ->get('openTabs');

    expect($tabs)->toHaveCount(1);
    expect($tabs->first()->table_label)->toBe('Mesa 2');
});

test('caixa fecha e paga comanda aberta pelo garçom, e o valor entra na conferência do caixa', function () {
    ['company' => $company, 'branch' => $branch, 'product' => $product, 'admin' => $admin] = pdvContext();
    $waiter = makeWaiter($company, $branch);
    $table = openTable($company, $branch, 8);

    Livewire::actingAs($waiter)
        ->test(TabTerminal::class)
        ->set('selectedTableId', $table->id)
        ->call('addProduct', $product->id)
        ->assertHasNoErrors();

    $orderId = Order::withoutGlobalScopes()->first()->id;

    $cashier = Livewire::actingAs($admin)->test(TabTerminal::class);
    $cashSessionId = $cashier->get('cashSessionId');

    $cashier
        ->call('proceedToCloseTab', $orderId)
        ->assertSet('step', 'payment')
        ->set('paymentMethod', 'cash')
        ->set('cashReceivedInput', '10.00')
        ->call('confirmCloseTab')
        ->assertSet('step', 'catalog');

    $order = Order::withoutGlobalScopes()->find($orderId);
    expect($order->status)->toBe('paid');
    expect($order->is_open_tab)->toBeFalse();
    expect($order->pdv_cash_session_id)->toBe($cashSessionId);

    $session = PdvCashSession::withoutGlobalScopes()->find($cashSessionId);
    expect($cashier->instance()->cashSessionExpected($session))->toBe(8.0);
});

test('garçom não consegue abrir nem fechar caixa', function () {
    ['company' => $company, 'branch' => $branch] = pdvContext();
    $waiter = makeWaiter($company, $branch);

    Livewire::actingAs($waiter)
        ->test(TabTerminal::class)
        ->call('openCashSession')
        ->assertForbidden();

    Livewire::actingAs($waiter)
        ->test(TabTerminal::class)
        ->call('proceedToCloseCash')
        ->assertForbidden();

    Livewire::actingAs($waiter)
        ->test(TabTerminal::class)
        ->call('closeCashSession')
        ->assertForbidden();
});

test('garçom não consegue registrar movimentação de caixa', function () {
    ['company' => $company, 'branch' => $branch] = pdvContext();
    $waiter = makeWaiter($company, $branch);

    Livewire::actingAs($waiter)
        ->test(TabTerminal::class)
        ->call('toggleCashMovementForm', 'supply')
        ->assertForbidden();

    Livewire::actingAs($waiter)
        ->test(TabTerminal::class)
        ->call('registerCashMovement')
        ->assertForbidden();
});

test('garçom não consegue aplicar desconto manual', function () {
    ['company' => $company, 'branch' => $branch] = pdvContext();
    $company->update(['pdv_manual_discount_enabled' => true]);
    $waiter = makeWaiter($company, $branch);

    // set() já dispara updatedManualDiscountInput() -> applyManualDiscount() internamente.
    Livewire::actingAs($waiter)
        ->test(TabTerminal::class)
        ->set('manualDiscountInput', '2,00')
        ->assertForbidden();
});

test('garçom não consegue fechar comanda', function () {
    ['company' => $company, 'branch' => $branch, 'product' => $product] = pdvContext();
    $waiter = makeWaiter($company, $branch);
    $table = openTable($company, $branch, 6);

    $component = Livewire::actingAs($waiter)
        ->test(TabTerminal::class)
        ->set('selectedTableId', $table->id)
        ->call('addProduct', $product->id)
        ->assertHasNoErrors();

    $orderId = Order::withoutGlobalScopes()->first()->id;

    $component->call('proceedToCloseTab', $orderId)->assertForbidden();
});

test('sidebar do garçom mostra só mesas/comandas, sem dashboard nem resto do painel', function () {
    ['company' => $company, 'branch' => $branch] = pdvContext();
    $waiter = makeWaiter($company, $branch);

    $this->actingAs($waiter)
        ->get(route('admin.pdv.tabs'))
        ->assertOk()
        ->assertSee('Mesas / Comandas')
        ->assertDontSee('Dashboard')
        ->assertDontSee('Relatório PDV')
        ->assertDontSee('Cardápio')
        ->assertDontSee('Notificações');
});

test('garçom é redirecionado direto pra mesas/comandas ao acessar /pdv', function () {
    ['company' => $company, 'branch' => $branch] = pdvContext();
    $waiter = makeWaiter($company, $branch);

    $this->actingAs($waiter)
        ->get(route('admin.pdv'))
        ->assertRedirect(route('admin.pdv.tabs'));
});

test('não-garçom sem caixa aberto é redirecionado pro Terminal (venda direta) ao tentar operar mesas', function () {
    $company = \App\Models\Company::create([
        'name' => 'PDV Sem Caixa',
        'slug' => 'pdv-sem-caixa-'.uniqid(),
        'order_prefix' => 'PSC',
        'active' => true,
        'plan' => 'pro',
        'pdv_module_enabled' => true,
    ]);

    app()->instance('current.company', $company);

    $branch = \App\Models\Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Balcão',
        'address' => 'Rua A, 1',
        'city' => 'SP',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    $admin = \App\Models\User::factory()->create(['is_super_admin' => true]);

    $this->actingAs($admin)
        ->get(route('admin.pdv.tabs'))
        ->assertRedirect(route('admin.pdv.checkout'));
});
