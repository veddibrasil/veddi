<?php

use App\DTOs\CreditCardDTO;
use App\DTOs\CreditCardHolderDTO;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Payment\PaymentCalculatorService;
use App\Services\Payment\PaymentOrchestrator;
use App\Services\Payment\VindiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function vindiCardContext(): array
{
    $company = Company::create([
        'name' => 'Empresa Cartão',
        'slug' => 'empresa-cartao',
        'order_prefix' => 'VCD',
        'active' => true,
        'card_fee_absorbed_by_company' => false,
    ]);

    app()->instance('current.company', $company);

    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial Cartão',
        'address' => 'Rua B, 2',
        'city' => 'SP',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Cliente Cartão',
        'phone' => '11888880001',
        'email' => 'cartao@teste.com',
        'tax_id' => '987.654.321-00',
        'address' => 'Rua B',
        'number' => '2',
        'neighborhood' => 'Centro',
        'city' => 'São Paulo',
        'state' => 'SP',
        'cep' => '01310-100',
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
        'status' => 'pending',
        'payment_method' => 'credit_card',
        'order_type' => 'delivery',
        'delivery_address' => 'Rua B',
        'delivery_number' => '2',
        'delivery_neighborhood' => 'Centro',
        'delivery_city' => 'São Paulo',
        'delivery_cep' => '01310-100',
        'order_number' => 'VCD-2026-00001',
    ]);

    return compact('company', 'branch', 'customer', 'order');
}

test('VindiService.createCreditCardCharge retorna transaction_token', function () {
    config()->set('payments.vindi_token_account', 'tok_test');
    config()->set('payments.vindi_reseller_token', 'res_test');

    $capturedPayload = null;

    Http::fake([
        '*/transactions/payment' => function ($request) use (&$capturedPayload) {
            $capturedPayload = $request->data();

            return Http::response([
                'data_response' => [
                    'transaction' => [
                        'token_transaction' => 'vindi_card_token_789',
                        'status_name' => 'Em análise',
                        'payment' => [],
                    ],
                ],
                'message_response' => ['message' => 'success'],
            ], 200);
        },
    ]);

    $ctx = vindiCardContext();
    $service = app(VindiService::class);

    $result = $service->createCreditCardCharge(
        amount: 103.09,
        externalRef: (string) $ctx['order']->id,
        card: new CreditCardDTO(
            holderName: 'Cliente Cartão',
            number: '4111111111111111',
            expiryMonth: '12',
            expiryYear: '2027',
            ccv: '123',
        ),
        holder: new CreditCardHolderDTO(
            name: 'Cliente Cartão',
            email: 'cartao@teste.com',
            cpfCnpj: '98765432100',
            postalCode: '01310-100',
            addressNumber: '1',
            phone: '11888880001',
            street: 'Rua B',
            neighborhood: 'Centro',
            city: 'São Paulo',
            state: 'SP',
        ),
        installments: 1,
    );

    expect($result['transaction_token'])->toBe('vindi_card_token_789');

    assert(is_array($capturedPayload));
    expect($capturedPayload)->toHaveKey('transaction_product');
    expect($capturedPayload['transaction_product'])->toHaveCount(1);
    expect($capturedPayload['transaction_product'][0])->toMatchArray([
        'quantity' => '1',
        'price_unit' => '103.09',
    ]);
});

test('PaymentOrchestrator.processCreditCard cria Payment com vindi_transaction_token', function () {
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
                        'token_transaction' => 'vindi_card_token_abc',
                        'status_name' => 'Em análise',
                        'payment' => [],
                    ],
                ],
                'message_response' => ['message' => 'success'],
            ], 200);
        },
    ]);

    $ctx = vindiCardContext();
    $ctx['company']->update(['email' => 'empresa-card@test.com']);

    $cardData = [
        'holderName' => 'Cliente Cartão',
        'number' => '4111111111111111',
        'expiryMonth' => '12',
        'expiryYear' => '2027',
        'ccv' => '123',
        'cpfCnpj' => '98765432100',
        'postalCode' => '01310-100',
        'addressNumber' => '1',
    ];

    $orchestrator = app(PaymentOrchestrator::class);
    $result = $orchestrator->processCreditCard(
        $ctx['order'], $ctx['customer'], $ctx['company'], $cardData, 1
    );

    expect($result['gateway'])->toBe('vindi')
        ->and($result['method'])->toBe('credit_card');

    $payment = Payment::where('order_id', $ctx['order']->id)->first();

    expect($payment)->not->toBeNull()
        ->and($payment->vindi_transaction_token)->toBe('vindi_card_token_abc')
        ->and($payment->payment_gateway)->toBe('vindi')
        ->and($payment->status)->toBe('pending');

    assert(is_array($capturedPayload));

    // Visa = 1%; fee not absorbed → charge = round(100/0.99, 2) = 101.01
    // plano null = 0% veddi fee → netAfterCard = 100.00 → affiliate nets 100.00
    expect($capturedPayload['affiliates'])->toHaveCount(1)
        ->and($capturedPayload['affiliates'][0]['account_email'])->toBe('empresa-card@test.com')
        ->and($capturedPayload['affiliates'][0]['commission_amount'])->toBe('100.00');

    $payment = Payment::where('order_id', $ctx['order']->id)->first();
    expect((float) $payment->card_fee_rate)->toEqual(0.01)
        ->and(round((float) $payment->card_fee, 2))->toEqual(1.01)
        ->and(round((float) $payment->original_amount, 2))->toEqual(100.00);
});

test('PaymentOrchestrator.processCreditCard aplica taxa de 1% no split para plano free abaixo do limite', function () {
    config()->set('payments.vindi_token_account', 'tok_test');
    config()->set('payments.vindi_reseller_token', 'res_test');

    $capturedPayload = null;

    Http::fake([
        '*/transactions/payment' => function ($request) use (&$capturedPayload) {
            $capturedPayload = $request->data();

            return Http::response([
                'data_response' => [
                    'transaction' => [
                        'token_transaction' => 'vindi_card_free_plan_token',
                        'status_name' => 'Em análise',
                        'payment' => [],
                    ],
                ],
                'message_response' => ['message' => 'success'],
            ], 200);
        },
    ]);

    $ctx = vindiCardContext();
    $ctx['company']->update([
        'email' => 'empresa-card-free@test.com',
        'plan' => 'free',
    ]);

    $cardData = [
        'holderName' => 'Cliente Cartão',
        'number' => '4111111111111111',
        'expiryMonth' => '12',
        'expiryYear' => '2027',
        'ccv' => '123',
        'cpfCnpj' => '98765432100',
        'postalCode' => '01310-100',
        'addressNumber' => '1',
    ];

    app(PaymentOrchestrator::class)->processCreditCard(
        $ctx['order'], $ctx['customer'], $ctx['company']->fresh(), $cardData, 1
    );

    assert(is_array($capturedPayload));

    // Visa D+30 = 3.10%; fee not absorbed → charge = round(100/0.969, 2) = 103.20
    // free plan 1% → affiliate nets 99.00 (Veddi retém R$1 de R$100)
    expect($capturedPayload['affiliates'])->toHaveCount(1)
        ->and($capturedPayload['affiliates'][0]['account_email'])->toBe('empresa-card-free@test.com')
        ->and($capturedPayload['affiliates'][0]['commission_amount'])->toBe('99.00');
});

test('VindiService.createCreditCardCharge aceita token via additional_data quando API retorna erro de validação soft', function () {
    config()->set('payments.vindi_token_account', 'tok_test');
    config()->set('payments.vindi_reseller_token', 'res_test');

    Http::fake([
        '*/transactions/payment' => Http::response([
            'message_response' => ['message' => 'error'],
            'error_response' => [
                'validation_errors' => [
                    ['code' => '1', 'message' => 'não pode ficar em branco', 'field' => 'name_customer', 'message_complete' => 'Nome Impresso Cartão não pode ficar em branco'],
                ],
            ],
            'additional_data' => [
                'transaction_id' => 2126122,
                'order_number' => '108',
                'status_id' => 4,
                'status_name' => 'Aguardando Pagamento',
                'token_transaction' => 'vindi_soft_error_token',
            ],
        ], 422),
    ]);

    $ctx = vindiCardContext();
    $service = app(VindiService::class);

    $result = $service->createCreditCardCharge(
        amount: 100.00,
        externalRef: (string) $ctx['order']->id,
        card: new CreditCardDTO(
            holderName: 'Guilherme h jeske',
            number: '4111111111111111',
            expiryMonth: '12',
            expiryYear: '2030',
            ccv: '123',
        ),
        holder: new CreditCardHolderDTO(
            name: 'Guilherme Henrique Jeske',
            email: 'test@test.com',
            cpfCnpj: '11441385908',
            postalCode: '88360000',
            addressNumber: 'S/N',
        ),
        installments: 1,
    );

    expect($result['transaction_token'])->toBe('vindi_soft_error_token');
    expect($result['status_name'])->toBe('Aguardando Pagamento');
});

test('free plan acima do limite (≥50 pedidos): commission_amount reflete 3% — replica text.json', function () {
    // Replica o payload de text.json: Mastercard 5448280000000007, R$120, commission_amount=116.40
    config()->set('payments.vindi_token_account', 'tok_test');
    config()->set('payments.vindi_reseller_token', 'res_test');

    $capturedPayload = null;

    Http::fake([
        '*/transactions/payment' => function ($request) use (&$capturedPayload) {
            $capturedPayload = $request->data();

            return Http::response([
                'data_response' => [
                    'transaction' => [
                        'token_transaction' => 'vindi_over_limit_token',
                        'status_name' => 'Em análise',
                        'payment' => [],
                    ],
                ],
                'message_response' => ['message' => 'success'],
            ], 200);
        },
    ]);

    $ctx = vindiCardContext();
    $company = $ctx['company'];
    $company->update(['email' => 'anderson.mickaleski@vindi.com.br', 'plan' => 'free']);

    // Cria 50 pedidos confirmados no mês corrente para ativar a taxa de 3%
    for ($i = 0; $i < 50; $i++) {
        Order::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'branch_id' => $ctx['branch']->id,
            'customer_id' => $ctx['customer']->id,
            'subtotal' => 120.00,
            'total' => 120.00,
            'delivery_fee' => 0,
            'discount' => 0,
            'fee' => 0,
            'net_value' => 0,
            'status' => 'delivered',
            'payment_method' => 'credit_card',
            'order_type' => 'delivery',
            'delivery_address' => 'Rua B',
            'delivery_number' => '2',
            'delivery_neighborhood' => 'Centro',
            'delivery_city' => 'São Paulo',
            'delivery_cep' => '01310-100',
            'order_number' => 'VCD-2026-'.str_pad($i + 2, 5, '0', STR_PAD_LEFT),
            'created_at' => now(),
        ]);
    }

    $order = $ctx['order'];
    $order->update(['total' => 120.00, 'subtotal' => 120.00]);

    // Mastercard D+30 = 3.10%; fee not absorbed
    // chargeAmount = round(120 / 0.969, 2) = 123.84
    // cardFee = round(123.84 * 0.031, 2) = 3.84; netAfterCard = 120.00
    // planFeeRate = 3% (≥50 pedidos) → platformFee = 120 * 0.03 = 3.60 → commission = 116.40
    $cardData = [
        'holderName' => 'Guilherme h jeske',
        'number' => '5448280000000007',
        'expiryMonth' => '01',
        'expiryYear' => '2035',
        'ccv' => '123',
        'cpfCnpj' => '11441385908',
        'postalCode' => '88360-000',
        'addressNumber' => 'S/N',
    ];

    app(PaymentOrchestrator::class)->processCreditCard(
        $order, $ctx['customer'], $company->fresh(), $cardData, 1
    );

    expect($capturedPayload['affiliates'])->toHaveCount(1)
        ->and($capturedPayload['affiliates'][0]['account_email'])->toBe('anderson.mickaleski@vindi.com.br')
        ->and($capturedPayload['affiliates'][0]['commission_amount'])->toBe('116.40');
});

test('free plan com taxa absorvida pela empresa: commission_amount usa líquido após taxa do cartão (abaixo do limite → 1%)', function () {
    // Visa = 1%; fee absorbed → chargeAmount = 120
    // cardFee = round(120 * 0.01, 2) = 1.20; netAfterCard = 118.80
    // platformFee = round(118.80 * 0.01, 2) = 1.19; commission = 117.61
    config()->set('payments.vindi_token_account', 'tok_test');
    config()->set('payments.vindi_reseller_token', 'res_test');

    $capturedPayload = null;

    Http::fake([
        '*/transactions/payment' => function ($request) use (&$capturedPayload) {
            $capturedPayload = $request->data();

            return Http::response([
                'data_response' => [
                    'transaction' => [
                        'token_transaction' => 'vindi_absorbed_under_token',
                        'status_name' => 'Em análise',
                        'payment' => [],
                    ],
                ],
                'message_response' => ['message' => 'success'],
            ], 200);
        },
    ]);

    $ctx = vindiCardContext();
    $ctx['company']->update([
        'email' => 'empresa-absorbed@test.com',
        'plan' => 'free',
        'card_fee_absorbed_by_company' => true,
    ]);

    $order = $ctx['order'];
    $order->update(['total' => 120.00, 'subtotal' => 120.00]);

    app(PaymentOrchestrator::class)->processCreditCard(
        $order, $ctx['customer'], $ctx['company']->fresh(), [
            'holderName' => 'Cliente Teste',
            'number' => '4111111111111111', // Visa D+30 = 3.10%
            'expiryMonth' => '12',
            'expiryYear' => '2030',
            'ccv' => '123',
            'cpfCnpj' => '98765432100',
            'postalCode' => '01310-100',
            'addressNumber' => '1',
        ], 1
    );

    // cardFee = round(120 * 0.01, 2) = 1.20; netAfterCard = 118.80
    // platformFee = round(120 * 0.01, 2) = 1.20 (commission on subtotal, not netAfterCard)
    // targetCompanyNet = 118.80 - 1.20 = 117.60
    // affiliatePercentual = round(117.60 / 120 * 100, 4) = 98.0000
    // Vindi commission_amount = round(120 * 98.0000 / 100, 2) = 117.60
    expect($capturedPayload['affiliates'])->toHaveCount(1)
        ->and($capturedPayload['affiliates'][0]['account_email'])->toBe('empresa-absorbed@test.com')
        ->and($capturedPayload['affiliates'][0]['commission_amount'])->toBe('117.60');
});

test('free plan com taxa absorvida pela empresa: commission_amount usa líquido após taxa do cartão (acima do limite → 3%)', function () {
    // Visa = 1%; fee absorbed → chargeAmount = 120, free plan ≥ 50 pedidos → planFeeRate = 3%
    // cardFee = round(120 * 0.01, 2) = 1.20; netAfterCard = 118.80
    // platformFee = round(116.28 * 0.03, 2) = 3.49; commission = 112.79
    config()->set('payments.vindi_token_account', 'tok_test');
    config()->set('payments.vindi_reseller_token', 'res_test');

    $capturedPayload = null;

    Http::fake([
        '*/transactions/payment' => function ($request) use (&$capturedPayload) {
            $capturedPayload = $request->data();

            return Http::response([
                'data_response' => [
                    'transaction' => [
                        'token_transaction' => 'vindi_absorbed_over_token',
                        'status_name' => 'Em análise',
                        'payment' => [],
                    ],
                ],
                'message_response' => ['message' => 'success'],
            ], 200);
        },
    ]);

    $ctx = vindiCardContext();
    $company = $ctx['company'];
    $company->update([
        'email' => 'empresa-absorbed-over@test.com',
        'plan' => 'free',
        'card_fee_absorbed_by_company' => true,
    ]);

    // 50 confirmed orders this month → triggers 3% over-limit rate
    for ($i = 0; $i < 50; $i++) {
        Order::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'branch_id' => $ctx['branch']->id,
            'customer_id' => $ctx['customer']->id,
            'subtotal' => 120.00,
            'total' => 120.00,
            'delivery_fee' => 0,
            'discount' => 0,
            'fee' => 0,
            'net_value' => 0,
            'status' => 'delivered',
            'payment_method' => 'credit_card',
            'order_type' => 'delivery',
            'delivery_address' => 'Rua B',
            'delivery_number' => '2',
            'delivery_neighborhood' => 'Centro',
            'delivery_city' => 'São Paulo',
            'delivery_cep' => '01310-100',
            'order_number' => 'VCD-ABS-'.str_pad($i + 2, 5, '0', STR_PAD_LEFT),
            'created_at' => now(),
        ]);
    }

    $order = $ctx['order'];
    $order->update(['total' => 120.00, 'subtotal' => 120.00]);

    app(PaymentOrchestrator::class)->processCreditCard(
        $order, $ctx['customer'], $company->fresh(), [
            'holderName' => 'Cliente Teste',
            'number' => '4111111111111111', // Visa D+30 = 3.10%
            'expiryMonth' => '12',
            'expiryYear' => '2030',
            'ccv' => '123',
            'cpfCnpj' => '98765432100',
            'postalCode' => '01310-100',
            'addressNumber' => '1',
        ], 1
    );

    // cardFee = round(120 * 0.01, 2) = 1.20; netAfterCard = 118.80
    // platformFee = round(120 * 0.03, 2) = 3.60 (commission on subtotal, not netAfterCard)
    // targetCompanyNet = 118.80 - 3.60 = 115.20
    // affiliatePercentual = round(115.20 / 120 * 100, 4) = 96.0000
    // Vindi commission_amount = round(120 * 96.0000 / 100, 2) = 115.20
    expect($capturedPayload['affiliates'])->toHaveCount(1)
        ->and($capturedPayload['affiliates'][0]['account_email'])->toBe('empresa-absorbed-over@test.com')
        ->and($capturedPayload['affiliates'][0]['commission_amount'])->toBe('115.20');
});

test('feePercentageForOrder sem $order retorna 3% quando empresa tem ≥50 pedidos confirmados no mês', function () {
    $ctx = vindiCardContext();
    $company = $ctx['company'];
    $company->update(['plan' => 'free']);

    for ($i = 0; $i < 50; $i++) {
        Order::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'branch_id' => $ctx['branch']->id,
            'customer_id' => $ctx['customer']->id,
            'subtotal' => 50.00,
            'total' => 50.00,
            'delivery_fee' => 0,
            'discount' => 0,
            'fee' => 0,
            'net_value' => 0,
            'status' => 'paid',
            'payment_method' => 'pix',
            'order_type' => 'delivery',
            'delivery_address' => 'Rua B',
            'delivery_number' => '2',
            'delivery_neighborhood' => 'Centro',
            'delivery_city' => 'São Paulo',
            'delivery_cep' => '01310-100',
            'order_number' => 'VCD-2026-'.str_pad($i + 100, 5, '0', STR_PAD_LEFT),
            'created_at' => now(),
        ]);
    }

    // Sem $order: antes do fix retornava 1% (pulava o count). Após fix retorna 3%.
    expect($company->fresh()->feePercentageForOrder())->toBe(0.03);
});

test('PaymentCalculatorService retorna platform_rate e platform_fee_amount separados do card_rate', function () {
    // R$120, free plan 3%, Mastercard 2.80%
    // card fee = 120 / (1-0.028) - 120 = 3.46
    // platform fee = 120 * 0.03 = 3.60
    $result = app(PaymentCalculatorService::class)->calculate(
        orderAmount: 120.0,
        cardRate: 0.028,
        platformFeeRate: 0.03,
    );

    expect($result['card_rate'])->toBe(0.028)
        ->and($result['platform_rate'])->toBe(0.03)
        ->and($result['platform_fee_amount'])->toBe(round(120.0 * 0.03, 2));

    // finalAmount deve refletir só a taxa do cartão (não infla pelo platform rate)
    $expectedFinal = round(120.0 / (1 - 0.028), 2);
    expect($result['final_amount'])->toBe($expectedFinal);
});

test('cartão simulado criado quando sem credenciais Vindi', function () {
    config()->set('payments.vindi_token_account', null);

    $ctx = vindiCardContext();

    $cardData = [
        'holderName' => 'Cliente Cartão',
        'number' => '4111111111111111',
        'expiryMonth' => '12',
        'expiryYear' => '2027',
        'ccv' => '123',
        'cpfCnpj' => '98765432100',
        'postalCode' => '01310-100',
        'addressNumber' => '1',
    ];

    $orchestrator = app(PaymentOrchestrator::class);
    $orchestrator->processCreditCard(
        $ctx['order'], $ctx['customer'], $ctx['company'], $cardData, 1
    );

    $payment = Payment::where('order_id', $ctx['order']->id)->first();
    expect($payment)->not->toBeNull()
        ->and($payment->vindi_transaction_token)->toStartWith('sim_card_')
        ->and($payment->payment_gateway)->toBe('vindi');
});
