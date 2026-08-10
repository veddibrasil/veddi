<?php

use App\Models\Branch;
use App\Models\BranchPause;
use App\Models\Company;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeBranchPauseTestBranch(): Branch
{
    $company = Company::create([
        'name' => 'Empresa Pausa Teste',
        'slug' => 'empresa-pausa-teste-'.uniqid(),
        'order_prefix' => 'PZ',
        'active' => true,
    ]);

    return Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial 1',
        'address' => 'Rua A, 1',
        'city' => 'SP',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);
}

test('pausa de intervalo único cobre apenas o período informado', function () {
    $branch = makeBranchPauseTestBranch();

    BranchPause::create([
        'company_id' => $branch->company_id,
        'branch_id' => $branch->id,
        'reason' => 'Reforma',
        'recurring_annual' => false,
        'starts_at' => '2026-08-01 08:00:00',
        'ends_at' => '2026-08-05 20:00:00',
    ]);

    expect($branch->isPaused(Carbon::parse('2026-08-03 10:00:00')))->toBeTrue();
    expect($branch->isPaused(Carbon::parse('2026-07-31 10:00:00')))->toBeFalse();
    expect($branch->isPaused(Carbon::parse('2026-08-06 10:00:00')))->toBeFalse();
});

test('pausa recorrente anual cobre a mesma data independente do ano', function () {
    $branch = makeBranchPauseTestBranch();

    BranchPause::create([
        'company_id' => $branch->company_id,
        'branch_id' => $branch->id,
        'reason' => 'Natal',
        'recurring_annual' => true,
        'starts_at' => '2026-12-25 00:00:00',
        'ends_at' => '2026-12-25 23:59:59',
    ]);

    expect($branch->isPaused(Carbon::parse('2026-12-25 12:00:00')))->toBeTrue();
    expect($branch->isPaused(Carbon::parse('2030-12-25 12:00:00')))->toBeTrue();
    expect($branch->isPaused(Carbon::parse('2030-12-24 12:00:00')))->toBeFalse();
});

test('pausa recorrente anual cruzando a virada do ano cobre corretamente', function () {
    $branch = makeBranchPauseTestBranch();

    BranchPause::create([
        'company_id' => $branch->company_id,
        'branch_id' => $branch->id,
        'reason' => 'Réveillon',
        'recurring_annual' => true,
        'starts_at' => '2026-12-31 20:00:00',
        'ends_at' => '2026-01-01 08:00:00',
    ]);

    expect($branch->isPaused(Carbon::parse('2026-12-31 22:00:00')))->toBeTrue();
    expect($branch->isPaused(Carbon::parse('2027-01-01 03:00:00')))->toBeTrue();
    expect($branch->isPaused(Carbon::parse('2028-01-01 03:00:00')))->toBeTrue();
    expect($branch->isPaused(Carbon::parse('2026-12-30 22:00:00')))->toBeFalse();
});

test('filial em pausa fica fechada mesmo dentro do horário de funcionamento', function () {
    $branch = makeBranchPauseTestBranch();

    BranchPause::create([
        'company_id' => $branch->company_id,
        'branch_id' => $branch->id,
        'recurring_annual' => false,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHour(),
    ]);

    expect($branch->isOpen())->toBeFalse();
});
