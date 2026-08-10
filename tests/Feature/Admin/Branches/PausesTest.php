<?php

use App\Livewire\Admin\Branches\Pauses;
use App\Models\Branch;
use App\Models\BranchPause;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function makePausesTestCompany(): Company
{
    return Company::create([
        'name' => 'Pausas Teste '.uniqid(),
        'slug' => 'pausas-teste-'.uniqid(),
        'order_prefix' => 'PST',
        'active' => true,
    ]);
}

function makePausesTestBranch(Company $company): Branch
{
    return Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial 1',
        'address' => 'Rua A, 1',
        'city' => 'SP',
        'active' => true,
    ]);
}

test('company_admin cadastra uma pausa de intervalo único', function () {
    $company = makePausesTestCompany();
    $branch = makePausesTestBranch($company);

    app()->instance('current.company', $company);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    Livewire::actingAs($admin)
        ->test(Pauses::class, ['branch' => $branch])
        ->set('reason', 'Reforma')
        ->set('starts_date', '2026-09-01')
        ->set('starts_time', '08:00')
        ->set('ends_date', '2026-09-05')
        ->set('ends_time', '20:00')
        ->call('create')
        ->assertHasNoErrors();

    expect(BranchPause::where('branch_id', $branch->id)->count())->toBe(1);
    $pause = BranchPause::where('branch_id', $branch->id)->first();
    expect($pause->reason)->toBe('Reforma');
    expect($pause->recurring_annual)->toBeFalse();
    expect($pause->starts_at->format('H:i'))->toBe('08:00');
    expect($pause->ends_at->format('H:i'))->toBe('20:00');
});

test('pausa sem horário informado considera o dia todo', function () {
    $company = makePausesTestCompany();
    $branch = makePausesTestBranch($company);

    app()->instance('current.company', $company);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    Livewire::actingAs($admin)
        ->test(Pauses::class, ['branch' => $branch])
        ->set('starts_date', '2026-09-01')
        ->set('ends_date', '2026-09-01')
        ->call('create')
        ->assertHasNoErrors();

    $pause = BranchPause::where('branch_id', $branch->id)->first();
    expect($pause->starts_at->format('H:i:s'))->toBe('00:00:00');
    expect($pause->ends_at->format('H:i:s'))->toBe('23:59:59');
});

test('término antes do início é rejeitado para pausa não recorrente', function () {
    $company = makePausesTestCompany();
    $branch = makePausesTestBranch($company);

    app()->instance('current.company', $company);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    Livewire::actingAs($admin)
        ->test(Pauses::class, ['branch' => $branch])
        ->set('starts_date', '2026-09-05')
        ->set('starts_time', '08:00')
        ->set('ends_date', '2026-09-01')
        ->set('ends_time', '20:00')
        ->call('create')
        ->assertHasErrors(['ends_date']);

    expect(BranchPause::where('branch_id', $branch->id)->count())->toBe(0);
});

test('pausa recorrente anual pode ter término antes do início', function () {
    $company = makePausesTestCompany();
    $branch = makePausesTestBranch($company);

    app()->instance('current.company', $company);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    Livewire::actingAs($admin)
        ->test(Pauses::class, ['branch' => $branch])
        ->set('recurring_annual', true)
        ->set('starts_date', '2026-12-31')
        ->set('starts_time', '20:00')
        ->set('ends_date', '2026-01-01')
        ->set('ends_time', '08:00')
        ->call('create')
        ->assertHasNoErrors();

    expect(BranchPause::where('branch_id', $branch->id)->where('recurring_annual', true)->count())->toBe(1);
});

test('company_admin exclui uma pausa e invalida o cache de horário', function () {
    $company = makePausesTestCompany();
    $branch = makePausesTestBranch($company);

    app()->instance('current.company', $company);

    $pause = BranchPause::create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'recurring_annual' => false,
        'starts_at' => now(),
        'ends_at' => now()->addDay(),
    ]);

    Cache::put("open_branches:company:{$company->id}", false, now()->addMinutes(5));

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    Livewire::actingAs($admin)
        ->test(Pauses::class, ['branch' => $branch])
        ->call('confirmDelete', $pause->id)
        ->call('delete');

    expect(BranchPause::find($pause->id))->toBeNull();
    expect(Cache::has("open_branches:company:{$company->id}"))->toBeFalse();
});

test('branch_manager sem permissão branches.update não consegue cadastrar pausa', function () {
    $company = makePausesTestCompany();
    $branch = makePausesTestBranch($company);

    app()->instance('current.company', $company);
    app()->instance('current.branch', $branch);

    $manager = User::factory()->create();
    $manager->companies()->attach($company->id, ['role' => 'branch_manager', 'branch_id' => $branch->id]);

    Livewire::actingAs($manager)
        ->test(Pauses::class, ['branch' => $branch])
        ->set('starts_date', '2026-09-01')
        ->set('ends_date', '2026-09-05')
        ->call('create')
        ->assertForbidden();
});
