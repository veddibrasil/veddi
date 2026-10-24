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
