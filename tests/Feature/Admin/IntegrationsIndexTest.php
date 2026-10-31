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
        ->assertSee('Conectado')
        ->assertSee('1 de 1 filial conectada');
});

test('filial suspensa ou aguardando reconexão aparece como precisando de atenção', function (string $status) {
    // O card da 99Food mostra "Não conectado" e não faz parte do que este teste olha.
    config(['food99.enabled' => false]);

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
        'status' => $status,
    ]);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->assertSee('Precisa de atenção')
        ->assertSee('1 filial precisa de atenção')
        ->assertDontSee('Não conectado');
})->with(['paused', 'reauthorization_required']);
