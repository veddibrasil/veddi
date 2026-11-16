<?php

use App\Livewire\Admin\Pdv\Report;
use App\Livewire\Admin\Pdv\Selector;
use App\Livewire\Admin\Pdv\Terminal;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/** Usuário com papel cozinha, com o papel carregando pdv.sell (como o seeder/migration entregam). */
function makeCozinhaWithSell(\App\Models\Company $company, \App\Models\Branch $branch): User
{
    $sell = Permission::firstOrCreate(['name' => 'pdv.sell'], ['group' => 'pdv', 'label' => 'Venda direta']);
    $role = Role::firstOrCreate(['slug' => 'cozinha', 'company_id' => null], ['name' => 'Cozinha', 'is_system' => true]);
    $role->permissions()->syncWithoutDetaching([$sell->id]);

    $user = User::factory()->create();
    $user->companies()->attach($company->id, ['role' => 'cozinha', 'branch_id' => $branch->id]);

    return $user;
}

test('cozinha com pdv.sell acessa a venda direta', function () {
    ['company' => $company, 'branch' => $branch] = pdvContext();
    $cozinha = makeCozinhaWithSell($company, $branch);

    Livewire::actingAs($cozinha)
        ->test(Terminal::class)
        ->assertOk()
        ->assertSet('canOperate', true)
        ->assertSet('isCaixa', true)
        ->assertSet('canOpenTabs', false)
        ->assertDontSee('Mesas/Comandas');
});

test('cozinha com pdv.sell não acessa relatório PDV', function () {
    ['company' => $company, 'branch' => $branch] = pdvContext();
    $cozinha = makeCozinhaWithSell($company, $branch);

    Livewire::actingAs($cozinha)
        ->test(Report::class)
        ->assertForbidden();
});

test('seletor do PDV manda cozinha só com venda direta direto pro terminal', function () {
    ['company' => $company, 'branch' => $branch] = pdvContext();
    $cozinha = makeCozinhaWithSell($company, $branch);

    Livewire::actingAs($cozinha)
        ->test(Selector::class)
        ->assertRedirect(route('admin.pdv.checkout'));
});

test('bar (sem pdv.sell nem pdv.operate) segue sem acesso ao terminal', function () {
    ['company' => $company, 'branch' => $branch] = pdvContext();
    $user = User::factory()->create();
    $user->companies()->attach($company->id, ['role' => 'bar', 'branch_id' => $branch->id]);

    Livewire::actingAs($user)
        ->test(Terminal::class)
        ->assertForbidden();
});
