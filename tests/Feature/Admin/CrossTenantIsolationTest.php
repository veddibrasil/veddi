<?php

use App\Livewire\Admin\Branches\Index as BranchesIndex;
use App\Livewire\Admin\Categories\Index as CategoriesIndex;
use App\Livewire\Admin\Coupons\Index as CouponsIndex;
use App\Livewire\Admin\Products\Index as ProductsIndex;
use App\Livewire\Admin\Roles\Index as RolesIndex;
use App\Livewire\Admin\Stock\Index as StockIndex;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function ctCompany(string $suffix): Company
{
    return Company::create([
        'name' => 'Empresa CT '.$suffix,
        'slug' => 'ct-'.$suffix.'-'.uniqid(),
        'order_prefix' => 'CT'.strtoupper($suffix),
        'active' => true,
        'plan' => 'pro',
    ]);
}

function ctBranch(Company $company, string $name = 'Filial'): Branch
{
    return Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => $name,
        'address' => 'Rua A, 1',
        'city' => 'SP',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);
}

function ctCategory(Company $company, string $name = 'Categoria'): ProductCategory
{
    return ProductCategory::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => $name,
        'active' => true,
        'sort_order' => 1,
    ]);
}

function ctProduct(Company $company, ProductCategory $category, string $name = 'Produto'): Product
{
    return Product::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'product_category_id' => $category->id,
        'name' => $name,
        'price' => 10.0,
        'active' => true,
        'sort_order' => 1,
    ]);
}

function ctCoupon(Company $company, string $code = 'PROMO10'): Coupon
{
    return Coupon::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'code' => $code,
        'name' => 'Cupom '.$code,
        'type' => 'percentage',
        'discount_value' => 10,
        'scope' => 'order',
        'active' => true,
    ]);
}

function ctCompanyAdmin(Company $company): User
{
    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    return $admin;
}

// ─── Cupons ─────────────────────────────────────────────────────────────────

test('company_admin não pode editar cupom de outra empresa', function () {
    $companyA = ctCompany('cup-a');
    $companyB = ctCompany('cup-b');
    app()->instance('current.company', $companyA);
    $admin = ctCompanyAdmin($companyA);
    $foreignCoupon = ctCoupon($companyB, 'FORA10');

    Livewire::actingAs($admin)
        ->test(CouponsIndex::class)
        ->call('edit', $foreignCoupon->id)
        ->assertForbidden();
});

test('company_admin não pode ativar/desativar cupom de outra empresa', function () {
    $companyA = ctCompany('cup-c');
    $companyB = ctCompany('cup-d');
    app()->instance('current.company', $companyA);
    $admin = ctCompanyAdmin($companyA);
    $foreignCoupon = ctCoupon($companyB, 'FORA20');

    Livewire::actingAs($admin)
        ->test(CouponsIndex::class)
        ->call('toggleActive', $foreignCoupon->id)
        ->assertForbidden();

    expect($foreignCoupon->refresh()->active)->toBeTrue();
});

test('company_admin não pode excluir cupom de outra empresa', function () {
    $companyA = ctCompany('cup-e');
    $companyB = ctCompany('cup-f');
    app()->instance('current.company', $companyA);
    $admin = ctCompanyAdmin($companyA);
    $foreignCoupon = ctCoupon($companyB, 'FORA30');

    Livewire::actingAs($admin)
        ->test(CouponsIndex::class)
        ->call('confirmDelete', $foreignCoupon->id)
        ->call('delete')
        ->assertForbidden();

    expect(Coupon::withoutGlobalScopes()->find($foreignCoupon->id))->not->toBeNull();
});

// ─── Produtos ───────────────────────────────────────────────────────────────

test('company_admin não pode excluir produto de outra empresa', function () {
    $companyA = ctCompany('prod-a');
    $companyB = ctCompany('prod-b');
    app()->instance('current.company', $companyA);
    $admin = ctCompanyAdmin($companyA);

    $categoryB = ctCategory($companyB);
    $foreignProduct = ctProduct($companyB, $categoryB, 'Produto Alheio');

    Livewire::actingAs($admin)
        ->test(ProductsIndex::class)
        ->call('confirmDelete', $foreignProduct->id)
        ->call('delete')
        ->assertForbidden();

    expect(Product::withoutGlobalScopes()->find($foreignProduct->id))->not->toBeNull();
    expect($foreignProduct->refresh()->active)->toBeTrue();
});

// ─── Categorias ─────────────────────────────────────────────────────────────

test('company_admin não pode visualizar/editar categoria de outra empresa', function () {
    $companyA = ctCompany('cat-a');
    $companyB = ctCompany('cat-b');
    app()->instance('current.company', $companyA);
    $admin = ctCompanyAdmin($companyA);
    $foreignCategory = ctCategory($companyB, 'Categoria Alheia');

    Livewire::actingAs($admin)
        ->test(CategoriesIndex::class)
        ->call('edit', $foreignCategory->id)
        ->assertForbidden();
});

test('company_admin não pode excluir categoria de outra empresa', function () {
    $companyA = ctCompany('cat-c');
    $companyB = ctCompany('cat-d');
    app()->instance('current.company', $companyA);
    $admin = ctCompanyAdmin($companyA);
    $foreignCategory = ctCategory($companyB, 'Categoria Alheia 2');

    Livewire::actingAs($admin)
        ->test(CategoriesIndex::class)
        ->call('confirmDelete', $foreignCategory->id)
        ->call('delete')
        ->assertForbidden();

    expect(ProductCategory::withoutGlobalScopes()->find($foreignCategory->id))->not->toBeNull();
});

// ─── Filiais ────────────────────────────────────────────────────────────────

test('company_admin não pode excluir filial de outra empresa', function () {
    $companyA = ctCompany('branch-a');
    $companyB = ctCompany('branch-b');
    app()->instance('current.company', $companyA);
    $admin = ctCompanyAdmin($companyA);

    $foreignBranch = ctBranch($companyB, 'Filial Alheia 1');
    ctBranch($companyB, 'Filial Alheia 2'); // companyB precisa ter 2+ filiais

    Livewire::actingAs($admin)
        ->test(BranchesIndex::class)
        ->call('confirmDelete', $foreignBranch->id)
        ->call('delete')
        ->assertForbidden();

    expect(Branch::withoutGlobalScopes()->find($foreignBranch->id))->not->toBeNull();
});

// ─── Estoque ────────────────────────────────────────────────────────────────

test('usuário não pode ajustar estoque de produto/filial de outra empresa', function () {
    $companyA = ctCompany('stock-a');
    $companyB = ctCompany('stock-b');
    app()->instance('current.company', $companyA);
    $admin = ctCompanyAdmin($companyA);

    $branchB = ctBranch($companyB, 'Filial B');
    $categoryB = ctCategory($companyB);
    $productB = ctProduct($companyB, $categoryB, 'Produto B');

    DB::table('branch_product')->insert([
        'branch_id' => $branchB->id,
        'product_id' => $productB->id,
        'available' => 1,
        'quantity' => 5,
        'track_stock' => 1,
    ]);

    Livewire::actingAs($admin)
        ->test(StockIndex::class)
        ->call('openAdjustModal', $productB->id, $branchB->id)
        ->assertForbidden();

    Livewire::actingAs($admin)
        ->test(StockIndex::class)
        ->set('adjustingProductId', $productB->id)
        ->set('adjustingBranchId', $branchB->id)
        ->set('adjustQuantity', '10')
        ->set('adjustNotes', 'tentativa cross-tenant')
        ->call('applyAdjustment')
        ->assertForbidden();

    $pivot = DB::table('branch_product')
        ->where('branch_id', $branchB->id)
        ->where('product_id', $productB->id)
        ->first();

    expect((int) $pivot->quantity)->toBe(5);
});

// ─── Papéis (Roles) ─────────────────────────────────────────────────────────

test('company_admin não pode atribuir role customizado de outra empresa', function () {
    $companyA = ctCompany('role-a');
    $companyB = ctCompany('role-b');
    app()->instance('current.company', $companyA);
    $admin = ctCompanyAdmin($companyA);

    $target = User::factory()->create();
    $target->companies()->attach($companyA->id, ['role' => 'cozinha']);

    $foreignRole = Role::create([
        'name' => 'Papel da Empresa B',
        'slug' => 'papel_empresa_b_'.uniqid(),
        'company_id' => $companyB->id,
        'is_system' => false,
    ]);

    expect(fn () => Livewire::actingAs($admin)
        ->test(RolesIndex::class)
        ->set('assignRoleId', $foreignRole->id)
        ->set('assignUserEmail', $target->email)
        ->call('assignUser')
    )->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

    expect($target->companies()->where('companies.id', $companyA->id)->first()?->pivot->role)->toBe('cozinha');
});
