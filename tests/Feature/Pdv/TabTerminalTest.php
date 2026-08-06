<?php

use App\Events\TabOrderSentToProduction;
use App\Jobs\IssueFiscalNote;
use App\Livewire\Admin\Pdv\TabTerminal;
use App\Models\BranchPrinter;
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

test('mesa ocupada continua disponível e reabrir cria uma segunda comanda distinta', function () {
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

    expect($component->get('availableTables'))->toHaveCount(1);

    $component
        ->call('deselectOpenTab')
        ->set('selectedTableId', $table5->id)
        ->call('addProduct', $product->id)
        ->assertHasNoErrors();

    $openOrders = Order::withoutGlobalScopes()
        ->where('restaurant_table_id', $table5->id)
        ->where('is_open_tab', true)
        ->latest('id')
        ->get();

    expect($openOrders)->toHaveCount(2);
    expect($openOrders->first()->table_label)->toBe('Mesa 5 (2)');
});

test('nome opcional informado ao abrir comanda vira sufixo do rótulo da mesa', function () {
    ['admin' => $admin, 'product' => $product, 'branch' => $branch, 'company' => $company] = pdvContext();
    $table = openTable($company, $branch);

    $this->actingAs($admin);

    Livewire::test(TabTerminal::class)
        ->set('selectedTableId', $table->id)
        ->set('tabCustomerName', 'João')
        ->call('addProduct', $product->id)
        ->assertHasNoErrors();

    $order = Order::withoutGlobalScopes()->first();
    expect($order->table_label)->toBe('Mesa 5 · João');
});

test('mesa com comandas abertas mostra tela de escolha em vez de ir direto pro catálogo', function () {
    ['admin' => $admin, 'product' => $product, 'branch' => $branch, 'company' => $company] = pdvContext();
    $table = openTable($company, $branch);

    $this->actingAs($admin);

    Livewire::test(TabTerminal::class)
        ->set('selectedTableId', $table->id)
        ->call('addProduct', $product->id)
        ->call('deselectOpenTab')
        ->set('selectedTableId', $table->id)
        ->call('addProduct', $product->id)
        ->call('deselectOpenTab');

    $component = Livewire::test(TabTerminal::class)
        ->set('selectedTableId', $table->id);

    $component->assertSee('Escolha uma comanda ou abra uma nova.');
    $component->assertDontSee('aguardando itens');
});

test('nova comanda nesta mesa bypassa a tela de escolha e cria uma terceira comanda', function () {
    ['admin' => $admin, 'product' => $product, 'branch' => $branch, 'company' => $company] = pdvContext();
    $table = openTable($company, $branch);

    $this->actingAs($admin);

    Livewire::test(TabTerminal::class)
        ->set('selectedTableId', $table->id)
        ->call('addProduct', $product->id)
        ->call('deselectOpenTab')
        ->set('selectedTableId', $table->id)
        ->call('addProduct', $product->id)
        ->call('deselectOpenTab');

    $component = Livewire::test(TabTerminal::class)
        ->set('selectedTableId', $table->id)
        ->set('openingNewTabForTable', true);

    $component->assertSee('aguardando itens');
    $component->assertDontSee('Escolha uma comanda ou abra uma nova.');

    $component->call('addProduct', $product->id)->assertHasNoErrors();

    $openOrders = Order::withoutGlobalScopes()
        ->where('restaurant_table_id', $table->id)
        ->where('is_open_tab', true)
        ->latest('id')
        ->get();

    expect($openOrders)->toHaveCount(3);
    expect($openOrders->first()->table_label)->toBe('Mesa 5 (3)');
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

test('fechar comanda com pagamento dividido cria split e um payment por parte', function () {
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
        ->set('isSplitPayment', true)
        ->set('splitPayments.0.method', 'cash')
        ->set('splitPayments.0.amount', '3.00')
        ->set('splitPayments.0.cash_received', '3.00')
        ->set('splitPayments.1.method', 'credit_card')
        ->set('splitPayments.1.amount', '5.00')
        ->call('confirmCloseTab')
        ->assertHasNoErrors()
        ->assertSet('step', 'catalog');

    $order = Order::withoutGlobalScopes()->find($orderId);
    expect($order->is_open_tab)->toBeFalse();
    expect($order->status)->toBe('paid');
    expect($order->payment_method)->toBe('split');
    expect((float) $order->cash_received)->toBe(3.0);

    $payments = Payment::where('order_id', $orderId)->orderBy('id')->get();
    expect($payments)->toHaveCount(2);
    expect($payments[0]->payment_gateway)->toBe('cash');
    expect((float) $payments[0]->amount)->toBe(3.0);
    expect($payments[1]->payment_gateway)->toBe('card_machine');
    expect((float) $payments[1]->amount)->toBe(5.0);
});

// ─── Pagar todas as comandas da mesa juntas ────────────────────────────────────

test('pagar todas as comandas da mesa em dinheiro fecha e paga cada uma pelo seu próprio total', function () {
    ['admin' => $admin, 'product' => $product, 'branch' => $branch, 'company' => $company] = pdvContext();
    $table = openTable($company, $branch);

    $this->actingAs($admin);

    // Comanda A: 1x produto = 8.00
    Livewire::test(TabTerminal::class)
        ->set('selectedTableId', $table->id)
        ->call('addProduct', $product->id)
        ->assertHasNoErrors();

    // Comanda B (mesma mesa): 2x produto = 16.00
    $component = Livewire::test(TabTerminal::class)
        ->call('deselectOpenTab')
        ->set('selectedTableId', $table->id)
        ->call('addProduct', $product->id)
        ->call('addProduct', $product->id)
        ->assertHasNoErrors();

    $orders = Order::withoutGlobalScopes()->where('restaurant_table_id', $table->id)->orderBy('id')->get();
    expect($orders)->toHaveCount(2);
    [$orderA, $orderB] = [$orders[0], $orders[1]];
    expect((float) $orderA->total)->toBe(8.0);
    expect((float) $orderB->total)->toBe(16.0);

    $component
        ->call('proceedToCloseTableTabs', $table->id)
        ->assertSet('step', 'payment')
        // proceedToCloseTableTabs já pré-preenche o split (1 parte por comanda) — aqui testamos
        // o caminho sem split, então desliga antes de escolher um método único pra tudo.
        ->set('isSplitPayment', false)
        ->set('paymentMethod', 'cash')
        ->set('cashReceivedInput', '24.00')
        ->call('confirmCloseTableTabs')
        ->assertHasNoErrors()
        ->assertSet('step', 'catalog');

    $orderA->refresh();
    $orderB->refresh();
    expect($orderA->status)->toBe('paid');
    expect($orderA->is_open_tab)->toBeFalse();
    expect($orderB->status)->toBe('paid');
    expect($orderB->is_open_tab)->toBeFalse();

    $paymentsA = Payment::where('order_id', $orderA->id)->get();
    expect($paymentsA)->toHaveCount(1);
    expect($paymentsA->first()->payment_gateway)->toBe('cash');
    expect((float) $paymentsA->first()->amount)->toBe(8.0);

    $paymentsB = Payment::where('order_id', $orderB->id)->get();
    expect($paymentsB)->toHaveCount(1);
    expect($paymentsB->first()->payment_gateway)->toBe('cash');
    expect((float) $paymentsB->first()->amount)->toBe(16.0);
});

test('abrir pagamento da mesa toda já pré-preenche o split com uma parte por comanda no valor exato dela', function () {
    ['admin' => $admin, 'product' => $product, 'branch' => $branch, 'company' => $company] = pdvContext();
    $table = openTable($company, $branch);

    $this->actingAs($admin);

    // Comanda A: 1x produto = 8.00
    Livewire::test(TabTerminal::class)
        ->set('selectedTableId', $table->id)
        ->call('addProduct', $product->id)
        ->assertHasNoErrors();

    // Comanda B (mesma mesa): 2x produto = 16.00
    $component = Livewire::test(TabTerminal::class)
        ->call('deselectOpenTab')
        ->set('selectedTableId', $table->id)
        ->call('addProduct', $product->id)
        ->call('addProduct', $product->id)
        ->assertHasNoErrors();

    $component
        ->call('proceedToCloseTableTabs', $table->id)
        ->assertSet('isSplitPayment', true)
        ->assertSet('splitPayments.0.method', 'cash')
        ->assertSet('splitPayments.0.amount', '8.00')
        ->assertSet('splitPayments.0.cash_received', '8.00')
        ->assertSet('splitPayments.1.method', 'credit_card')
        ->assertSet('splitPayments.1.amount', '16.00');

    // Confirmar sem editar nada já cobra cada comanda exatamente pelo que veio pré-preenchido.
    $component->call('confirmCloseTableTabs')->assertHasNoErrors();

    $orders = Order::withoutGlobalScopes()->where('restaurant_table_id', $table->id)->orderBy('id')->get();
    expect($orders->every(fn ($o) => $o->status === 'paid'))->toBeTrue();
});

test('pagar todas as comandas da mesa com split aloca as partes em sequência, uma comanda de cada vez', function () {
    ['admin' => $admin, 'product' => $product, 'branch' => $branch, 'company' => $company] = pdvContext();
    $table = openTable($company, $branch);

    $this->actingAs($admin);

    // Comanda A: 1x produto = 8.00
    Livewire::test(TabTerminal::class)
        ->set('selectedTableId', $table->id)
        ->call('addProduct', $product->id)
        ->assertHasNoErrors();

    // Comanda B (mesma mesa): 2x produto = 16.00 — total combinado 24.00
    $component = Livewire::test(TabTerminal::class)
        ->call('deselectOpenTab')
        ->set('selectedTableId', $table->id)
        ->call('addProduct', $product->id)
        ->call('addProduct', $product->id)
        ->assertHasNoErrors();

    $orders = Order::withoutGlobalScopes()->where('restaurant_table_id', $table->id)->orderBy('id')->get();
    [$orderA, $orderB] = [$orders[0], $orders[1]];

    $component
        ->call('proceedToCloseTableTabs', $table->id)
        ->assertSet('step', 'payment')
        ->set('isSplitPayment', true)
        ->set('splitPayments.0.method', 'cash')
        ->set('splitPayments.0.amount', '10.00')
        ->set('splitPayments.0.cash_received', '10.00')
        ->set('splitPayments.1.method', 'credit_card')
        ->set('splitPayments.1.amount', '14.00')
        ->call('confirmCloseTableTabs')
        ->assertHasNoErrors()
        ->assertSet('step', 'catalog')
        ->assertSet('changeAmount', 0.0);

    $orderA->refresh();
    $orderB->refresh();
    expect($orderA->payment_method)->toBe('split');
    expect($orderB->payment_method)->toBe('split');

    // Comanda A (8.00) sai inteira do dinheiro: 8 dos 10 do pool cash, sem sobra de cartão.
    $paymentsA = Payment::where('order_id', $orderA->id)->orderBy('id')->get();
    expect($paymentsA)->toHaveCount(1);
    expect($paymentsA[0]->payment_gateway)->toBe('cash');
    expect((float) $paymentsA[0]->amount)->toBe(8.0);
    expect((float) $orderA->cash_change)->toBe(0.0);

    // Comanda B (16.00) fecha o resto do dinheiro (2.00) + cartão (14.00).
    $paymentsB = Payment::where('order_id', $orderB->id)->orderBy('id')->get();
    expect($paymentsB)->toHaveCount(2);
    expect($paymentsB[0]->payment_gateway)->toBe('cash');
    expect((float) $paymentsB[0]->amount)->toBe(2.0);
    expect($paymentsB[1]->payment_gateway)->toBe('card_machine');
    expect((float) $paymentsB[1]->amount)->toBe(14.0);
    expect((float) $orderB->cash_change)->toBe(0.0);
});

test('pagar todas as comandas da mesa com soma errada no split bloqueia e não altera nenhuma comanda', function () {
    ['admin' => $admin, 'product' => $product, 'branch' => $branch, 'company' => $company] = pdvContext();
    $table = openTable($company, $branch);

    $this->actingAs($admin);

    Livewire::test(TabTerminal::class)
        ->set('selectedTableId', $table->id)
        ->call('addProduct', $product->id)
        ->assertHasNoErrors();

    $component = Livewire::test(TabTerminal::class)
        ->call('deselectOpenTab')
        ->set('selectedTableId', $table->id)
        ->call('addProduct', $product->id)
        ->call('addProduct', $product->id)
        ->assertHasNoErrors();

    $orders = Order::withoutGlobalScopes()->where('restaurant_table_id', $table->id)->orderBy('id')->get();
    [$orderA, $orderB] = [$orders[0], $orders[1]];

    $component
        ->call('proceedToCloseTableTabs', $table->id)
        ->set('isSplitPayment', true)
        ->set('splitPayments.0.method', 'cash')
        ->set('splitPayments.0.amount', '10.00')
        ->set('splitPayments.1.method', 'credit_card')
        ->set('splitPayments.1.amount', '10.00')
        ->call('confirmCloseTableTabs')
        ->assertHasErrors('order');

    $orderA->refresh();
    $orderB->refresh();
    expect($orderA->is_open_tab)->toBeTrue();
    expect($orderB->is_open_tab)->toBeTrue();
    expect(Payment::where('order_id', $orderA->id)->count())->toBe(0);
    expect(Payment::where('order_id', $orderB->id)->count())->toBe(0);
});

test('pagar todas as comandas da mesa cobra a taxa uma única vez, não soma por comanda', function () {
    ['admin' => $admin, 'product' => $product, 'branch' => $branch, 'company' => $company] = pdvContext();
    $table = openTable($company, $branch);

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

    // Comanda A: 1x produto = 8.00 subtotal, +0.80 taxa serviço +5.00 couvert = 13.80 (individual)
    Livewire::test(TabTerminal::class)
        ->set('selectedTableId', $table->id)
        ->call('addProduct', $product->id)
        ->assertHasNoErrors();

    // Comanda B (mesma mesa): idem, 13.80 individual
    $component = Livewire::test(TabTerminal::class)
        ->call('deselectOpenTab')
        ->set('selectedTableId', $table->id)
        ->call('addProduct', $product->id)
        ->assertHasNoErrors();

    $orders = Order::withoutGlobalScopes()->where('restaurant_table_id', $table->id)->orderBy('id')->get();
    [$orderA, $orderB] = [$orders[0], $orders[1]];
    expect((float) $orderA->total)->toBe(13.8);
    expect((float) $orderB->total)->toBe(13.8);

    // Subtotal combinado = 16.00 → serviço 10% = 1.60 (uma vez) + couvert 5.00 (uma vez) = 22.60,
    // NÃO 27.60 (que seria 13.80 × 2, dobrando o couvert fixo por comanda).
    $component
        ->call('proceedToCloseTableTabs', $table->id)
        ->set('isSplitPayment', false)
        ->set('paymentMethod', 'cash')
        ->call('confirmCloseTableTabs')
        ->assertHasNoErrors();

    $orderA->refresh();
    $orderB->refresh();
    expect((float) $orderA->service_fee)->toBe(1.6);
    expect((float) $orderA->couvert_fee)->toBe(5.0);
    expect((float) $orderA->total)->toBe(14.6);
    expect((float) $orderB->service_fee)->toBe(0.0);
    expect((float) $orderB->couvert_fee)->toBe(0.0);
    expect((float) $orderB->total)->toBe(8.0);

    $paymentsA = Payment::where('order_id', $orderA->id)->get();
    expect($paymentsA)->toHaveCount(1);
    expect((float) $paymentsA->first()->amount)->toBe(14.6);

    $paymentsB = Payment::where('order_id', $orderB->id)->get();
    expect($paymentsB)->toHaveCount(1);
    expect((float) $paymentsB->first()->amount)->toBe(8.0);
});

test('desmarcar isenção de taxa no split redistribui o valor removido proporcionalmente entre as partes', function () {
    ['admin' => $admin, 'product' => $product, 'branch' => $branch, 'company' => $company] = pdvContext();
    $table = openTable($company, $branch);

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

    Livewire::test(TabTerminal::class)
        ->set('selectedTableId', $table->id)
        ->call('addProduct', $product->id)
        ->assertHasNoErrors();

    $component = Livewire::test(TabTerminal::class)
        ->call('deselectOpenTab')
        ->set('selectedTableId', $table->id)
        ->call('addProduct', $product->id)
        ->assertHasNoErrors();

    $orders = Order::withoutGlobalScopes()->where('restaurant_table_id', $table->id)->orderBy('id')->get();
    [$orderA, $orderB] = [$orders[0], $orders[1]];

    $component->call('proceedToCloseTableTabs', $table->id);

    $sumOf = fn () => collect($component->get('splitPayments'))
        ->sum(fn ($part) => (float) str_replace(',', '.', $part['amount'] ?: 0));

    // Pré-preenchido com a taxa (16.00 de itens + 6.60 de taxa = 22.60).
    expect(round($sumOf(), 2))->toBe(22.6);

    // Remove as duas taxas — os campos já preenchidos do split encolhem proporcionalmente,
    // sem precisar de ajuste manual pra soma voltar a bater com o novo total (16.00).
    $component
        ->set('serviceFeeWaived', true)
        ->set('couvertFeeWaived', true);

    expect(round($sumOf(), 2))->toBe(16.0);

    $component->call('confirmCloseTableTabs')->assertHasNoErrors();

    $orderA->refresh();
    $orderB->refresh();
    expect((float) $orderA->total + (float) $orderB->total)->toBe(16.0);
    expect((float) Payment::whereIn('order_id', [$orderA->id, $orderB->id])->sum('amount'))->toBe(16.0);
});

test('pagar todas as comandas da mesa com isenção de taxa aplica a todas de uma vez', function () {
    ['admin' => $admin, 'product' => $product, 'branch' => $branch, 'company' => $company] = pdvContext();
    $table = openTable($company, $branch);

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

    // Comanda A: 1x produto = 8.00 subtotal, +0.80 taxa serviço (10%) +5.00 couvert = 13.80
    Livewire::test(TabTerminal::class)
        ->set('selectedTableId', $table->id)
        ->call('addProduct', $product->id)
        ->assertHasNoErrors();

    // Comanda B (mesma mesa): idem, 13.80
    $component = Livewire::test(TabTerminal::class)
        ->call('deselectOpenTab')
        ->set('selectedTableId', $table->id)
        ->call('addProduct', $product->id)
        ->assertHasNoErrors();

    $orders = Order::withoutGlobalScopes()->where('restaurant_table_id', $table->id)->orderBy('id')->get();
    [$orderA, $orderB] = [$orders[0], $orders[1]];
    expect((float) $orderA->total)->toBe(13.8);
    expect((float) $orderB->total)->toBe(13.8);

    // Marca isenção de taxa no modal combinado e paga sem split — vale pras duas comandas.
    $component
        ->call('proceedToCloseTableTabs', $table->id)
        ->set('isSplitPayment', false)
        ->set('serviceFeeWaived', true)
        ->set('couvertFeeWaived', true)
        ->set('paymentMethod', 'cash')
        ->call('confirmCloseTableTabs')
        ->assertHasNoErrors();

    $orderA->refresh();
    $orderB->refresh();
    expect((float) $orderA->service_fee)->toBe(0.0);
    expect((float) $orderA->couvert_fee)->toBe(0.0);
    expect((float) $orderA->total)->toBe(8.0);
    expect((float) $orderB->service_fee)->toBe(0.0);
    expect((float) $orderB->couvert_fee)->toBe(0.0);
    expect((float) $orderB->total)->toBe(8.0);

    $paymentsA = Payment::where('order_id', $orderA->id)->get();
    expect($paymentsA)->toHaveCount(1);
    expect((float) $paymentsA->first()->amount)->toBe(8.0);

    $paymentsB = Payment::where('order_id', $orderB->id)->get();
    expect($paymentsB)->toHaveCount(1);
    expect((float) $paymentsB->first()->amount)->toBe(8.0);
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

test('Finalizar Pedido volta pra seleção de mesas/comandas, sem fechar a comanda', function () {
    ['admin' => $admin, 'product' => $product, 'branch' => $branch, 'company' => $company] = pdvContext();
    $table = openTable($company, $branch);

    $this->actingAs($admin);

    $component = Livewire::test(TabTerminal::class)
        ->set('selectedTableId', $table->id)
        ->call('addProduct', $product->id)
        ->assertHasNoErrors();

    $orderId = $component->get('openTabOrderId');

    $component->call('finalizeOrder')
        ->assertSet('openTabOrderId', null)
        ->assertSet('selectedTableId', null);

    // Comanda continua aberta — só foi enviada pra produção, não fechada/paga.
    $order = Order::withoutGlobalScopes()->find($orderId);
    expect($order->is_open_tab)->toBeTrue();
    expect($order->status)->toBe('pending');
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

test('lançar item na comanda não dispara nada pra produção — só "Finalizar Pedido" avisa a cozinha/bar', function () {
    ['admin' => $admin, 'product' => $product, 'branch' => $branch, 'company' => $company] = pdvContext();
    $table = openTable($company, $branch);

    BranchPrinter::create([
        'company_id' => $company->id, 'branch_id' => $branch->id, 'station' => 'cozinha',
        'ip_address' => '192.168.0.20', 'port' => 9100, 'paper_width' => 80,
        'auto_print' => true, 'active' => true,
    ]);

    $this->actingAs($admin);

    Event::fake([TabOrderSentToProduction::class]);

    Livewire::test(TabTerminal::class)
        ->set('selectedTableId', $table->id)
        ->call('addProduct', $product->id)
        ->assertHasNoErrors();

    Event::assertNotDispatched(TabOrderSentToProduction::class);
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

test('garçom vê botão flutuante de voltar pras mesas dentro de uma comanda, admin não vê', function () {
    ['company' => $company, 'branch' => $branch, 'product' => $product, 'admin' => $admin] = pdvContext();
    $waiter = makeWaiter($company, $branch);
    $table = openTable($company, $branch, 7);

    Livewire::actingAs($waiter)
        ->test(TabTerminal::class)
        ->assertDontSee('Voltar para as mesas/comandas')
        ->set('selectedTableId', $table->id)
        ->call('addProduct', $product->id)
        ->assertSee('Voltar para as mesas/comandas');

    $this->actingAs($admin);

    Livewire::test(TabTerminal::class)
        ->set('selectedTableId', $table->id)
        ->assertDontSee('Voltar para as mesas/comandas');
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
