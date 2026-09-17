<?php

use App\Livewire\Admin\Pdv\Terminal;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('operador PDV não pode adicionar ao carrinho produto/filial de outra empresa via selectedBranchId adulterado', function () {
    $companyA = Company::create([
        'name' => 'PDV Empresa A',
        'slug' => 'pdv-empresa-a-'.uniqid(),
        'order_prefix' => 'PDA',
        'active' => true,
        'plan' => 'pro',
        'pdv_module_enabled' => true,
    ]);
    app()->instance('current.company', $companyA);

    Branch::withoutGlobalScopes()->create([
        'company_id' => $companyA->id,
        'name' => 'Balcão A',
        'address' => 'Rua A, 1',
        'city' => 'SP',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    $operator = User::factory()->create();
    $operator->companies()->attach($companyA->id, ['role' => 'caixa']);

    $companyB = Company::create([
        'name' => 'PDV Empresa B',
        'slug' => 'pdv-empresa-b-'.uniqid(),
        'order_prefix' => 'PDB',
        'active' => true,
        'plan' => 'pro',
        'pdv_module_enabled' => true,
    ]);

    $branchB = Branch::withoutGlobalScopes()->create([
        'company_id' => $companyB->id,
        'name' => 'Balcão B',
        'address' => 'Rua B, 2',
        'city' => 'SP',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    $categoryB = ProductCategory::withoutGlobalScopes()->create([
        'company_id' => $companyB->id,
        'name' => 'Categoria B',
        'active' => true,
        'sort_order' => 1,
    ]);

    $productB = Product::withoutGlobalScopes()->create([
        'company_id' => $companyB->id,
        'product_category_id' => $categoryB->id,
        'name' => 'Produto B',
        'price' => 12.0,
        'active' => true,
        'available_in_pdv' => true,
        'sort_order' => 1,
    ]);

    DB::table('branch_product')->insert([
        'branch_id' => $branchB->id,
        'product_id' => $productB->id,
        'available' => 1,
    ]);

    $this->actingAs($operator);

    Livewire::test(Terminal::class)
        ->set('selectedBranchId', $branchB->id)
        ->call('addProduct', $productB->id)
        ->assertForbidden();

    Livewire::test(Terminal::class)
        ->set('selectedBranchId', $branchB->id)
        ->call('addProductWithOptions', $productB->id, [])
        ->assertForbidden();
});
