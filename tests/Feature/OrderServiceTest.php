<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\Order\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// ─── Setup helpers ────────────────────────────────────────────────────────────

function setupOrderContext(): array
{
    $company = Company::create([
        'name' => 'Empresa Teste',
        'slug' => 'empresa-teste',
        'order_prefix' => 'TST',
        'active' => true,
    ]);

    app()->instance('current.company', $company);

    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial Central',
        'address' => 'Rua A, 1',
        'city' => 'SP',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'João',
        'phone' => '11999990001',
        'email' => 'joao@teste.com',
    ]);

    $category = ProductCategory::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Lanches',
        'active' => true,
        'sort_order' => 1,
    ]);

    $product = Product::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'product_category_id' => $category->id,
        'name' => 'X-Burguer',
        'price' => 25.00,
        'active' => true,
        'sort_order' => 1,
    ]);

    // Disponibiliza o produto na filial (inserção direta para evitar problema de timestamps no pivot)
    DB::table('branch_product')->insert([
        'branch_id' => $branch->id,
        'product_id' => $product->id,
        'available' => 1,
    ]);

    return compact('company', 'branch', 'customer', 'category', 'product');
}

// ─── Testes ───────────────────────────────────────────────────────────────────

test('createOrder usa preços do banco de dados, ignorando o preço do carrinho', function () {
    ['customer' => $customer, 'branch' => $branch, 'product' => $product] = setupOrderContext();

    $service = app(OrderService::class);

    // Cliente envia preço manipulado: R$ 0,01 (deveria ser R$ 25,00)
    $cartComPrecoManipulado = [
        $product->id => ['qty' => 2, 'name' => 'X-Burguer', 'price' => 0.01],
    ];

    $order = $service->createOrder(
        customerId: $customer->id,
        branchId: $branch->id,
        cart: $cartComPrecoManipulado,
        notes: '',
        paymentMethod: 'PIX',
        orderType: 'delivery',
    );

    // O total deve usar o preço do banco (R$ 25,00 x 2 = R$ 50,00)
    expect((float) $order->total)->toBe(50.00);
    expect((float) $order->subtotal)->toBe(50.00);

    $item = $order->items->first();
    expect((float) $item->unit_price)->toBe(25.00);
    expect((float) $item->subtotal)->toBe(50.00);
});

test('createOrder cria order_items com quantidade correta', function () {
    ['customer' => $customer, 'branch' => $branch, 'product' => $product] = setupOrderContext();

    $service = app(OrderService::class);

    $order = $service->createOrder(
        customerId: $customer->id,
        branchId: $branch->id,
        cart: [$product->id => ['qty' => 3, 'name' => 'X-Burguer', 'price' => 99.99]],
        notes: 'Sem cebola',
        paymentMethod: 'CASH',
        orderType: 'pickup',
        status: 'paid',
    );

    expect($order->status)->toBe('paid');
    expect($order->notes)->toBe('Sem cebola');
    expect($order->order_type)->toBe('pickup');
    expect($order->items)->toHaveCount(1);
    expect($order->items->first()->quantity)->toBe(3);
});

test('createOrder lança exceção para produto não disponível na filial', function () {
    ['customer' => $customer, 'branch' => $branch, 'category' => $category] = setupOrderContext();

    // Produto sem vínculo com a filial
    $produtoIndisponivel = Product::withoutGlobalScopes()->create([
        'company_id' => app('current.company')->id,
        'product_category_id' => $category->id,
        'name' => 'Produto Bloqueado',
        'price' => 15.00,
        'active' => true,
        'sort_order' => 2,
    ]);

    $service = app(OrderService::class);

    expect(fn () => $service->createOrder(
        customerId: $customer->id,
        branchId: $branch->id,
        cart: [$produtoIndisponivel->id => ['qty' => 1, 'name' => 'Produto Bloqueado', 'price' => 15.00]],
        notes: '',
        paymentMethod: 'PIX',
        orderType: 'delivery',
    ))->toThrow(\RuntimeException::class);
});

test('createOrder bloqueia produto fora do canal: available_in_delivery false não vende no delivery, available_in_pdv false não vende no PDV', function () {
    ['customer' => $customer, 'branch' => $branch, 'product' => $product] = setupOrderContext();

    $service = app(OrderService::class);

    $product->update(['available_in_delivery' => false]);

    expect(fn () => $service->createOrder(
        customerId: $customer->id,
        branchId: $branch->id,
        cart: [$product->id => ['qty' => 1, 'name' => $product->name, 'price' => (float) $product->price]],
        notes: '',
        paymentMethod: 'PIX',
        orderType: 'delivery',
    ))->toThrow(\RuntimeException::class);

    // Mas continua vendável no PDV, pois available_in_pdv segue true
    $order = $service->createOrder(
        customerId: $customer->id,
        branchId: $branch->id,
        cart: [$product->id => ['qty' => 1, 'name' => $product->name, 'price' => (float) $product->price]],
        notes: '',
        paymentMethod: '',
        orderType: 'pdv',
    );

    expect($order->items)->toHaveCount(1);

    $product->update(['available_in_delivery' => true, 'available_in_pdv' => false]);

    expect(fn () => $service->createOrder(
        customerId: $customer->id,
        branchId: $branch->id,
        cart: [$product->id => ['qty' => 1, 'name' => $product->name, 'price' => (float) $product->price]],
        notes: '',
        paymentMethod: '',
        orderType: 'pdv',
    ))->toThrow(\RuntimeException::class);
});

test('cancelOrder altera status para cancelled', function () {
    ['customer' => $customer, 'branch' => $branch, 'product' => $product] = setupOrderContext();

    $order = Order::withoutGlobalScopes()->create([
        'company_id' => app('current.company')->id,
        'customer_id' => $customer->id,
        'branch_id' => $branch->id,
        'subtotal' => 50.00,
        'total' => 50.00,
        'status' => 'paid',
        'payment_method' => 'pix',
        'order_type' => 'delivery',
        'order_number' => 'TST-2026-00001',
    ]);

    app(OrderService::class)->cancelOrder($order, $customer->id);

    expect($order->fresh()->status)->toBe('cancelled');
});

test('cancelOrder lança exceção se pedido não pode ser cancelado', function () {
    ['customer' => $customer, 'branch' => $branch] = setupOrderContext();

    $order = Order::withoutGlobalScopes()->create([
        'company_id' => app('current.company')->id,
        'customer_id' => $customer->id,
        'branch_id' => $branch->id,
        'subtotal' => 50.00,
        'total' => 50.00,
        'status' => 'delivered',
        'payment_method' => 'pix',
        'order_type' => 'delivery',
        'order_number' => 'TST-2026-00002',
    ]);

    expect(fn () => app(OrderService::class)->cancelOrder($order, $customer->id))
        ->toThrow(\RuntimeException::class);
});

test('cancelOrder de pedido com pagamento dividido dispara um reembolso por payment pago', function () {
    \Illuminate\Support\Facades\Bus::fake();

    ['customer' => $customer, 'branch' => $branch, 'product' => $product] = setupOrderContext();

    $order = Order::withoutGlobalScopes()->create([
        'company_id' => app('current.company')->id,
        'customer_id' => $customer->id,
        'branch_id' => $branch->id,
        'subtotal' => 50.00,
        'total' => 50.00,
        'status' => 'paid',
        'payment_method' => 'split',
        'order_type' => 'pdv',
        'order_number' => 'TST-2026-00003',
    ]);

    $cashPayment = \App\Models\Payment::create([
        'order_id' => $order->id,
        'payment_gateway' => 'cash',
        'amount' => 20.00,
        'status' => 'paid',
        'paid_at' => now(),
        'payment_token' => 'test-split-cash-token',
    ]);

    $cardPayment = \App\Models\Payment::create([
        'order_id' => $order->id,
        'payment_gateway' => 'card_machine',
        'amount' => 30.00,
        'status' => 'paid',
        'paid_at' => now(),
        'payment_token' => 'test-split-card-token',
    ]);

    app(OrderService::class)->cancelOrder($order, $customer->id);

    expect($order->fresh()->status)->toBe('cancelled');

    $refunds = \App\Models\PaymentRefund::where('order_id', $order->id)->orderBy('payment_id')->get();
    expect($refunds)->toHaveCount(2);
    expect((float) $refunds->firstWhere('payment_id', $cashPayment->id)->amount)->toBe(20.00);
    expect((float) $refunds->firstWhere('payment_id', $cardPayment->id)->amount)->toBe(30.00);

    \Illuminate\Support\Facades\Bus::assertDispatched(\App\Jobs\ProcessRefund::class, 2);
});

test('buildOrderSummaryFromOrder usa preços reais dos order_items', function () {
    ['customer' => $customer, 'branch' => $branch, 'product' => $product] = setupOrderContext();

    $order = app(OrderService::class)->createOrder(
        customerId: $customer->id,
        branchId: $branch->id,
        cart: [$product->id => ['qty' => 2, 'name' => 'X-Burguer', 'price' => 0.01]],
        notes: '',
        paymentMethod: 'PIX',
        orderType: 'delivery',
    );

    $summary = app(OrderService::class)->buildOrderSummaryFromOrder($order);

    // O resumo deve conter o preço correto do banco (R$ 50,00), não o manipulado
    expect($summary)->toContain('R$ 50,00');
    expect($summary)->toContain('2x X-Burguer');
    expect($summary)->toContain($order->order_number);
});

test('createOrder fecha a conta com promoção e cupom', function () {
    ['customer' => $customer, 'branch' => $branch, 'product' => $product] = setupOrderContext();

    // Promoção: 20% OFF (25.00 -> 20.00)
    $product->update([
        'promo_price_enabled' => true,
        'promo_price_type' => 'percentage',
        'promo_price_value' => 20.0,
    ]);

    $coupon = Coupon::withoutGlobalScopes()->create([
        'company_id' => app('current.company')->id,
        'code' => 'OFF10',
        'name' => '10% off',
        'type' => 'percentage',
        'discount_value' => 10.0,
        'scope' => 'order',
        'active' => true,
    ]);

    $cart = [
        $product->id => ['qty' => 2, 'name' => $product->name, 'price' => 0.01],
    ];

    $order = app(OrderService::class)->createOrder(
        customerId: $customer->id,
        branchId: $branch->id,
        cart: $cart,
        notes: '',
        paymentMethod: 'PIX',
        orderType: 'delivery',
        coupon: $coupon,
    );

    // Subtotal = 2 * 20.00 = 40.00
    // Desconto cupom = 10% de 40.00 = 4.00
    // Total = 36.00
    expect((float) $order->subtotal)->toBe(40.00);
    expect((float) $order->discount)->toBe(4.00);
    expect((float) $order->total)->toBe(36.00);

    $item = $order->items->first();
    expect((float) $item->unit_price)->toBe(20.00);
    expect((float) $item->subtotal)->toBe(40.00);
});
