<?php

use App\Livewire\Admin\Categories\Index;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function categoriesCompany(string $suffix): Company
{
    return Company::create([
        'name' => 'Categorias '.$suffix,
        'slug' => 'categorias-'.$suffix.'-'.uniqid(),
        'order_prefix' => 'CT'.strtoupper($suffix),
        'active' => true,
        'plan' => 'pro',
    ]);
}

function categoriesAdmin(Company $company): User
{
    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    return $admin;
}

function categoryProduct(ProductCategory $category, string $name = 'Coxinha'): Product
{
    return Product::withoutGlobalScopes()->create([
        'company_id' => $category->company_id,
        'product_category_id' => $category->id,
        'name' => $name,
        'price' => 8.0,
        'active' => true,
        'sort_order' => 1,
    ]);
}

test('cria categoria ativa por padrão e permite criar já inativa', function () {
    $company = categoriesCompany('a');
    app()->instance('current.company', $company);
    $admin = categoriesAdmin($company);

    $component = Livewire::actingAs($admin)->test(Index::class);

    $component->set('name', 'Salgados')->call('save')->assertHasNoErrors();
    expect(ProductCategory::where('name', 'Salgados')->value('active'))->toBeTrue();

    $component->set('name', 'Sazonais')->set('active', false)->call('save')->assertHasNoErrors();
    expect(ProductCategory::where('name', 'Sazonais')->value('active'))->toBeFalse();
});

test('depois de salvar o formulário volta ao estado inicial (ativa, sem edição)', function () {
    $company = categoriesCompany('b');
    app()->instance('current.company', $company);
    $admin = categoriesAdmin($company);

    Livewire::actingAs($admin)->test(Index::class)
        ->set('name', 'Sazonais')->set('active', false)->call('save')
        ->assertSet('name', '')
        ->assertSet('active', true)
        ->assertSet('editingId', null);
});

test('editar carrega o status ativa/inativa da categoria', function () {
    $company = categoriesCompany('c');
    app()->instance('current.company', $company);
    $admin = categoriesAdmin($company);
    $category = ProductCategory::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'name' => 'Antiga', 'active' => false, 'sort_order' => 1,
    ]);

    Livewire::actingAs($admin)->test(Index::class)
        ->call('edit', $category->id)
        ->assertSet('active', false)
        ->assertSet('name', 'Antiga');
});

test('a lista mostra a contagem de produtos e o selo Inativa', function () {
    $company = categoriesCompany('d');
    app()->instance('current.company', $company);
    $admin = categoriesAdmin($company);
    $category = ProductCategory::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'name' => 'Salgados', 'active' => false, 'sort_order' => 1,
    ]);
    categoryProduct($category, 'Coxinha');
    categoryProduct($category, 'Risole');

    Livewire::actingAs($admin)->test(Index::class)
        ->assertSee('2 produtos')
        ->assertSee('Inativa');
});

test('não exclui categoria com produtos (a FK em cascata apagaria os produtos)', function () {
    $company = categoriesCompany('e');
    app()->instance('current.company', $company);
    $admin = categoriesAdmin($company);
    $category = ProductCategory::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'name' => 'Salgados', 'active' => true, 'sort_order' => 1,
    ]);
    $product = categoryProduct($category);

    Livewire::actingAs($admin)->test(Index::class)
        ->call('confirmDelete', $category->id)
        ->call('delete')
        ->assertSet('deletingId', null)
        ->assertSee('ainda tem 1 produto(s)');

    expect(ProductCategory::withoutGlobalScopes()->whereKey($category->id)->exists())->toBeTrue();
    expect(Product::withoutGlobalScopes()->whereKey($product->id)->exists())->toBeTrue();
});

test('produto removido logicamente (com pedidos) também segura a exclusão da categoria', function () {
    $company = categoriesCompany('f');
    app()->instance('current.company', $company);
    $admin = categoriesAdmin($company);
    $category = ProductCategory::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'name' => 'Salgados', 'active' => true, 'sort_order' => 1,
    ]);
    categoryProduct($category)->delete(); // soft delete

    Livewire::actingAs($admin)->test(Index::class)->call('confirmDelete', $category->id)->call('delete');

    expect(ProductCategory::withoutGlobalScopes()->whereKey($category->id)->exists())->toBeTrue();
});

test('exclui categoria vazia', function () {
    $company = categoriesCompany('g');
    app()->instance('current.company', $company);
    $admin = categoriesAdmin($company);
    $category = ProductCategory::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'name' => 'Vazia', 'active' => true, 'sort_order' => 1,
    ]);

    Livewire::actingAs($admin)->test(Index::class)->call('confirmDelete', $category->id)->call('delete');

    expect(ProductCategory::withoutGlobalScopes()->whereKey($category->id)->exists())->toBeFalse();
});

test('contagem e exclusão respeitam a empresa: categoria de outra empresa não aparece nem é excluída', function () {
    $companyA = categoriesCompany('h');
    $companyB = categoriesCompany('i');
    app()->instance('current.company', $companyA);
    $admin = categoriesAdmin($companyA);
    $foreign = ProductCategory::withoutGlobalScopes()->create([
        'company_id' => $companyB->id, 'name' => 'Categoria Alheia', 'active' => true, 'sort_order' => 1,
    ]);

    Livewire::actingAs($admin)->test(Index::class)
        ->assertDontSee('Categoria Alheia')
        ->call('confirmDelete', $foreign->id)
        ->call('delete')
        ->assertForbidden();

    expect(ProductCategory::withoutGlobalScopes()->whereKey($foreign->id)->exists())->toBeTrue();
});
