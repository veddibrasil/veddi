<?php

use App\Livewire\Admin\Orders\Show;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// ─── Helpers ─────────────────────────────────────────────────────────────────

function orderEditContext(): array
{
    $company = Company::create([
        'name' => 'Lancheria Teste',
        'slug' => 'lancheria-teste',
        'order_prefix' => 'LT',
        'active' => true,
    ]);

    app()->instance('current.company', $company);

    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial Central',
        'address' => 'Rua B, 10',
        'city' => 'SP',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Maria',
        'phone' => '11999990002',
        'email' => 'maria@teste.com',
        'address' => 'Rua das Flores',
        'number' => '123',
        'neighborhood' => 'Centro',
        'city' => 'São Paulo',
        'cep' => '01310-100',
    ]);

    $admin = User::factory()->create(['is_super_admin' => true]);

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

    DB::table('branch_product')->insert([
        'branch_id' => $branch->id,
        'product_id' => $product->id,
        'available' => 1,
    ]);

    $order = Order::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'customer_id' => $customer->id,
        'branch_id' => $branch->id,
        'subtotal' => 25.00,
        'delivery_fee' => 5.00,
        'discount' => 0,
        'total' => 30.00,
        'fee' => 0,
        'net_value' => 30.00,
        'status' => 'pending',
        'notes' => '',
        'payment_method' => 'pix',
        'order_type' => 'delivery',
        'delivery_address' => 'Rua das Flores',
        'delivery_number' => '123',
        'delivery_neighborhood' => 'Centro',
        'delivery_city' => 'São Paulo',
        'delivery_cep' => '01310-100',
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'product_name' => 'X-Burguer',
        'unit_price' => 25.00,
        'quantity' => 1,
        'subtotal' => 25.00,
    ]);

    return compact('company', 'branch', 'customer', 'admin', 'product', 'order');
}

// ─── Delivery address snapshot ────────────────────────────────────────────────

test('createOrder snapshots customer delivery address', function () {
    ['customer' => $customer, 'branch' => $branch, 'product' => $product] = orderEditContext();

    $service = app(\App\Services\Order\OrderService::class);

    $order = $service->createOrder(
        customerId: $customer->id,
        branchId: $branch->id,
        cart: [$product->id => ['qty' => 1, 'name' => 'X-Burguer', 'price' => 25.00]],
        notes: '',
        paymentMethod: 'PIX',
        orderType: 'delivery',
    );

    expect($order->delivery_address)->toBe($customer->address);
    expect($order->delivery_city)->toBe($customer->city);
    expect($order->delivery_neighborhood)->toBe($customer->neighborhood);
});

test('createOrder does not snapshot address for pickup orders', function () {
    ['customer' => $customer, 'branch' => $branch, 'product' => $product] = orderEditContext();

    $service = app(\App\Services\Order\OrderService::class);

    $order = $service->createOrder(
        customerId: $customer->id,
        branchId: $branch->id,
        cart: [$product->id => ['qty' => 1, 'name' => 'X-Burguer', 'price' => 25.00]],
        notes: '',
        paymentMethod: 'PIX',
        orderType: 'pickup',
    );

    expect($order->delivery_address)->toBeNull();
});

test('deliveryFullAddress falls back to customer address when snapshot is null', function () {
    ['customer' => $customer, 'order' => $order] = orderEditContext();

    $order->update(['delivery_address' => null, 'delivery_neighborhood' => null, 'delivery_city' => null]);
    $order->refresh();

    expect($order->deliveryFullAddress())->toContain($customer->address);
});

// ─── isEditable ───────────────────────────────────────────────────────────────

test('order is editable in active statuses', function (string $status) {
    ['order' => $order] = orderEditContext();
    $order->update(['status' => $status]);
    expect($order->isEditable())->toBeTrue();
})->with(['pending', 'awaiting_payment', 'paid', 'preparing', 'ready', 'scheduled']);

test('order is not editable in terminal statuses', function (string $status) {
    ['order' => $order] = orderEditContext();
    $order->update(['status' => $status]);
    expect($order->isEditable())->toBeFalse();
})->with(['delivered', 'cancelled', 'refunded']);

// ─── Admin can edit address ───────────────────────────────────────────────────

test('admin can update delivery address on editable order', function () {
    ['admin' => $admin, 'order' => $order] = orderEditContext();

    $this->actingAs($admin);

    Livewire::test(Show::class, ['order' => $order])
        ->call('startEditAddress')
        ->set('editDeliveryAddress', 'Av. Paulista')
        ->set('editDeliveryNumber', '1000')
        ->set('editDeliveryNeighborhood', 'Bela Vista')
        ->set('editDeliveryCity', 'São Paulo')
        ->set('editDeliveryCep', '01310-200')
        ->set('editDeliveryComplement', 'Apto 42')
        ->call('saveAddress')
        ->assertHasNoErrors()
        ->assertSet('editingAddress', false);

    $order->refresh();

    expect($order->delivery_address)->toBe('Av. Paulista');
    expect($order->delivery_city)->toBe('São Paulo');
    expect($order->delivery_complement)->toBe('Apto 42');
});

test('admin cannot edit address when order is delivered', function () {
    ['admin' => $admin, 'order' => $order] = orderEditContext();

    $order->update(['status' => 'delivered']);
    $this->actingAs($admin);

    Livewire::test(Show::class, ['order' => $order])
        ->call('startEditAddress')
        ->assertForbidden();
});

test('saveAddress validates required fields', function () {
    ['admin' => $admin, 'order' => $order] = orderEditContext();

    $this->actingAs($admin);

    Livewire::test(Show::class, ['order' => $order])
        ->call('startEditAddress')
        ->set('editDeliveryAddress', '')
        ->set('editDeliveryCity', '')
        ->call('saveAddress')
        ->assertHasErrors(['editDeliveryAddress', 'editDeliveryCity']);
});

// ─── Admin can edit items ─────────────────────────────────────────────────────

test('admin can change item quantity', function () {
    ['admin' => $admin, 'order' => $order] = orderEditContext();

    $this->actingAs($admin);

    $component = Livewire::test(Show::class, ['order' => $order])
        ->call('startEditItems');

    $component->call('updateItemQuantity', 0, 3)
        ->call('saveItems')
        ->assertHasErrors(['editableItems']);

    $order->refresh();

    expect((float) $order->subtotal)->toBe(25.00);
    expect((float) $order->total)->toBe(30.00);
    expect($order->items->first()->quantity)->toBe(1);
});

test('admin can remove an item', function () {
    ['admin' => $admin, 'order' => $order, 'product' => $product] = orderEditContext();

    // Add a second item so removing the first still leaves one
    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'product_name' => 'Batata Frita',
        'unit_price' => 10.00,
        'quantity' => 1,
        'subtotal' => 10.00,
    ]);
    $order->update(['subtotal' => 35.00, 'total' => 40.00]);

    $this->actingAs($admin);

    Livewire::test(Show::class, ['order' => $order])
        ->call('startEditItems')
        ->call('removeItem', 0)
        ->call('saveItems')
        ->assertHasErrors(['editableItems']);

    $order->refresh();
    expect($order->items)->toHaveCount(2);
});

test('saveItems prevents saving with zero items', function () {
    ['admin' => $admin, 'order' => $order] = orderEditContext();

    $this->actingAs($admin);

    Livewire::test(Show::class, ['order' => $order])
        ->call('startEditItems')
        ->call('removeItem', 0)
        ->call('saveItems')
        ->assertHasErrors(['editableItems']);
});

test('order total recalculates after item edit', function () {
    ['admin' => $admin, 'order' => $order] = orderEditContext();

    $this->actingAs($admin);

    Livewire::test(Show::class, ['order' => $order])
        ->call('startEditItems')
        ->call('updateItemQuantity', 0, 2)
        ->call('saveItems')
        ->assertHasErrors(['editableItems']);

    $order->refresh();

    // Não pode salvar se o subtotal mudar
    expect((float) $order->total)->toBe(30.00);
});

// ─── Multi-tenancy isolation ──────────────────────────────────────────────────

test('product search only returns products from current company', function () {
    ['admin' => $admin, 'order' => $order, 'company' => $company] = orderEditContext();

    $otherCompany = Company::create([
        'name' => 'Outra Empresa',
        'slug' => 'outra-empresa',
        'order_prefix' => 'OE',
        'active' => true,
    ]);

    $otherCategory = ProductCategory::withoutGlobalScopes()->create([
        'company_id' => $otherCompany->id,
        'name' => 'Pizzas',
        'active' => true,
        'sort_order' => 1,
    ]);

    // Product with the same name but belonging to another company
    Product::withoutGlobalScopes()->create([
        'company_id' => $otherCompany->id,
        'product_category_id' => $otherCategory->id,
        'name' => 'X-Burguer Especial',
        'price' => 35.00,
        'active' => true,
        'sort_order' => 1,
    ]);

    $this->actingAs($admin);

    // current.company is set to $company (lancheria-teste), so other company product must not appear
    $component = Livewire::test(Show::class, ['order' => $order])
        ->call('startEditItems')
        ->set('productSearch', 'X-Burguer');

    $results = $component->get('productResults');

    foreach ($results as $result) {
        // All results must belong to the current company's branch
        $product = Product::withoutGlobalScopes()->find($result['id']);
        expect($product->company_id)->toBe($company->id);
    }
});
