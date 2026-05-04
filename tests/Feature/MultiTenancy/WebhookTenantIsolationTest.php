<?php

use App\Jobs\ProcessAsaasWebhook;
use App\Jobs\ProcessStarkWebhook;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyWalletEntry;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Company\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ─── Helpers ─────────────────────────────────────────────────────────────────

function makeTenantContext(string $slug, string $prefix): array
{
    $company = Company::create([
        'name' => "Empresa {$prefix}",
        'slug' => $slug,
        'order_prefix' => $prefix,
        'active' => true,
        'status' => 'ACTIVE',
    ]);

    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial',
        'address' => 'Rua',
        'city' => 'SP',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Cliente',
        'phone' => '11999990000',
    ]);

    app()->instance('current.company', $company);

    $order = Order::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'customer_id' => $customer->id,
        'subtotal' => 40.00,
        'delivery_fee' => 0,
        'total' => 40.00,
        'fee' => 1.50,
        'net_value' => 38.50,
        'status' => 'awaiting_payment',
        'payment_method' => 'PIX',
        'order_type' => 'delivery',
    ]);

    return compact('company', 'order');
}

// ─── Testes ───────────────────────────────────────────────────────────────────

test('asaas: webhook credita apenas a empresa correta, não vaza para outra', function () {
    ['company' => $companyA, 'order' => $orderA] = makeTenantContext('empresa-a', 'AAA');

    $paymentA = Payment::create([
        'order_id' => $orderA->id,
        'asaas_payment_id' => 'pay_A_001',
        'payment_gateway' => 'asaas',
        'amount' => 40.00,
        'original_amount' => 40.00,
        'status' => 'pending',
        'payment_token' => 'tok_a',
    ]);

    ['company' => $companyB, 'order' => $orderB] = makeTenantContext('empresa-b', 'BBB');

    Payment::create([
        'order_id' => $orderB->id,
        'asaas_payment_id' => 'pay_B_001',
        'payment_gateway' => 'asaas',
        'amount' => 40.00,
        'original_amount' => 40.00,
        'status' => 'pending',
        'payment_token' => 'tok_b',
    ]);

    // Jobs rodam sem current.company (fila não tem contexto HTTP)
    app()->forgetInstance('current.company');

    // Dispara webhook apenas para empresa A
    (new ProcessAsaasWebhook('PAYMENT_CONFIRMED', [
        'payment' => [
            'id' => $paymentA->asaas_payment_id,
            'externalReference' => (string) $orderA->id,
            'status' => 'CONFIRMED',
        ],
    ]))->handle(app(CompanyService::class));

    // Empresa A: deve ter entrada de crédito
    expect(CompanyWalletEntry::where('company_id', $companyA->id)->where('order_id', $orderA->id)->exists())->toBeTrue();

    // Empresa B: não deve ter nenhuma entrada
    expect(CompanyWalletEntry::where('company_id', $companyB->id)->count())->toBe(0);
});

test('stark: webhook credita apenas a empresa correta, não vaza para outra', function () {
    ['company' => $companyA, 'order' => $orderA] = makeTenantContext('empresa-c', 'CCC');

    $paymentA = Payment::create([
        'order_id' => $orderA->id,
        'stark_payment_id' => 'inv_C_001',
        'payment_gateway' => 'stark',
        'amount' => 40.00,
        'original_amount' => 40.00,
        'status' => 'pending',
        'payment_token' => 'tok_c',
    ]);

    ['company' => $companyB, 'order' => $orderB] = makeTenantContext('empresa-d', 'DDD');

    Payment::create([
        'order_id' => $orderB->id,
        'stark_payment_id' => 'inv_D_001',
        'payment_gateway' => 'stark',
        'amount' => 40.00,
        'original_amount' => 40.00,
        'status' => 'pending',
        'payment_token' => 'tok_d',
    ]);

    // Jobs rodam sem current.company (fila não tem contexto HTTP)
    app()->forgetInstance('current.company');

    // Dispara webhook apenas para empresa C (ordem A)
    (new ProcessStarkWebhook('credited', [
        'event' => [
            'log' => [
                'invoice' => ['id' => $paymentA->stark_payment_id],
            ],
        ],
    ]))->handle();

    expect(CompanyWalletEntry::where('company_id', $companyA->id)->where('order_id', $orderA->id)->exists())->toBeTrue();
    expect(CompanyWalletEntry::where('company_id', $companyB->id)->count())->toBe(0);
});
