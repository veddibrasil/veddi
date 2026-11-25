<?php

use App\Enums\Plan;
use App\Services\Payment\AsaasService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.asaas.api_key' => 'chave_teste',
        'services.asaas.base_url' => 'https://asaas.test/api/v3',
    ]);
});

test('atualizar valor da assinatura também atualiza a fatura pendente já gerada', function () {
    Http::fake(['asaas.test/*' => Http::response(['id' => 'sub_1', 'status' => 'ACTIVE', 'value' => 158.0])]);

    app(AsaasService::class)->updateSubscriptionValue('sub_1', 158.0, 'Assinatura Plano VEDDI ESSENCIAL + Módulo PDV');

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && $request->url() === 'https://asaas.test/api/v3/subscriptions/sub_1'
        && $request['value'] === 158.0
        && $request['updatePendingPayments'] === true);
});

test('criar assinatura devolve o vencimento da 1ª fatura, não o do ciclo seguinte que o Asaas responde', function () {
    $this->travelTo(now()->setDate(2026, 10, 24));
    // Resposta real do Asaas para a sub_1ng40jnk9rpx2z5n: criada com 24/11, devolveu 24/12.
    Http::fake(['asaas.test/*' => Http::response([
        'id' => 'sub_1ng40jnk9rpx2z5n', 'status' => 'ACTIVE', 'value' => 158.0, 'nextDueDate' => '2026-12-24',
    ])]);

    $result = app(AsaasService::class)->createSubscription(
        customerId: 'cus_1',
        plan: Plan::Essencial,
        billingType: 'CREDIT_CARD',
        nextDueDate: '2026-11-24',
        extraAmount: 99.0,
        extraDescription: 'Módulo PDV',
        creditCardToken: 'tok_1',
    );

    expect($result['nextDueDate'])->toBe('2026-11-24');
});

test('assinatura apagada no Asaas volta como deleted, seja 200 com deleted ou 404', function (int $status, array $body) {
    Http::fake(['asaas.test/*' => Http::response($body, $status)]);

    $subscription = app(AsaasService::class)->getSubscription('sub_1ng40jnk9rpx2z5n');

    expect($subscription['deleted'])->toBeTrue();
})->with([
    'removida' => [200, ['id' => 'sub_1ng40jnk9rpx2z5n', 'status' => 'INACTIVE', 'deleted' => true, 'value' => 158.0, 'nextDueDate' => '2026-12-24']],
    'não encontrada' => [404, ['errors' => [['code' => 'not_found']]]],
]);

test('falha ao consultar assinatura lança exceção em vez de parecer assinatura sem dados', function () {
    Http::fake(['asaas.test/*' => Http::response(['errors' => []], 500)]);

    expect(fn () => app(AsaasService::class)->getSubscription('sub_1'))->toThrow(RuntimeException::class);
    expect(fn () => app(AsaasService::class)->getPendingSubscriptionPayments('sub_1'))->toThrow(RuntimeException::class);
});

test('faturas pendentes da assinatura são filtradas por status no Asaas', function () {
    Http::fake(['asaas.test/*' => Http::response(['data' => [
        ['id' => 'pay_1', 'status' => 'PENDING', 'value' => 158.0, 'dueDate' => '2026-11-24'],
    ]])]);

    $payments = app(AsaasService::class)->getPendingSubscriptionPayments('sub_1');

    expect($payments)->toHaveCount(1);
    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && str_starts_with($request->url(), 'https://asaas.test/api/v3/subscriptions/sub_1/payments')
        && $request['status'] === 'PENDING');
});

// ─── Troca do cartão da assinatura ──────────────────────────────────────────

function asaasApiCard(): array
{
    return [
        new \App\DTOs\CreditCardDTO(holderName: 'fulano', number: '4111 1111 1111 1111', expiryMonth: '12', expiryYear: '2030', ccv: '123'),
        new \App\DTOs\CreditCardHolderDTO(name: 'Fulano', email: 'fulano@example.com', cpfCnpj: '123.456.789-09', postalCode: '01310-000', addressNumber: '100', mobilePhone: '11999990000', phone: '11999990000'),
    ];
}

test('troca de cartão usa o endpoint updateCreditCard com o IP de quem paga', function () {
    Http::fake(['asaas.test/*' => Http::response(['id' => 'sub_1', 'status' => 'ACTIVE'])]);
    [$card, $holder] = asaasApiCard();

    app(AsaasService::class)->updateSubscriptionCreditCard('sub_1', $card, $holder, '200.100.50.25');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://asaas.test/api/v3/subscriptions/sub_1/updateCreditCard'
        && $request['remoteIp'] === '200.100.50.25'
        && $request['creditCard']['number'] === '4111111111111111'
        && $request['creditCard']['holderName'] === 'FULANO'
        && $request['creditCardHolderInfo']['cpfCnpj'] === '12345678909'
        && $request['creditCardHolderInfo']['postalCode'] === '01310000');
});

test('pagar fatura existente usa o endpoint payWithCreditCard e devolve a cobrança', function () {
    Http::fake(['asaas.test/*' => Http::response(['id' => 'pay_1', 'status' => 'CONFIRMED'])]);
    [$card, $holder] = asaasApiCard();

    $payment = app(AsaasService::class)->payWithCreditCard('pay_1', $card, $holder);

    expect($payment['status'])->toBe('CONFIRMED');
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://asaas.test/api/v3/payments/pay_1/payWithCreditCard'
        && $request['creditCard']['ccv'] === '123');
});

test('400 do Asaas no cartão vira recusa com o motivo; 500 vira erro de comunicação', function (int $status, string $exception, string $message) {
    Http::fake(['asaas.test/*' => Http::response(['errors' => [['code' => 'invalid_creditCard', 'description' => 'Transação não autorizada']]], $status)]);
    [$card, $holder] = asaasApiCard();

    expect(fn () => app(AsaasService::class)->payWithCreditCard('pay_1', $card, $holder))
        ->toThrow($exception, $message);
})->with([
    'recusado' => [400, \App\Exceptions\CardPaymentDeclinedException::class, 'Transação não autorizada'],
    'fora do ar' => [500, RuntimeException::class, 'Erro ao pagar cobrança com cartão no Asaas'],
]);

test('cobranças vencidas do cliente filtram por cliente e status', function () {
    Http::fake(['asaas.test/*' => Http::response(['data' => [['id' => 'pay_nov', 'status' => 'OVERDUE']]])]);

    $payments = app(AsaasService::class)->getCustomerOverduePayments('cus_1');

    expect($payments)->toHaveCount(1);
    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && str_starts_with($request->url(), 'https://asaas.test/api/v3/payments?')
        && $request['customer'] === 'cus_1'
        && $request['status'] === 'OVERDUE');
});
