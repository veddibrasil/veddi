<?php

use App\Livewire\Admin\Branches\ServiceCharges;
use App\Models\Branch;
use App\Models\BranchServiceCharge;
use App\Models\Company;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function makeServiceChargeTestCompany(): Company
{
    return Company::create([
        'name' => 'Service Charge Teste '.uniqid(),
        'slug' => 'service-charge-'.uniqid(),
        'order_prefix' => 'SCT',
        'active' => true,
    ]);
}

function makeServiceChargeTestBranch(Company $company, string $name = 'Filial 1'): Branch
{
    return Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => $name,
        'address' => 'Rua A, 1',
        'city' => 'SP',
        'active' => true,
    ]);
}

test('company_admin salva taxa de serviço e couvert da filial', function () {
    $company = makeServiceChargeTestCompany();
    $branch = makeServiceChargeTestBranch($company);

    app()->instance('current.company', $company);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    Livewire::actingAs($admin)
        ->test(ServiceCharges::class, ['branch' => $branch])
        ->set('service_fee_enabled', true)
        ->set('service_fee_type', 'percent')
        ->set('service_fee_value', '10')
        ->set('couvert_enabled', true)
        ->set('couvert_type', 'fixed')
        ->set('couvert_value', '5')
        ->call('save')
        ->assertHasNoErrors();

    $charge = BranchServiceCharge::where('branch_id', $branch->id)->first();
    expect($charge->service_fee_enabled)->toBeTrue();
    expect((float) $charge->service_fee_value)->toBe(10.0);
    expect($charge->couvert_enabled)->toBeTrue();
    expect((float) $charge->couvert_value)->toBe(5.0);
});

test('branch_manager sem permissão branches.update não consegue salvar', function () {
    $company = makeServiceChargeTestCompany();
    $branch = makeServiceChargeTestBranch($company);

    app()->instance('current.company', $company);
    app()->instance('current.branch', $branch);

    $manager = User::factory()->create();
    $manager->companies()->attach($company->id, ['role' => 'branch_manager', 'branch_id' => $branch->id]);

    Livewire::actingAs($manager)
        ->test(ServiceCharges::class, ['branch' => $branch])
        ->set('service_fee_enabled', true)
        ->call('save')
        ->assertForbidden();
});

test('branch_manager não acessa taxas de outra filial', function () {
    $company = makeServiceChargeTestCompany();
    $ownBranch = makeServiceChargeTestBranch($company, 'Filial do gerente');
    $otherBranch = makeServiceChargeTestBranch($company, 'Outra filial');

    app()->instance('current.company', $company);
    app()->instance('current.branch', $ownBranch);

    $manager = User::factory()->create();
    $manager->companies()->attach($company->id, ['role' => 'branch_manager', 'branch_id' => $ownBranch->id]);

    Livewire::actingAs($manager)
        ->test(ServiceCharges::class, ['branch' => $otherBranch])
        ->assertForbidden();
});

test('percentual acima de 100 é rejeitado', function () {
    $company = makeServiceChargeTestCompany();
    $branch = makeServiceChargeTestBranch($company);

    app()->instance('current.company', $company);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    Livewire::actingAs($admin)
        ->test(ServiceCharges::class, ['branch' => $branch])
        ->set('service_fee_enabled', true)
        ->set('service_fee_type', 'percent')
        ->set('service_fee_value', '150')
        ->call('save')
        ->assertHasErrors('service_fee_value');
});

test('branch_manager com permissão concedida salva taxas da própria filial', function () {
    $company = makeServiceChargeTestCompany();
    $branch = makeServiceChargeTestBranch($company);

    app()->instance('current.company', $company);
    app()->instance('current.branch', $branch);

    $manager = User::factory()->create();
    $manager->companies()->attach($company->id, ['role' => 'branch_manager', 'branch_id' => $branch->id]);

    $permission = Permission::firstOrCreate(['name' => 'branches.update'], ['group' => 'branches', 'label' => 'Editar filial']);
    UserPermission::create([
        'user_id' => $manager->id,
        'company_id' => $company->id,
        'permission_id' => $permission->id,
        'granted' => true,
    ]);

    Livewire::actingAs($manager)
        ->test(ServiceCharges::class, ['branch' => $branch])
        ->set('couvert_enabled', true)
        ->set('couvert_type', 'fixed')
        ->set('couvert_value', '8')
        ->call('save')
        ->assertHasNoErrors();

    expect((float) BranchServiceCharge::where('branch_id', $branch->id)->first()->couvert_value)->toBe(8.0);
});
