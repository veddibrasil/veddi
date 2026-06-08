<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Payment\PaymentOrchestrator;
use App\Services\Payment\VindiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function vindiPixContext(): array
{
    $company = Company::create([
        'name' => 'Empresa Vindi PIX',
        'slug' => 'empresa-vindi-pix',
        'order_prefix' => 'VPX',
        'active' => true,
        'pix_fee_absorbed_by_company' => false,
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
        'name' => 'Cliente Teste',
        'phone' => '11999990001',
        'email' => 'cliente@teste.com',
        'tax_id' => '123.456.789-09',
    ]);

    $order = Order::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'customer_id' => $customer->id,
        'subtotal' => 50.00,
        'total' => 50.00,
        'delivery_fee' => 0,
        'discount' => 0,
        'fee' => 0,
        'net_value' => 0,
        'status' => 'pending',
        'payment_method' => 'pix',
        'order_type' => 'delivery',
        'order_number' => 'VPX-2026-00001',
    ]);

    return compact('company', 'branch', 'customer', 'order');
}

test('VindiService.createPixCharge retorna transaction_token e pix_copy_paste', function () {
    config()->set('payments.vindi_token_account', 'tok_test');
    config()->set('payments.vindi_reseller_token', 'res_test');

    Http::fake([
        '*/transactions/payment' => Http::response([
            'transaction' => [
                'token' => 'vindi_pix_token_123',
                'status_name' => 'Aguardando Pagamento',
                'pix_qr_code' => 'data:image/png;base64,abc',
                'pix_copy_paste' => '00020126580014br.gov.bcb.pix0136abc',
            ],
        ], 200),
    ]);

    $ctx = vindiPixContext();
    $service = app(VindiService::class);

    $result = $service->createPixCharge(
        amount: 50.50,
        externalRef: (string) $ctx['order']->id,
        customer: $ctx['customer'],
    );

    expect($result['transaction_token'])->toBe('vindi_pix_token_123')
        ->and($result['pix_copy_paste'])->toBe('00020126580014br.gov.bcb.pix0136abc')
        ->and($result['pix_qr_code'])->toBe('data:image/png;base64,abc');
});

test('PaymentOrchestrator.processPix cria Payment com vindi_transaction_token', function () {
    config()->set('payments.vindi_token_account', 'tok_test');
    config()->set('payments.vindi_reseller_token', 'res_test');

    Http::fake([
        '*/transactions/payment' => Http::response([
            'transaction' => [
                'token' => 'vindi_pix_token_456',
                'status_name' => 'Aguardando Pagamento',
                'pix_qr_code' => null,
                'pix_copy_paste' => '00020126abc',
            ],
        ], 200),
    ]);

    $ctx = vindiPixContext();

    $orchestrator = app(PaymentOrchestrator::class);
    $result = $orchestrator->processPix($ctx['order'], $ctx['customer'], $ctx['company']);

    expect($result['gateway'])->toBe('vindi')
        ->and($result['method'])->toBe('pix');

    $payment = Payment::where('order_id', $ctx['order']->id)->first();

    expect($payment)->not->toBeNull()
        ->and($payment->vindi_transaction_token)->toBe('vindi_pix_token_456')
        ->and($payment->payment_gateway)->toBe('vindi')
        ->and($payment->status)->toBe('pending')
        ->and((float) $payment->amount)->toBe(50.0)  // order->total sem acréscimo de taxa
        ->and((float) $payment->pix_fee)->toBe(0.0); // taxa absorvida no split percentual
});

test('PIX simulado criado quando sem credenciais Vindi', function () {
    config()->set('payments.vindi_token_account', null);
    config()->set('payments.vindi_pix_rate', 0.0085);
    config()->set('payments.vindi_pix_fee_min', 1.60);

    $ctx = vindiPixContext();

    $orchestrator = app(PaymentOrchestrator::class);
    $result = $orchestrator->processPix($ctx['order'], $ctx['customer'], $ctx['company']);

    expect($result['gateway'])->toBe('vindi');

    $payment = Payment::where('order_id', $ctx['order']->id)->first();
    expect($payment)->not->toBeNull()
        ->and($payment->vindi_transaction_token)->toStartWith('sim_vindi_')
        ->and($payment->status)->toBe('pending');
});
