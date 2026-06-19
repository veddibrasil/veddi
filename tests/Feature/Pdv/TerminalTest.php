<?php

use App\Livewire\Admin\Pdv\Terminal;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PdvAuditLog;
use App\Models\PdvCashSession;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\Payment\PaymentOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// ─── Helpers ─────────────────────────────────────────────────────────────────

function pdvContext(): array
{
    $company = Company::create([
        'name' => 'PDV Teste',
        'slug' => 'pdv-teste-'.uniqid(),
        'order_prefix' => 'PDV',
        'active' => true,
        'plan' => 'pro',
        'pdv_module_enabled' => true,
    ]);

    app()->instance('current.company', $company);

    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Balcão',
        'address' => 'Rua A, 1',
        'city' => 'SP',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    $category = ProductCategory::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Salgados',
        'active' => true,
        'sort_order' => 1,
    ]);

    $product = Product::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'product_category_id' => $category->id,
        'name' => 'Coxinha',
        'price' => 8.00,
        'active' => true,
        'sort_order' => 1,
    ]);

    DB::table('branch_product')->insert([
        'branch_id' => $branch->id,
        'product_id' => $product->id,
        'available' => 1,
    ]);

    $admin = User::factory()->create(['is_super_admin' => true]);

    PdvCashSession::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'user_id' => $admin->id,
        'opening_amount' => 0,
    ]);

    return compact('company', 'branch', 'category', 'product', 'admin');
}

// ─── Acesso ───────────────────────────────────────────────────────────────────

test('visitante é redirecionado para login ao acessar PDV', function () {
    pdvContext();

    $this->get(route('admin.pdv'))
        ->assertRedirect(route('login'));
});

test('empresa com plano sem PDV recebe 403', function () {
    $company = Company::create([
        'name' => 'Sem PDV',
        'slug' => 'sem-pdv-'.uniqid(),
        'order_prefix' => 'SP',
        'active' => true,
        'plan' => 'pro',
    ]);

    app()->instance('current.company', $company);

    Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial',
        'address' => 'Rua A',
        'city' => 'SP',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    $admin = User::factory()->create(['is_super_admin' => true]);

    $this->actingAs($admin)
        ->get(route('admin.pdv'))
        ->assertForbidden();
});

// ─── Terminal: carregamento ───────────────────────────────────────────────────

test('terminal PDV carrega com filial ativa', function () {
    ['admin' => $admin, 'branch' => $branch] = pdvContext();

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->assertSet('selectedBranchId', $branch->id)
        ->assertSet('step', 'catalog');
});

test('terminal exibe produtos disponíveis na filial', function () {
    ['admin' => $admin, 'product' => $product] = pdvContext();

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->assertSee($product->name);
});

// ─── Carrinho ─────────────────────────────────────────────────────────────────

test('adicionar produto ao carrinho', function () {
    ['admin' => $admin, 'product' => $product] = pdvContext();

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->assertSet('cart.'.(string) $product->id.'.qty', 1)
        ->assertSet('cart.'.(string) $product->id.'.price', 8.0);
});

test('incrementar e decrementar quantidade no carrinho', function () {
    ['admin' => $admin, 'product' => $product] = pdvContext();

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('updateCartQty', (string) $product->id, 2)
        ->assertSet('cart.'.(string) $product->id.'.qty', 2)
        ->call('updateCartQty', (string) $product->id, 1)
        ->assertSet('cart.'.(string) $product->id.'.qty', 1);
});

test('decrementar a 0 remove item do carrinho', function () {
    ['admin' => $admin, 'product' => $product] = pdvContext();

    $this->actingAs($admin);

    $key = (string) $product->id;

    Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('updateCartQty', $key, 0)
        ->assertSet('cart', []);
});

// ─── Pedido com dinheiro ──────────────────────────────────────────────────────

test('pedido PDV com pagamento em dinheiro cria order e payment', function () {
    ['admin' => $admin, 'product' => $product, 'branch' => $branch] = pdvContext();

    $this->actingAs($admin);

    $component = Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->assertSet('step', 'payment')
        ->set('paymentMethod', 'cash')
        ->set('cashReceivedInput', '10.00')
        ->call('processOrder')
        ->assertSet('step', 'success')
        ->assertSet('changeAmount', 2.0);

    expect(Order::withoutGlobalScopes()->count())->toBe(1);

    $order = Order::withoutGlobalScopes()->first();
    expect($order->order_type)->toBe('pdv');
    expect($order->status)->toBe('paid');
    expect((float) $order->total)->toBe(8.0);
    expect((float) $order->cash_received)->toBe(10.0);
    expect((float) $order->cash_change)->toBe(2.0);

    $payment = Payment::where('order_id', $order->id)->first();
    expect($payment)->not->toBeNull();
    expect($payment->status)->toBe('paid');
    expect($payment->payment_gateway)->toBe('cash');
});

// ─── Pedido com PIX ───────────────────────────────────────────────────────────

test('pedido PDV com PIX cria order e avança para tela pix', function () {
    ['admin' => $admin, 'product' => $product] = pdvContext();

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('paymentMethod', 'pix')
        ->call('processOrder')
        ->assertSet('step', 'pix');

    expect(Order::withoutGlobalScopes()->count())->toBe(1);

    $order = Order::withoutGlobalScopes()->first();
    expect($order->order_type)->toBe('pdv');
    expect($order->payment_method)->toBe('pix');
});

// ─── Cliente anônimo ──────────────────────────────────────────────────────────

test('pedido PDV sem cliente cria ou reusa cliente balcão', function () {
    ['admin' => $admin, 'product' => $product, 'company' => $company] = pdvContext();

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('paymentMethod', 'cash')
        ->call('processOrder');

    $guest = Customer::withoutGlobalScopes()
        ->where('company_id', $company->id)
        ->where('phone', 'pdv-guest')
        ->first();

    expect($guest)->not->toBeNull();
    expect($guest->name)->toBe('Cliente Balcão');

    // Segundo pedido reutiliza mesmo cliente balcão
    Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('paymentMethod', 'cash')
        ->call('processOrder');

    expect(Customer::withoutGlobalScopes()
        ->where('company_id', $company->id)
        ->where('phone', 'pdv-guest')
        ->count()
    )->toBe(1);
});

// ─── Troco ────────────────────────────────────────────────────────────────────

test('troco calculado corretamente', function () {
    ['admin' => $admin, 'product' => $product] = pdvContext();

    $this->actingAs($admin);

    // R$ 8,00 produto, paga R$ 20,00 → troco R$ 12,00
    Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('paymentMethod', 'cash')
        ->set('cashReceivedInput', '20.00')
        ->call('processOrder')
        ->assertSet('changeAmount', 12.0);
});

// ─── Isolamento multi-tenant ───────────────────────────────────────────────────

test('pedido PDV é associado à empresa correta', function () {
    ['admin' => $admin, 'product' => $product, 'company' => $company] = pdvContext();

    $otherCompany = Company::create([
        'name' => 'Outra Empresa',
        'slug' => 'outra-'.uniqid(),
        'order_prefix' => 'OUT',
        'active' => true,
        'plan' => 'pro',
        'pdv_module_enabled' => true,
    ]);

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('paymentMethod', 'cash')
        ->call('processOrder');

    $order = Order::withoutGlobalScopes()->first();
    expect($order->branch->company_id)->toBe($company->id);
    expect($order->branch->company_id)->not->toBe($otherCompany->id);
});

// ─── Pedido com cartão ────────────────────────────────────────────────────────

test('pedido PDV com cartão registra como pago via card_machine', function () {
    ['admin' => $admin, 'product' => $product] = pdvContext();

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('paymentMethod', 'credit_card')
        ->call('processOrder')
        ->assertSet('step', 'success');

    $order = Order::withoutGlobalScopes()->first();
    expect($order->status)->toBe('paid');
    expect($order->payment_method)->toBe('credit_card');

    $payment = Payment::where('order_id', $order->id)->first();
    expect($payment)->not->toBeNull();
    expect($payment->status)->toBe('paid');
    expect($payment->payment_gateway)->toBe('card_machine');
    expect($payment->paid_at)->not->toBeNull();
});

// ─── Notas no pedido ─────────────────────────────────────────────────────────

test('pedido PDV salva observação do operador', function () {
    ['admin' => $admin, 'product' => $product] = pdvContext();

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('paymentMethod', 'cash')
        ->set('notes', 'sem cebola')
        ->call('processOrder');

    $order = Order::withoutGlobalScopes()->first();
    expect($order->notes)->toBe('sem cebola');
});

// ─── Desconto manual ────────────────────────────────────────────────────────

test('desconto manual fica bloqueado quando empresa desabilita a opção', function () {
    ['admin' => $admin, 'product' => $product, 'company' => $company] = pdvContext();
    $company->update(['pdv_manual_discount_enabled' => false]);

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->assertSet('manualDiscountAllowed', false)
        ->set('manualDiscountInput', '2,00')
        ->call('applyManualDiscount')
        ->assertSet('manualDiscountAmount', 0.0)
        ->assertHasErrors('manual_discount');
});

// ─── Entrega ────────────────────────────────────────────────────────────────

test('pedido PDV de entrega salva endereço e taxa na order', function () {
    ['admin' => $admin, 'product' => $product, 'branch' => $branch, 'company' => $company] = pdvContext();

    \App\Models\DeliverySetting::create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'fee_type' => 'flat',
        'flat_fee' => 5.00,
        'active' => true,
    ]);

    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Cliente Entrega',
        'phone' => '11999990000',
    ]);

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('customerId', $customer->id)
        ->set('deliveryType', 'entrega')
        ->set('deliveryAddress', 'Rua das Flores')
        ->set('deliveryNumber', '123')
        ->set('deliveryNeighborhood', 'Centro')
        ->set('deliveryCity', 'São Paulo')
        ->set('deliveryCep', '01000-000')
        ->call('calculateDeliveryFee')
        ->assertSet('deliveryFeeError', null)
        ->assertSet('deliveryFeeAmount', 5.0)
        ->set('paymentMethod', 'cash')
        ->call('processOrder')
        ->assertSet('step', 'success');

    $order = Order::withoutGlobalScopes()->first();
    expect((float) $order->delivery_fee)->toBe(5.0);
    expect($order->delivery_address)->toBe('Rua das Flores');
    expect($order->delivery_neighborhood)->toBe('Centro');
});

test('pedido PDV de entrega exige cliente selecionado', function () {
    ['admin' => $admin, 'product' => $product] = pdvContext();

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('deliveryType', 'entrega')
        ->set('paymentMethod', 'cash')
        ->call('processOrder')
        ->assertHasErrors('order');

    expect(Order::withoutGlobalScopes()->count())->toBe(0);
});

// ─── Cancelamento no terminal ─────────────────────────────────────────────────

test('operador cancela pedido PDV dentro de 5 minutos', function () {
    ['admin' => $admin, 'product' => $product] = pdvContext();

    $this->actingAs($admin);

    $component = Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('paymentMethod', 'cash')
        ->call('processOrder')
        ->assertSet('step', 'success');

    $orderId = $component->get('lastOrderId');
    expect($orderId)->not->toBeNull();

    $component
        ->call('cancelLastOrder')
        ->assertSet('step', 'catalog')
        ->assertSet('lastOrderId', null);

    $order = Order::withoutGlobalScopes()->find($orderId);
    expect($order->status)->toBe('cancelled');
});

test('operador cancela pedido PDV de sessão mesmo após 5 minutos', function () {
    ['admin' => $admin, 'product' => $product] = pdvContext();

    $this->actingAs($admin);

    $component = Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('paymentMethod', 'cash')
        ->call('processOrder')
        ->assertSet('step', 'success');

    $orderId = $component->get('lastOrderId');
    Order::withoutGlobalScopes()->where('id', $orderId)->update(['created_at' => now()->subMinutes(6)]);

    $component->call('cancelLastOrder');

    $order = Order::withoutGlobalScopes()->find($orderId);
    expect($order->status)->toBe('cancelled');
});

test('pedido em status terminal não pode ser cancelado no PDV', function () {
    ['admin' => $admin, 'product' => $product] = pdvContext();

    $this->actingAs($admin);

    $component = Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('paymentMethod', 'cash')
        ->call('processOrder')
        ->assertSet('step', 'success');

    $orderId = $component->get('lastOrderId');
    Order::withoutGlobalScopes()->where('id', $orderId)->update(['status' => 'delivered']);

    $component->call('cancelLastOrder');

    $component->assertHasErrors('cancel');

    $order = Order::withoutGlobalScopes()->find($orderId);
    expect($order->status)->toBe('delivered');
});

// ─── checkPixStatus ───────────────────────────────────────────────────────────

test('checkPixStatus mantém step pix quando pagamento não confirmado', function () {
    ['admin' => $admin, 'product' => $product] = pdvContext();

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('paymentMethod', 'pix')
        ->call('processOrder')
        ->assertSet('step', 'pix')
        ->call('checkPixStatus')
        ->assertSet('step', 'pix');
});

test('checkPixStatus avança para success quando pagamento confirmado', function () {
    ['admin' => $admin, 'product' => $product] = pdvContext();

    $this->actingAs($admin);

    $component = Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('paymentMethod', 'pix')
        ->call('processOrder')
        ->assertSet('step', 'pix');

    $orderId = $component->get('pixOrderId');
    Payment::where('order_id', $orderId)->update(['status' => 'paid']);

    $component->call('checkPixStatus')->assertSet('step', 'success');
});

// ─── processCash direto ───────────────────────────────────────────────────────

test('processCash cria payment com status paid sem gateway', function () {
    ['company' => $company, 'branch' => $branch] = pdvContext();

    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Ana',
        'phone' => '11999990099',
    ]);

    $order = Order::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'customer_id' => $customer->id,
        'branch_id' => $branch->id,
        'subtotal' => 15.00,
        'total' => 15.00,
        'fee' => 0,
        'net_value' => 15.00,
        'status' => 'paid',
        'payment_method' => 'cash',
        'order_type' => 'pdv',
        'cash_received' => 20.00,
        'cash_change' => 5.00,
    ]);

    $result = app(PaymentOrchestrator::class)->processCash($order);

    expect($result['status'])->toBe('paid');
    expect($result['gateway'])->toBe('cash');
    expect($result['change'])->toBe(5.0);

    $payment = Payment::where('order_id', $order->id)->first();
    expect($payment)->not->toBeNull();
    expect($payment->status)->toBe('paid');
    expect($payment->payment_gateway)->toBe('cash');
    expect($payment->paid_at)->not->toBeNull();
});

// ─── Auditoria e caixa profissional ──────────────────────────────────────────

test('suprimento e sangria alteram valor esperado do caixa', function () {
    ['admin' => $admin] = pdvContext();

    $this->actingAs($admin);

    $component = Livewire::test(Terminal::class)
        ->call('toggleCashMovementForm', 'supply')
        ->set('cashMovementAmountInput', '50.00')
        ->set('cashMovementReason', 'Troco adicional')
        ->call('registerCashMovement')
        ->call('toggleCashMovementForm', 'withdrawal')
        ->set('cashMovementAmountInput', '20.00')
        ->set('cashMovementReason', 'Retirada para cofre')
        ->call('registerCashMovement');

    $session = PdvCashSession::withoutGlobalScopes()->find($component->get('cashSessionId'));

    expect($component->instance()->cashSessionExpected($session))->toBe(30.0);
    expect(PdvAuditLog::withoutGlobalScopes()->where('action', 'cash_supply')->count())->toBe(1);
    expect(PdvAuditLog::withoutGlobalScopes()->where('action', 'cash_withdrawal')->count())->toBe(1);
});

test('PDV registra auditoria para venda cancelamento e fechamento', function () {
    ['admin' => $admin, 'product' => $product] = pdvContext();

    $this->actingAs($admin);

    $component = Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('paymentMethod', 'cash')
        ->call('processOrder')
        ->assertSet('step', 'success');

    $orderId = $component->get('lastOrderId');

    expect(PdvAuditLog::withoutGlobalScopes()
        ->where('action', 'order_created')
        ->where('order_id', $orderId)
        ->exists()
    )->toBeTrue();

    $component->call('cancelLastOrder');

    expect(PdvAuditLog::withoutGlobalScopes()
        ->where('action', 'order_cancelled')
        ->where('order_id', $orderId)
        ->exists()
    )->toBeTrue();

    $session = PdvCashSession::withoutGlobalScopes()->whereNull('closed_at')->first();

    Livewire::test(Terminal::class)
        ->set('closingAmountInput', (string) $component->instance()->cashSessionExpected($session))
        ->call('closeCashSession');

    expect(PdvAuditLog::withoutGlobalScopes()
        ->where('action', 'cash_closed')
        ->exists()
    )->toBeTrue();
});

test('relatório de fechamento lista sessões encerradas e exibe detalhe', function () {
    ['admin' => $admin] = pdvContext();

    $this->actingAs($admin);

    $opening = Livewire::test(Terminal::class);
    $session = PdvCashSession::withoutGlobalScopes()->find($opening->get('cashSessionId'));

    $opening->set('closingAmountInput', (string) $opening->instance()->cashSessionExpected($session))
        ->call('closeCashSession');

    $session->refresh();

    Livewire::test(Terminal::class)
        ->call('openClosingReports')
        ->assertSet('showClosingReports', true)
        ->assertSee($session->closed_at->format('d/m/Y'))
        ->call('viewClosedSession', $session->id)
        ->assertSet('viewingClosedSessionId', $session->id)
        ->assertSee('Esperado no caixa')
        ->call('backToClosingReportsList')
        ->assertSet('viewingClosedSessionId', null);
});

// ─── Mesa/Comanda ─────────────────────────────────────────────────────────────

test('abrir comanda cria pedido pendente sem pagamento e deduz estoque', function () {
    ['admin' => $admin, 'product' => $product, 'branch' => $branch] = pdvContext();

    DB::table('branch_product')
        ->where('branch_id', $branch->id)
        ->where('product_id', $product->id)
        ->update(['track_stock' => true, 'quantity' => 10]);

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->set('orderMode', 'mesa')
        ->call('addProduct', $product->id)
        ->set('tableLabel', 'Mesa 5')
        ->call('openTab')
        ->assertHasNoErrors()
        ->assertSet('cart', []);

    $order = Order::withoutGlobalScopes()->first();
    expect($order->status)->toBe('pending');
    expect($order->is_open_tab)->toBeTrue();
    expect($order->table_label)->toBe('Mesa 5');
    expect((float) $order->total)->toBe(8.0);
    expect(Payment::where('order_id', $order->id)->exists())->toBeFalse();

    $stock = DB::table('branch_product')->where('product_id', $product->id)->first();
    expect((int) $stock->quantity)->toBe(9);
});

test('somar rodada de itens na comanda soma total e deduz estoque de novo', function () {
    ['admin' => $admin, 'product' => $product, 'branch' => $branch] = pdvContext();

    DB::table('branch_product')
        ->where('branch_id', $branch->id)
        ->where('product_id', $product->id)
        ->update(['track_stock' => true, 'quantity' => 10]);

    $this->actingAs($admin);

    $component = Livewire::test(Terminal::class)
        ->set('orderMode', 'mesa')
        ->call('addProduct', $product->id)
        ->set('tableLabel', 'Mesa 5')
        ->call('openTab');

    $orderId = Order::withoutGlobalScopes()->first()->id;
    expect($component->get('openTabOrderId'))->toBe($orderId);

    $component
        ->call('addProduct', $product->id)
        ->call('addItemsToTab')
        ->assertHasNoErrors()
        ->assertSet('cart', []);

    $order = Order::withoutGlobalScopes()->find($orderId);
    expect((float) $order->total)->toBe(16.0);
    expect($order->items()->count())->toBe(2);

    $stock = DB::table('branch_product')->where('product_id', $product->id)->first();
    expect((int) $stock->quantity)->toBe(8);
});

test('fechar comanda com dinheiro cobra e marca como paga', function () {
    ['admin' => $admin, 'product' => $product] = pdvContext();

    $this->actingAs($admin);

    $component = Livewire::test(Terminal::class)
        ->set('orderMode', 'mesa')
        ->call('addProduct', $product->id)
        ->set('tableLabel', 'Mesa 5')
        ->call('openTab');

    $orderId = Order::withoutGlobalScopes()->first()->id;

    $component
        ->call('proceedToCloseTab', $orderId)
        ->assertSet('step', 'payment')
        ->set('paymentMethod', 'cash')
        ->set('cashReceivedInput', '10.00')
        ->call('processOrder')
        ->assertSet('step', 'success');

    $order = Order::withoutGlobalScopes()->find($orderId);
    expect($order->is_open_tab)->toBeFalse();
    expect($order->status)->toBe('paid');
    expect($order->payment_method)->toBe('cash');

    $payment = Payment::where('order_id', $orderId)->first();
    expect($payment)->not->toBeNull();
    expect($payment->status)->toBe('paid');
});

test('comanda aberta não aparece em shiftStats nem sessionOrders', function () {
    ['admin' => $admin, 'product' => $product] = pdvContext();

    $this->actingAs($admin);

    $component = Livewire::test(Terminal::class)
        ->set('orderMode', 'mesa')
        ->call('addProduct', $product->id)
        ->set('tableLabel', 'Mesa 5')
        ->call('openTab');

    expect($component->instance()->shiftStats()['orders'])->toBe(0);
    expect($component->instance()->sessionOrders()->count())->toBe(0);
    expect($component->instance()->openTabs()->count())->toBe(1);
});

test('cancelar comanda aberta restaura estoque das duas rodadas', function () {
    ['admin' => $admin, 'product' => $product, 'branch' => $branch] = pdvContext();

    DB::table('branch_product')
        ->where('branch_id', $branch->id)
        ->where('product_id', $product->id)
        ->update(['track_stock' => true, 'quantity' => 10]);

    $this->actingAs($admin);

    $component = Livewire::test(Terminal::class)
        ->set('orderMode', 'mesa')
        ->call('addProduct', $product->id)
        ->set('tableLabel', 'Mesa 5')
        ->call('openTab');

    $orderId = Order::withoutGlobalScopes()->first()->id;

    $component
        ->call('addProduct', $product->id)
        ->call('addItemsToTab');

    $component->call('cancelPdvOrder', $orderId);

    $order = Order::withoutGlobalScopes()->find($orderId);
    expect($order->status)->toBe('cancelled');

    $stock = DB::table('branch_product')->where('product_id', $product->id)->first();
    expect((int) $stock->quantity)->toBe(10);
});
