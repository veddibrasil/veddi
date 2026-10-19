<?php

/*
 * Auditoria 15/10/2026 — props públicas Livewire de escopo/permissão sem #[Locked].
 *
 * O navegador pode reescrever qualquer propriedade pública não travada no payload
 * de /livewire/update. Estes testes simulam isso com ->set() e afirmam o
 * comportamento SEGURO esperado: devem FALHAR enquanto a falha existir.
 */

use App\Livewire\Admin\Branches\Form as BranchForm;
use App\Livewire\Admin\Categories\Index as CategoriesIndex;
use App\Livewire\Admin\Coupons\Index as CouponsIndex;
use App\Livewire\Admin\Orders\Report as OrdersReport;
use App\Livewire\Admin\Products\Index as ProductsIndex;
use App\Livewire\Admin\Stock\Index as StockIndex;
use App\Models\Branch;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\ProductCategory;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

require_once __DIR__.'/TenantAuditHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
});

test('Stock: usuário cozinha não pode virar isSuperAdmin e ajustar estoque de outra empresa', function () {
    $companyA = atnCompany('StockA');
    $companyB = atnCompany('StockB');
    $branchA = atnBranch($companyA);
    $branchB = atnBranch($companyB, 'Filial B');
    $productB = atnProduct($companyB, atnCategory($companyB), 'Coxinha B', $branchB, 5);

    app()->instance('current.company', $companyA);
    $cook = atnUser($companyA, 'cozinha', $branchA); // papel mais baixo com acesso a /admin/stock

    atnAttempt(fn () => Livewire::actingAs($cook)
        ->test(StockIndex::class)
        ->set('isSuperAdmin', true)
        ->call('openAdjustModal', $productB->id, $branchB->id)
        ->set('adjustMode', 'set')
        ->set('adjustQuantity', '999')
        ->set('adjustNotes', 'ataque cross-tenant')
        ->call('applyAdjustment'));

    $qty = DB::table('branch_product')->where('branch_id', $branchB->id)->where('product_id', $productB->id)->value('quantity');

    expect((int) $qty)->toBe(5);
});

test('Orders/Report: company_admin não pode virar isSuperAdmin e listar pedidos/clientes de outra empresa', function () {
    $companyA = atnCompany('RepA');
    $companyB = atnCompany('RepB');
    $branchB = atnBranch($companyB, 'Filial B');
    // Total "assinatura" fácil de achar no HTML. O nome do cliente não vaza só porque a
    // relação customer (CompanyScope) volta nula; número, total, status e receita vazam.
    $orderB = atnOrder($companyB, $branchB, atnCustomer($companyB, 'Cliente B'), ['subtotal' => 4321.98, 'total' => 4321.98]);

    app()->instance('current.company', $companyA);
    atnBranch($companyA);
    $adminA = atnUser($companyA, 'company_admin');

    $html = atnAttempt(fn () => Livewire::actingAs($adminA)
        ->test(OrdersReport::class)
        ->set('isSuperAdmin', true)
        ->html());

    expect((string) $html)
        ->not->toContain($orderB->order_number)
        ->not->toContain('4.321,98');
});

test('Coupons: company_admin não pode virar isSuperAdmin e listar códigos de cupom de outra empresa', function () {
    $companyA = atnCompany('CupA');
    $companyB = atnCompany('CupB');
    Coupon::withoutGlobalScopes()->create([
        'company_id' => $companyB->id,
        'code' => 'FUNCIONARIO100',
        'name' => 'Cupom interno B',
        'type' => 'percentage',
        'discount_value' => 100,
        'scope' => 'order',
        'active' => true,
    ]);

    app()->instance('current.company', $companyA);
    $adminA = atnUser($companyA, 'company_admin');

    $html = atnAttempt(fn () => Livewire::actingAs($adminA)
        ->test(CouponsIndex::class)
        ->set('isSuperAdmin', true)
        ->html());

    expect((string) $html)->not->toContain('FUNCIONARIO100');
});

test('Categories: company_admin não pode criar categoria no cardápio de outra empresa via isSuperAdmin/company_id', function () {
    $companyA = atnCompany('CatA');
    $companyB = atnCompany('CatB');

    app()->instance('current.company', $companyA);
    $adminA = atnUser($companyA, 'company_admin');

    atnAttempt(fn () => Livewire::actingAs($adminA)
        ->test(CategoriesIndex::class)
        ->set('isSuperAdmin', true)
        ->set('company_id', $companyB->id)
        ->set('name', 'Categoria Injetada')
        ->call('save'));

    expect(ProductCategory::withoutGlobalScopes()
        ->where('company_id', $companyB->id)
        ->where('name', 'Categoria Injetada')
        ->exists())->toBeFalse();
});

test('Products/Index: company_admin não pode reordenar o cardápio de outra empresa via isSuperAdmin', function () {
    $companyA = atnCompany('PrdA');
    $companyB = atnCompany('PrdB');
    $categoryB = atnCategory($companyB);
    $p1 = atnProduct($companyB, $categoryB, 'B1');
    $p2 = atnProduct($companyB, $categoryB, 'B2');
    Product::withoutGlobalScopes()->whereKey($p1->id)->update(['sort_order' => 0]);
    Product::withoutGlobalScopes()->whereKey($p2->id)->update(['sort_order' => 1]);

    app()->instance('current.company', $companyA);
    $adminA = atnUser($companyA, 'company_admin');

    atnAttempt(fn () => Livewire::actingAs($adminA)
        ->test(ProductsIndex::class)
        ->set('isSuperAdmin', true)
        ->call('updateOrder', $categoryB->id, [$p2->id, $p1->id]));

    expect(Product::withoutGlobalScopes()->find($p1->id)->sort_order)->toBe(0);
});

test('Branches/Form: company_admin não pode criar filial dentro de outra empresa via needsCompanySelect/company_id', function () {
    $companyA = atnCompany('BrA');
    $companyB = atnCompany('BrB');
    atnBranch($companyB, 'Matriz B');

    app()->instance('current.company', $companyA);
    atnBranch($companyA, 'Matriz A');
    $adminA = atnUser($companyA, 'company_admin');

    // company_id da empresa B; demais campos mínimos válidos do formulário.
    atnAttempt(fn () => Livewire::actingAs($adminA)
        ->test(BranchForm::class)
        ->set('needsCompanySelect', true)
        ->set('company_id', $companyB->id)
        ->set('name', 'Filial Fantasma')
        ->set('address', 'Rua X')
        ->set('number', '1')
        ->set('neighborhood', 'Centro')
        ->set('city', 'SP')
        ->set('state', 'SP')
        ->set('cep', '01001-000')
        ->set('phone', '(11) 99999-0000')
        ->call('save'));

    expect(Branch::withoutGlobalScopes()
        ->where('company_id', $companyB->id)
        ->where('name', 'Filial Fantasma')
        ->exists())->toBeFalse();
});
