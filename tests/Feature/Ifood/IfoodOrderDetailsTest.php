<?php

use App\Models\Customer;
use App\Models\IfoodOrderEvent;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * Pedido no formato real devolvido pela Order API na loja de teste (21/09/2026), com o
 * item trocado por um produto mapeado do ifoodContext().
 */
function ifoodRealOrderPayload(array $ctx, string $orderId, array $overrides = []): array
{
    $base = [
        'id' => $orderId,
        'displayId' => '5396',
        'createdAt' => now()->subMinutes(2)->utc()->format('Y-m-d\TH:i:s.v\Z'),
        'category' => 'FOOD',
        'orderTiming' => 'IMMEDIATE',
        'orderType' => 'DELIVERY',
        'delivery' => [
            'mode' => 'DEFAULT',
            'deliveredBy' => 'MERCHANT',
            'deliveryDateTime' => now()->addMinutes(45)->utc()->toIso8601String(),
            'observations' => 'Portão azul, tocar interfone 12',
            'deliveryAddress' => [
                'streetName' => 'Rua TESTE',
                'streetNumber' => '999',
                'formattedAddress' => 'Rua TESTE, 999',
                'neighborhood' => 'Bairro TESTE',
                'complement' => 'Apto 3',
                'postalCode' => '99999999',
                'city' => 'TESTE',
                'state' => 'SP',
                'country' => 'BR',
                'reference' => 'Ao lado da padaria',
                'coordinates' => ['latitude' => -23.5, 'longitude' => -46.6],
            ],
            'pickupCode' => '0951',
        ],
        'preparationStartDateTime' => now()->utc()->toIso8601String(),
        'isTest' => true,
        'salesChannel' => 'IFOOD',
        'merchant' => ['id' => $ctx['integration']->merchant_id, 'name' => 'Teste'],
        'customer' => [
            'id' => 'ifood-customer-a',
            'name' => 'Maria Cliente',
            'documentNumber' => '12345678909',
            'phone' => [
                'number' => '0800 700 3021',
                'localizer' => '29369477',
                'localizerExpiration' => now()->addHours(4)->utc()->toIso8601String(),
            ],
        ],
        'extraInfo' => 'Enviar talheres',
        'items' => [[
            'index' => 1,
            'id' => DB::table('branch_product')->where('branch_id', $ctx['branch']->id)->value('ifood_item_id'),
            'name' => 'Coxinha',
            'quantity' => 2,
            'unitPrice' => 8,
            'observations' => 'Sem cebola',
            'options' => [],
        ]],
        'benefits' => [[
            'value' => 3,
            'target' => 'CART',
            'campaign' => ['name' => 'Cupom da semana'],
            'sponsorshipValues' => [
                ['name' => 'IFOOD', 'value' => 2],
                ['name' => 'MERCHANT', 'value' => 1],
            ],
        ]],
        'total' => ['additionalFees' => 1, 'subTotal' => 16, 'deliveryFee' => 5, 'benefits' => 3, 'orderAmount' => 19],
        'payments' => [
            'prepaid' => 19,
            'pending' => 0,
            'methods' => [[
                'value' => 19, 'currency' => 'BRL', 'method' => 'CREDIT', 'prepaid' => true, 'type' => 'ONLINE',
                'card' => ['brand' => 'Visa'],
            ]],
        ],
        'additionalFees' => [['type' => 'SMALL_ORDER_FEE', 'description' => 'Taxa de Serviço', 'value' => 1]],
    ];

    return array_replace_recursive($base, $overrides);
}

function processIfoodPlaced(array $ctx, array $payload, string $eventId): Order
{
    // URL exata por pedido: Http::fake acumula stubs e o primeiro curinga venceria sempre.
    Http::fake([
        '*/authentication/v1.0/oauth/token' => Http::response(['accessToken' => 'tok-test', 'expiresIn' => 3600], 200),
        '*/order/v1.0/orders/'.$payload['id'] => Http::response($payload, 200),
    ]);

    $event = IfoodOrderEvent::create([
        'event_id' => $eventId,
        'event_type' => 'PLC',
        'source' => 'polling',
        'ifood_integration_id' => $ctx['integration']->id,
        'payload' => ['orderId' => $payload['id'], 'merchantId' => $ctx['integration']->merchant_id],
        'status' => 'pending',
    ]);

    runIfoodOrderJob($event->id);

    return Order::withoutGlobalScopes()->findOrFail($event->fresh()->order_id);
}

test('pedido iFood guarda as informações obrigatórias do payload real', function () {
    $ctx = ifoodContext('det1');
    $order = processIfoodPlaced($ctx, ifoodRealOrderPayload($ctx, 'order-det1'), 'evt-det1');
    $ifood = $order->ifoodDetails();

    expect($order->status)->toBe('pending')
        ->and($order->order_type)->toBe('delivery')
        ->and($order->notes)->toBe('Enviar talheres')
        ->and($ifood->displayId())->toBe('5396')
        ->and($ifood->status())->toBe('PLACED')
        ->and($ifood->awaitingConfirmation())->toBeTrue()
        ->and($ifood->confirmationDeadline())->not->toBeNull()
        ->and($ifood->pickupCode())->toBe('0951')
        ->and($ifood->customerPhone())->toBe('0800 700 3021')
        ->and($ifood->phoneLocalizer())->toBe('29369477')
        ->and($ifood->customerDocument())->toBe('12345678909')
        ->and($ifood->deliveryObservations())->toBe('Portão azul, tocar interfone 12')
        ->and($ifood->deliveryReference())->toBe('Ao lado da padaria')
        ->and($ifood->orderTypeLabel())->toBe('Entrega própria')
        ->and($ifood->paymentLines()[0]['label'])->toBe('Crédito (Visa)')
        ->and($ifood->paymentLines()[0]['prepaid'])->toBeTrue()
        ->and($ifood->benefitLines()[0]['sponsors'])->toBe('R$ 2,00 pago pelo iFood + R$ 1,00 pago pela loja')
        ->and($ifood->pendingAmount())->toBe(0.0);

    $item = OrderItem::where('order_id', $order->id)->first();
    expect($item->notes)->toBe('Sem cebola');
    expect($order->payments()->pluck('status')->all())->toBe(['paid']);
});

test('clientes iFood com o mesmo 0800 não se fundem; o mesmo cliente é reaproveitado', function () {
    $ctx = ifoodContext('det2');

    $first = processIfoodPlaced($ctx, ifoodRealOrderPayload($ctx, 'order-det2a'), 'evt-det2a');
    $second = processIfoodPlaced($ctx, ifoodRealOrderPayload($ctx, 'order-det2b', [
        'customer' => ['id' => 'ifood-customer-b', 'name' => 'João Outro'],
        'delivery' => ['deliveryAddress' => ['streetName' => 'Rua Outra']],
    ]), 'evt-det2b');
    $third = processIfoodPlaced($ctx, ifoodRealOrderPayload($ctx, 'order-det2c', [
        'customer' => ['name' => 'Maria C. Atualizada'],
    ]), 'evt-det2c');

    expect($first->customer_id)->not->toBe($second->customer_id)
        ->and($third->customer_id)->toBe($first->customer_id);

    $maria = Customer::withoutGlobalScopes()->find($first->customer_id);
    $joao = Customer::withoutGlobalScopes()->find($second->customer_id);
    expect($maria->name)->toBe('Maria C. Atualizada')
        ->and($maria->ifood_customer_id)->toBe('ifood-customer-a')
        ->and($maria->phone)->not->toBe('08007003021')
        ->and($joao->name)->toBe('João Outro')
        ->and($joao->address)->toBe('Rua Outra')
        ->and($maria->address)->toBe('Rua TESTE');

    // O endereço de entrega de cada pedido é o do próprio pedido.
    expect($second->fresh()->delivery_address)->toBe('Rua Outra')
        ->and($first->fresh()->delivery_address)->toBe('Rua TESTE');
});

test('cliente do chat com telefone igual ao 0800 nunca recebe pedido iFood', function () {
    $ctx = ifoodContext('det3');
    $chatCustomer = Customer::withoutGlobalScopes()->create([
        'company_id' => $ctx['company']->id,
        'name' => 'Cliente do chat',
        'phone' => '08007003021',
    ]);

    $order = processIfoodPlaced($ctx, ifoodRealOrderPayload($ctx, 'order-det3'), 'evt-det3');

    expect($order->customer_id)->not->toBe($chatCustomer->id)
        ->and($chatCustomer->fresh()->name)->toBe('Cliente do chat');
});

test('pagamento na entrega fica pendente, com troco, e é dado como recebido na conclusão', function () {
    $ctx = ifoodContext('det4');
    $payload = ifoodRealOrderPayload($ctx, 'order-det4', [
        'payments' => ['prepaid' => 0, 'pending' => 19],
    ]);
    $payload['payments']['methods'] = [[
        'value' => 19, 'currency' => 'BRL', 'method' => 'CASH', 'prepaid' => false, 'type' => 'OFFLINE',
        'cash' => ['changeFor' => 50],
    ]];

    $order = processIfoodPlaced($ctx, $payload, 'evt-det4');
    $ifood = $order->ifoodDetails();

    expect($ifood->pendingAmount())->toBe(19.0)
        ->and($ifood->paymentLines()[0])->toMatchArray(['label' => 'Dinheiro', 'prepaid' => false, 'change_for' => 50.0, 'change' => 31.0])
        ->and($order->payments()->where('status', 'paid')->exists())->toBeFalse()
        ->and((float) $order->payments()->where('status', 'pending')->sum('amount'))->toBe((float) $order->total);

    $lines = collect($ifood->receiptLines('entrega'))->pluck('text');
    expect($lines)->toContain('Dinheiro: R$ 19,00 - COBRAR NA ENTREGA')
        ->toContain('Troco para R$ 50,00 (levar R$ 31,00)')
        ->toContain('COBRAR DO CLIENTE: R$ 19,00');

    $event = IfoodOrderEvent::create([
        'event_id' => 'evt-det4-con', 'event_type' => 'CON', 'source' => 'polling',
        'ifood_integration_id' => $ctx['integration']->id, 'payload' => ['orderId' => 'order-det4'], 'status' => 'pending',
    ]);
    runIfoodOrderJob($event->id);

    expect($order->fresh()->status)->toBe('delivered')
        ->and($order->payments()->where('status', 'pending')->exists())->toBeFalse();
});

test('pedido agendado guarda o horário do iFood e entra como agendado', function () {
    $ctx = ifoodContext('det5');
    $start = now()->addDay()->setTime(19, 0)->utc();
    $order = processIfoodPlaced($ctx, ifoodRealOrderPayload($ctx, 'order-det5', [
        'orderTiming' => 'SCHEDULED',
        'schedule' => [
            'deliveryDateTimeStart' => $start->toIso8601String(),
            'deliveryDateTimeEnd' => $start->copy()->addHour()->toIso8601String(),
        ],
    ]), 'evt-det5');

    expect($order->status)->toBe('scheduled')
        ->and($order->scheduled_at->equalTo($start))->toBeTrue()
        ->and($order->status_label)->toBe('Agendado (aguardando aceite)')
        ->and($order->ifoodDetails()->confirmationDeadline())->toBeNull()
        ->and($order->ifoodDetails()->scheduleWindow())->toBe($start->copy()->setTimezone(config('app.timezone'))->format('d/m/Y H:i').' às '.$start->copy()->addHour()->setTimezone(config('app.timezone'))->format('H:i'));
});

test('retirada e consumo no local não viram entrega', function (string $ifoodType, string $label) {
    $ctx = ifoodContext('det6'.$ifoodType);
    $payload = ifoodRealOrderPayload($ctx, 'order-det6-'.$ifoodType, ['orderType' => $ifoodType]);
    unset($payload['delivery']);
    $payload['indoor'] = ['table' => '7'];

    $order = processIfoodPlaced($ctx, $payload, 'evt-det6-'.$ifoodType);

    expect($order->order_type)->toBe('pickup')
        ->and($order->isDeliveryOrder())->toBeFalse()
        ->and($order->ifoodDetails()->orderTypeLabel())->toBe($label)
        ->and($order->ifoodDetails()->canBeDispatchedByStore())->toBeFalse();
})->with([
    ['TAKEOUT', 'Retirada pelo cliente'],
    ['INDOOR', 'Consumo no local'],
]);

test('entrega feita pelo iFood é identificada e não tem despacho pela loja', function () {
    $ctx = ifoodContext('det7');
    $order = processIfoodPlaced($ctx, ifoodRealOrderPayload($ctx, 'order-det7', ['delivery' => ['deliveredBy' => 'IFOOD']]), 'evt-det7');

    expect($order->ifoodDetails()->isDeliveredByIfood())->toBeTrue()
        ->and($order->ifoodDetails()->orderTypeLabel())->toBe('Entrega pelo iFood')
        ->and($order->ifoodDetails()->canBeDispatchedByStore())->toBeFalse();
});

test('PLACED repetido com o pedido já criado não duplica', function () {
    $ctx = ifoodContext('det8');
    $order = processIfoodPlaced($ctx, ifoodRealOrderPayload($ctx, 'order-det8'), 'evt-det8a');
    $again = processIfoodPlaced($ctx, ifoodRealOrderPayload($ctx, 'order-det8'), 'evt-det8b');

    expect($again->id)->toBe($order->id)
        ->and(Order::withoutGlobalScopes()->where('external_order_id', 'order-det8')->count())->toBe(1);
});

test('comanda de cozinha leva número iFood mas não pagamento; geral leva tudo', function () {
    $ctx = ifoodContext('det9');
    $order = processIfoodPlaced($ctx, ifoodRealOrderPayload($ctx, 'order-det9'), 'evt-det9');

    $kitchen = collect($order->ifoodDetails()->receiptLines('cozinha'))->pluck('text');
    $general = collect($order->ifoodDetails()->receiptLines('geral'))->pluck('text')->implode("\n");

    expect($kitchen->all())->toBe(['IFOOD #5396', 'ENTREGA PROPRIA'])
        ->and($general)->toContain('Codigo de coleta: 0951')
        ->toContain('Localizador: 29369477')
        ->toContain('CPF/CNPJ na nota: 12345678909')
        ->toContain('Credito (Visa): R$ 19,00 - pago online')
        ->toContain('Cupom (Cupom da semana): -R$ 3,00')
        ->toContain('Obs. entrega: Portao azul, tocar interfone 12');

    $escpos = app(\App\Contracts\PrinterServiceInterface::class)->buildOrderReceipt($order->fresh()->load('items', 'branch', 'customer'), 'cozinha');
    expect($escpos)->toContain('IFOOD #5396')->toContain('Obs: Sem cebola')->not->toContain('08007003021');
});
