<?php

use App\Models\Company;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createCompany(string $suffix = ''): Company
{
    return Company::create([
        'name'         => "Empresa {$suffix}",
        'slug'         => "empresa-{$suffix}-" . uniqid(),
        'order_prefix' => 'TST',
        'active'       => true,
    ]);
}

function createCustomerFor(Company $company, string $phone, string $emailSuffix = ''): Customer
{
    return Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name'       => 'Cliente',
        'phone'      => $phone,
        'email'      => "cliente{$emailSuffix}@teste.com",
    ]);
}

// ─── Testes ───────────────────────────────────────────────────────────────────

test('findByPhone retorna cliente da empresa correta', function () {
    $companyA = createCompany('A');
    $companyB = createCompany('B');

    $customerA = createCustomerFor($companyA, '11999990001', 'a');
    $customerB = createCustomerFor($companyB, '11999990001', 'b'); // mesmo telefone, empresa diferente

    // Busca no contexto da empresa A
    app()->instance('current.company', $companyA);
    $found = Customer::findByPhone('11999990001');

    expect($found)->not->toBeNull();
    expect($found->id)->toBe($customerA->id);
    expect($found->company_id)->toBe($companyA->id);
});

test('findByPhone não retorna cliente de outra empresa', function () {
    $companyA = createCompany('A');
    $companyB = createCompany('B');

    createCustomerFor($companyA, '11999990002', 'a2');

    // Busca no contexto da empresa B (onde o cliente NÃO existe)
    app()->instance('current.company', $companyB);
    $found = Customer::findByPhone('11999990002');

    expect($found)->toBeNull();
});

test('findByPhone retorna null quando telefone não existe', function () {
    $company = createCompany('X');
    app()->instance('current.company', $company);

    $found = Customer::findByPhone('11000000000');

    expect($found)->toBeNull();
});

test('company scope impede acesso a clientes de outra empresa via query normal', function () {
    $companyA = createCompany('A');
    $companyB = createCompany('B');

    createCustomerFor($companyA, '11999990003', 'scope-a');

    // Ativa o scope da empresa B
    app()->instance('current.company', $companyB);

    // Cliente da empresa A não deve aparecer no contexto da empresa B
    $count = Customer::where('phone', '11999990003')->count();

    expect($count)->toBe(0);
});

test('dois clientes de empresas diferentes podem ter o mesmo telefone', function () {
    $companyA = createCompany('A');
    $companyB = createCompany('B');

    createCustomerFor($companyA, '11999990004', 'dup-a');
    createCustomerFor($companyB, '11999990004', 'dup-b');

    // Sem escopo de empresa, ambos devem existir no banco
    $total = Customer::withoutGlobalScopes()->where('phone', '11999990004')->count();

    expect($total)->toBe(2);
});
