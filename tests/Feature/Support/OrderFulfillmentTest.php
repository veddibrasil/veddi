<?php

use App\Models\Order;
use App\Models\Payment;
use App\Support\Orders\OrderFulfillment;
use Illuminate\Support\Carbon;

/** Pedido em memória; `payments` vazio por padrão (nenhum pagamento registrado). */
function fulfillmentOrder(array $attributes, array $payments = []): Order
{
    $order = new Order(array_merge([
        'channel' => 'chat',
        'order_type' => 'delivery',
        'status' => 'preparing',
        'payment_method' => 'pix',
        'total' => 45.00,
        'notes' => '',
    ], $attributes));

    return $order->setRelation('payments', collect($payments));
}

test('tipo de saída por origem do pedido', function (array $attributes, string $kind, string $label) {
    $fulfillment = OrderFulfillment::for(fulfillmentOrder($attributes));

    expect($fulfillment->kind())->toBe($kind)
        ->and($fulfillment->label())->toBe($label)
        ->and($fulfillment->isHandoff())->toBe(in_array($kind, [OrderFulfillment::DELIVERY, OrderFulfillment::PICKUP], true));
})->with([
    'chat entrega' => [['order_type' => 'delivery'], OrderFulfillment::DELIVERY, 'Entrega'],
    'chat retirada' => [['order_type' => 'pickup'], OrderFulfillment::PICKUP, 'Retirada'],
    'iFood retirada' => [['channel' => 'ifood', 'order_type' => 'pickup'], OrderFulfillment::PICKUP, 'Retirada'],
    'PDV balcão' => [['order_type' => 'pdv', 'delivery_type' => 'balcao'], OrderFulfillment::COUNTER, 'Balcão'],
    'PDV retirada' => [['order_type' => 'pdv', 'delivery_type' => 'retirar'], OrderFulfillment::PICKUP, 'Retirada'],
    'PDV entrega' => [['order_type' => 'pdv', 'delivery_type' => 'entrega', 'delivery_address_id' => 99], OrderFulfillment::DELIVERY, 'Entrega'],
    'PDV mesa' => [['order_type' => 'pdv', 'is_open_tab' => true, 'table_label' => 'Mesa 3', 'restaurant_table_id' => 1], OrderFulfillment::TABLE, 'Mesa 3'],
]);

test('chat PIX pago é "Pago · PIX online"; não pago aguarda o gateway', function () {
    expect(OrderFulfillment::for(fulfillmentOrder(['status' => 'preparing']))->paymentHeadline())->toBe('Pago · PIX online')
        ->and(OrderFulfillment::for(fulfillmentOrder(['status' => 'awaiting_payment']))->payment()['state'])->toBe(OrderFulfillment::PAYMENT_AWAITING_ONLINE);
});

test('chat dinheiro na entrega cobra o total e lê o troco das observações', function () {
    $fulfillment = OrderFulfillment::for(fulfillmentOrder([
        'payment_method' => 'cash',
        'payment_on_delivery' => true,
        'status' => 'awaiting_payment',
        'notes' => "Sem cebola\nTroco para R$ 1.050,00",
        'total' => 1000.00,
    ]));

    expect($fulfillment->payment())->toMatchArray([
        'state' => OrderFulfillment::PAYMENT_COLLECT,
        'method' => 'Dinheiro',
        'amount_due' => 1000.0,
        'change_for' => 1050.0,
        'change' => 50.0,
    ])
        ->and($fulfillment->paymentHeadline())->toBe('Cobrar R$ 1.000,00 · Dinheiro')
        ->and($fulfillment->changeLine())->toBe('Troco para R$ 1.050,00 (levar R$ 50,00)');
});

test('PDV a receber no cartão cobra na maquininha; já recebido fica pago', function () {
    $pending = OrderFulfillment::for(fulfillmentOrder(['order_type' => 'pdv', 'payment_method' => 'card', 'status' => 'awaiting_payment']));
    $settled = OrderFulfillment::for(fulfillmentOrder(['order_type' => 'pdv', 'payment_method' => 'cash', 'status' => 'paid'], [new Payment(['status' => 'paid'])]));

    expect($pending->paymentHeadline())->toBe('Cobrar R$ 45,00 · Cartão na maquininha')
        ->and($pending->changeLine())->toBeNull()
        ->and($settled->paymentHeadline())->toBe('Pago · Dinheiro');
});

test('chat antigo em dinheiro já pago não vira "online"', function () {
    expect(OrderFulfillment::for(fulfillmentOrder(['payment_method' => 'cash', 'status' => 'paid']))->paymentHeadline())->toBe('Pago · Dinheiro');
});

test('marketplace: valor pendente vira cobrança com troco; pré-pago fica pago pela plataforma', function () {
    $collect = OrderFulfillment::for(fulfillmentOrder(['channel' => 'ifood', 'payment_method' => 'ifood', 'external_metadata' => [
        'pending_amount' => 19,
        'payment_methods' => [['method' => 'CASH', 'prepaid' => false, 'value' => 19, 'change_for' => 50]],
    ]]));
    $prepaid = OrderFulfillment::for(fulfillmentOrder(['channel' => 'ifood', 'payment_method' => 'ifood', 'external_metadata' => [
        'pending_amount' => 0,
        'payment_methods' => [['method' => 'CREDIT', 'prepaid' => true, 'value' => 45]],
    ]]));

    expect($collect->paymentHeadline())->toBe('Cobrar R$ 19,00 · Dinheiro')
        ->and($collect->changeLine())->toBe('Troco para R$ 50,00 (levar R$ 31,00)')
        ->and($prepaid->paymentHeadline())->toBe('Pago · iFood');
});

test('horário combinado sai no fuso da aplicação', function () {
    $scheduled = OrderFulfillment::for(fulfillmentOrder(['scheduled_at' => Carbon::parse('2026-11-06 20:30:00', config('app.timezone'))]));

    expect($scheduled->dueAt()?->format('d/m H:i'))->toBe('06/11 20:30')
        ->and(OrderFulfillment::for(fulfillmentOrder([]))->dueAt())->toBeNull();
});
