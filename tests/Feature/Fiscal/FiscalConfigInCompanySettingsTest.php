<?php

use App\Livewire\Admin\Fiscal\Config;
use App\Livewire\Admin\Settings\CompanySettings;
use App\Models\Company;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function companySettingsTestCompany(bool $fiscalEnabled): Company
{
    return Company::create([
        'name' => 'Empresa Settings Fiscal',
        'slug' => 'empresa-settings-fiscal-'.uniqid(),
        'order_prefix' => 'CFG',
        'active' => true,
        'status' => 'ACTIVE',
        'fiscal_notes_enabled' => $fiscalEnabled,
    ])->fresh();
}

test('página de configurações da empresa não embute mais o formulário fiscal (virou página própria)', function () {
    $company = companySettingsTestCompany(true);
    app()->instance('current.company', $company);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    Livewire::actingAs($admin)
        ->test(CompanySettings::class)
        ->assertDontSeeLivewire(Config::class);
});

test('página de configurações da empresa mostra aviso de responsabilidade fiscal quando módulo desabilitado', function () {
    $company = companySettingsTestCompany(false);
    app()->instance('current.company', $company);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    Livewire::actingAs($admin)
        ->test(CompanySettings::class)
        ->assertSee('módulo fiscal da plataforma não está habilitado');
});

test('página de configurações da empresa não mostra aviso de responsabilidade fiscal quando módulo habilitado', function () {
    $company = companySettingsTestCompany(true);
    app()->instance('current.company', $company);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    Livewire::actingAs($admin)
        ->test(CompanySettings::class)
        ->assertDontSee('módulo fiscal da plataforma não está habilitado');
});

test('página dedicada /admin/fiscal/configuracoes renderiza o componente Config para usuário com permissão', function () {
    $company = companySettingsTestCompany(true);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    $permission = Permission::firstOrCreate(
        ['name' => 'fiscal.settings'],
        ['group' => 'fiscal', 'label' => 'Configurações fiscais'],
    );

    UserPermission::create([
        'user_id' => $admin->id,
        'company_id' => $company->id,
        'permission_id' => $permission->id,
        'granted' => true,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.fiscal.config'))
        ->assertOk()
        ->assertSeeLivewire(Config::class);
});
