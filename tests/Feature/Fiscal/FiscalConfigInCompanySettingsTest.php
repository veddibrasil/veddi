<?php

use App\Livewire\Admin\Settings\CompanySettings;
use App\Models\Company;
use App\Models\User;
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

test('página de configurações da empresa embute o formulário fiscal quando módulo habilitado', function () {
    $company = companySettingsTestCompany(true);
    app()->instance('current.company', $company);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    Livewire::actingAs($admin)
        ->test(CompanySettings::class)
        ->assertSeeLivewire(\App\Livewire\Admin\Fiscal\Config::class);
});

test('página de configurações da empresa não mostra formulário fiscal quando módulo desabilitado', function () {
    $company = companySettingsTestCompany(false);
    app()->instance('current.company', $company);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    Livewire::actingAs($admin)
        ->test(CompanySettings::class)
        ->assertDontSeeLivewire(\App\Livewire\Admin\Fiscal\Config::class);
});
