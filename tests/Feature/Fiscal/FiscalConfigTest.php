<?php

use App\Livewire\Admin\Fiscal\Config;
use App\Models\Company;
use App\Models\CompanyFiscalConfig;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function fiscalConfigTestCompany(): Company
{
    return Company::create([
        'name' => 'Empresa Config Fiscal',
        'slug' => 'empresa-config-fiscal-'.uniqid(),
        'order_prefix' => 'CFG',
        'active' => true,
        'status' => 'ACTIVE',
        'fiscal_notes_enabled' => true,
    ]);
}

function grantFiscalSettingsPermission(User $user, Company $company): void
{
    $permission = Permission::firstOrCreate(
        ['name' => 'fiscal.settings'],
        ['group' => 'fiscal', 'label' => 'Configurações fiscais'],
    );

    UserPermission::create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'permission_id' => $permission->id,
        'granted' => true,
    ]);
}

test('usuário com permissão fiscal.settings salva configuração fiscal', function () {
    $company = fiscalConfigTestCompany();
    app()->instance('current.company', $company);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);
    grantFiscalSettingsPermission($admin, $company);

    Livewire::actingAs($admin)
        ->test(Config::class)
        ->assertSet('canManage', true)
        ->set('enabled', true)
        ->set('crt', 3)
        ->set('inscricaoEstadual', '123456789')
        ->set('environment', 'producao')
        ->set('nfceSerie', '2')
        ->set('providerToken', 'meu-token-secreto')
        ->call('save')
        ->assertHasNoErrors();

    $config = CompanyFiscalConfig::where('company_id', $company->id)->first();

    expect($config)->not->toBeNull();
    expect($config->enabled)->toBeTrue();
    expect($config->crt)->toBe(3);
    expect($config->inscricao_estadual)->toBe('123456789');
    expect($config->environment)->toBe('producao');
    expect($config->nfce_serie)->toBe(2);
    expect($config->provider_token)->toBe('meu-token-secreto');
});

test('token não é sobrescrito quando campo fica em branco no reenvio', function () {
    $company = fiscalConfigTestCompany();
    app()->instance('current.company', $company);

    CompanyFiscalConfig::create([
        'company_id' => $company->id,
        'enabled' => true,
        'provider_token' => 'token-existente',
    ]);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);
    grantFiscalSettingsPermission($admin, $company);

    Livewire::actingAs($admin)
        ->test(Config::class)
        ->assertSet('hasProviderToken', true)
        ->assertSet('providerToken', '')
        ->set('crt', 2)
        ->call('save')
        ->assertHasNoErrors();

    expect(CompanyFiscalConfig::where('company_id', $company->id)->first()->provider_token)
        ->toBe('token-existente');
});

test('usuário sem permissão fiscal.settings não consegue salvar', function () {
    $company = fiscalConfigTestCompany();
    app()->instance('current.company', $company);

    $manager = User::factory()->create();
    $manager->companies()->attach($company->id, ['role' => 'branch_manager']);

    Livewire::actingAs($manager)
        ->test(Config::class)
        ->assertSet('canManage', false)
        ->call('save')
        ->assertForbidden();
});

test('upload de certificado salva arquivo no disco privado', function () {
    \Illuminate\Support\Facades\Storage::fake('local');

    $company = fiscalConfigTestCompany();
    app()->instance('current.company', $company);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);
    grantFiscalSettingsPermission($admin, $company);

    Livewire::actingAs($admin)
        ->test(Config::class)
        ->set('certificateFile', UploadedFile::fake()->create('certificado.pfx', 100))
        ->set('certificatePassword', 'senha123')
        ->call('save')
        ->assertHasNoErrors();

    $config = CompanyFiscalConfig::where('company_id', $company->id)->first();

    expect($config->certificate_path)->not->toBeNull();
    expect($config->certificate_password)->toBe('senha123');
    \Illuminate\Support\Facades\Storage::disk('local')->assertExists($config->certificate_path);
});
