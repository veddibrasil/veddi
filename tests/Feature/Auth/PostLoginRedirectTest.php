<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function loginRedirectContext(): array
{
    $company = Company::create([
        'name' => 'Login Redirect Teste',
        'slug' => 'login-redirect-'.uniqid(),
        'order_prefix' => 'LRT',
        'active' => true,
        'plan' => 'pro',
        'pdv_module_enabled' => true,
    ]);

    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial',
        'address' => 'Rua A, 1',
        'city' => 'SP',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    return compact('company', 'branch');
}

test('garçom é redirecionado direto para mesas/comandas após o login', function () {
    ['company' => $company, 'branch' => $branch] = loginRedirectContext();

    $waiter = User::factory()->create(['email' => 'garcom@teste.com']);
    $waiter->companies()->attach($company->id, ['role' => 'garcom', 'branch_id' => $branch->id]);

    $this->post(route('login.store'), [
        'email' => 'garcom@teste.com',
        'password' => 'password',
    ])->assertRedirect(route('admin.pdv.tabs', absolute: false));

    $this->assertAuthenticatedAs($waiter);
});

test('entregador é redirecionado direto para a fila de pedidos após o login', function () {
    ['company' => $company, 'branch' => $branch] = loginRedirectContext();

    $entrega = User::factory()->create(['email' => 'entrega@teste.com']);
    $entrega->companies()->attach($company->id, ['role' => 'entrega', 'branch_id' => $branch->id]);

    $this->post(route('login.store'), [
        'email' => 'entrega@teste.com',
        'password' => 'password',
    ])->assertRedirect(route('admin.orders.index', absolute: false));

    $this->assertAuthenticatedAs($entrega);
});

test('company_admin é redirecionado para o dashboard após o login', function () {
    ['company' => $company] = loginRedirectContext();

    $admin = User::factory()->create(['email' => 'admin@teste.com']);
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    $this->post(route('login.store'), [
        'email' => 'admin@teste.com',
        'password' => 'password',
    ])->assertRedirect(route('admin.dashboard', absolute: false));
});

test('super admin é redirecionado para o dashboard da plataforma após o login', function () {
    $superAdmin = User::factory()->create(['email' => 'super@teste.com', 'is_super_admin' => true]);

    $this->post(route('login.store'), [
        'email' => 'super@teste.com',
        'password' => 'password',
    ])->assertRedirect(route('superadmin.dashboard', absolute: false));
});
