<?php

use App\Exceptions\CouponException;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\CouponService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

// ─── Setup helpers ────────────────────────────────────────────────────────────

function setupCouponContext(): array
{
    $company = Company::create([
        'name' => 'Empresa Cupom',
        'slug' => 'empresa-cupom',
        'order_prefix' => 'CUP',
        'active' => true,
    ]);

    app()->instance('current.company', $company);

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
        'price' => 30.00,
        'active' => true,
        'sort_order' => 1,
    ]);

    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial Cupom',
        'address' => 'Rua B, 2',
        'city' => 'SP',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Maria',
        'phone' => '11888880001',
        'email' => 'maria@teste.com',
    ]);

    return compact('company', 'category', 'product', 'branch', 'customer');
}

function makeCouponOrder(array $ctx, int $seq = 1): Order
{
    return Order::withoutGlobalScopes()->create([
        'company_id' => $ctx['company']->id,
        'branch_id' => $ctx['branch']->id,
        'customer_id' => $ctx['customer']->id,
        'subtotal' => 100.0,
        'total' => 100.0,
        'status' => 'paid',
        'payment_method' => 'pix',
        'order_type' => 'delivery',
        'order_number' => "CUP-2026-{$seq}",
    ]);
}

function makeCoupon(array $attrs = []): Coupon
{
    return Coupon::withoutGlobalScopes()->create(array_merge([
        'company_id' => app('current.company')->id,
        'code' => 'TESTE10',
        'name' => 'Desconto Teste',
        'type' => 'percentage',
        'discount_value' => 10,
        'scope' => 'order',
        'active' => true,
        'starts_at' => null,
        'expires_at' => null,
    ], $attrs));
}

// ─── validate ────────────────────────────────────────────────────────────────

test('validate retorna cupom válido', function () {
    setupCouponContext();
    $coupon = makeCoupon();
    Cache::forget('coupon:code:TESTE10');

    $result = app(CouponService::class)->validate('TESTE10', 1, [], 100.0);

    expect($result->id)->toBe($coupon->id);
});

test('validate normaliza código para maiúsculas', function () {
    setupCouponContext();
    makeCoupon(['code' => 'PROMO20']);
    Cache::forget('coupon:code:PROMO20');

    $result = app(CouponService::class)->validate('promo20', 1, [], 100.0);

    expect($result->code)->toBe('PROMO20');
});

test('validate lança exceção para código inexistente', function () {
    setupCouponContext();

    expect(fn () => app(CouponService::class)->validate('INEXISTENTE', 1, [], 100.0))
        ->toThrow(CouponException::class, 'inválido');
});

test('validate lança exceção para cupom inativo', function () {
    setupCouponContext();
    makeCoupon(['active' => false]);
    Cache::forget('coupon:code:TESTE10');

    expect(fn () => app(CouponService::class)->validate('TESTE10', 1, [], 100.0))
        ->toThrow(CouponException::class, 'inválido');
});

test('validate lança exceção para cupom expirado', function () {
    setupCouponContext();
    makeCoupon(['expires_at' => now()->subDay()]);
    Cache::forget('coupon:code:TESTE10');

    expect(fn () => app(CouponService::class)->validate('TESTE10', 1, [], 100.0))
        ->toThrow(CouponException::class, 'expirado');
});

test('validate lança exceção para cupom ainda não vigente', function () {
    setupCouponContext();
    makeCoupon(['starts_at' => now()->addDay()]);
    Cache::forget('coupon:code:TESTE10');

    expect(fn () => app(CouponService::class)->validate('TESTE10', 1, [], 100.0))
        ->toThrow(CouponException::class, 'expirado');
});

test('validate lança exceção quando subtotal abaixo do mínimo', function () {
    setupCouponContext();
    makeCoupon(['minimum_order_value' => 50.0]);
    Cache::forget('coupon:code:TESTE10');

    expect(fn () => app(CouponService::class)->validate('TESTE10', 1, [], 30.0))
        ->toThrow(CouponException::class, 'mínimo');
});

test('validate lança exceção quando limite global de usos atingido', function () {
    $ctx = setupCouponContext();
    $coupon = makeCoupon(['max_uses' => 2]);
    Cache::forget('coupon:code:TESTE10');

    $order1 = makeCouponOrder($ctx, 1);
    $order2 = makeCouponOrder($ctx, 2);

    CouponUsage::withoutGlobalScopes()->create([
        'coupon_id' => $coupon->id,
        'order_id' => $order1->id,
        'customer_id' => $ctx['customer']->id,
        'company_id' => $ctx['company']->id,
        'discount_applied' => 10,
    ]);
    CouponUsage::withoutGlobalScopes()->create([
        'coupon_id' => $coupon->id,
        'order_id' => $order2->id,
        'customer_id' => $ctx['customer']->id,
        'company_id' => $ctx['company']->id,
        'discount_applied' => 10,
    ]);

    expect(fn () => app(CouponService::class)->validate('TESTE10', 1, [], 100.0))
        ->toThrow(CouponException::class, 'Limite');
});

test('validate lança exceção quando limite por cliente atingido', function () {
    $ctx = setupCouponContext();
    $coupon = makeCoupon(['max_uses_per_customer' => 1]);
    Cache::forget('coupon:code:TESTE10');

    $order = makeCouponOrder($ctx, 99);

    CouponUsage::withoutGlobalScopes()->create([
        'coupon_id' => $coupon->id,
        'order_id' => $order->id,
        'customer_id' => $ctx['customer']->id,
        'company_id' => $ctx['company']->id,
        'discount_applied' => 10,
    ]);

    expect(fn () => app(CouponService::class)->validate('TESTE10', $ctx['customer']->id, [], 100.0))
        ->toThrow(CouponException::class, 'máximo de vezes');
});

// ─── calculateDiscount ────────────────────────────────────────────────────────

test('calculateDiscount calcula desconto percentual sobre pedido', function () {
    setupCouponContext();
    $coupon = makeCoupon(['type' => 'percentage', 'discount_value' => 20]);

    $discount = app(CouponService::class)->calculateDiscount($coupon, [], 100.0, 10.0);

    expect($discount)->toBe(20.0);
});

test('calculateDiscount calcula desconto fixo', function () {
    setupCouponContext();
    $coupon = makeCoupon(['type' => 'fixed', 'discount_value' => 15]);

    $discount = app(CouponService::class)->calculateDiscount($coupon, [], 100.0, 10.0);

    expect($discount)->toBe(15.0);
});

test('calculateDiscount fixo não excede o subtotal', function () {
    setupCouponContext();
    $coupon = makeCoupon(['type' => 'fixed', 'discount_value' => 200]);

    $discount = app(CouponService::class)->calculateDiscount($coupon, [], 50.0, 10.0);

    expect($discount)->toBe(50.0);
});

test('calculateDiscount free_delivery retorna valor do frete', function () {
    setupCouponContext();
    $coupon = makeCoupon(['type' => 'free_delivery']);

    $discount = app(CouponService::class)->calculateDiscount($coupon, [], 100.0, 12.50);

    expect($discount)->toBe(12.50);
});

test('calculateDiscount free_product retorna preço do produto grátis', function () {
    $ctx = setupCouponContext();
    $coupon = makeCoupon(['type' => 'free_product', 'free_product_id' => $ctx['product']->id]);

    $discount = app(CouponService::class)->calculateDiscount($coupon, [], 100.0, 10.0);

    expect($discount)->toBe(30.0); // Preço do X-Burguer
});

test('calculateDiscount percentual com escopo de produto', function () {
    $ctx = setupCouponContext();
    $coupon = makeCoupon([
        'type' => 'percentage',
        'discount_value' => 50,
        'scope' => 'product',
        'scope_ids' => [$ctx['product']->id],
    ]);

    // Carrinho: 2 unidades do produto
    $cart = [$ctx['product']->id => ['qty' => 2, 'name' => 'X-Burguer', 'price' => 30.0]];

    $discount = app(CouponService::class)->calculateDiscount($coupon, $cart, 60.0, 0.0);

    // 50% sobre R$60 (base do produto no carrinho)
    expect($discount)->toBe(30.0);
});

test('calculateDiscount percentual com escopo de categoria', function () {
    $ctx = setupCouponContext();
    $coupon = makeCoupon([
        'type' => 'percentage',
        'discount_value' => 10,
        'scope' => 'category',
        'scope_ids' => [$ctx['category']->id],
    ]);

    $cart = [$ctx['product']->id => ['qty' => 1, 'name' => 'X-Burguer', 'price' => 30.0]];

    $discount = app(CouponService::class)->calculateDiscount($coupon, $cart, 30.0, 0.0);

    expect($discount)->toBe(3.0); // 10% de R$30
});

// ─── recordUsage ─────────────────────────────────────────────────────────────

test('recordUsage salva registro de uso do cupom', function () {
    $ctx = setupCouponContext();
    $coupon = makeCoupon();
    $order = makeCouponOrder($ctx, 1);

    $usage = app(CouponService::class)->recordUsage($coupon, $order, $ctx['customer']->id, 10.0);

    expect($usage->coupon_id)->toBe($coupon->id);
    expect($usage->customer_id)->toBe($ctx['customer']->id);
    expect((float) $usage->discount_applied)->toBe(10.0);
    expect(CouponUsage::withoutGlobalScopes()->count())->toBe(1);
});
