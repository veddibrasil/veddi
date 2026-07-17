<?php

use App\Contracts\TransactionServiceInterface;
use App\Contracts\WalletServiceInterface;
use App\Jobs\ProcessVindiWebhook;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

function vindiWebhookContext(): array
{
    $company = Company::create([
        'name' => 'Empresa Webhook',
        'slug' => 'empresa-webhook',
        'order_prefix' => 'VWH',
        'active' => true,
    ]);

    app()->instance('current.company', $company);

    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial Webhook',
        'address' => 'Rua X, 1',
        'city' => 'SP',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Cliente Webhook',
        'phone' => '11900000001',
        'email' => 'wh@teste.com',
    ]);

    $order = Order::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'customer_id' => $customer->id,
        'subtotal' => 80.00,
        'total' => 80.00,
        'delivery_fee' => 0,
        'discount' => 0,
        'fee' => 0,
        'net_value' => 0,
        'status' => 'awaiting_payment',
        'payment_method' => 'pix',
        'order_type' => 'delivery',
        'order_number' => 'VWH-2026-00001',
    ]);

    $payment = Payment::create([
        'order_id' => $order->id,
        'vindi_transaction_token' => 'vindi_tok_wh_001',
        'payment_gateway' => 'vindi',
        'amount' => 80.50,
        'pix_fee' => 0.50,
        'status' => 'pending',
        'payment_token' => hash('sha256', 'test'),
        'expires_at' => now()->addMinutes(30),
    ]);

    return compact('company', 'branch', 'customer', 'order', 'payment');
}

test('webhook Vindi enfileira ProcessVindiWebhook', function () {
    Bus::fake();
    config()->set('payments.vindi_token_account', 'tok_test');

    $response = $this->postJson('/webhooks/vindi', [
        'token_transaction' => 'vindi_tok_wh_001',
        'transaction' => [
            'seller_token' => 'tok_test',
            'transaction_token' => 'vindi_tok_wh_001',
            'status_name' => 'Aprovada',
            'order_number' => '1',
        ],
    ]);

    $response->assertStatus(200)->assertJson(['status' => 'queued']);
    Bus::assertDispatched(ProcessVindiWebhook::class);
});

test('webhook Vindi rejeita token_account inválido', function () {
    config()->set('payments.vindi_token_account', 'tok_real');

    $response = $this->postJson('/webhooks/vindi', [
        'token_account' => 'tok_errado',
        'transaction' => ['token' => 'x', 'status_name' => 'Aprovada'],
    ]);

    $response->assertStatus(401);
});

test('ProcessVindiWebhook marca pagamento como pago e atualiza pedido', function () {
    $ctx = vindiWebhookContext();

    $wallet = Mockery::mock(WalletServiceInterface::class);
    $wallet->shouldReceive('creditForOrder')->once();
    app()->instance(WalletServiceInterface::class, $wallet);

    $transactions = Mockery::mock(TransactionServiceInterface::class);
    $transactions->shouldReceive('createForPayment')->once();
    app()->instance(TransactionServiceInterface::class, $transactions);

    $payload = [
        'token_account' => 'tok_test',
        'transaction' => [
            'token' => 'vindi_tok_wh_001',
            'status_name' => 'Aprovada',
            'order_number' => (string) $ctx['order']->id,
        ],
    ];

    $job = new ProcessVindiWebhook('vindi_tok_wh_001', 'Aprovada', $payload);
    $job->handle();

    $ctx['payment']->refresh();
    $ctx['order']->refresh();

    expect($ctx['payment']->status)->toBe('paid')
        ->and($ctx['order']->status)->toBe('paid');
});

test('ProcessVindiWebhook fallback por order_number namespaced (sem token local ainda) encontra o pedido', function () {
    $ctx = vindiWebhookContext();
    $ctx['payment']->update(['vindi_transaction_token' => null]);

    $wallet = Mockery::mock(WalletServiceInterface::class);
    $wallet->shouldReceive('creditForOrder')->once();
    app()->instance(WalletServiceInterface::class, $wallet);

    $transactions = Mockery::mock(TransactionServiceInterface::class);
    $transactions->shouldReceive('createForPayment')->once();
    app()->instance(TransactionServiceInterface::class, $transactions);

    // Formato "{namespace-do-ambiente}-{order_id}" — ver PaymentOrchestrator::vindiExternalRef.
    $payload = [
        'token_account' => 'tok_test',
        'transaction' => [
            'token' => 'vindi_tok_wh_002',
            'status_name' => 'Aprovada',
            'order_number' => 'a1b2c3-'.$ctx['order']->id,
        ],
    ];

    $job = new ProcessVindiWebhook('vindi_tok_wh_002', 'Aprovada', $payload);
    $job->handle();

    $ctx['payment']->refresh();
    $ctx['order']->refresh();

    expect($ctx['payment']->status)->toBe('paid')
        ->and($ctx['order']->status)->toBe('paid');
});

test('ProcessVindiWebhook idempotente — webhook duplicado não credita duas vezes', function () {
    $ctx = vindiWebhookContext();
    $ctx['payment']->update(['status' => 'paid', 'paid_at' => now()]);

    $wallet = Mockery::mock(WalletServiceInterface::class);
    $wallet->shouldNotReceive('creditForOrder');
    app()->instance(WalletServiceInterface::class, $wallet);

    $transactions = Mockery::mock(TransactionServiceInterface::class);
    $transactions->shouldNotReceive('createForPayment');
    app()->instance(TransactionServiceInterface::class, $transactions);

    $payload = [
        'transaction' => [
            'token' => 'vindi_tok_wh_001',
            'status_name' => 'Aprovada',
        ],
    ];

    $job = new ProcessVindiWebhook('vindi_tok_wh_001', 'Aprovada', $payload);
    $job->handle();

    // Assertions on mocks (shouldNotReceive) validate idempotency
    $ctx['payment']->refresh();
    expect($ctx['payment']->status)->toBe('paid');
});

test('ProcessVindiWebhook Cancelada marca pagamento como failed e pedido cancelado', function () {
    $ctx = vindiWebhookContext();

    $payload = [
        'transaction' => [
            'token' => 'vindi_tok_wh_001',
            'status_name' => 'Cancelada',
        ],
    ];

    $job = new ProcessVindiWebhook('vindi_tok_wh_001', 'Cancelada', $payload);
    $job->handle();

    $ctx['payment']->refresh();
    $ctx['order']->refresh();

    expect($ctx['payment']->status)->toBe('failed')
        ->and($ctx['order']->status)->toBe('cancelled');
});
