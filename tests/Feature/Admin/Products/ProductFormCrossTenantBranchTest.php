<?php

use App\Livewire\Admin\Products\Form;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('company_admin não pode vincular produto a filial de outra empresa via selectedBranches adulterado', function () {
    $companyA = Company::create([
        'name' => 'Empresa A Form',
        'slug' => 'empresa-a-form-'.uniqid(),
        'order_prefix' => 'EAF',
        'active' => true,
        'plan' => 'pro',
    ]);
    app()->instance('current.company', $companyA);

    $categoryA = ProductCategory::withoutGlobalScopes()->create([
        'company_id' => $companyA->id,
        'name' => 'Categoria A',
        'active' => true,
        'sort_order' => 1,
    ]);

    $admin = User::factory()->create();
    $admin->companies()->attach($companyA->id, ['role' => 'company_admin']);

    $companyB = Company::create([
        'name' => 'Empresa B Form',
        'slug' => 'empresa-b-form-'.uniqid(),
        'order_prefix' => 'EBF',
        'active' => true,
        'plan' => 'pro',
    ]);

    $branchB = Branch::withoutGlobalScopes()->create([
        'company_id' => $companyB->id,
        'name' => 'Filial B',
        'address' => 'Rua B, 2',
        'city' => 'SP',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    $this->actingAs($admin);

    Livewire::test(Form::class)
        ->set('product_category_id', $categoryA->id)
        ->set('name', 'Produto Cross Tenant')
        ->set('price', '10.00')
        ->set('selectedBranches', [(string) $branchB->id])
        ->call('save')
        ->assertHasErrors(['selectedBranches.0']);

    expect(Product::withoutGlobalScopes()->where('name', 'Produto Cross Tenant')->exists())->toBeFalse();
});
