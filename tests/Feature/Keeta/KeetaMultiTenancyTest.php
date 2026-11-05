<?php

use App\Models\KeetaIntegration;
use App\Models\KeetaRequest;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('pedido de uma loja nunca entra na outra empresa', function () {
    $a = keetaContext('A');
    $b = keetaContext('B');
    $raw = keetaOrder($a);

    receiveKeetaOrder($this, $a, $raw);

    expect(Order::withoutGlobalScopes()->where('company_id', $a['company']->id)->where('channel', 'keeta')->count())->toBe(1)
        ->and(Order::withoutGlobalScopes()->where('company_id', $b['company']->id)->count())->toBe(0);

    // Evento do pedido de A enviado com o merchantId de B não acha o pedido (fica ignorado).
    postKeetaWebhook($this, keetaEvent('CANCELLED', $raw['id']), $b['integration']->merchant_ref)->assertNoContent();
    expect(Order::withoutGlobalScopes()->where('external_order_id', $raw['id'])->first()->status)->toBe('pending');
});

it('cliente Keeta é único por empresa', function () {
    $a = keetaContext('A');
    $b = keetaContext('B');

    $orderA = receiveKeetaOrder($this, $a, keetaOrder($a));
    $orderB = receiveKeetaOrder($this, $b, keetaOrder($b, ['id' => '900000000000001']));

    $customer = fn (Order $order) => \App\Models\Customer::withoutGlobalScopes()->find($order->customer_id);

    expect($orderA->customer_id)->not->toBe($orderB->customer_id)
        ->and($customer($orderA)->company_id)->toBe($a['company']->id)
        ->and($customer($orderB)->company_id)->toBe($b['company']->id)
        ->and($customer($orderB)->keeta_customer_id)->toBe('88123');
});

it('a tela de outra empresa não enxerga a integração nem as solicitações', function () {
    $a = keetaContext('A');
    $b = keetaContext('B');
    $order = receiveKeetaOrder($this, $a, keetaOrder($a));
    KeetaRequest::withoutGlobalScopes()->create([
        'company_id' => $a['company']->id, 'keeta_integration_id' => $a['integration']->id, 'order_id' => $order->id,
        'external_order_id' => $order->external_order_id, 'kind' => 'refund', 'event_id' => 'r-1', 'status' => 'open',
    ]);

    app()->instance('current.company', $b['company']);

    expect(KeetaIntegration::pluck('id')->all())->toBe([$b['integration']->id])
        ->and(KeetaRequest::count())->toBe(0);
});

it('a mesma loja da Keeta não liga em duas filiais', function () {
    $a = keetaContext('A');
    $b = keetaContext('B', ['keeta_merchant_id' => null, 'status' => 'pending_mapping', 'available_shops' => [['id' => $a['integration']->keeta_merchant_id, 'name' => 'Loja A', 'address' => null]]]);
    fakeKeetaApi();

    expect(fn () => app(\App\Services\Keeta\KeetaAuthService::class)->linkShop($b['integration'], $a['integration']->keeta_merchant_id))
        ->toThrow(RuntimeException::class, 'outra filial');
});
