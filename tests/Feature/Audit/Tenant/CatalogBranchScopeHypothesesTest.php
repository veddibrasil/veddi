<?php

/*
 * Auditoria 16/10/2026 — hipóteses confirmadas na correção de cardápio/filiais (além dos
 * achados T-02/T-03/T-05/T-09). Cada teste afirma o comportamento seguro.
 */

use App\Livewire\Admin\Branches\Form as BranchForm;
use App\Livewire\Admin\Branches\Index as BranchesIndex;
use App\Livewire\Admin\Coupons\Index as CouponsIndex;
use App\Livewire\Admin\Products\Form as ProductForm;
use App\Livewire\Admin\Products\Index as ProductsIndex;
use App\Models\Branch;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\ProductOptionGroup;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

require_once __DIR__.'/TenantAuditHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
});

test('Products/Form: gerente de filial não vincula produto novo a outra filial pela lista selectedBranches', function () {
    $company = atnCompany('PfBr');
    app()->instance('current.company', $company);
    $centro = atnBranch($company, 'Centro');
    $bairro = atnBranch($company, 'Bairro');
    $category = atnCategory($company);
    $manager = atnUser($company, 'branch_manager', $centro);
    app()->instance('current.branch', $centro);

    Livewire::actingAs($manager)
        ->test(ProductForm::class)
        ->set('product_category_id', $category->id)
        ->set('name', 'Coxinha do Gerente')
        ->set('price', '9.90')
        ->set('selectedBranches', [(string) $centro->id, (string) $bairro->id])
        ->call('save')
        ->assertHasNoErrors();

    $product = Product::withoutGlobalScopes()->where('name', 'Coxinha do Gerente')->firstOrFail();

    expect(DB::table('branch_product')->where('product_id', $product->id)->pluck('branch_id')->all())
        ->toBe([$centro->id]);
});

test('Products/Form: gerente de filial não tira o produto das outras filiais ao editar', function () {
    $company = atnCompany('PfEd');
    app()->instance('current.company', $company);
    $centro = atnBranch($company, 'Centro');
    $bairro = atnBranch($company, 'Bairro');
    $category = atnCategory($company);
    $product = atnProduct($company, $category, 'Pastel', $centro);
    DB::table('branch_product')->insert(['branch_id' => $bairro->id, 'product_id' => $product->id, 'available' => 1]);
    $manager = atnUser($company, 'branch_manager', $centro);
    app()->instance('current.branch', $centro);

    // Lista vazia: desmarca a própria filial (permitido) e tenta tirar o Bairro (não permitido).
    Livewire::actingAs($manager)
        ->test(ProductForm::class, ['product' => $product->fresh()])
        ->set('selectedBranches', [])
        ->call('save')
        ->assertHasNoErrors();

    expect(DB::table('branch_product')->where('product_id', $product->id)->pluck('branch_id')->all())
        ->toBe([$bairro->id]);
});

test('Products/Form: categoria de outra empresa é recusada na validação', function () {
    $companyB = atnCompany('PfCatB');
    $categoryB = atnCategory($companyB, 'Categoria B');

    $companyA = atnCompany('PfCatA');
    app()->instance('current.company', $companyA);
    $branchA = atnBranch($companyA);
    $adminA = atnUser($companyA, 'company_admin');

    Livewire::actingAs($adminA)
        ->test(ProductForm::class)
        ->set('product_category_id', $categoryB->id)
        ->set('name', 'Produto Pendurado em B')
        ->set('price', '10.00')
        ->set('selectedBranches', [(string) $branchA->id])
        ->call('save')
        ->assertHasErrors('product_category_id');

    expect(Product::withoutGlobalScopes()->where('name', 'Produto Pendurado em B')->exists())->toBeFalse();
});

test('Products/Form: attachExistingGroup não anexa grupo de adicionais de outra empresa', function () {
    $companyB = atnCompany('PfGrpB');
    $groupB = ProductOptionGroup::create([
        'company_id' => $companyB->id, 'name' => 'Molhos B', 'total_qty' => 1, 'min_qty' => 0,
        'fixed' => false, 'allow_skip' => false, 'sort_order' => 0,
    ]);

    $companyA = atnCompany('PfGrpA');
    app()->instance('current.company', $companyA);
    atnBranch($companyA);
    $adminA = atnUser($companyA, 'company_admin');

    Livewire::actingAs($adminA)
        ->test(ProductForm::class)
        ->call('attachExistingGroup', $groupB->id)
        ->assertSet('optionGroups', []);
});

test('Products/Index: gerente de filial não amplia a lista para outras filiais zerando lockedBranchId', function () {
    $company = atnCompany('PiBr');
    app()->instance('current.company', $company);
    $centro = atnBranch($company, 'Centro');
    $bairro = atnBranch($company, 'Bairro');
    $category = atnCategory($company);
    atnProduct($company, $category, 'Exclusivo do Bairro', $bairro);
    $manager = atnUser($company, 'branch_manager', $centro);
    app()->instance('current.branch', $centro);

    $html = atnAttempt(fn () => Livewire::actingAs($manager)
        ->test(ProductsIndex::class)
        ->set('lockedBranchId', null)
        ->html());

    expect((string) $html)->not->toContain('Exclusivo do Bairro');
});

test('Coupons: produto grátis de outra empresa é recusado na validação', function () {
    $companyB = atnCompany('CpFpB');
    $productB = atnProduct($companyB, atnCategory($companyB), 'Brinde B');

    $companyA = atnCompany('CpFpA');
    app()->instance('current.company', $companyA);
    $adminA = atnUser($companyA, 'company_admin');

    Livewire::actingAs($adminA)
        ->test(CouponsIndex::class)
        ->set('code', 'BRINDEB')
        ->set('name', 'Brinde de B')
        ->set('type', 'free_product')
        ->set('free_product_id', $productB->id)
        ->call('save')
        ->assertHasErrors('free_product_id');

    expect(Coupon::withoutGlobalScopes()->where('code', 'BRINDEB')->exists())->toBeFalse();
});

test('Branches/Form: admin no limite do plano não cria filial pela tela de edição forjando isEditing=false', function () {
    $company = atnCompany('BfEd', ['plan' => 'essencial']); // max_branches = 1
    app()->instance('current.company', $company);
    $branch = atnBranch($company, 'Única');
    $admin = atnUser($company, 'company_admin');

    atnAttempt(fn () => Livewire::actingAs($admin)
        ->test(BranchForm::class, ['branch' => $branch])
        ->set('isEditing', false)
        ->set('name', 'Filial Extra')
        ->call('save'));

    expect(Branch::withoutGlobalScopes()->where('company_id', $company->id)->count())->toBe(1);
});

test('Branches/Index: gerente de filial não vê as demais filiais zerando branchScoped', function () {
    $company = atnCompany('BiSc');
    app()->instance('current.company', $company);
    $centro = atnBranch($company, 'Centro');
    atnBranch($company, 'Filial Secreta do Bairro');
    $manager = atnUser($company, 'branch_manager', $centro);

    $html = atnAttempt(fn () => Livewire::actingAs($manager)
        ->test(BranchesIndex::class)
        ->set('branchScoped', false)
        ->html());

    expect((string) $html)->not->toContain('Filial Secreta do Bairro');
});

test('controle: admin no limite do plano segue editando a filial existente', function () {
    $company = atnCompany('BfCtl', ['plan' => 'essencial']);
    app()->instance('current.company', $company);
    $branch = atnBranch($company, 'Única');
    $admin = atnUser($company, 'company_admin');

    Livewire::actingAs($admin)
        ->test(BranchForm::class, ['branch' => $branch])
        ->set('name', 'Única Renomeada')
        ->call('save')
        ->assertHasNoErrors();

    expect($branch->fresh()->name)->toBe('Única Renomeada')
        ->and(Branch::withoutGlobalScopes()->where('company_id', $company->id)->count())->toBe(1);
});
