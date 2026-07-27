<?php

use App\Contracts\WalletServiceInterface;
use App\Jobs\ProcessRefund;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyNotification;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Services\Refund\RefundService;
use App\Services\Refund\VindiRefundGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function vindiRefundContext(string $paymentStatus = 'paid'): array
{
    $company = Company::create([
        'name' => 'Empresa Refund',
        'slug' => 'empresa-refund',
        'order_prefix' => 'VRF',
        'active' => true,
    ]);

    app()->instance('current.company', $company);

    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial',
        'address' => 'Rua A, 1',
        'city' => 'SP',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Cliente Refund',
        'phone' => '11900000099',
        'email' => 'refund@teste.com',
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
        'status' => $paymentStatus === 'paid' ? 'paid' : 'awaiting_payment',
        'payment_method' => 'pix',
        'order_type' => 'delivery',
        'order_number' => 'VRF-2026-00001',
    ]);

    $payment = Payment::create([
        'order_id' => $order->id,
        'vindi_transaction_id' => 555001,
        'vindi_transaction_token' => 'vindi_tok_refund_001',
        'payment_gateway' => 'vindi',
        'amount' => 80.00,
        'pix_fee' => 0,
        'status' => $paymentStatus,
        'paid_at' => $paymentStatus === 'paid' ? now() : null,
        'payment_token' => hash('sha256', 'refund-test'),
        'expires_at' => now()->addMinutes(30),
    ]);

    return compact('company', 'branch', 'customer', 'order', 'payment');
}

// ─── VindiRefundGateway ───────────────────────────────────────────────────────

test('VindiRefundGateway retorna succeeded quando Vindi confirma Cancelada sincronamente', function () {
    config()->set('payments.vindi_token_account', 'tok_test');
    config()->set('payments.vindi_reseller_token', 'res_test');

    Http::fake([
        '*/authorizations/access_token' => Http::response([
            'data_response' => ['authorization' => ['access_token' => 'test_access_token']],
        ], 200),
        '*/transactions/cancel' => Http::response([
            'message_response' => ['message' => 'success'],
            'data_response' => [
                'transaction' => [
                    'status_name' => 'Cancelada',
                    'status_id' => 6,
                    'transaction_token' => 'vindi_tok_refund_001',
                ],
            ],
        ], 200),
    ]);

    $ctx = vindiRefundContext();
    $gateway = app(VindiRefundGateway::class);

    $result = $gateway->requestRefund($ctx['payment'], 80.00);

    expect($result['status'])->toBe('succeeded')
        ->and($result['external_status'])->toBe('Cancelada')
        ->and($result['external_refund_id'])->toBe('vindi_tok_refund_001');
});

test('VindiRefundGateway retorna succeeded quando Vindi confirma Estornada sincronamente', function () {
    config()->set('payments.vindi_token_account', 'tok_test');
    config()->set('payments.vindi_reseller_token', 'res_test');

    Http::fake([
        '*/authorizations/access_token' => Http::response([
            'data_response' => ['authorization' => ['access_token' => 'test_access_token']],
        ], 200),
        '*/transactions/cancel' => Http::response([
            'message_response' => ['message' => 'success'],
            'data_response' => [
                'transaction' => [
                    'status_name' => 'Estornada',
                    'status_id' => 9,
                    'transaction_token' => 'vindi_tok_refund_001',
                ],
            ],
        ], 200),
    ]);

    $ctx = vindiRefundContext();
    $gateway = app(VindiRefundGateway::class);

    $result = $gateway->requestRefund($ctx['payment'], 80.00);

    expect($result['status'])->toBe('succeeded')
        ->and($result['external_status'])->toBe('Estornada');
});

test('VindiRefundGateway retorna in_progress quando Vindi processa de forma assincrona', function () {
    config()->set('payments.vindi_token_account', 'tok_test');
    config()->set('payments.vindi_reseller_token', 'res_test');

    Http::fake([
        '*/authorizations/access_token' => Http::response([
            'data_response' => ['authorization' => ['access_token' => 'test_access_token']],
        ], 200),
        '*/transactions/cancel' => Http::response([
            'message_response' => ['message' => 'success'],
            'data_response' => [
                'transaction' => [
                    'status_name' => 'Em Processamento',
                    'status_id' => 2,
                    'transaction_token' => 'vindi_tok_refund_001',
                ],
            ],
        ], 200),
    ]);

    $ctx = vindiRefundContext();
    $gateway = app(VindiRefundGateway::class);

    $result = $gateway->requestRefund($ctx['payment'], 80.00);

    expect($result['status'])->toBe('in_progress')
        ->and($result['external_status'])->toBe('Em Processamento');
});

test('VindiRefundGateway retorna failed quando Vindi rejeita a requisicao', function () {
    config()->set('payments.vindi_token_account', 'tok_test');
    config()->set('payments.vindi_reseller_token', 'res_test');

    Http::fake([
        '*/authorizations/access_token' => Http::response([
            'data_response' => ['authorization' => ['access_token' => 'test_access_token']],
        ], 200),
        '*/transactions/cancel' => Http::response([
            'message_response' => ['message' => 'error'],
            'error_response' => ['general_errors' => [['code' => '001', 'message' => 'Token inválido']]],
        ], 422),
    ]);

    $ctx = vindiRefundContext();
    $gateway = app(VindiRefundGateway::class);

    $result = $gateway->requestRefund($ctx['payment'], 80.00);

    expect($result['status'])->toBe('failed')
        ->and($result['external_refund_id'])->toBeNull();
});

test('VindiRefundGateway envia amount para suportar estorno parcial', function () {
    config()->set('payments.vindi_token_account', 'tok_test');
    config()->set('payments.vindi_reseller_token', 'res_test');

    Http::fake([
        '*/authorizations/access_token' => Http::response([
            'data_response' => ['authorization' => ['access_token' => 'test_access_token']],
        ], 200),
        '*/transactions/cancel' => Http::response([
            'message_response' => ['message' => 'success'],
            'data_response' => ['transaction' => ['status_name' => 'Cancelada', 'transaction_token' => 'tok']],
        ], 200),
    ]);

    $ctx = vindiRefundContext();
    $gateway = app(VindiRefundGateway::class);
    $gateway->requestRefund($ctx['payment'], 40.00);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/transactions/cancel')
            && $request->data()['refund_amount'] === '40.00';
    });
});

// ─── ProcessRefund job ────────────────────────────────────────────────────────

test('ProcessRefund chama markSucceeded imediatamente quando gateway retorna succeeded', function () {
    $ctx = vindiRefundContext();

    $wallet = Mockery::mock(WalletServiceInterface::class);
    $wallet->shouldReceive('debitForRefund')->once();
    app()->instance(WalletServiceInterface::class, $wallet);

    $refund = PaymentRefund::create([
        'company_id' => $ctx['company']->id,
        'order_id' => $ctx['order']->id,
        'payment_id' => $ctx['payment']->id,
        'gateway' => 'vindi',
        'amount' => 80.00,
        'status' => 'requested',
        'reason' => 'customer_request',
        'requested_by_type' => 'system',
        'requested_at' => now(),
    ]);

    config()->set('payments.vindi_token_account', 'tok_test');
    config()->set('payments.vindi_reseller_token', 'res_test');

    Http::fake([
        '*/authorizations/access_token' => Http::response([
            'data_response' => ['authorization' => ['access_token' => 'test_access_token']],
        ], 200),
        '*/transactions/cancel' => Http::response([
            'message_response' => ['message' => 'success'],
            'data_response' => [
                'transaction' => [
                    'status_name' => 'Cancelada',
                    'transaction_token' => 'vindi_tok_refund_001',
                ],
            ],
        ], 200),
    ]);

    $job = new ProcessRefund($refund);
    $job->handle(app(RefundService::class));

    $refund->refresh();
    $ctx['payment']->refresh();
    $ctx['order']->refresh();

    expect($refund->status)->toBe('succeeded')
        ->and($ctx['payment']->status)->toBe('refunded')
        ->and($ctx['order']->status)->toBe('refunded');
});

test('ProcessRefund aguarda webhook quando gateway retorna in_progress', function () {
    $ctx = vindiRefundContext();

    $refund = PaymentRefund::create([
        'company_id' => $ctx['company']->id,
        'order_id' => $ctx['order']->id,
        'payment_id' => $ctx['payment']->id,
        'gateway' => 'vindi',
        'amount' => 80.00,
        'status' => 'requested',
        'reason' => 'customer_request',
        'requested_by_type' => 'system',
        'requested_at' => now(),
    ]);

    config()->set('payments.vindi_token_account', 'tok_test');
    config()->set('payments.vindi_reseller_token', 'res_test');

    Http::fake([
        '*/authorizations/access_token' => Http::response([
            'data_response' => ['authorization' => ['access_token' => 'test_access_token']],
        ], 200),
        '*/transactions/cancel' => Http::response([
            'message_response' => ['message' => 'success'],
            'data_response' => [
                'transaction' => [
                    'status_name' => 'Em Processamento',
                    'transaction_token' => 'vindi_tok_refund_001',
                ],
            ],
        ], 200),
    ]);

    $job = new ProcessRefund($refund);
    $job->handle(app(RefundService::class));

    $refund->refresh();

    expect($refund->status)->toBe('in_progress')
        ->and($ctx['payment']->fresh()->status)->toBe('paid');
});

test('ProcessRefund ignora pagamento nao pago', function () {
    $ctx = vindiRefundContext('pending');

    $refund = PaymentRefund::create([
        'company_id' => $ctx['company']->id,
        'order_id' => $ctx['order']->id,
        'payment_id' => $ctx['payment']->id,
        'gateway' => 'vindi',
        'amount' => 80.00,
        'status' => 'requested',
        'reason' => 'customer_request',
        'requested_by_type' => 'system',
        'requested_at' => now(),
    ]);

    Http::fake(['*' => Http::response([], 200)]);

    $job = new ProcessRefund($refund);
    $job->handle(app(RefundService::class));

    $refund->refresh();
    expect($refund->status)->toBe('requested');
    Http::assertNothingSent();
});

test('ProcessRefund simula estorno de pagamento Vindi simulado sem chamar API', function () {
    $ctx = vindiRefundContext();
    $ctx['payment']->update(['vindi_transaction_token' => 'sim_vindi_abc123']);

    $wallet = Mockery::mock(WalletServiceInterface::class);
    $wallet->shouldReceive('debitForRefund')->once();
    app()->instance(WalletServiceInterface::class, $wallet);

    $refund = PaymentRefund::create([
        'company_id' => $ctx['company']->id,
        'order_id' => $ctx['order']->id,
        'payment_id' => $ctx['payment']->id,
        'gateway' => 'vindi',
        'amount' => 80.00,
        'status' => 'requested',
        'reason' => 'customer_request',
        'requested_by_type' => 'system',
        'requested_at' => now(),
    ]);

    Http::fake(['*' => Http::response([], 200)]);

    $job = new ProcessRefund($refund);
    $job->handle(app(RefundService::class));

    $refund->refresh();
    expect($refund->status)->toBe('succeeded')
        ->and($refund->external_refund_id)->toBe('sim_refund_'.$refund->id);
    Http::assertNothingSent();
});

test('ProcessRefund cria CompanyNotification quando gateway rejeita o estorno', function () {
    $ctx = vindiRefundContext();

    $refund = PaymentRefund::create([
        'company_id' => $ctx['company']->id,
        'order_id' => $ctx['order']->id,
        'payment_id' => $ctx['payment']->id,
        'gateway' => 'vindi',
        'amount' => 80.00,
        'status' => 'requested',
        'reason' => 'customer_request',
        'requested_by_type' => 'system',
        'requested_at' => now(),
    ]);

    config()->set('payments.vindi_token_account', 'tok_test');
    config()->set('payments.vindi_reseller_token', 'res_test');

    Http::fake([
        '*/authorizations/access_token' => Http::response([
            'data_response' => ['authorization' => ['access_token' => 'test_access_token']],
        ], 200),
        '*/transactions/cancel' => Http::response([
            'message_response' => ['message' => 'error'],
            'error_response' => ['general_errors' => [['code' => '001', 'message' => 'Token inválido']]],
        ], 422),
    ]);

    $job = new ProcessRefund($refund);
    $job->handle(app(RefundService::class));

    $refund->refresh();
    expect($refund->status)->toBe('failed');

    $notification = CompanyNotification::where('company_id', $ctx['company']->id)
        ->where('type', 'refund_failed')
        ->first();

    expect($notification)->not->toBeNull()
        ->and($notification->subtitle)->toContain('Token inválido')
        ->and($notification->link)->toBe(route('admin.orders.show', $ctx['order']->id));
});

test('ProcessRefund cria CompanyNotification em failed() quando refund ainda não foi resolvido', function () {
    $ctx = vindiRefundContext();

    $refund = PaymentRefund::create([
        'company_id' => $ctx['company']->id,
        'order_id' => $ctx['order']->id,
        'payment_id' => $ctx['payment']->id,
        'gateway' => 'vindi',
        'amount' => 80.00,
        'status' => 'in_progress',
        'reason' => 'customer_request',
        'requested_by_type' => 'system',
        'requested_at' => now(),
    ]);

    $job = new ProcessRefund($refund);
    $job->failed(new RuntimeException('Timeout ao conectar no gateway'));

    $notification = CompanyNotification::where('company_id', $ctx['company']->id)
        ->where('type', 'refund_failed')
        ->first();

    expect($notification)->not->toBeNull()
        ->and($notification->subtitle)->toBe('Timeout ao conectar no gateway');
});

test('ProcessRefund não cria CompanyNotification em failed() quando refund já foi concluído', function () {
    $ctx = vindiRefundContext();

    $refund = PaymentRefund::create([
        'company_id' => $ctx['company']->id,
        'order_id' => $ctx['order']->id,
        'payment_id' => $ctx['payment']->id,
        'gateway' => 'vindi',
        'amount' => 80.00,
        'status' => 'succeeded',
        'reason' => 'customer_request',
        'requested_by_type' => 'system',
        'requested_at' => now(),
        'processed_at' => now(),
    ]);

    $job = new ProcessRefund($refund);
    $job->failed(new RuntimeException('Timeout ao conectar no gateway'));

    expect(CompanyNotification::where('company_id', $ctx['company']->id)
        ->where('type', 'refund_failed')
        ->exists())->toBeFalse();
});
