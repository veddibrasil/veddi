<?php

use App\Livewire\Admin\Products\Form;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductOption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function convertToOptionContext(): array
{
    $company = Company::create([
        'name' => 'Produto Convert Teste',
        'slug' => 'produto-convert-teste-'.uniqid(),
        'order_prefix' => 'PCT',
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

    $mainProduct = Product::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'product_category_id' => $category->id,
        'name' => 'Cento de Salgados',
        'price' => 40.00,
        'active' => true,
        'available_in_pdv' => true,
        'available_in_delivery' => true,
        'sort_order' => 1,
    ]);

    $sourceProduct = Product::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'product_category_id' => $category->id,
        'name' => 'Coxinha de Frango',
        'description' => 'Coxinha crocante de frango desfiado',
        'price' => 3.50,
        'active' => true,
        'available_in_pdv' => true,
        'available_in_delivery' => true,
        'sort_order' => 2,
    ]);

    $admin = User::factory()->create(['is_super_admin' => true]);

    return compact('company', 'branch', 'category', 'mainProduct', 'sourceProduct', 'admin');
}

test('importing a product as an option copies its data and leaves the original product untouched', function () {
    ['mainProduct' => $mainProduct, 'sourceProduct' => $sourceProduct, 'admin' => $admin] = convertToOptionContext();

    Livewire::actingAs($admin)
        ->test(Form::class, ['product' => $mainProduct])
        ->call('addOptionGroup')
        ->set('optionGroups.0.name', 'Sabores')
        ->call('openProductPicker', 0)
        ->call('importProductAsOption', $sourceProduct->id)
        ->call('save');

    $option = ProductOption::where('name', 'Coxinha de Frango')->first();

    expect($option)->not->toBeNull();
    expect((float) $option->additional_price)->toBe(3.50);
    expect($option->description)->toBe('Coxinha crocante de frango desfiado');

    $sourceProduct->refresh();
    expect($sourceProduct->active)->toBeTrue();
    expect($sourceProduct->trashed())->toBeFalse();

    $mainProduct->refresh();
    expect($mainProduct->active)->toBeTrue();
});

test('unlinking an imported option lets the source product be imported again elsewhere', function () {
    ['mainProduct' => $mainProduct, 'sourceProduct' => $sourceProduct, 'admin' => $admin] = convertToOptionContext();

    Livewire::actingAs($admin)
        ->test(Form::class, ['product' => $mainProduct])
        ->call('addOptionGroup')
        ->set('optionGroups.0.name', 'Sabores')
        ->call('openProductPicker', 0)
        ->call('importProductAsOption', $sourceProduct->id)
        ->call('unlinkSourceProduct', 0, 0)
        ->call('openProductPicker', 0)
        ->set('productSearch', 'e')
        ->assertViewHas('importableProducts', fn ($products) => $products->contains('id', $sourceProduct->id))
        ->call('save');

    $option = ProductOption::where('name', 'Coxinha de Frango')->first();
    expect($option)->not->toBeNull();

    $sourceProduct->refresh();
    expect($sourceProduct->active)->toBeTrue();
    expect($sourceProduct->trashed())->toBeFalse();
});

test('the product picker excludes the product being edited and already converted products', function () {
    ['mainProduct' => $mainProduct, 'sourceProduct' => $sourceProduct, 'admin' => $admin] = convertToOptionContext();

    Livewire::actingAs($admin)
        ->test(Form::class, ['product' => $mainProduct])
        ->call('addOptionGroup')
        ->call('openProductPicker', 0)
        ->set('productSearch', 'e')
        ->assertViewHas('importableProducts', function ($products) use ($mainProduct, $sourceProduct) {
            return ! $products->contains('id', $mainProduct->id) && $products->contains('id', $sourceProduct->id);
        })
        ->call('importProductAsOption', $sourceProduct->id)
        ->call('openProductPicker', 0)
        ->set('productSearch', 'e')
        ->assertViewHas('importableProducts', function ($products) use ($sourceProduct) {
            return ! $products->contains('id', $sourceProduct->id);
        });
});
