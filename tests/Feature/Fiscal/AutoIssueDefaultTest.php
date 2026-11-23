<?php

use App\Livewire\Admin\Fiscal\Config;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyFiscalConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function autoIssueDefaultBranch(): array
{
    $company = Company::create([
        'name' => 'Empresa Padrao NFC-e',
        'slug' => 'empresa-padrao-nfce-'.uniqid(),
        'order_prefix' => 'PNF',
        'active' => true,
        'status' => 'ACTIVE',
        'owner_cpf_cnpj' => '12345678000100',
        'fiscal_notes_enabled' => true,
    ]);
    app()->instance('current.company', $company);

    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial Padrao',
        'address' => 'Rua B',
        'number' => '200',
        'neighborhood' => 'Centro',
        'city' => 'São Paulo',
        'state' => 'SP',
        'cep' => '01310000',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    return [$company, $branch, User::factory()->create(['is_super_admin' => true])];
}

test('filial nova nasce com emissão automática ligada na tela', function () {
    [, $branch, $admin] = autoIssueDefaultBranch();

    Livewire::actingAs($admin)->test(Config::class)
        ->set('branchId', $branch->id)
        ->assertSet('autoIssueEnabled', true)
        ->assertSet('hasFiscalConfig', false)
        ->assertDontSee('Desligada nesta filial');
});

test('filial nova salva com emissão automática ligada e marca o início', function () {
    [, $branch, $admin] = autoIssueDefaultBranch();

    Livewire::actingAs($admin)->test(Config::class)
        ->set('branchId', $branch->id)
        ->set('nfceSerie', '1')
        ->call('save')
        ->assertHasNoErrors();

    $config = CompanyFiscalConfig::where('branch_id', $branch->id)->first();
    expect($config->auto_issue_enabled)->toBeTrue()
        ->and($config->auto_issue_enabled_at)->not->toBeNull()
        ->and($config->enabled)->toBeFalse();
});

test('filial existente com emissão automática desligada continua desligada e avisa', function () {
    [, $branch, $admin] = autoIssueDefaultBranch();
    CompanyFiscalConfig::create([
        'company_id' => $branch->company_id,
        'branch_id' => $branch->id,
        'is_default' => true,
        'enabled' => false,
        'auto_issue_enabled' => false,
    ]);

    Livewire::actingAs($admin)->test(Config::class)
        ->set('branchId', $branch->id)
        ->assertSet('autoIssueEnabled', false)
        ->assertSet('hasFiscalConfig', true)
        ->assertSee('Desligada nesta filial');
});

test('habilitar a emissão fiscal exige certificado A1 da filial', function () {
    [, $branch, $admin] = autoIssueDefaultBranch();

    Livewire::actingAs($admin)->test(Config::class)
        ->set('branchId', $branch->id)
        ->set('enabled', true)
        ->set('legalName', 'Filial Padrao LTDA')
        ->call('save')
        ->assertHasErrors(['certificateFile']);

    expect(CompanyFiscalConfig::where('branch_id', $branch->id)->exists())->toBeFalse();
});

test('the database default for auto issue is on', function () {
    $company = Company::create([
        'name' => 'Empresa Default DB',
        'slug' => 'empresa-default-db-'.uniqid(),
        'order_prefix' => 'DDB',
        'active' => true,
        'status' => 'ACTIVE',
        'fiscal_notes_enabled' => true,
    ]);
    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'name' => 'Filial DB', 'active' => true,
        'opens_at' => '00:00:00', 'closes_at' => '23:59:59',
    ]);

    $row = DB::table('company_fiscal_configs')->insert([
        'company_id' => $company->id, 'branch_id' => $branch->id, 'is_default' => true,
        'enabled' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    expect($row)->toBeTrue();
    expect(DB::table('company_fiscal_configs')->where('branch_id', $branch->id)->value('auto_issue_enabled'))->toBeTruthy();
});
