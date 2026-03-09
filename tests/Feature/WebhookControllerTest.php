<?php

use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

// Helpers para criar registros sem company scope
function makeCompany(array $attrs = []): Company
{
    return Company::create(array_merge([
        'name'         => 'Empresa Teste',
        'slug'         => 'empresa-teste-' . uniqid(),
        'subdomain'    => 'empresa-teste-' . uniqid(),
        'order_prefix' => 'TST',
        'active'       => true,
    ], $attrs));
}

function makeBranch(Company $company, array $attrs = []): \App\Models\Branch
{
    return \App\Models\Branch::withoutGlobalScopes()->create(array_merge([
        'company_id' => $company->id,
        'name'       => 'Filial Teste',
        'address'    => 'Rua Teste, 1',
        'city'       => 'São Paulo',
        'active'     => true,
        'opens_at'   => '00:00:00',
        'closes_at'  => '23:59:59',
    ], $attrs));
}

function makeCustomer(Company $company, array $attrs = []): Customer
{
    return Customer::withoutGlobalScopes()->create(array_merge([
        'company_id' => $company->id,
        'name'       => 'Cliente Teste',
        'phone'      => '11999990001',
        'email'      => 'cliente' . uniqid() . '@teste.com',
    ], $attrs));
}

function makeOrder(Company $company, Customer $customer, \App\Models\Branch $branch, array $attrs = []): Order
{
    return Order::withoutGlobalScopes()->create(array_merge([
        'company_id'     => $company->id,
        'customer_id'    => $customer->id,
        'branch_id'      => $branch->id,
        'subtotal'       => 50.00,
        'total'          => 50.00,
        'status'         => 'awaiting_payment',
        'payment_method' => 'pix',
        'order_type'     => 'delivery',
        'order_number'   => 'TST-2026-00001',
    ], $attrs));
}

function makePayment(Order $order, array $attrs = []): Payment
{
    return Payment::create(array_merge([
        'order_id'              => $order->id,
        'abacatepay_billing_id' => 'billing_' . uniqid(),
        'abacatepay_url'        => 'https://pay.example.com/billing/test',
        'amount'                => $order->total,
        'status'                => 'pending',
        'expires_at'            => now()->addMinutes(30),
        'payment_token'         => hash('sha256', $order->id . 'test' . uniqid()),
    ], $attrs));
}

// ─── Testes ───────────────────────────────────────────────────────────────────

test('webhook billing.paid confirma pagamento com devMode', function () {
    Event::fake([\App\Events\OrderStatusUpdated::class]);

    $company  = makeCompany();
    $branch   = makeBranch($company);
    $customer = makeCustomer($company);
    $order    = makeOrder($company, $customer, $branch);
    $payment  = makePayment($order);

    $payload = json_encode([
        'event'   => 'billing.paid',
        'devMode' => true,
        'data'    => [
            'billing' => ['id' => $payment->abacatepay_billing_id],
        ],
    ]);

    $response = $this->postJson('/webhooks/abacatepay', json_decode($payload, true));

    $response->assertOk()->assertJson(['status' => 'ok']);

    expect($payment->fresh()->status)->toBe('paid');
    expect($order->fresh()->status)->toBe('paid');

    Event::assertDispatched(\App\Events\OrderStatusUpdated::class);
});

test('webhook ignora eventos diferentes de billing.paid', function () {
    $payload = [
        'event'   => 'billing.created',
        'devMode' => true,
        'data'    => [],
    ];

    $response = $this->postJson('/webhooks/abacatepay', $payload);

    $response->assertOk()->assertJson(['status' => 'ignored']);
});

test('webhook retorna 404 se billing_id não existe', function () {
    $payload = [
        'event'   => 'billing.paid',
        'devMode' => true,
        'data'    => [
            'billing' => ['id' => 'billing_inexistente'],
        ],
    ];

    $response = $this->postJson('/webhooks/abacatepay', $payload);

    $response->assertStatus(404);
});

test('webhook rejeita assinatura inválida quando não é devMode', function () {
    $company  = makeCompany(['abacatepay_webhook_secret' => 'meu-segredo']);
    $branch   = makeBranch($company);
    $customer = makeCustomer($company);
    $order    = makeOrder($company, $customer, $branch);
    $payment  = makePayment($order);

    $data = [
        'event'   => 'billing.paid',
        'devMode' => false,
        'data'    => [
            'billing' => ['id' => $payment->abacatepay_billing_id],
        ],
    ];

    $response = $this->postJson(
        '/webhooks/abacatepay',
        $data,
        ['X-Signature' => 'assinatura-invalida']
    );

    $response->assertStatus(401);

    // Pagamento não deve ter mudado
    expect($payment->fresh()->status)->toBe('pending');
});

test('webhook ignora billing já pago (idempotência)', function () {
    $company  = makeCompany();
    $branch   = makeBranch($company);
    $customer = makeCustomer($company);
    $order    = makeOrder($company, $customer, $branch, ['status' => 'paid']);
    $payment  = makePayment($order, ['status' => 'paid', 'paid_at' => now()]);

    $payload = [
        'event'   => 'billing.paid',
        'devMode' => true,
        'data'    => [
            'billing' => ['id' => $payment->abacatepay_billing_id],
        ],
    ];

    $response = $this->postJson('/webhooks/abacatepay', $payload);

    $response->assertOk()->assertJson(['status' => 'already_processed']);
});

test('webhook rejeita billing expirado', function () {
    $company  = makeCompany();
    $branch   = makeBranch($company);
    $customer = makeCustomer($company);
    $order    = makeOrder($company, $customer, $branch);
    $payment  = makePayment($order, ['expires_at' => now()->subMinutes(5)]);

    $payload = [
        'event'   => 'billing.paid',
        'devMode' => true,
        'data'    => [
            'billing' => ['id' => $payment->abacatepay_billing_id],
        ],
    ];

    $response = $this->postJson('/webhooks/abacatepay', $payload);

    $response->assertStatus(422);
    expect($payment->fresh()->status)->toBe('pending');
});
