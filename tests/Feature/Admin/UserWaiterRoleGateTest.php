<?php

use App\Livewire\Admin\Users\Index as UsersIndex;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function usersIndexCompany(string $slug, bool $waiterModuleEnabled): Company
{
    return Company::create([
        'name' => "Empresa {$slug}",
        'slug' => $slug,
        'order_prefix' => strtoupper($slug),
        'active' => true,
        'waiter_module_enabled' => $waiterModuleEnabled,
    ]);
}

test('criação de usuário com papel Garçom é bloqueada quando o módulo Garçom não está ativo', function () {
    $company = usersIndexCompany('empresa-sem-garcom', false);
    app()->instance('current.company', $company);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    Livewire::actingAs($admin)
        ->test(UsersIndex::class)
        ->set('newName', 'Garçom Indevido')
        ->set('newEmail', 'garcom.indevido@teste.com')
        ->set('newPassword', 'senha1234')
        ->set('newRole', 'garcom')
        ->call('createUser')
        ->assertHasErrors(['newRole' => 'in']);

    expect(User::where('email', 'garcom.indevido@teste.com')->exists())->toBeFalse();
});

test('criação de usuário com papel Garçom funciona quando o módulo Garçom está ativo', function () {
    $company = usersIndexCompany('empresa-com-garcom', true);
    app()->instance('current.company', $company);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    Livewire::actingAs($admin)
        ->test(UsersIndex::class)
        ->set('newName', 'Garçom Válido')
        ->set('newEmail', 'garcom.valido@teste.com')
        ->set('newPassword', 'senha1234')
        ->set('newRole', 'garcom')
        ->call('createUser')
        ->assertHasNoErrors();

    expect(User::where('email', 'garcom.valido@teste.com')->exists())->toBeTrue();
});

test('vincular usuário existente ao papel Garçom é bloqueado quando o módulo Garçom não está ativo', function () {
    $company = usersIndexCompany('empresa-vincular-sem-garcom', false);
    app()->instance('current.company', $company);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    $existing = User::factory()->create(['email' => 'existente@teste.com']);

    Livewire::actingAs($admin)
        ->test(UsersIndex::class)
        ->set('linkEmail', 'existente@teste.com')
        ->set('linkRole', 'garcom')
        ->call('linkUser')
        ->assertHasErrors(['linkRole' => 'in']);

    expect($existing->companies()->where('companies.id', $company->id)->exists())->toBeFalse();
});

test('alterar tipo de usuário para Garçom é bloqueado quando o módulo Garçom não está ativo', function () {
    $company = usersIndexCompany('empresa-editar-sem-garcom', false);
    app()->instance('current.company', $company);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    $target = User::factory()->create();
    $target->companies()->attach($company->id, ['role' => 'caixa']);

    Livewire::actingAs($admin)
        ->test(UsersIndex::class)
        ->call('openEditRole', $target->id)
        ->set('editRole', 'garcom')
        ->call('saveEditRole')
        ->assertHasErrors(['editRole' => 'in']);

    expect($target->companies()->where('companies.id', $company->id)->first()->pivot->role)->toBe('caixa');
});
