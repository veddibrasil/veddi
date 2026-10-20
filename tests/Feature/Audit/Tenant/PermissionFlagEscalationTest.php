<?php

/*
 * Auditoria 15/10/2026 — flags de permissão calculadas no mount() e confiadas depois
 * (canManage/canSave/isCaixa sem #[Locked]). Dentro da mesma empresa, um papel menor
 * reescreve a flag e executa a ação reservada. Os testes devem FALHAR hoje.
 */

use App\Livewire\Admin\Branches\Form as BranchForm;
use App\Livewire\Admin\Branches\Pauses;
use App\Livewire\Admin\Fiscal\Config as FiscalConfig;
use App\Livewire\Admin\Pdv\Report as PdvReport;
use App\Models\Branch;
use App\Models\BranchPause;
use App\Models\CompanyFiscalConfig;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

require_once __DIR__.'/TenantAuditHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
});

test('Fiscal/Config: gerente de filial (só fiscal.view) não grava configuração fiscal forjando canManage', function () {
    $company = atnCompany('Fis', ['fiscal_notes_enabled' => true]);
    app()->instance('current.company', $company);
    $branch = atnBranch($company, 'Centro');
    $manager = atnUser($company, 'branch_manager', $branch);

    atnAttempt(fn () => Livewire::actingAs($manager)
        ->test(FiscalConfig::class)
        ->set('canManage', true)
        ->set('environment', 'producao')
        ->set('providerToken', 'token-do-gerente')
        ->set('enabled', false)
        ->call('save'));

    expect(CompanyFiscalConfig::where('company_id', $company->id)->exists())->toBeFalse();
});

test('Branches/Pauses: gerente de filial (sem branches.update) não pausa a loja forjando canSave', function () {
    $company = atnCompany('Pau');
    app()->instance('current.company', $company);
    $branch = atnBranch($company, 'Centro');
    $manager = atnUser($company, 'branch_manager', $branch);

    atnAttempt(fn () => Livewire::actingAs($manager)
        ->test(Pauses::class, ['branch' => $branch])
        ->set('canSave', true)
        ->set('starts_date', now()->toDateString())
        ->set('ends_date', now()->addDays(3)->toDateString())
        ->call('create'));

    expect(BranchPause::withoutGlobalScopes()->where('branch_id', $branch->id)->exists())->toBeFalse();
});

test('Branches/Form: company_admin no limite do plano não cria filial extra forjando canSave', function () {
    $company = atnCompany('Lim', ['plan' => 'essencial']); // max_branches = 1
    app()->instance('current.company', $company);
    atnBranch($company, 'Única');
    $admin = atnUser($company, 'company_admin');

    atnAttempt(fn () => Livewire::actingAs($admin)
        ->test(BranchForm::class)
        ->set('canSave', true)
        ->set('name', 'Filial Extra')
        ->set('address', 'Rua Y')
        ->set('city', 'SP')
        ->call('save'));

    expect(Branch::withoutGlobalScopes()->where('company_id', $company->id)->count())->toBe(1);
});

test('PDV Report: caixa não vê vendas de outros operadores/filiais forjando isCaixa=false', function () {
    $company = atnCompany('Cx', ['pdv_module_enabled' => true]);
    app()->instance('current.company', $company);
    $centro = atnBranch($company, 'Centro');
    $bairro = atnBranch($company, 'Bairro');
    $cashier = atnUser($company, 'caixa', $centro);
    $otherOrder = atnOrder($company, $bairro, atnCustomer($company), [
        'order_type' => 'pdv', 'subtotal' => 5555.55, 'total' => 5555.55,
    ]);

    $html = atnAttempt(fn () => Livewire::actingAs($cashier)
        ->test(PdvReport::class)
        ->set('isCaixa', false)
        ->set('branchFilter', '')
        ->html());

    expect((string) $html)
        ->not->toContain($otherOrder->order_number)
        ->not->toContain('5.555,55');
});
