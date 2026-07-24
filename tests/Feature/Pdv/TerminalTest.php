<?php

use App\Jobs\IssueFiscalNote;
use App\Livewire\Admin\Pdv\Terminal;
use App\Models\Branch;
use App\Models\BranchServiceCharge;
use App\Models\Company;
use App\Models\CompanyFiscalConfig;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PdvAuditLog;
use App\Models\PdvCashSession;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\RestaurantTable;
use App\Models\User;
use App\Models\UserPermission;
use App\Services\Payment\PaymentOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
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

/** Usuário com papel garçom: só `pdv.waiter_operate`, sem `pdv.operate`, restrito à filial informada. */
function makeWaiter(Company $company, Branch $branch): User
{
    $permission = Permission::firstOrCreate(
        ['name' => 'pdv.waiter_operate'],
        ['group' => 'pdv', 'label' => 'Operar PDV (garçom — mesas e comandas)']
    );

    $waiter = User::factory()->create();

    $waiter->companies()->attach($company->id, [
        'role' => 'garcom',
        'branch_id' => $branch->id,
    ]);

    UserPermission::create([
        'user_id' => $waiter->id,
        'company_id' => $company->id,
        'permission_id' => $permission->id,
        'granted' => true,
    ]);

    return $waiter;
}

/** Mesa registrada de antemão — abrir comanda no PDV agora exige mesa cadastrada. */
function openTable(Company $company, Branch $branch, int $number = 5): RestaurantTable
{
    return RestaurantTable::create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'number' => $number,
        'active' => true,
    ]);
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

test('produtos no terminal seguem ordem da categoria e depois do produto', function () {
    ['admin' => $admin, 'company' => $company, 'branch' => $branch] = pdvContext();

    $bebidas = ProductCategory::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Bebidas',
        'active' => true,
        'sort_order' => 0,
    ]);

    $refrigerante = Product::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'product_category_id' => $bebidas->id,
        'name' => 'Refrigerante',
        'price' => 6.00,
        'active' => true,
        'sort_order' => 0,
    ]);

    DB::table('branch_product')->insert([
        'branch_id' => $branch->id,
        'product_id' => $refrigerante->id,
        'available' => 1,
    ]);

    $this->actingAs($admin);

    $component = Livewire::test(Terminal::class);

    expect($component->get('products')->pluck('name')->all())
        ->toBe(['Refrigerante', 'Coxinha']);
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

test('trocar entre modo balcão e mesa não esvazia o carrinho', function () {
    ['admin' => $admin, 'product' => $product] = pdvContext();

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->set('orderMode', 'mesa')
        ->assertSet('cart.'.(string) $product->id.'.qty', 1)
        ->set('orderMode', 'impressao')
        ->assertSet('cart.'.(string) $product->id.'.qty', 1);
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

test('produto com available_in_pdv false não aparece nem pode ser adicionado no PDV', function () {
    ['admin' => $admin, 'product' => $product] = pdvContext();

    $product->update(['available_in_pdv' => false]);

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->assertDontSee($product->name)
        ->call('addProduct', $product->id)
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

test('pedido PDV pago em dinheiro dispara emissão automática de nota fiscal', function () {
    ['admin' => $admin, 'product' => $product, 'company' => $company] = pdvContext();

    $company->update(['fiscal_notes_enabled' => true]);
    CompanyFiscalConfig::create(['company_id' => $company->id, 'enabled' => true]);

    Bus::fake();
    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('paymentMethod', 'cash')
        ->set('cashReceivedInput', '10.00')
        ->call('processOrder')
        ->assertSet('step', 'success');

    $order = Order::withoutGlobalScopes()->first();

    Bus::assertDispatched(IssueFiscalNote::class, fn ($job) => $job->orderId === $order->id);
});

// ─── Pedido com PIX ───────────────────────────────────────────────────────────

test('pedido PDV com PIX é apenas informativo: marca como pago direto sem gerar cobrança', function () {
    ['admin' => $admin, 'product' => $product] = pdvContext();

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('paymentMethod', 'pix')
        ->call('processOrder')
        ->assertSet('step', 'success')
        ->assertHasNoErrors();

    expect(Order::withoutGlobalScopes()->count())->toBe(1);

    $order = Order::withoutGlobalScopes()->first();
    expect($order->order_type)->toBe('pdv');
    expect($order->payment_method)->toBe('pix');
    expect($order->status)->toBe('paid');

    $payment = Payment::where('order_id', $order->id)->first();
    expect($payment)->not->toBeNull();
    expect($payment->status)->toBe('paid');
    expect($payment->payment_gateway)->toBe('pix_manual');
});

// ─── Busca de cliente ─────────────────────────────────────────────────────────

test('busca de cliente lista resultados automaticamente conforme o operador digita', function () {
    ['admin' => $admin, 'company' => $company] = pdvContext();

    Customer::withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => 'Maria Souza', 'phone' => '11911112222']);
    Customer::withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => 'Marcos Lima', 'phone' => '11933334444']);

    $this->actingAs($admin);

    $component = Livewire::test(Terminal::class)
        ->set('customerQuery', 'Mar');

    expect($component->get('customerResults'))->toHaveCount(2);
    expect($component->get('customerFound'))->toBeFalse();
});

test('selecionar cliente com entrega preenche o endereço a partir do cadastro dele', function () {
    ['admin' => $admin, 'product' => $product, 'company' => $company] = pdvContext();

    $address = \App\Models\Address::create([
        'line1' => 'Av. Brasil',
        'number' => '500',
        'complement' => 'Fundos',
        'neighborhood' => 'Zona 7',
        'city' => 'Maringá',
        'state' => 'PR',
        'cep' => '87050-000',
    ]);

    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Cliente Cadastrado',
        'phone' => '11955556666',
        'address_id' => $address->id,
    ]);

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('deliveryType', 'entrega')
        ->call('selectCustomer', $customer->id)
        ->assertSet('customerId', $customer->id)
        ->assertSet('deliveryAddress', 'Av. Brasil')
        ->assertSet('deliveryNumber', '500')
        ->assertSet('deliveryComplement', 'Fundos')
        ->assertSet('deliveryNeighborhood', 'Zona 7')
        ->assertSet('deliveryCity', 'Maringá')
        ->assertSet('deliveryCep', '87050-000');
});

test('busca de cliente seleciona automaticamente quando só há uma correspondência', function () {
    ['admin' => $admin, 'company' => $company] = pdvContext();

    $customer = Customer::withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => 'Maria Souza', 'phone' => '11911112222']);

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->set('customerQuery', 'Maria Souza')
        ->assertSet('customerId', $customer->id)
        ->assertSet('customerFound', true);
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

test('taxa de entrega é calculada automaticamente conforme o endereço é preenchido, sem botão manual', function () {
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
        ->assertSet('deliveryFeeAmount', 0.0)
        ->set('deliveryAddress', 'Rua das Flores')
        ->assertSet('deliveryFeeAmount', 0.0)
        ->assertSet('deliveryFeeError', null)
        ->set('deliveryNumber', '123')
        ->set('deliveryNeighborhood', 'Centro')
        ->set('deliveryCity', 'São Paulo')
        ->set('deliveryCep', '01000-000')
        ->assertSet('deliveryFeeError', null)
        ->assertSet('deliveryFeeAmount', 5.0);
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

test('pedido PDV de entrega rejeita CEP inválido', function () {
    ['admin' => $admin, 'product' => $product] = pdvContext();

    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => Company::first()->id,
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
        ->set('deliveryCep', '123')
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
        ->call('closeCashSession')
        ->assertSet('showClosingReports', true)
        ->assertSet('viewingClosedSessionId', $session->id)
        ->assertSee('Esperado no caixa');

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
    ['admin' => $admin, 'product' => $product, 'branch' => $branch, 'company' => $company] = pdvContext();
    $table = openTable($company, $branch);

    DB::table('branch_product')
        ->where('branch_id', $branch->id)
        ->where('product_id', $product->id)
        ->update(['track_stock' => true, 'quantity' => 10]);

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->set('orderMode', 'mesa')
        ->call('addProduct', $product->id)
        ->assertSet('branchUsesRegisteredTables', true)
        ->set('selectedTableId', $table->id)
        ->call('openTab')
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

    Livewire::test(Terminal::class)
        ->set('orderMode', 'mesa')
        ->call('addProduct', $product->id)
        ->assertSet('branchUsesRegisteredTables', false)
        ->assertCount('availableTables', 0)
        ->call('openTab')
        ->assertHasErrors('selectedTableId');

    expect(Order::withoutGlobalScopes()->count())->toBe(0);
});

test('operador cadastra mesa direto do terminal e já consegue selecioná-la', function () {
    ['admin' => $admin, 'product' => $product] = pdvContext();

    $this->actingAs($admin);

    $component = Livewire::test(Terminal::class)
        ->set('orderMode', 'mesa')
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
        ->call('openTab')
        ->assertHasNoErrors();

    $order = Order::withoutGlobalScopes()->first();
    expect($order->table_label)->toBe('Mesa 12');
    expect($order->restaurant_table_id)->toBe($table->id);
});

test('cadastro de mesa rejeita número duplicado', function () {
    ['admin' => $admin, 'branch' => $branch, 'company' => $company] = pdvContext();
    openTable($company, $branch, 5);

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->set('orderMode', 'mesa')
        ->set('newTableNumber', '5')
        ->call('registerTable')
        ->assertHasErrors('newTableNumber');

    expect(RestaurantTable::where('number', 5)->count())->toBe(1);
});

test('garçom não consegue cadastrar mesa', function () {
    ['company' => $company, 'branch' => $branch] = pdvContext();
    $waiter = makeWaiter($company, $branch);

    Livewire::actingAs($waiter)
        ->test(Terminal::class)
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

    $component = Livewire::test(Terminal::class)
        ->set('orderMode', 'mesa')
        ->call('addProduct', $product->id)
        ->set('selectedTableId', $table5->id)
        ->call('openTab')
        ->assertHasNoErrors();

    expect($component->get('availableTables'))->toHaveCount(0);

    $component
        ->call('deselectOpenTab')
        ->call('addProduct', $product->id)
        ->set('selectedTableId', $table5->id)
        ->call('openTab')
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

    Livewire::test(Terminal::class)
        ->set('orderMode', 'mesa')
        ->call('addProduct', $product->id)
        ->assertSet('branchUsesRegisteredTables', true)
        ->assertCount('availableTables', 0);
});

test('abrir comanda volta para a lista de comandas abertas em vez de manter selecionada', function () {
    ['admin' => $admin, 'product' => $product, 'branch' => $branch, 'company' => $company] = pdvContext();
    $table = openTable($company, $branch);

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->set('orderMode', 'mesa')
        ->call('addProduct', $product->id)
        ->set('selectedTableId', $table->id)
        ->call('openTab')
        ->assertHasNoErrors()
        ->assertSet('openTabOrderId', null);
});

test('itens da comanda selecionada aparecem automaticamente, sem precisar clicar', function () {
    ['admin' => $admin, 'product' => $product, 'branch' => $branch, 'company' => $company] = pdvContext();
    $table = openTable($company, $branch);

    $this->actingAs($admin);

    $component = Livewire::test(Terminal::class)
        ->set('orderMode', 'mesa')
        ->call('addProduct', $product->id)
        ->set('selectedTableId', $table->id)
        ->call('openTab')
        ->assertHasNoErrors();

    $orderId = Order::withoutGlobalScopes()->first()->id;

    $component->call('selectOpenTab', $orderId);

    $items = $component->get('activeTabItems');
    expect($items)->toHaveCount(1);
    expect($items->first()->product_name)->toBe('Coxinha');
});

test('atendente alterna visualização de itens de uma comanda na lista com um clique', function () {
    ['admin' => $admin, 'product' => $product, 'branch' => $branch, 'company' => $company] = pdvContext();
    $table = openTable($company, $branch);

    $this->actingAs($admin);

    $component = Livewire::test(Terminal::class)
        ->set('orderMode', 'mesa')
        ->call('addProduct', $product->id)
        ->set('selectedTableId', $table->id)
        ->call('openTab')
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

test('somar rodada de itens na comanda soma total e deduz estoque de novo', function () {
    ['admin' => $admin, 'product' => $product, 'branch' => $branch, 'company' => $company] = pdvContext();
    $table = openTable($company, $branch);

    DB::table('branch_product')
        ->where('branch_id', $branch->id)
        ->where('product_id', $product->id)
        ->update(['track_stock' => true, 'quantity' => 10]);

    $this->actingAs($admin);

    $component = Livewire::test(Terminal::class)
        ->set('orderMode', 'mesa')
        ->call('addProduct', $product->id)
        ->set('selectedTableId', $table->id)
        ->call('openTab');

    $orderId = Order::withoutGlobalScopes()->first()->id;
    expect($component->get('openTabOrderId'))->toBeNull();

    $component
        ->call('selectOpenTab', $orderId)
        ->call('addProduct', $product->id)
        ->call('addItemsToTab')
        ->assertHasNoErrors()
        ->assertSet('cart', [])
        ->assertSet('openTabOrderId', $orderId)
        ->assertSet('tabMessage', 'Itens adicionados à comanda "Mesa 5".');

    $order = Order::withoutGlobalScopes()->find($orderId);
    expect((float) $order->total)->toBe(16.0);
    expect($order->items()->count())->toBe(2);

    $stock = DB::table('branch_product')->where('product_id', $product->id)->first();
    expect((int) $stock->quantity)->toBe(8);
});

test('botão Adicionar na lista de comandas soma itens direto, sem entrar na comanda', function () {
    ['admin' => $admin, 'product' => $product, 'branch' => $branch, 'company' => $company] = pdvContext();
    $table = openTable($company, $branch);

    $this->actingAs($admin);

    $component = Livewire::test(Terminal::class)
        ->set('orderMode', 'mesa')
        ->call('addProduct', $product->id)
        ->set('selectedTableId', $table->id)
        ->call('openTab');

    $orderId = Order::withoutGlobalScopes()->first()->id;

    $component
        ->call('addProduct', $product->id)
        ->call('addItemsToTab', $orderId)
        ->assertHasNoErrors()
        ->assertSet('cart', [])
        ->assertSet('openTabOrderId', null)
        ->assertSet('tabMessage', 'Itens adicionados à comanda "Mesa 5".');

    $order = Order::withoutGlobalScopes()->find($orderId);
    expect((float) $order->total)->toBe(16.0);
    expect($order->items()->count())->toBe(2);
});

test('clicar Adicionar sem itens no carrinho não faz nada e avisa o operador', function () {
    ['admin' => $admin, 'product' => $product, 'branch' => $branch, 'company' => $company] = pdvContext();
    $table = openTable($company, $branch);

    $this->actingAs($admin);

    // openTab exige carrinho não vazio; ao concluir, ele já limpa o carrinho sozinho.
    $component = Livewire::test(Terminal::class)
        ->set('orderMode', 'mesa')
        ->call('addProduct', $product->id)
        ->set('selectedTableId', $table->id)
        ->call('openTab')
        ->assertSet('cart', []);

    $orderId = Order::withoutGlobalScopes()->first()->id;
    $itemsBefore = Order::withoutGlobalScopes()->find($orderId)->items()->count();

    $component
        ->call('addItemsToTab', $orderId)
        ->assertHasErrors('order');

    $order = Order::withoutGlobalScopes()->find($orderId);
    expect($order->items()->count())->toBe($itemsBefore);
});

test('fechar comanda com dinheiro cobra e marca como paga', function () {
    ['admin' => $admin, 'product' => $product, 'branch' => $branch, 'company' => $company] = pdvContext();
    $table = openTable($company, $branch);

    $this->actingAs($admin);

    $component = Livewire::test(Terminal::class)
        ->set('orderMode', 'mesa')
        ->call('addProduct', $product->id)
        ->set('selectedTableId', $table->id)
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

test('fechar comanda vinculando cliente informado pelo mesário salva o cliente no pedido', function () {
    ['admin' => $admin, 'product' => $product, 'company' => $company, 'branch' => $branch] = pdvContext();
    $table = openTable($company, $branch);

    $customer = Customer::create([
        'company_id' => $company->id,
        'name' => 'Maria Cliente',
        'phone' => '11999998888',
    ]);

    $this->actingAs($admin);

    $component = Livewire::test(Terminal::class)
        ->set('orderMode', 'mesa')
        ->call('addProduct', $product->id)
        ->set('selectedTableId', $table->id)
        ->call('openTab');

    $orderId = Order::withoutGlobalScopes()->first()->id;

    // Comanda abre sem cliente vinculado (guest); o mesário só identifica o cliente no fechamento.
    expect(Order::withoutGlobalScopes()->find($orderId)->customer->phone)->toBe('pdv-guest');

    $component
        ->call('proceedToCloseTab', $orderId)
        ->assertSet('step', 'payment')
        ->call('selectCustomer', $customer->id)
        ->set('paymentMethod', 'cash')
        ->set('cashReceivedInput', '10.00')
        ->call('processOrder')
        ->assertSet('step', 'success');

    $order = Order::withoutGlobalScopes()->find($orderId);
    expect($order->customer_id)->toBe($customer->id);
});

test('fechar comanda com dinheiro dispara emissão automática de nota fiscal', function () {
    ['admin' => $admin, 'product' => $product, 'company' => $company, 'branch' => $branch] = pdvContext();
    $table = openTable($company, $branch);

    $company->update(['fiscal_notes_enabled' => true]);
    CompanyFiscalConfig::create(['company_id' => $company->id, 'enabled' => true]);

    $this->actingAs($admin);

    $component = Livewire::test(Terminal::class)
        ->set('orderMode', 'mesa')
        ->call('addProduct', $product->id)
        ->set('selectedTableId', $table->id)
        ->call('openTab');

    $orderId = Order::withoutGlobalScopes()->first()->id;

    Bus::fake();

    $component
        ->call('proceedToCloseTab', $orderId)
        ->set('paymentMethod', 'cash')
        ->set('cashReceivedInput', '10.00')
        ->call('processOrder')
        ->assertSet('step', 'success');

    Bus::assertDispatched(IssueFiscalNote::class, fn ($job) => $job->orderId === $orderId);
});

test('comanda aberta não aparece em shiftStats nem sessionOrders', function () {
    ['admin' => $admin, 'product' => $product, 'branch' => $branch, 'company' => $company] = pdvContext();
    $table = openTable($company, $branch);

    $this->actingAs($admin);

    $component = Livewire::test(Terminal::class)
        ->set('orderMode', 'mesa')
        ->call('addProduct', $product->id)
        ->set('selectedTableId', $table->id)
        ->call('openTab');

    expect($component->instance()->shiftStats()['orders'])->toBe(0);
    expect($component->instance()->sessionOrders()->count())->toBe(0);
    expect($component->instance()->openTabs()->count())->toBe(1);
});

test('cancelar comanda aberta restaura estoque das duas rodadas', function () {
    ['admin' => $admin, 'product' => $product, 'branch' => $branch, 'company' => $company] = pdvContext();
    $table = openTable($company, $branch);

    DB::table('branch_product')
        ->where('branch_id', $branch->id)
        ->where('product_id', $product->id)
        ->update(['track_stock' => true, 'quantity' => 10]);

    $this->actingAs($admin);

    $component = Livewire::test(Terminal::class)
        ->set('orderMode', 'mesa')
        ->call('addProduct', $product->id)
        ->set('selectedTableId', $table->id)
        ->call('openTab');

    $orderId = Order::withoutGlobalScopes()->first()->id;

    $component
        ->call('selectOpenTab', $orderId)
        ->call('addProduct', $product->id)
        ->call('addItemsToTab');

    $component->call('cancelPdvOrder', $orderId);

    $order = Order::withoutGlobalScopes()->find($orderId);
    expect($order->status)->toBe('cancelled');

    $stock = DB::table('branch_product')->where('product_id', $product->id)->first();
    expect((int) $stock->quantity)->toBe(10);
});

test('comanda aberta individualmente aparece no painel de comandas com o total correto no mesmo request', function () {
    ['admin' => $admin, 'product' => $product, 'branch' => $branch, 'company' => $company] = pdvContext();
    $table = openTable($company, $branch, 9);

    $this->actingAs($admin);

    $component = Livewire::test(Terminal::class)
        ->call('openCashSession')
        ->set('orderMode', 'mesa')
        ->call('addProduct', $product->id)
        ->set('selectedTableId', $table->id)
        ->call('openTab')
        ->assertHasNoErrors();

    $tabs = $component->get('openTabs');
    expect($tabs)->toHaveCount(1);
    expect((float) $tabs->first()->total)->toBe(8.0);
});

test('taxa de serviço e couvert da filial são aplicados automaticamente no pedido de balcão', function () {
    ['company' => $company, 'admin' => $admin, 'product' => $product, 'branch' => $branch] = pdvContext();

    $company->update(['plan' => 'free']);

    BranchServiceCharge::create([
        'branch_id' => $branch->id,
        'company_id' => $company->id,
        'service_fee_enabled' => true,
        'service_fee_type' => 'percent',
        'service_fee_value' => 10,
        'couvert_enabled' => true,
        'couvert_type' => 'fixed',
        'couvert_value' => 5,
    ]);

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('paymentMethod', 'cash')
        ->call('processOrder')
        ->assertHasNoErrors();

    $order = Order::withoutGlobalScopes()->first();

    expect((float) $order->subtotal)->toBe(8.0);
    expect((float) $order->service_fee)->toBe(0.8);
    expect((float) $order->couvert_fee)->toBe(5.0);
    expect((float) $order->total)->toBe(13.8);
    // comissão da plataforma incide só sobre o subtotal de produtos, não sobre taxa/couvert
    expect((float) $order->fee)->toBe(0.08);
    expect((float) $order->net_value)->toBe(13.72);
});

test('taxa de serviço e couvert não são cobrados em pedido de entrega', function () {
    ['company' => $company, 'admin' => $admin, 'product' => $product, 'branch' => $branch] = pdvContext();

    BranchServiceCharge::create([
        'branch_id' => $branch->id,
        'company_id' => $company->id,
        'service_fee_enabled' => true,
        'service_fee_type' => 'percent',
        'service_fee_value' => 10,
        'couvert_enabled' => true,
        'couvert_type' => 'fixed',
        'couvert_value' => 5,
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
        ->assertSet('rawServiceFeeAmount', 0.8)
        ->assertSet('rawCouvertFeeAmount', 5.0)
        ->set('customerId', $customer->id)
        ->set('deliveryType', 'entrega')
        ->assertSet('rawServiceFeeAmount', 0.0)
        ->assertSet('rawCouvertFeeAmount', 0.0)
        ->set('deliveryAddress', 'Rua das Flores')
        ->set('deliveryNumber', '123')
        ->set('deliveryNeighborhood', 'Centro')
        ->set('deliveryCity', 'São Paulo')
        ->set('deliveryCep', '01000-000')
        ->set('paymentMethod', 'cash')
        ->call('processOrder')
        ->assertHasNoErrors();

    $order = Order::withoutGlobalScopes()->first();
    expect((float) $order->service_fee)->toBe(0.0);
    expect((float) $order->couvert_fee)->toBe(0.0);
});

test('desconto manual é aplicado e removido automaticamente conforme o operador digita', function () {
    ['admin' => $admin, 'product' => $product, 'company' => $company] = pdvContext();

    $company->update(['pdv_manual_discount_enabled' => true]);

    $this->actingAs($admin);

    $component = Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('manualDiscountInput', '2,00')
        ->assertSet('manualDiscountAmount', 2.0);

    $component
        ->set('manualDiscountInput', '')
        ->assertSet('manualDiscountAmount', 0.0);
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

    $component = Livewire::test(Terminal::class)
        ->call('openCashSession')
        ->set('orderMode', 'mesa')
        ->call('addProduct', $product->id)
        ->set('selectedTableId', $table->id)
        ->call('openTab')
        ->assertHasNoErrors();

    $orderId = Order::withoutGlobalScopes()->first()->id;
    $order = Order::withoutGlobalScopes()->find($orderId);
    expect((float) $order->service_fee)->toBe(0.8);
    expect((float) $order->couvert_fee)->toBe(5.0);
    expect((float) $order->total)->toBe(13.8);

    $component->call('selectOpenTab', $orderId)
        ->call('addProduct', $product->id)
        ->call('addItemsToTab')
        ->assertHasNoErrors();

    $order = Order::withoutGlobalScopes()->find($orderId);
    expect((float) $order->subtotal)->toBe(16.0);
    expect((float) $order->service_fee)->toBe(1.6);
    expect((float) $order->couvert_fee)->toBe(5.0);
    expect((float) $order->total)->toBe(22.6);
});

test('operador remove taxa de serviço e couvert na hora de finalizar o pedido de balcão', function () {
    ['admin' => $admin, 'product' => $product, 'branch' => $branch] = pdvContext();

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

    Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->assertSet('serviceFeeWaived', false)
        ->set('serviceFeeWaived', true)
        ->set('couvertFeeWaived', true)
        ->set('paymentMethod', 'cash')
        ->call('processOrder')
        ->assertHasNoErrors();

    $order = Order::withoutGlobalScopes()->first();
    expect((float) $order->service_fee)->toBe(0.0);
    expect((float) $order->couvert_fee)->toBe(0.0);
    expect((float) $order->total)->toBe(8.0);
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

    $component = Livewire::test(Terminal::class)
        ->call('openCashSession')
        ->set('orderMode', 'mesa')
        ->call('addProduct', $product->id)
        ->set('selectedTableId', $table->id)
        ->call('openTab')
        ->assertHasNoErrors();

    $orderId = Order::withoutGlobalScopes()->first()->id;

    $component
        ->call('proceedToCloseTab', $orderId)
        ->set('serviceFeeWaived', true)
        ->set('couvertFeeWaived', true)
        ->set('paymentMethod', 'cash')
        ->call('processOrder')
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
        ->test(Terminal::class)
        ->assertSet('isWaiter', true)
        ->assertSet('step', 'catalog')
        ->assertSet('orderMode', 'mesa')
        ->assertSet('cashSessionId', null);
});

test('garçom abre comanda e lança itens sem precisar de caixa', function () {
    ['company' => $company, 'branch' => $branch, 'product' => $product] = pdvContext();
    $waiter = makeWaiter($company, $branch);
    $table = openTable($company, $branch, 7);

    Livewire::actingAs($waiter)
        ->test(Terminal::class)
        ->call('addProduct', $product->id)
        ->set('selectedTableId', $table->id)
        ->call('openTab')
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
        ->test(Terminal::class)
        ->set('orderMode', 'mesa')
        ->call('addProduct', $product->id)
        ->set('selectedTableId', $table->id)
        ->call('openTab')
        ->assertHasNoErrors();

    $tabs = Livewire::actingAs($waiter)
        ->test(Terminal::class)
        ->get('openTabs');

    expect($tabs)->toHaveCount(1);
    expect($tabs->first()->table_label)->toBe('Mesa 2');
});

test('caixa fecha e paga comanda aberta pelo garçom, e o valor entra na conferência do caixa', function () {
    ['company' => $company, 'branch' => $branch, 'product' => $product, 'admin' => $admin] = pdvContext();
    $waiter = makeWaiter($company, $branch);
    $table = openTable($company, $branch, 8);

    Livewire::actingAs($waiter)
        ->test(Terminal::class)
        ->call('addProduct', $product->id)
        ->set('selectedTableId', $table->id)
        ->call('openTab')
        ->assertHasNoErrors();

    $orderId = Order::withoutGlobalScopes()->first()->id;

    $cashier = Livewire::actingAs($admin)->test(Terminal::class);
    $cashSessionId = $cashier->get('cashSessionId');

    $cashier
        ->call('proceedToCloseTab', $orderId)
        ->assertSet('step', 'payment')
        ->set('paymentMethod', 'cash')
        ->set('cashReceivedInput', '10.00')
        ->call('processOrder')
        ->assertSet('step', 'success');

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
        ->test(Terminal::class)
        ->call('openCashSession')
        ->assertForbidden();

    Livewire::actingAs($waiter)
        ->test(Terminal::class)
        ->call('proceedToCloseCash')
        ->assertForbidden();

    Livewire::actingAs($waiter)
        ->test(Terminal::class)
        ->call('closeCashSession')
        ->assertForbidden();
});

test('garçom não consegue registrar movimentação de caixa', function () {
    ['company' => $company, 'branch' => $branch] = pdvContext();
    $waiter = makeWaiter($company, $branch);

    Livewire::actingAs($waiter)
        ->test(Terminal::class)
        ->call('toggleCashMovementForm', 'supply')
        ->assertForbidden();

    Livewire::actingAs($waiter)
        ->test(Terminal::class)
        ->call('registerCashMovement')
        ->assertForbidden();
});

test('garçom não consegue aplicar desconto manual', function () {
    ['company' => $company, 'branch' => $branch] = pdvContext();
    $company->update(['pdv_manual_discount_enabled' => true]);
    $waiter = makeWaiter($company, $branch);

    // set() já dispara updatedManualDiscountInput() -> applyManualDiscount() internamente.
    Livewire::actingAs($waiter)
        ->test(Terminal::class)
        ->set('manualDiscountInput', '2,00')
        ->assertForbidden();
});

test('garçom não consegue ir para pagamento nem processar venda direta', function () {
    ['company' => $company, 'branch' => $branch, 'product' => $product] = pdvContext();
    $waiter = makeWaiter($company, $branch);

    Livewire::actingAs($waiter)
        ->test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->assertForbidden();

    Livewire::actingAs($waiter)
        ->test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('processOrder')
        ->assertForbidden();
});

test('garçom não consegue fechar comanda', function () {
    ['company' => $company, 'branch' => $branch, 'product' => $product] = pdvContext();
    $waiter = makeWaiter($company, $branch);
    $table = openTable($company, $branch, 6);

    $component = Livewire::actingAs($waiter)
        ->test(Terminal::class)
        ->call('addProduct', $product->id)
        ->set('selectedTableId', $table->id)
        ->call('openTab')
        ->assertHasNoErrors();

    $orderId = Order::withoutGlobalScopes()->first()->id;

    $component->call('proceedToCloseTab', $orderId)->assertForbidden();
});

test('garçom não consegue cancelar pedido nem acessar relatórios de fechamento', function () {
    ['company' => $company, 'branch' => $branch] = pdvContext();
    $waiter = makeWaiter($company, $branch);

    Livewire::actingAs($waiter)
        ->test(Terminal::class)
        ->call('cancelPdvOrder', 1)
        ->assertForbidden();

    Livewire::actingAs($waiter)
        ->test(Terminal::class)
        ->call('openClosingReports')
        ->assertForbidden();
});

test('usuário com papel sem permissão de PDV recebe 403 ao acessar o terminal', function () {
    ['company' => $company, 'branch' => $branch] = pdvContext();

    // Papel "cozinha" (seedado por migration) só tem orders.view/orders.update — sem pdv.operate
    // nem pdv.waiter_operate.
    $userSemPermissao = User::factory()->create();
    $userSemPermissao->companies()->attach($company->id, ['role' => 'cozinha', 'branch_id' => $branch->id]);

    Livewire::actingAs($userSemPermissao)
        ->test(Terminal::class)
        ->assertForbidden();
});

test('sidebar do garçom mostra só o terminal PDV, sem dashboard nem resto do painel', function () {
    ['company' => $company, 'branch' => $branch] = pdvContext();
    $waiter = makeWaiter($company, $branch);

    $this->actingAs($waiter)
        ->get(route('admin.pdv'))
        ->assertOk()
        ->assertSee('Terminal PDV')
        ->assertDontSee('Dashboard')
        ->assertDontSee('Relatório PDV')
        ->assertDontSee('Cardápio');
});

test('sidebar do caixa continua mostrando dashboard e painel completo', function () {
    ['admin' => $admin] = pdvContext();

    $this->actingAs($admin)
        ->get(route('admin.pdv'))
        ->assertOk()
        ->assertSee('Terminal PDV')
        ->assertSee('Dashboard');
});
