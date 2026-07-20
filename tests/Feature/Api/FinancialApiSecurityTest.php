<?php

use App\Models\Company;
use App\Models\User;
use App\Services\Finance\BalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ─── Helpers ─────────────────────────────────────────────────────────────────

function makeFinancialCompany(): Company
{
    return Company::create([
        'name' => 'Empresa Financeira '.uniqid(),
        'slug' => 'empresa-fin-'.uniqid(),
        'order_prefix' => 'FIN',
        'active' => true,
    ]);
}

function makeFinancialUserWithRole(string $role): array
{
    $company = makeFinancialCompany();
    $user = User::factory()->create();
    $user->companies()->attach($company->id, ['role' => $role]);

    return [$company, $user];
}

// ─── Autenticação ─────────────────────────────────────────────────────────────

test('visitante não autenticado é redirecionado ao acessar balance', function () {
    makeFinancialCompany();

    $this->get(route('api.company.balance'))
        ->assertRedirect(route('login'));
});

// ─── Controle de Acesso por Papel ─────────────────────────────────────────────

test('branch_manager recebe 403 ao acessar balance', function () {
    [, $user] = makeFinancialUserWithRole('branch_manager');

    $this->actingAs($user)
        ->getJson(route('api.company.balance'))
        ->assertForbidden();
});

test('company_admin acessa balance com sucesso', function () {
    [$company, $user] = makeFinancialUserWithRole('company_admin');

    $this->mock(BalanceService::class)
        ->shouldReceive('calculateBalance')
        ->once()
        ->andReturn([
            'total_balance' => 500.00,
            'available_balance' => 450.00,
            'blocked_balance' => 50.00,
        ]);

    $this->actingAs($user)
        ->getJson(route('api.company.balance'))
        ->assertOk()
        ->assertJsonPath('data.total_balance', 500);
});

test('company_admin acessa forecast com sucesso', function () {
    [, $user] = makeFinancialUserWithRole('company_admin');

    $this->mock(BalanceService::class)
        ->shouldReceive('getFinancialForecast')
        ->once()
        ->andReturn([]);

    $this->actingAs($user)
        ->getJson(route('api.company.balance.forecast'))
        ->assertOk()
        ->assertJsonPath('data', []);
});

// ─── Saque/antecipação desativados ─────────────────────────────────────────────
// Saldo/saque/antecipação migrou pro portal Vindi (commit a9ba8d2); o endpoint de
// saque local nunca completava a transferência real (ficava travado em
// "processing" pra sempre, sem tela de operador pra resolver). Ambos os
// endpoints foram removidos — as rotas não devem mais existir.

test('rota de saque não existe mais', function () {
    expect(fn () => route('api.company.withdraw'))->toThrow(Exception::class);
});

test('rota de antecipação não existe mais', function () {
    expect(fn () => route('api.company.anticipate'))->toThrow(Exception::class);
});
