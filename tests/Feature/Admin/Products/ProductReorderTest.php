<?php

use App\Livewire\Admin\Products\Index;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function reorderTestCompany(string $suffix): Company
{
    return Company::create([
        'name' => 'Reorder Teste '.$suffix,
        'slug' => 'reorder-teste-'.$suffix.'-'.uniqid(),
        'order_prefix' => 'RDT'.$suffix,
        'active' => true,
        'plan' => 'pro',
    ]);
}

test('updateOrder persists sort_order for products within a category', function () {
    $company = reorderTestCompany('a');
    app()->instance('current.company', $company);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    $category = ProductCategory::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Salgados',
        'active' => true,
        'sort_order' => 1,
    ]);

    $p1 = Product::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'product_category_id' => $category->id,
        'name' => 'Coxinha', 'price' => 8.00, 'active' => true, 'sort_order' => 1,
    ]);
    $p2 = Product::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'product_category_id' => $category->id,
        'name' => 'Risole', 'price' => 7.00, 'active' => true, 'sort_order' => 2,
    ]);
    $p3 = Product::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'product_category_id' => $category->id,
        'name' => 'Bolinha', 'price' => 6.00, 'active' => true, 'sort_order' => 3,
    ]);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('updateOrder', $category->id, [$p3->id, $p1->id, $p2->id]);

    expect($p3->refresh()->sort_order)->toBe(0);
    expect($p1->refresh()->sort_order)->toBe(1);
    expect($p2->refresh()->sort_order)->toBe(2);
});

test('updateOrder does not let a company reorder another company products', function () {
    $companyA = reorderTestCompany('b');
    $companyB = reorderTestCompany('c');
    app()->instance('current.company', $companyA);

    $admin = User::factory()->create();
    $admin->companies()->attach($companyA->id, ['role' => 'company_admin']);

    $categoryA = ProductCategory::withoutGlobalScopes()->create([
        'company_id' => $companyA->id, 'name' => 'Categoria A', 'active' => true, 'sort_order' => 1,
    ]);

    $categoryB = ProductCategory::withoutGlobalScopes()->create([
        'company_id' => $companyB->id, 'name' => 'Categoria B', 'active' => true, 'sort_order' => 1,
    ]);

    $foreignProduct = Product::withoutGlobalScopes()->create([
        'company_id' => $companyB->id, 'product_category_id' => $categoryB->id,
        'name' => 'Produto Alheio', 'price' => 10.00, 'active' => true, 'sort_order' => 5,
    ]);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('updateOrder', $categoryA->id, [$foreignProduct->id]);

    expect($foreignProduct->refresh()->sort_order)->toBe(5);
});

test('updateOrder aborts for user without products.update permission', function () {
    $company = reorderTestCompany('d');
    app()->instance('current.company', $company);

    $viewer = User::factory()->create(); // sem vínculo com a empresa: hasPermission() nega

    $category = ProductCategory::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'name' => 'Salgados', 'active' => true, 'sort_order' => 1,
    ]);

    $product = Product::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'product_category_id' => $category->id,
        'name' => 'Coxinha', 'price' => 8.00, 'active' => true, 'sort_order' => 1,
    ]);

    Livewire::actingAs($viewer)
        ->test(Index::class)
        ->call('updateOrder', $category->id, [$product->id])
        ->assertForbidden();

    expect($product->refresh()->sort_order)->toBe(1);
});

test('updateCategoryOrder persists sort_order for categories within a company', function () {
    $company = reorderTestCompany('e');
    app()->instance('current.company', $company);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    $c1 = ProductCategory::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'name' => 'Salgados', 'active' => true, 'sort_order' => 1,
    ]);
    $c2 = ProductCategory::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'name' => 'Bebidas', 'active' => true, 'sort_order' => 2,
    ]);
    $c3 = ProductCategory::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'name' => 'Sobremesas', 'active' => true, 'sort_order' => 3,
    ]);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('updateCategoryOrder', [$c3->id, $c1->id, $c2->id]);

    expect($c3->refresh()->sort_order)->toBe(0);
    expect($c1->refresh()->sort_order)->toBe(1);
    expect($c2->refresh()->sort_order)->toBe(2);
});

test('updateCategoryOrder does not let a company reorder another company categories', function () {
    $companyA = reorderTestCompany('f');
    $companyB = reorderTestCompany('g');
    app()->instance('current.company', $companyA);

    $admin = User::factory()->create();
    $admin->companies()->attach($companyA->id, ['role' => 'company_admin']);

    $foreignCategory = ProductCategory::withoutGlobalScopes()->create([
        'company_id' => $companyB->id, 'name' => 'Categoria Alheia', 'active' => true, 'sort_order' => 5,
    ]);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('updateCategoryOrder', [$foreignCategory->id]);

    expect($foreignCategory->refresh()->sort_order)->toBe(5);
});

test('updateCategoryOrder aborts for user without products.update permission', function () {
    $company = reorderTestCompany('h');
    app()->instance('current.company', $company);

    $viewer = User::factory()->create();

    $category = ProductCategory::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'name' => 'Salgados', 'active' => true, 'sort_order' => 1,
    ]);

    Livewire::actingAs($viewer)
        ->test(Index::class)
        ->call('updateCategoryOrder', [$category->id])
        ->assertForbidden();

    expect($category->refresh()->sort_order)->toBe(1);
});
