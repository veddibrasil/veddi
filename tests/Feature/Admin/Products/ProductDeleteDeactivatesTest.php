<?php

use App\Livewire\Admin\Pdv\Terminal;
use App\Livewire\Admin\Products\Index;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PdvCashSession;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function productDeleteContext(): array
{
    $company = Company::create([
        'name' => 'Produto Teste',
        'slug' => 'produto-teste-'.uniqid(),
        'order_prefix' => 'PDT',
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

    app()->instance('current.branch', $branch);

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
        'available_in_pdv' => true,
        'available_in_delivery' => true,
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

test('deleting product without orders hard deletes it and hides it from pdv and chat', function () {
    ['branch' => $branch, 'company' => $company, 'product' => $product, 'admin' => $admin] = productDeleteContext();

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('confirmDelete', $product->id)
        ->call('delete');

    expect(Product::withoutGlobalScopes()->find($product->id))->toBeNull();

    Livewire::actingAs($admin)
        ->test(Terminal::class)
        ->assertDontSee('Coxinha');

    $this->get("/{$company->slug}")->assertDontSee('Coxinha');
});

test('deleting product with orders deactivates it instead of removing it, and hides it from pdv and chat', function () {
    ['branch' => $branch, 'company' => $company, 'product' => $product, 'admin' => $admin] = productDeleteContext();

    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Cliente Teste',
        'phone' => '11999999999',
    ]);

    $order = Order::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'customer_id' => $customer->id,
        'status' => 'completed',
        'subtotal' => 8.00,
        'total' => 8.00,
        'order_number' => 'PDT-0001',
    ]);

    OrderItem::withoutGlobalScopes()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'product_name' => $product->name,
        'quantity' => 1,
        'unit_price' => 8.00,
        'subtotal' => 8.00,
    ]);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('confirmDelete', $product->id)
        ->call('delete');

    $product->refresh();
    expect($product->active)->toBeFalse();
    expect($product->trashed())->toBeTrue();

    Livewire::actingAs($admin)
        ->test(Terminal::class)
        ->assertDontSee('Coxinha');

    $this->get("/{$company->slug}")->assertDontSee('Coxinha');
});
