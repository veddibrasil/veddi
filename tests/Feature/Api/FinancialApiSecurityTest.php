<?php

use App\Models\Company;
use App\Models\User;
use App\Services\BalanceService;
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

test('visitante não autenticado é redirecionado ao tentar saque', function () {
    makeFinancialCompany();

    $this->postJson(route('api.company.withdraw'), [
        'amount' => 100,
        'payout_type' => 'PIX',
        'pix_key' => 'chave@pix.com',
        'pix_key_type' => 'EMAIL',
    ])
        ->assertUnauthorized();
});

// ─── Controle de Acesso por Papel ─────────────────────────────────────────────

test('branch_manager recebe 403 ao acessar balance', function () {
    [, $user] = makeFinancialUserWithRole('branch_manager');

    $this->actingAs($user)
        ->getJson(route('api.company.balance'))
        ->assertForbidden();
});

test('branch_manager recebe 403 ao tentar saque', function () {
    [, $user] = makeFinancialUserWithRole('branch_manager');

    $this->actingAs($user)
        ->postJson(route('api.company.withdraw'), [
            'amount' => 100,
            'payout_type' => 'PIX',
            'pix_key' => 'chave@pix.com',
            'pix_key_type' => 'EMAIL',
        ])
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

// ─── Validação de Saque ───────────────────────────────────────────────────────

test('saque rejeita amount negativo', function () {
    [, $user] = makeFinancialUserWithRole('company_admin');

    $this->actingAs($user)
        ->postJson(route('api.company.withdraw'), [
            'amount' => -50,
            'payout_type' => 'PIX',
            'pix_key' => 'chave@pix.com',
            'pix_key_type' => 'EMAIL',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['amount']);
});

test('saque rejeita amount zero', function () {
    [, $user] = makeFinancialUserWithRole('company_admin');

    $this->actingAs($user)
        ->postJson(route('api.company.withdraw'), [
            'amount' => 0,
            'payout_type' => 'PIX',
            'pix_key' => 'chave@pix.com',
            'pix_key_type' => 'EMAIL',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['amount']);
});

test('saque PIX rejeita quando pix_key está ausente', function () {
    [, $user] = makeFinancialUserWithRole('company_admin');

    $this->actingAs($user)
        ->postJson(route('api.company.withdraw'), [
            'amount' => 100,
            'payout_type' => 'PIX',
            // pix_key ausente
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['pix_key']);
});

test('saque TED rejeita quando bank_code está ausente', function () {
    [, $user] = makeFinancialUserWithRole('company_admin');

    $this->actingAs($user)
        ->postJson(route('api.company.withdraw'), [
            'amount' => 100,
            'payout_type' => 'TED',
            // bank_code ausente
            'bank_agency' => '0001',
            'bank_account' => '12345',
            'bank_account_type' => 'CHECKING',
            'bank_owner_cpf_cnpj' => '529.982.247-25',
            'bank_owner_name' => 'João Silva',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['bank_code']);
});

test('saque TED rejeita quando bank_owner_cpf_cnpj está ausente', function () {
    [, $user] = makeFinancialUserWithRole('company_admin');

    $this->actingAs($user)
        ->postJson(route('api.company.withdraw'), [
            'amount' => 100,
            'payout_type' => 'TED',
            'bank_code' => '001',
            'bank_agency' => '0001',
            'bank_account' => '12345',
            'bank_account_type' => 'CHECKING',
            'bank_owner_name' => 'João Silva',
            // bank_owner_cpf_cnpj ausente
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['bank_owner_cpf_cnpj']);
});

test('saque rejeita payout_type inválido', function () {
    [, $user] = makeFinancialUserWithRole('company_admin');

    $this->actingAs($user)
        ->postJson(route('api.company.withdraw'), [
            'amount' => 100,
            'payout_type' => 'BOLETO', // inválido
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['payout_type']);
});

// ─── Validação de Antecipação ─────────────────────────────────────────────────

test('antecipação rejeita transaction_ids vazio', function () {
    [, $user] = makeFinancialUserWithRole('company_admin');

    $this->actingAs($user)
        ->postJson(route('api.company.anticipate'), [
            'transaction_ids' => [],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['transaction_ids']);
});

test('antecipação rejeita transaction_ids ausente', function () {
    [, $user] = makeFinancialUserWithRole('company_admin');

    $this->actingAs($user)
        ->postJson(route('api.company.anticipate'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['transaction_ids']);
});

test('antecipação rejeita id não inteiro em transaction_ids', function () {
    [, $user] = makeFinancialUserWithRole('company_admin');

    $this->actingAs($user)
        ->postJson(route('api.company.anticipate'), [
            'transaction_ids' => ['abc', 'xyz'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['transaction_ids.0']);
});
