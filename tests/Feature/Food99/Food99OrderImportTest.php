<?php

use App\Jobs\ProcessFood99EventJob;
use App\Models\CompanyNotification;
use App\Models\Customer;
use App\Models\Food99WebhookEvent;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(fn () => Http::preventStrayRequests());

it('cria pedido pendente com itens ligados e avulsos a partir do orderNew', function () {
    $ctx = food99Context();
    $order = receiveFood99Order($this, $ctx, food99OrderInfo($ctx));

    $order->load('items', 'payments', 'customer');
    expect($order->status)->toBe('pending')
        ->and($order->company_id)->toBe($ctx['company']->id)
        ->and($order->branch_id)->toBe($ctx['branch']->id)
        ->and($order->external_order_id)->toBe('5764607523034934815')
        ->and($order->order_type)->toBe('delivery')
        ->and($order->payment_method)->toBe('99food')
        ->and($order->notes)->toBe('Sem cebola, por favor')
        ->and((float) $order->total)->toBe(18.0)
        ->and($order->items)->toHaveCount(2)
        ->and($order->items[0]->product_id)->toBe($ctx['product']->id)
        ->and($order->items[0]->notes)->toBe('bem frita')
        ->and($order->items[1]->product_id)->toBeNull()
        ->and($order->items[1]->product_name)->toBe('Refrigerante lata')
        ->and($order->payments)->toHaveCount(1)
        ->and($order->payments[0]->payment_gateway)->toBe('99food')
        ->and($order->payments[0]->status)->toBe('paid')
        ->and($order->customer->food99_customer_id)->toBe('650000000000123456')
        ->and($order->customer->phone)->toStartWith('99food:')
        ->and($order->delivery_address)->toBe('Av. T-2');

    $details = $order->marketplaceDetails();
    expect($details->channelLabel())->toBe('99Food')
        ->and($order->displayNumber())->toBe('99Food #12')
        ->and($details->orderId())->toBe('5764607523034934815')
        ->and($details->awaitingConfirmation())->toBeTrue()
        ->and($details->orderTypeLabel())->toBe('Entrega 99Food')
        ->and($details->customerPhone())->toBe('+55 11 4000-1234')
        ->and($details->phoneLocalizer())->toBe('88123')
        ->and($order->status_label)->toBe('Aguardando aceite')
        ->and(Food99WebhookEvent::sole()->status)->toBe('processed')
        ->and(Food99WebhookEvent::sole()->order_id)->toBe($order->id);

    $notification = CompanyNotification::withoutGlobalScopes()->where('company_id', $ctx['company']->id)->where('type', 'order')->sole();
    expect($notification->title)->toBe('Novo pedido 99Food #12')
        ->and($notification->subtitle)->toBe('Aceite em até 5 min');
});

it('registra entrega própria em dinheiro como pagamento pendente com frete da loja', function () {
    $ctx = food99Context();
    $order = receiveFood99Order($this, $ctx, food99OrderInfo($ctx, 'order-store-delivery-cash'));

    $order->load('payments', 'customer');
    expect((float) $order->delivery_fee)->toBe(5.0)
        ->and((float) $order->total)->toBe(21.0)
        ->and($order->payments->sole()->status)->toBe('pending')
        ->and((float) $order->payments->sole()->amount)->toBe(21.0)
        ->and($order->customer->food99_customer_id)->toBe('order-5764607523034934816')
        ->and($order->customer->name)->toBe('João')
        ->and($order->marketplaceDetails()->orderTypeLabel())->toBe('Entrega própria')
        ->and($order->marketplaceDetails()->paymentLines()[0]['change'])->toBe(29.0);
});

it('importa retirada como pickup com código de retirada', function () {
    $ctx = food99Context();
    $order = receiveFood99Order($this, $ctx, food99OrderInfo($ctx, 'order-pickup'));

    expect($order->order_type)->toBe('pickup')
        ->and($order->marketplaceDetails()->takeawayCode())->toBe('ABCD')
        ->and($order->marketplaceDetails()->isPickup())->toBeTrue();
});

it('não duplica o pedido em reentrega do orderNew', function () {
    $ctx = food99Context();
    $info = food99OrderInfo($ctx);

    receiveFood99Order($this, $ctx, $info);
    receiveFood99Order($this, $ctx, $info);

    expect(Order::withoutGlobalScopes()->where('channel', '99food')->count())->toBe(1);
});

it('busca o detalhe na 99 quando o callback vem sem order_info completo', function () {
    $ctx = food99Context();
    $info = food99OrderInfo($ctx);
    Http::fake(['*/v1/order/order/detail*' => Http::response(food99Ok($info), 200)]);

    postFood99Webhook($this, food99Callback($ctx['integration'], 'orderNew', ['order_id' => $info['order_id']]))->assertOk();

    Http::assertSent(fn ($r) => str_contains($r->url(), '/v1/order/order/detail') && $r['order_id'] === '5764607523034934815');
    expect(Order::withoutGlobalScopes()->where('channel', '99food')->sole()->external_order_id)->toBe('5764607523034934815');
});

it('mantém o pedido na empresa dona da loja', function () {
    $a = food99Context('A');
    $b = food99Context('B');

    $order = receiveFood99Order($this, $b, food99OrderInfo($b));

    expect($order->company_id)->toBe($b['company']->id)
        ->and(Order::withoutGlobalScopes()->where('company_id', $a['company']->id)->count())->toBe(0)
        ->and(Customer::withoutGlobalScopes()->where('company_id', $a['company']->id)->count())->toBe(0);
});

it('reaproveita o cadastro do cliente pelo uid da 99 dentro da empresa', function () {
    $ctx = food99Context();
    receiveFood99Order($this, $ctx, food99OrderInfo($ctx));
    receiveFood99Order($this, $ctx, food99OrderInfo($ctx, overrides: ['order_id' => '5764607523034939999', 'order_index' => 20]));

    expect(Customer::withoutGlobalScopes()->where('food99_customer_id', '650000000000123456')->count())->toBe(1);
});

it('avisa a loja quando o pedido não entra depois de todas as tentativas', function () {
    Queue::fake();
    $ctx = food99Context();
    Http::fake(['*' => Http::response(food99Error(10100, 'boom'), 200)]);

    postFood99Webhook($this, food99Callback($ctx['integration'], 'orderNew', ['order_id' => '5764607523034930000']))->assertOk();
    $event = Food99WebhookEvent::sole();

    for ($i = 0; $i < ProcessFood99EventJob::MAX_ATTEMPTS; $i++) {
        try {
            (new ProcessFood99EventJob($event->id))->handle();
        } catch (Throwable) {
        }
    }

    expect($event->fresh()->status)->toBe('failed')
        ->and(Order::withoutGlobalScopes()->count())->toBe(0)
        ->and(CompanyNotification::withoutGlobalScopes()->where('type', 'food99_alert')->sole()->subtitle)->toContain('app da 99Food');
});
