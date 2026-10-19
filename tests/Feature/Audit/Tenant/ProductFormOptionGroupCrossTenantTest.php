<?php

/*
 * Auditoria 15/10/2026 — Products/Form: optionGroups[].group_id e options[].id
 * vêm do cliente e são resolvidos com ProductOptionGroup::find()/ProductOption::find()
 * (modelos SEM CompanyScope). Um company_admin edita preço/nome e apaga adicionais de
 * outra empresa. saveCategory() usa $this->company_id (prop pública) mesmo para
 * não-super-admin. Os testes afirmam o comportamento seguro e devem FALHAR hoje.
 */

use App\Livewire\Admin\Products\Form as ProductForm;
use App\Models\ProductCategory;
use App\Models\ProductOption;
use App\Models\ProductOptionGroup;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

require_once __DIR__.'/TenantAuditHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
});

function atnOptionGroupFixture(): array
{
    $companyB = atnCompany('OptB');
    $branchB = atnBranch($companyB, 'Filial B');
    $productB = atnProduct($companyB, atnCategory($companyB), 'Pizza B', $branchB);

    $groupB = ProductOptionGroup::create([
        'company_id' => $companyB->id, 'name' => 'Bordas B', 'total_qty' => 1, 'min_qty' => 0,
        'fixed' => false, 'allow_skip' => false, 'sort_order' => 0,
    ]);
    $productB->optionGroups()->attach($groupB->id, ['sort_order' => 0]);

    $optCatupiry = ProductOption::create([
        'product_option_group_id' => $groupB->id, 'name' => 'Catupiry', 'additional_price' => 12.00,
        'default_qty' => 0, 'active' => true, 'sort_order' => 0,
    ]);
    $optCheddar = ProductOption::create([
        'product_option_group_id' => $groupB->id, 'name' => 'Cheddar', 'additional_price' => 10.00,
        'default_qty' => 0, 'active' => true, 'sort_order' => 1,
    ]);

    $companyA = atnCompany('OptA');
    app()->instance('current.company', $companyA);
    $branchA = atnBranch($companyA, 'Filial A');
    $categoryA = atnCategory($companyA, 'Lanches A');
    $adminA = atnUser($companyA, 'company_admin');

    return compact('companyA', 'branchA', 'categoryA', 'adminA', 'companyB', 'groupB', 'optCatupiry', 'optCheddar');
}

test('Products/Form: company_admin não pode alterar/apagar adicionais de outra empresa forjando group_id/options.id', function () {
    $f = atnOptionGroupFixture();

    $forgedGroups = [[
        'key' => 'gid-'.$f['groupB']->id,
        'group_id' => $f['groupB']->id,            // grupo da empresa B
        'name' => 'Bordas B',
        'image_path' => null,
        'total_qty' => '1',
        'min_qty' => '0',
        'fixed' => false,
        'allow_skip' => false,
        'sort_order' => 0,
        'options' => [[
            'key' => 'oid-'.$f['optCatupiry']->id,
            'id' => $f['optCatupiry']->id,          // opção da empresa B
            'name' => 'Catupiry',
            'image_path' => null,
            'active' => true,
            'description' => '',
            'additional_price' => '0.01',           // derruba o preço no cardápio de B
            'default_qty' => '0',
            'max_qty' => '',
            'sort_order' => 0,
        ]],                                         // Cheddar omitido => apagado
    ]];

    atnAttempt(fn () => Livewire::actingAs($f['adminA'])
        ->test(ProductForm::class)
        ->set('product_category_id', $f['categoryA']->id)
        ->set('name', 'Produto A')
        ->set('price', '20.00')
        ->set('selectedBranches', [(string) $f['branchA']->id])
        ->set('optionGroups', $forgedGroups)
        ->call('save'));

    expect((float) $f['optCatupiry']->fresh()->additional_price)->toBe(12.0)
        ->and(ProductOption::whereKey($f['optCheddar']->id)->exists())->toBeTrue();
});

test('Products/Form: saveCategory não pode criar categoria em outra empresa via company_id', function () {
    $f = atnOptionGroupFixture();

    atnAttempt(fn () => Livewire::actingAs($f['adminA'])
        ->test(ProductForm::class)
        ->set('company_id', $f['companyB']->id)
        ->set('newCategoryName', 'Categoria Injetada via Produto')
        ->call('saveCategory'));

    expect(ProductCategory::withoutGlobalScopes()
        ->where('company_id', $f['companyB']->id)
        ->where('name', 'Categoria Injetada via Produto')
        ->exists())->toBeFalse();
});
