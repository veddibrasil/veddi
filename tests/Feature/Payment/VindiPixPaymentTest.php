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
        'address' => 'Rua A',
        'number' => '1',
        'neighborhood' => 'Centro',
        'city' => 'São Paulo',
        'state' => 'SP',
        'cep' => '01310-100',
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
        'delivery_address' => 'Rua A',
        'delivery_number' => '1',
        'delivery_neighborhood' => 'Centro',
        'delivery_city' => 'São Paulo',
        'delivery_cep' => '01310-100',
        'order_number' => 'VPX-2026-00001',
    ]);

    return compact('company', 'branch', 'customer', 'order');
}

test('VindiService.createPixCharge retorna transaction_token e pix_copy_paste', function () {
    config()->set('payments.vindi_token_account', 'tok_test');
    config()->set('payments.vindi_reseller_token', 'res_test');

    Http::fake([
        '*/transactions/payment' => Http::response([
            'data_response' => [
                'transaction' => [
                    'token_transaction' => 'vindi_pix_token_123',
                    'status_name' => 'Aguardando Pagamento',
                    'payment' => [
                        'qrcode_path' => 'data:image/png;base64,abc',
                        'qrcode_original_path' => '00020126580014br.gov.bcb.pix0136abc',
                    ],
                ],
            ],
            'message_response' => ['message' => 'success'],
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

test('VindiService envia contato móvel e person_addresses para PIX', function () {
    config()->set('payments.vindi_token_account', 'tok_test');
    config()->set('payments.vindi_reseller_token', 'res_test');

    $capturedPayload = null;
    $capturedBody = null;

    Http::fake([
        '*/transactions/payment' => function ($request) use (&$capturedPayload, &$capturedBody) {
            $capturedPayload = $request->data();
            $capturedBody = $request->body();

            return Http::response([
                'data_response' => [
                    'transaction' => [
                        'token_transaction' => 'vindi_pix_token_payload',
                        'status_name' => 'Aguardando Pagamento',
                        'payment' => [
                            'qrcode_original_path' => '00020126abc',
                            'qrcode_path' => null,
                        ],
                    ],
                ],
                'message_response' => ['message' => 'success'],
            ], 200);
        },
    ]);

    $ctx = vindiPixContext();

    app(VindiService::class)->createPixCharge(
        amount: 50.50,
        externalRef: (string) $ctx['order']->id,
        customer: $ctx['customer'],
        address: [
            'street' => $ctx['order']->delivery_address,
            'number' => $ctx['order']->delivery_number,
            'neighborhood' => $ctx['order']->delivery_neighborhood,
            'city' => $ctx['order']->delivery_city,
            'state' => $ctx['customer']->state,
            'postal_code' => $ctx['order']->delivery_cep,
        ],
    );

    assert(is_array($capturedPayload));

    expect($capturedPayload['customer']['contacts'][0])->toMatchArray([
        'type_contact' => 'M',
        'number_contact' => '11999990001',
    ]);

    expect($capturedPayload['customer']['addresses'][0])->toMatchArray([
        'type_address' => 'D',
        'street' => 'Rua A',
        'city' => 'São Paulo',
        'state' => 'São Paulo',
    ]);

    expect($capturedPayload)->toHaveKey('transaction_product');
    expect($capturedPayload['transaction_product'])->toHaveCount(1);
    expect($capturedPayload['transaction_product'][0])->toMatchArray([
        'quantity' => '1',
        'price_unit' => '50.50',
    ]);

    expect($capturedBody)->toContain('"number_contact":"11999990001"')
        ->and($capturedBody)->toContain('"addresses"');
});

test('VindiService envia telefone fixo como tipo H e person_addresses no PIX', function () {
    config()->set('payments.vindi_token_account', 'tok_test');
    config()->set('payments.vindi_reseller_token', 'res_test');

    $capturedPayload = null;

    Http::fake([
        '*/transactions/payment' => function ($request) use (&$capturedPayload) {
            $capturedPayload = $request->data();

            return Http::response([
                'data_response' => [
                    'transaction' => [
                        'token_transaction' => 'vindi_pix_token_pickup',
                        'status_name' => 'Aguardando Pagamento',
                        'payment' => [
                            'qrcode_original_path' => '00020126pickup',
                            'qrcode_path' => null,
                        ],
                    ],
                ],
                'message_response' => ['message' => 'success'],
            ], 200);
        },
    ]);

    $ctx = vindiPixContext();
    $ctx['customer']->update([
        'phone' => '4733540074',
        'state' => null,
        'cep' => '88360-000',
    ]);

    app(VindiService::class)->createPixCharge(
        amount: 50.50,
        externalRef: (string) $ctx['order']->id,
        customer: $ctx['customer']->fresh(),
    );

    assert(is_array($capturedPayload));

    expect($capturedPayload['customer']['contacts'][0])->toMatchArray([
        'type_contact' => 'H',
        'number_contact' => '4733540074',
    ]);

    expect($capturedPayload['customer'])->toHaveKey('addresses');
    expect($capturedPayload['customer']['addresses'][0]['type_address'])->toBe('D');
});

test('PaymentOrchestrator.processPix cria Payment com vindi_transaction_token', function () {
    config()->set('payments.vindi_token_account', 'tok_test');
    config()->set('payments.vindi_reseller_token', 'res_test');

    Http::fake([
        '*/transactions/payment' => Http::response([
            'data_response' => [
                'transaction' => [
                    'token_transaction' => 'vindi_pix_token_456',
                    'status_name' => 'Aguardando Pagamento',
                    'payment' => [
                        'qrcode_original_path' => '00020126abc',
                        'qrcode_path' => null,
                    ],
                ],
            ],
            'message_response' => ['message' => 'success'],
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

test('PaymentOrchestrator.processPix envia somente afiliado da empresa com taxa da plataforma descontada em plano pago', function () {
    config()->set('payments.vindi_token_account', 'tok_test');
    config()->set('payments.vindi_reseller_token', 'res_test');
    config()->set('payments.vindi_pix_platform_rate', 0.0014);

    $capturedPayload = null;

    Http::fake([
        '*/transactions/payment' => function ($request) use (&$capturedPayload) {
            $capturedPayload = $request->data();

            return Http::response([
                'data_response' => [
                    'transaction' => [
                        'token_transaction' => 'vindi_pix_paid_plan_token',
                        'status_name' => 'Aguardando Pagamento',
                        'payment' => [
                            'qrcode_original_path' => '00020126abc',
                            'qrcode_path' => null,
                        ],
                    ],
                ],
                'message_response' => ['message' => 'success'],
            ], 200);
        },
    ]);

    $ctx = vindiPixContext();
    $ctx['company']->update([
        'email' => 'empresa@test.com',
        'plan' => 'pro',
    ]);

    app(PaymentOrchestrator::class)->processPix(
        $ctx['order'],
        $ctx['customer'],
        $ctx['company']->fresh(),
    );

    assert(is_array($capturedPayload));
    expect($capturedPayload['affiliates'])->toHaveCount(1);

    expect($capturedPayload['affiliates'][0]['account_email'])->toBe('empresa@test.com')
        ->and($capturedPayload['affiliates'][0]['commission_amount'])->toBe('49.51');
});

test('PaymentOrchestrator.processPix adiciona 1 por cento para conta master quando plano free', function () {
    config()->set('payments.vindi_token_account', 'tok_test');
    config()->set('payments.vindi_reseller_token', 'res_test');
    config()->set('payments.vindi_pix_rate', 0.0085);
    config()->set('payments.vindi_pix_platform_rate', 0.0014);

    $capturedPayload = null;

    Http::fake([
        '*/transactions/payment' => function ($request) use (&$capturedPayload) {
            $capturedPayload = $request->data();

            return Http::response([
                'data_response' => [
                    'transaction' => [
                        'token_transaction' => 'vindi_pix_free_plan_token',
                        'status_name' => 'Aguardando Pagamento',
                        'payment' => [
                            'qrcode_original_path' => '00020126abc',
                            'qrcode_path' => null,
                        ],
                    ],
                ],
                'message_response' => ['message' => 'success'],
            ], 200);
        },
    ]);

    $ctx = vindiPixContext();
    $ctx['company']->update([
        'email' => 'empresa-free@test.com',
        'plan' => 'free',
    ]);

    app(PaymentOrchestrator::class)->processPix(
        $ctx['order'],
        $ctx['customer'],
        $ctx['company']->fresh(),
    );

    assert(is_array($capturedPayload));
    expect($capturedPayload['affiliates'])->toHaveCount(1);

    expect($capturedPayload['affiliates'][0]['account_email'])->toBe('empresa-free@test.com')
        ->and($capturedPayload['affiliates'][0]['commission_amount'])->toBe('49.01');
});

test('PIX simulado criado quando sem credenciais Vindi', function () {
    config()->set('payments.vindi_token_account', null);
    config()->set('payments.vindi_pix_rate', 0.0085);

    $ctx = vindiPixContext();

    $orchestrator = app(PaymentOrchestrator::class);
    $result = $orchestrator->processPix($ctx['order'], $ctx['customer'], $ctx['company']);

    expect($result['gateway'])->toBe('vindi');

    $payment = Payment::where('order_id', $ctx['order']->id)->first();
    expect($payment)->not->toBeNull()
        ->and($payment->vindi_transaction_token)->toStartWith('sim_vindi_')
        ->and($payment->status)->toBe('pending');
});

test('PIX com frete: shipping_price enviado separado e comissao sobre subtotal', function () {
    config()->set('payments.vindi_token_account', 'tok_test');
    config()->set('payments.vindi_reseller_token', 'res_test');
    config()->set('payments.vindi_pix_rate', 0.0085);
    config()->set('payments.vindi_pix_platform_rate', 0.0014);

    $capturedPayload = null;

    Http::fake([
        '*/transactions/payment' => function ($request) use (&$capturedPayload) {
            $capturedPayload = $request->data();

            return Http::response([
                'data_response' => [
                    'transaction' => [
                        'token_transaction' => 'vindi_pix_frete_token',
                        'status_name' => 'Aguardando Pagamento',
                        'payment' => ['qrcode_original_path' => '00020126abc', 'qrcode_path' => null],
                    ],
                ],
                'message_response' => ['message' => 'success'],
            ], 200);
        },
    ]);

    $ctx = vindiPixContext();
    $ctx['company']->update(['email' => 'empresa-frete@test.com', 'plan' => 'free']);

    $order = $ctx['order'];
    $order->update(['subtotal' => 40.00, 'delivery_fee' => 10.00, 'total' => 50.00]);

    app(PaymentOrchestrator::class)->processPix($order->fresh(), $ctx['customer'], $ctx['company']->fresh());

    assert(is_array($capturedPayload));

    // Frete separado: shipping_price=10, product=40 (nao o total 50)
    expect($capturedPayload['transaction']['shipping_price'])->toBe('10.00')
        ->and($capturedPayload['transaction']['shipping_type'])->toBe('Entrega')
        ->and($capturedPayload['transaction_product'][0]['price_unit'])->toBe('40.00');

    // Comissao sobre subtotal=40 (nao total=50): commissionAmount=0.40
    // affiliateAmount = 50*(1-0.0099) - 0.40 = 49.505 - 0.40 = 49.105
    // affiliatePercentual = 98.21 → Vindi commission_amount = 49.11
    expect($capturedPayload['affiliates'])->toHaveCount(1)
        ->and($capturedPayload['affiliates'][0]['commission_amount'])->toBe('49.11');
});
