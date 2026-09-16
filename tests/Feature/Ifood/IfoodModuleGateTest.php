<?php

use App\Livewire\Admin\Integrations\Index as IntegrationsIndex;
use App\Livewire\Admin\Settings\IfoodIntegrationSettings;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function ifoodGateTestCompany(array $attributes = []): Company
{
    $company = Company::create(array_merge([
        'name' => 'Empresa Gate iFood',
        'slug' => 'empresa-gate-ifood-'.uniqid(),
        'order_prefix' => 'GTI',
        'active' => true,
        'status' => 'ACTIVE',
        'plan' => 'free',
    ], $attributes));

    Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial Principal',
        'address' => 'Rua A',
        'city' => 'São Paulo',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    return $company;
}

function ifoodGateTestAdmin(Company $company): User
{
    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    return $admin;
}

test('empresa free sem módulo ativo toma 403 na lista de integrações', function () {
    $company = ifoodGateTestCompany();
    app()->instance('current.company', $company);
    $admin = ifoodGateTestAdmin($company);

    Livewire::actingAs($admin)
        ->test(IntegrationsIndex::class)
        ->assertForbidden();
});

test('empresa free sem módulo ativo toma 403 nas configurações do iFood', function () {
    $company = ifoodGateTestCompany();
    app()->instance('current.company', $company);
    $admin = ifoodGateTestAdmin($company);

    Livewire::actingAs($admin)
        ->test(IfoodIntegrationSettings::class)
        ->assertForbidden();
});

test('empresa em plano pago acessa integrações mesmo sem módulos', function () {
    $company = ifoodGateTestCompany(['plan' => 'essencial']);
    app()->instance('current.company', $company);
    $admin = ifoodGateTestAdmin($company);

    Livewire::actingAs($admin)
        ->test(IntegrationsIndex::class)
        ->assertOk();
});

test('empresa free com módulo PDV ativo acessa integrações', function () {
    $company = ifoodGateTestCompany(['pdv_module_enabled' => true]);
    app()->instance('current.company', $company);
    $admin = ifoodGateTestAdmin($company);

    Livewire::actingAs($admin)
        ->test(IntegrationsIndex::class)
        ->assertOk();
});

test('empresa free com módulo Fiscal ativo acessa integrações', function () {
    $company = ifoodGateTestCompany(['fiscal_notes_enabled' => true]);
    app()->instance('current.company', $company);
    $admin = ifoodGateTestAdmin($company);

    Livewire::actingAs($admin)
        ->test(IntegrationsIndex::class)
        ->assertOk();
});

test('empresa free com módulo Garçom ativo acessa integrações', function () {
    $company = ifoodGateTestCompany(['waiter_module_enabled' => true]);
    app()->instance('current.company', $company);
    $admin = ifoodGateTestAdmin($company);

    Livewire::actingAs($admin)
        ->test(IntegrationsIndex::class)
        ->assertOk();
});
