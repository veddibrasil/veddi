<?php

use App\Contracts\IfoodGatewayContract;
use App\Contracts\OrderServiceInterface;
use App\Jobs\ProcessIfoodOrderJob;
use App\Models\IfoodOrderEvent;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Ifood\IfoodOrderMapper;
use App\Services\Payment\PaymentOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function fakeIfoodApi(array $orderDetails): void
{
    Http::fake([
        '*/authentication/v1.0/oauth/token' => Http::response(['accessToken' => 'tok-test', 'expiresIn' => 3600], 200),
        '*/order/v1.0/orders/*' => Http::response($orderDetails, 200),
    ]);
}

function runIfoodOrderJob(int $eventId): void
{
    (new ProcessIfoodOrderJob($eventId))->handle(
        app(IfoodGatewayContract::class),
        app(IfoodOrderMapper::class),
        app(OrderServiceInterface::class),
        app(PaymentOrchestrator::class),
    );
}

test('processa evento PLC e cria pedido com channel ifood, company_id e fee corretos', function () {
    ['company' => $company, 'branch' => $branch, 'integration' => $integration] = ifoodContext('job1');

    fakeIfoodApi(ifoodOrderDetailsPayload('ifood-order-job1', $integration->merchant_id, 'ifood-item-coxinha-job1', 2));

    $event = IfoodOrderEvent::create([
        'event_id' => 'evt-job-1',
        'event_type' => 'PLC',
        'source' => 'webhook',
        'ifood_integration_id' => $integration->id,
        'payload' => ['orderId' => 'ifood-order-job1', 'merchantId' => $integration->merchant_id],
        'status' => 'pending',
    ]);

    runIfoodOrderJob($event->id);

    $event->refresh();
    expect($event->status)->toBe('processed')
        ->and($event->order_id)->not->toBeNull();

    $order = Order::withoutGlobalScopes()->find($event->order_id);
    expect($order)->not->toBeNull()
        ->and($order->company_id)->toBe($company->id)
        ->and($order->branch_id)->toBe($branch->id)
        ->and($order->channel)->toBe('ifood')
        ->and($order->external_order_id)->toBe('ifood-order-job1')
        ->and($order->status)->toBe('paid');

    $payment = Payment::where('order_id', $order->id)->first();
    expect($payment)->not->toBeNull()
        ->and($payment->payment_gateway)->toBe('ifood')
        ->and($payment->status)->toBe('paid');

    expect(app()->bound('current.company'))->toBeFalse();
});

test('idempotência: evento já processado não cria pedido duplicado', function () {
    ['integration' => $integration] = ifoodContext('job2');

    fakeIfoodApi(ifoodOrderDetailsPayload('ifood-order-job2', $integration->merchant_id, 'ifood-item-coxinha-job2'));

    $event = IfoodOrderEvent::create([
        'event_id' => 'evt-job-2',
        'event_type' => 'PLC',
        'source' => 'webhook',
        'ifood_integration_id' => $integration->id,
        'payload' => ['orderId' => 'ifood-order-job2', 'merchantId' => $integration->merchant_id],
        'status' => 'pending',
    ]);

    runIfoodOrderJob($event->id);
    expect(Order::withoutGlobalScopes()->count())->toBe(1);

    // Segunda tentativa (ex.: retry de fila) — evento já está processed, não deve reprocessar.
    runIfoodOrderJob($event->id);
    expect(Order::withoutGlobalScopes()->count())->toBe(1);
});

test('item não mapeado falha o evento sem criar pedido malformado', function () {
    ['integration' => $integration] = ifoodContext('job3');

    // ifoodItemId que não existe em branch_product.ifood_item_id para esta filial.
    fakeIfoodApi(ifoodOrderDetailsPayload('ifood-order-job3', $integration->merchant_id, 'item-nao-cadastrado'));

    $event = IfoodOrderEvent::create([
        'event_id' => 'evt-job-3',
        'event_type' => 'PLC',
        'source' => 'webhook',
        'ifood_integration_id' => $integration->id,
        'payload' => ['orderId' => 'ifood-order-job3', 'merchantId' => $integration->merchant_id],
        'status' => 'pending',
    ]);

    runIfoodOrderJob($event->id);

    $event->refresh();
    expect($event->status)->toBe('failed')
        ->and($event->order_id)->toBeNull();

    expect(Order::withoutGlobalScopes()->count())->toBe(0);
});

test('evento que não é PLC é marcado processado sem criar pedido', function () {
    ['integration' => $integration] = ifoodContext('job4');

    $event = IfoodOrderEvent::create([
        'event_id' => 'evt-job-4',
        'event_type' => 'CFM',
        'source' => 'webhook',
        'ifood_integration_id' => $integration->id,
        'payload' => ['orderId' => 'ifood-order-job4', 'merchantId' => $integration->merchant_id],
        'status' => 'pending',
    ]);

    runIfoodOrderJob($event->id);

    $event->refresh();
    expect($event->status)->toBe('processed')
        ->and(Order::withoutGlobalScopes()->count())->toBe(0);
});
