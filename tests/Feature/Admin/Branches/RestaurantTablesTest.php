<?php

use App\Livewire\Admin\Branches\RestaurantTables;
use App\Models\Branch;
use App\Models\Company;
use App\Models\RestaurantTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function makeRestaurantTablesTestCompany(): Company
{
    return Company::create([
        'name' => 'Mesas Teste '.uniqid(),
        'slug' => 'mesas-teste-'.uniqid(),
        'order_prefix' => 'MST',
        'active' => true,
        'pdv_module_enabled' => true,
    ]);
}

function makeRestaurantTablesTestBranch(Company $company): Branch
{
    return Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial 1',
        'address' => 'Rua A, 1',
        'city' => 'SP',
        'active' => true,
    ]);
}

test('company_admin gera mesas numeradas para a filial', function () {
    $company = makeRestaurantTablesTestCompany();
    $branch = makeRestaurantTablesTestBranch($company);

    app()->instance('current.company', $company);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    Livewire::actingAs($admin)
        ->test(RestaurantTables::class, ['branch' => $branch])
        ->set('newCount', '5')
        ->call('generate')
        ->assertHasNoErrors();

    expect(RestaurantTable::where('branch_id', $branch->id)->count())->toBe(5);
    expect(RestaurantTable::where('branch_id', $branch->id)->pluck('number')->sort()->values()->all())
        ->toBe([1, 2, 3, 4, 5]);
});

test('gerar mesas novamente não duplica números já existentes', function () {
    $company = makeRestaurantTablesTestCompany();
    $branch = makeRestaurantTablesTestBranch($company);

    app()->instance('current.company', $company);

    RestaurantTable::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'number' => 2, 'active' => true]);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    Livewire::actingAs($admin)
        ->test(RestaurantTables::class, ['branch' => $branch])
        ->set('newCount', '3')
        ->call('generate')
        ->assertHasNoErrors();

    expect(RestaurantTable::where('branch_id', $branch->id)->count())->toBe(3);
});

test('company_admin desativa e reativa uma mesa', function () {
    $company = makeRestaurantTablesTestCompany();
    $branch = makeRestaurantTablesTestBranch($company);

    app()->instance('current.company', $company);

    $table = RestaurantTable::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'number' => 1, 'active' => true]);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    Livewire::actingAs($admin)
        ->test(RestaurantTables::class, ['branch' => $branch])
        ->call('toggleActive', $table->id);

    expect($table->fresh()->active)->toBeFalse();
});

test('branch_manager sem permissão branches.update não consegue gerar mesas', function () {
    $company = makeRestaurantTablesTestCompany();
    $branch = makeRestaurantTablesTestBranch($company);

    app()->instance('current.company', $company);
    app()->instance('current.branch', $branch);

    $manager = User::factory()->create();
    $manager->companies()->attach($company->id, ['role' => 'branch_manager', 'branch_id' => $branch->id]);

    Livewire::actingAs($manager)
        ->test(RestaurantTables::class, ['branch' => $branch])
        ->set('newCount', '5')
        ->call('generate')
        ->assertForbidden();
});

test('company sem modulo PDV não acessa cadastro de mesas', function () {
    $company = makeRestaurantTablesTestCompany();
    $company->update(['pdv_module_enabled' => false]);
    $branch = makeRestaurantTablesTestBranch($company);

    app()->instance('current.company', $company);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    Livewire::actingAs($admin)
        ->test(RestaurantTables::class, ['branch' => $branch])
        ->assertForbidden();
});
