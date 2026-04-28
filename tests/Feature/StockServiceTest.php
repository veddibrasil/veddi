<?php

use App\Exceptions\InsufficientStockException;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\StockMovement;
use App\Services\Order\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// ─── Setup helpers ────────────────────────────────────────────────────────────

function setupStockContext(): array
{
    $company = Company::create([
        'name' => 'Empresa Estoque',
        'slug' => 'empresa-estoque',
        'order_prefix' => 'EST',
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

    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Cliente Teste',
        'phone' => '11999990001',
        'email' => 'cliente@teste.com',
    ]);

    return compact('company', 'branch', 'product', 'customer');
}

function insertBranchProduct(int $branchId, int $productId, int $quantity = 10, bool $trackStock = true): void
{
    DB::table('branch_product')->insert([
        'branch_id' => $branchId,
        'product_id' => $productId,
        'available' => 1,
        'quantity' => $quantity,
        'min_quantity' => 2,
        'track_stock' => $trackStock,
    ]);
}

function createOrder(array $context, int $quantity = 1): Order
{
    $order = Order::withoutGlobalScopes()->create([
        'company_id' => $context['company']->id,
        'branch_id' => $context['branch']->id,
        'customer_id' => $context['customer']->id,
        'subtotal' => 25.00 * $quantity,
        'total' => 25.00 * $quantity,
        'status' => 'paid',
        'payment_method' => 'pix',
        'order_type' => 'delivery',
        'order_number' => 'EST-2026-00001',
    ]);

    OrderItem::withoutGlobalScopes()->create([
        'order_id' => $order->id,
        'product_id' => $context['product']->id,
        'quantity' => $quantity,
        'unit_price' => 25.00,
        'subtotal' => 25.00 * $quantity,
        'product_name' => 'X-Burguer',
    ]);

    return $order;
}

// ─── deductForOrder ───────────────────────────────────────────────────────────

test('deductForOrder deduz estoque corretamente', function () {
    $ctx = setupStockContext();
    insertBranchProduct($ctx['branch']->id, $ctx['product']->id, 10);
    $order = createOrder($ctx, 3);

    DB::transaction(fn () => app(StockService::class)->deductForOrder($order));

    $pivot = DB::table('branch_product')
        ->where('branch_id', $ctx['branch']->id)
        ->where('product_id', $ctx['product']->id)
        ->first();

    expect($pivot->quantity)->toBe(7);
    expect((bool) $pivot->available)->toBeTrue();
});

test('deductForOrder cria StockMovement do tipo order_deduction', function () {
    $ctx = setupStockContext();
    insertBranchProduct($ctx['branch']->id, $ctx['product']->id, 10);
    $order = createOrder($ctx, 2);

    DB::transaction(fn () => app(StockService::class)->deductForOrder($order));

    $movement = StockMovement::withoutGlobalScopes()->first();

    expect($movement->type)->toBe(StockMovement::TYPE_ORDER_DEDUCTION);
    expect($movement->quantity)->toBe(-2);
    expect($movement->quantity_before)->toBe(10);
    expect($movement->quantity_after)->toBe(8);
});

test('deductForOrder lança InsufficientStockException quando estoque insuficiente', function () {
    $ctx = setupStockContext();
    insertBranchProduct($ctx['branch']->id, $ctx['product']->id, 1);
    $order = createOrder($ctx, 5);

    expect(fn () => DB::transaction(fn () => app(StockService::class)->deductForOrder($order)))
        ->toThrow(InsufficientStockException::class);
});

test('deductForOrder ignora produto sem rastreamento de estoque', function () {
    $ctx = setupStockContext();
    insertBranchProduct($ctx['branch']->id, $ctx['product']->id, 1, trackStock: false);
    $order = createOrder($ctx, 99);

    // Não deve lançar exceção mesmo com quantidade absurda
    DB::transaction(fn () => app(StockService::class)->deductForOrder($order));

    expect(StockMovement::withoutGlobalScopes()->count())->toBe(0);
});

test('deductForOrder marca produto como indisponível quando estoque chega a zero', function () {
    $ctx = setupStockContext();
    insertBranchProduct($ctx['branch']->id, $ctx['product']->id, 3);
    $order = createOrder($ctx, 3);

    DB::transaction(fn () => app(StockService::class)->deductForOrder($order));

    $pivot = DB::table('branch_product')
        ->where('branch_id', $ctx['branch']->id)
        ->where('product_id', $ctx['product']->id)
        ->first();

    expect($pivot->quantity)->toBe(0);
    expect((bool) $pivot->available)->toBeFalse();
});

// ─── restoreForOrder ──────────────────────────────────────────────────────────

test('restoreForOrder restaura estoque ao cancelar pedido', function () {
    $ctx = setupStockContext();
    insertBranchProduct($ctx['branch']->id, $ctx['product']->id, 10);
    $order = createOrder($ctx, 4);

    DB::transaction(fn () => app(StockService::class)->deductForOrder($order));
    app(StockService::class)->restoreForOrder($order);

    $pivot = DB::table('branch_product')
        ->where('branch_id', $ctx['branch']->id)
        ->where('product_id', $ctx['product']->id)
        ->first();

    expect($pivot->quantity)->toBe(10);
});

test('restoreForOrder cria StockMovement do tipo order_restore', function () {
    $ctx = setupStockContext();
    insertBranchProduct($ctx['branch']->id, $ctx['product']->id, 10);
    $order = createOrder($ctx, 2);

    DB::transaction(fn () => app(StockService::class)->deductForOrder($order));
    app(StockService::class)->restoreForOrder($order);

    $restore = StockMovement::withoutGlobalScopes()
        ->where('type', StockMovement::TYPE_ORDER_RESTORE)
        ->first();

    expect($restore)->not->toBeNull();
    expect($restore->quantity)->toBe(2);
});

test('restoreForOrder não faz nada se não houver movimentos de dedução', function () {
    $ctx = setupStockContext();
    insertBranchProduct($ctx['branch']->id, $ctx['product']->id, 10);
    $order = createOrder($ctx, 1);

    // Restaurar sem ter deduzido antes
    app(StockService::class)->restoreForOrder($order);

    expect(StockMovement::withoutGlobalScopes()->count())->toBe(0);
});

// ─── adjust ──────────────────────────────────────────────────────────────────

test('adjust aumenta estoque com delta positivo', function () {
    $ctx = setupStockContext();
    insertBranchProduct($ctx['branch']->id, $ctx['product']->id, 5);

    app(StockService::class)->adjust($ctx['branch'], $ctx['product'], 10);

    $pivot = DB::table('branch_product')
        ->where('branch_id', $ctx['branch']->id)
        ->where('product_id', $ctx['product']->id)
        ->first();

    expect($pivot->quantity)->toBe(15);
});

test('adjust diminui estoque com delta negativo', function () {
    $ctx = setupStockContext();
    insertBranchProduct($ctx['branch']->id, $ctx['product']->id, 10);

    app(StockService::class)->adjust($ctx['branch'], $ctx['product'], -3);

    $pivot = DB::table('branch_product')
        ->where('branch_id', $ctx['branch']->id)
        ->where('product_id', $ctx['product']->id)
        ->first();

    expect($pivot->quantity)->toBe(7);
});

test('adjust registra tipo manual_add para delta positivo', function () {
    $ctx = setupStockContext();
    insertBranchProduct($ctx['branch']->id, $ctx['product']->id, 0);

    app(StockService::class)->adjust($ctx['branch'], $ctx['product'], 5);

    $movement = StockMovement::withoutGlobalScopes()->first();
    expect($movement->type)->toBe(StockMovement::TYPE_MANUAL_ADD);
});

test('adjust registra tipo manual_remove para delta negativo', function () {
    $ctx = setupStockContext();
    insertBranchProduct($ctx['branch']->id, $ctx['product']->id, 10);

    app(StockService::class)->adjust($ctx['branch'], $ctx['product'], -5);

    $movement = StockMovement::withoutGlobalScopes()->first();
    expect($movement->type)->toBe(StockMovement::TYPE_MANUAL_REMOVE);
});

test('adjust não deixa estoque abaixo de zero', function () {
    $ctx = setupStockContext();
    insertBranchProduct($ctx['branch']->id, $ctx['product']->id, 3);

    app(StockService::class)->adjust($ctx['branch'], $ctx['product'], -100);

    $pivot = DB::table('branch_product')
        ->where('branch_id', $ctx['branch']->id)
        ->where('product_id', $ctx['product']->id)
        ->first();

    expect($pivot->quantity)->toBe(0);
});

// ─── setQuantity ──────────────────────────────────────────────────────────────

test('setQuantity define quantidade absoluta', function () {
    $ctx = setupStockContext();
    insertBranchProduct($ctx['branch']->id, $ctx['product']->id, 5);

    app(StockService::class)->setQuantity($ctx['branch'], $ctx['product'], 20);

    $pivot = DB::table('branch_product')
        ->where('branch_id', $ctx['branch']->id)
        ->where('product_id', $ctx['product']->id)
        ->first();

    expect($pivot->quantity)->toBe(20);
});

test('setQuantity registra tipo manual_set', function () {
    $ctx = setupStockContext();
    insertBranchProduct($ctx['branch']->id, $ctx['product']->id, 5);

    app(StockService::class)->setQuantity($ctx['branch'], $ctx['product'], 20);

    $movement = StockMovement::withoutGlobalScopes()->first();
    expect($movement->type)->toBe(StockMovement::TYPE_MANUAL_SET);
    expect($movement->quantity_before)->toBe(5);
    expect($movement->quantity_after)->toBe(20);
});

test('setQuantity não permite quantidade negativa', function () {
    $ctx = setupStockContext();
    insertBranchProduct($ctx['branch']->id, $ctx['product']->id, 10);

    app(StockService::class)->setQuantity($ctx['branch'], $ctx['product'], -50);

    $pivot = DB::table('branch_product')
        ->where('branch_id', $ctx['branch']->id)
        ->where('product_id', $ctx['product']->id)
        ->first();

    expect($pivot->quantity)->toBe(0);
});

// ─── getLowStockProducts ──────────────────────────────────────────────────────

test('getLowStockProducts retorna produtos abaixo do mínimo', function () {
    $ctx = setupStockContext();
    insertBranchProduct($ctx['branch']->id, $ctx['product']->id, 1); // quantity=1, min_quantity=2

    $lowStock = app(StockService::class)->getLowStockProducts($ctx['branch']);

    expect($lowStock)->toHaveCount(1);
    expect($lowStock->first()->name)->toBe('X-Burguer');
});

test('getLowStockProducts não retorna produtos acima do mínimo', function () {
    $ctx = setupStockContext();
    insertBranchProduct($ctx['branch']->id, $ctx['product']->id, 10); // quantity=10, min_quantity=2

    $lowStock = app(StockService::class)->getLowStockProducts($ctx['branch']);

    expect($lowStock)->toHaveCount(0);
});

test('getLowStockProducts ignora produtos sem rastreamento', function () {
    $ctx = setupStockContext();
    insertBranchProduct($ctx['branch']->id, $ctx['product']->id, 0, trackStock: false);

    $lowStock = app(StockService::class)->getLowStockProducts($ctx['branch']);

    expect($lowStock)->toHaveCount(0);
});
