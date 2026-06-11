<?php

use App\DTOs\CreditCardDTO;
use App\DTOs\CreditCardHolderDTO;
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
    config()->set('payments.credit_card.rate_1x', 0.0299);
    config()->set('payments.credit_card.anticipation_d15', 0.0199);

    Http::fake([
        '*/transactions/payment' => Http::response([
            'data_response' => [
                'transaction' => [
                    'token_transaction' => 'vindi_card_token_abc',
                    'status_name' => 'Em análise',
                    'payment' => [],
                ],
            ],
            'message_response' => ['message' => 'success'],
        ], 200),
    ]);

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
