<?php

use App\Livewire\Admin\Pdv\Terminal;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\Payment\PaymentOrchestrator;
use App\Services\Payment\StarkService;
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
        'plan' => 'pdv',
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

    $mockStark = Mockery::mock(StarkService::class);
    $mockStark->shouldReceive('createPixCharge')->andReturn([
        'id' => 'test_pix_123',
        'brcode' => '00020126580014br.gov.bcb.pix0136test52040000530398654000080005802BR5924TEST62070503***6304ABCD',
        'qr_code_url' => null,
        'amount' => 8.0,
    ]);
    app()->instance(StarkService::class, $mockStark);

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
        'plan' => 'pdv',
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
