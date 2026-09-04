<?php

use App\Livewire\Admin\Integrations\Index;
use App\Models\Branch;
use App\Models\Company;
use App\Models\IfoodIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function integrationsTestCompany(): Company
{
    return Company::create([
        'name' => 'Empresa Integrações',
        'slug' => 'empresa-integracoes-'.uniqid(),
        'order_prefix' => 'INT',
        'active' => true,
        'status' => 'ACTIVE',
    ]);
}

test('lista integração iFood como não conectada quando não há filial conectada', function () {
    $company = integrationsTestCompany();
    app()->instance('current.company', $company);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->assertSee('iFood')
        ->assertSee('Não conectado');
});

test('lista integração iFood como conectada quando há filial ativa', function () {
    $company = integrationsTestCompany();
    app()->instance('current.company', $company);

    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial',
        'address' => 'Rua A',
        'city' => 'SP',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    IfoodIntegration::create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'merchant_id' => 'merchant-abc',
        'status' => 'active',
    ]);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->assertSee('1 filial conectada');
});
