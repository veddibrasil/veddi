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
        'order_number' => 'VCD-2026-00001',
    ]);

    return compact('company', 'branch', 'customer', 'order');
}

test('VindiService.createCreditCardCharge retorna transaction_token', function () {
    config()->set('payments.vindi_token_account', 'tok_test');
    config()->set('payments.vindi_reseller_token', 'res_test');

    Http::fake([
        '*/transactions/payment' => Http::response([
            'transaction' => [
                'token' => 'vindi_card_token_789',
                'status_name' => 'Em análise',
            ],
        ], 200),
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
        ),
        installments: 1,
    );

    expect($result['transaction_token'])->toBe('vindi_card_token_789');
});

test('PaymentOrchestrator.processCreditCard cria Payment com vindi_transaction_token', function () {
    config()->set('payments.vindi_token_account', 'tok_test');
    config()->set('payments.vindi_reseller_token', 'res_test');
    config()->set('payments.credit_card.rate_1x', 0.0299);
    config()->set('payments.credit_card.anticipation_d15', 0.0199);

    Http::fake([
        '*/transactions/payment' => Http::response([
            'transaction' => [
                'token' => 'vindi_card_token_abc',
                'status_name' => 'Em análise',
            ],
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
