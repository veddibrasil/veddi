<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Services\Fiscal\FiscalEmissionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function recipientComplementContext(): array
{
    $company = Company::create([
        'name' => 'Empresa Complemento', 'slug' => 'empresa-complemento-'.uniqid(), 'order_prefix' => 'CPL',
        'active' => true, 'status' => 'ACTIVE', 'owner_cpf_cnpj' => '12345678000100', 'fiscal_notes_enabled' => true,
    ]);
    app()->instance('current.company', $company);
    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'name' => 'Filial Complemento', 'active' => true,
        'opens_at' => '00:00:00', 'closes_at' => '23:59:59',
    ]);
    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'name' => 'Maria da Silva', 'phone' => '41999990000', 'tax_id' => '52998224725',
        'address' => 'Rua do Cadastro', 'number' => '42', 'neighborhood' => 'Centro', 'city' => 'Curitiba', 'state' => 'PR', 'cep' => '80010000',
    ]);

    return [$company, $branch, $customer];
}

function recipientComplementOrder(Company $company, Branch $branch, ?Customer $customer, array $recipient): Order
{
    return Order::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'branch_id' => $branch->id, 'customer_id' => $customer?->id,
        'order_number' => 'C'.uniqid(), 'status' => 'paid', 'channel' => 'chat', 'order_type' => 'delivery',
        'total' => 30, 'subtotal' => 30, 'fiscal_recipient' => $recipient,
    ]);
}

test('pedido com só o CPF herda o cadastro do cliente de mesmo documento', function () {
    [$company, $branch, $customer] = recipientComplementContext();
    $order = recipientComplementOrder($company, $branch, $customer, ['document' => '52998224725']);

    $recipient = FiscalEmissionPolicy::recipientFor($order->load('customer'), null);

    expect($recipient['document'])->toBe('52998224725')
        ->and($recipient['name'])->toBe('Maria da Silva')
        ->and($recipient['street'])->toBe('Rua do Cadastro')
        ->and($recipient['zip'])->toBe('80010000');
});

test('documento diferente do cliente não herda cadastro', function () {
    [$company, $branch, $customer] = recipientComplementContext();
    $order = recipientComplementOrder($company, $branch, $customer, ['document' => '11444777000161']);

    $recipient = FiscalEmissionPolicy::recipientFor($order->load('customer'), null);

    expect($recipient)->toBe(['document' => '11444777000161']);
});

test('pedido com CPF sem dados e cliente cadastrado não pendura dados do destinatário na pré-checagem', function () {
    [$company, $branch, $customer] = recipientComplementContext();
    $order = recipientComplementOrder($company, $branch, $customer, ['document' => '52998224725']);

    ['issues' => $issues] = app(\App\Services\Fiscal\FiscalNoteService::class)->check($order->load(['customer', 'items']), null, null, 'nfe');

    expect(json_encode($issues, JSON_UNESCAPED_UNICODE))->not->toContain('nome completo')->not->toContain('logradouro')->not->toContain('CEP');
});
