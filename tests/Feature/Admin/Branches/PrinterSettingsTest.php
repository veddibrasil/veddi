<?php

use App\Livewire\Admin\Branches\PrinterSettings;
use App\Models\Branch;
use App\Models\BranchPrinter;
use App\Models\Company;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function makePrinterTestCompany(): Company
{
    return Company::create([
        'name' => 'Printer Teste '.uniqid(),
        'slug' => 'printer-'.uniqid(),
        'order_prefix' => 'PRT',
        'active' => true,
    ]);
}

function makePrinterTestBranch(Company $company, string $name = 'Filial 1'): Branch
{
    return Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => $name,
        'address' => 'Rua A, 1',
        'city' => 'SP',
        'active' => true,
    ]);
}

test('company_admin salva configuração de impressora da filial', function () {
    $company = makePrinterTestCompany();
    $branch = makePrinterTestBranch($company);

    app()->instance('current.company', $company);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    Livewire::actingAs($admin)
        ->test(PrinterSettings::class, ['branch' => $branch])
        ->set('ipAddress', '192.168.0.50')
        ->set('port', '9100')
        ->set('paperWidth', '80')
        ->call('save')
        ->assertHasNoErrors();

    expect(BranchPrinter::where('branch_id', $branch->id)->first())
        ->ip_address->toBe('192.168.0.50')
        ->port->toBe(9100);
});

test('branch_manager sem permissão branches.update não consegue salvar', function () {
    $company = makePrinterTestCompany();
    $branch = makePrinterTestBranch($company);

    app()->instance('current.company', $company);
    app()->instance('current.branch', $branch);

    $manager = User::factory()->create();
    $manager->companies()->attach($company->id, ['role' => 'branch_manager', 'branch_id' => $branch->id]);

    Livewire::actingAs($manager)
        ->test(PrinterSettings::class, ['branch' => $branch])
        ->set('ipAddress', '192.168.0.50')
        ->call('save')
        ->assertForbidden();
});

test('branch_manager com permissão concedida salva impressora da própria filial', function () {
    $company = makePrinterTestCompany();
    $branch = makePrinterTestBranch($company);

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
        ->test(PrinterSettings::class, ['branch' => $branch])
        ->set('ipAddress', '10.0.0.10')
        ->set('port', '9100')
        ->set('paperWidth', '58')
        ->call('save')
        ->assertHasNoErrors();

    expect(BranchPrinter::where('branch_id', $branch->id)->first()->ip_address)->toBe('10.0.0.10');
});

test('branch_manager não acessa impressora de outra filial', function () {
    $company = makePrinterTestCompany();
    $ownBranch = makePrinterTestBranch($company, 'Filial do gerente');
    $otherBranch = makePrinterTestBranch($company, 'Outra filial');

    app()->instance('current.company', $company);
    app()->instance('current.branch', $ownBranch);

    $manager = User::factory()->create();
    $manager->companies()->attach($company->id, ['role' => 'branch_manager', 'branch_id' => $ownBranch->id]);

    Livewire::actingAs($manager)
        ->test(PrinterSettings::class, ['branch' => $otherBranch])
        ->assertForbidden();
});

test('ip inválido é rejeitado na validação', function () {
    $company = makePrinterTestCompany();
    $branch = makePrinterTestBranch($company);

    app()->instance('current.company', $company);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    Livewire::actingAs($admin)
        ->test(PrinterSettings::class, ['branch' => $branch])
        ->set('ipAddress', 'não-é-um-ip')
        ->call('save')
        ->assertHasErrors(['ipAddress' => 'ip']);
});
