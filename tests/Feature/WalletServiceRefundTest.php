<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyWalletEntry;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Finance\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('WalletService debitForRefund desfaz o crédito líquido do pedido', function () {
    config()->set('plans.free.fee_percentage', 0.02);
    config()->set('payments.pix_payment_fee', 0.50);

    $company = Company::create([
        'name' => 'Empresa Wallet',
        'slug' => 'empresa-wallet',
        'order_prefix' => 'WAL',
        'plan' => 'free',
        'pix_fee_absorbed_by_company' => true,
        'card_fee_absorbed_by_company' => true,
        'active' => true,
    ]);
    app()->instance('current.company', $company);

    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial Wallet',
        'address' => 'Rua C, 3',
        'city' => 'SP',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Cliente Wallet',
        'phone' => '11777770001',
    ]);

    $order = Order::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'customer_id' => $customer->id,
        'subtotal' => 100.00,
        'total' => 100.00,
        'delivery_fee' => 0,
        'discount' => 0,
        'fee' => 0,
        'net_value' => 0,
        'status' => 'paid',
        'payment_method' => 'pix',
        'order_type' => 'delivery',
        'order_number' => 'WAL-2026-00001',
    ]);

    $payment = Payment::withoutGlobalScopes()->create([
        'order_id' => $order->id,
        'asaas_payment_id' => 'pay_test_123',
        'payment_gateway' => 'asaas',
        'amount' => 100.00,
        'status' => 'paid',
        'paid_at' => now(),
    ]);

    app(WalletService::class)->creditForOrder($order, $payment);
    $balanceAfterCredit = CompanyWalletEntry::balanceFor($company->id);

    expect($balanceAfterCredit)->toBeGreaterThan(0);

    app(WalletService::class)->debitForRefund($order, $payment);
    $balanceAfterRefund = CompanyWalletEntry::balanceFor($company->id);

    expect($balanceAfterRefund)->toBe(0.0);

    // Idempotência: segunda chamada não deve alterar o saldo.
    app(WalletService::class)->debitForRefund($order, $payment);
    expect(CompanyWalletEntry::balanceFor($company->id))->toBe(0.0);
});

