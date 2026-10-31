<?php

use App\Events\OrderStatusUpdated;
use App\Exceptions\Food99ApiException;
use App\Jobs\ProcessFood99EventJob;
use App\Livewire\Admin\Orders\Index as OrdersIndex;
use App\Livewire\Admin\Orders\Show as OrdersShow;
use App\Models\CompanyNotification;
use App\Models\Food99WebhookEvent;
use App\Models\User;
use App\Services\Food99\Food99OrderActionService;
use App\Services\Food99\Food99OrderStatusSync;
use App\Services\Order\OrderService;
use App\Services\Order\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function food99Actions(): Food99OrderActionService
{
    return app(Food99OrderActionService::class);
}

it('aceita, marca pronto e conclui a entrega 99 pelos callbacks', function () {
    $ctx = food99Context();
    $info = food99OrderInfo($ctx);
    fakeFood99Api(array_merge($info, ['status' => 200, 'expected_cook_eta' => now()->addMinutes(15)->getTimestamp()]));
    $order = receiveFood99Order($this, $ctx, $info);

    food99Actions()->accept($order);
    expect($order->fresh()->status)->toBe('preparing')
        ->and($order->fresh()->food99Details()->stage())->toBe('CONFIRMED')
        ->and($order->fresh()->food99Details()->get('expected_cook_eta'))->not->toBeNull();
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/order/order/confirm') && str_contains($r->body(), '"order_id":5764607523034934815'));

    food99Actions()->markReady($order->fresh());
    expect($order->fresh()->status)->toBe('ready')
        ->and($order->fresh()->food99Details()->waitingMessage())->toContain('entregador da 99Food');

    postFood99Webhook($this, food99Callback($ctx['integration'], 'deliveryStatus', [
        'order_id' => $info['order_id'], 'delivery_status' => 140, 'rider_name' => 'Carlos', 'rider_phone' => '11999990000',
    ]))->assertOk();
    expect($order->fresh()->status)->toBe('out_for_delivery')
        ->and($order->fresh()->food99Details()->rider()['name'])->toBe('Carlos');

    postFood99Webhook($this, food99Callback($ctx['integration'], 'orderFinish', ['order_id' => $info['order_id']]))->assertOk();
    expect($order->fresh()->status)->toBe('delivered')
        ->and($order->fresh()->status_label)->toBe('Concluído');
});

it('aceita pelo callback quando a loja confirma no app da 99', function () {
    $ctx = food99Context();
    $info = food99OrderInfo($ctx);
    fakeFood99Api($info);
    $order = receiveFood99Order($this, $ctx, $info);

    postFood99Webhook($this, food99Callback($ctx['integration'], 'orderConfirm', ['order_id' => $info['order_id']]))->assertOk();

    expect($order->fresh()->status)->toBe('preparing');
});

it('nunca volta de etapa com callback atrasado', function () {
    $ctx = food99Context();
    $info = food99OrderInfo($ctx);
    fakeFood99Api($info);
    $order = receiveFood99Order($this, $ctx, $info);

    postFood99Webhook($this, food99Callback($ctx['integration'], 'orderFinish', ['order_id' => $info['order_id']]))->assertOk();
    postFood99Webhook($this, food99Callback($ctx['integration'], 'orderReady', ['order_id' => $info['order_id']], ['timestamp' => now()->getTimestamp() + 5]))->assertOk();

    expect($order->fresh()->status)->toBe('delivered');
});

it('despacha e entrega com código na entrega própria, quitando o dinheiro', function () {
    $ctx = food99Context();
    $info = food99OrderInfo($ctx, 'order-store-delivery-cash');
    fakeFood99Api($info);
    $order = receiveFood99Order($this, $ctx, $info);

    food99Actions()->accept($order);
    food99Actions()->dispatch($order->fresh(), courierName: 'Zé', courierPhone: '(62) 99999-0000');
    expect($order->fresh()->status)->toBe('out_for_delivery');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/selfdelivery/dispatch')
        && $r['courier_info'] === [
            'courier_name' => 'Zé',
            'courier_first_name' => 'Zé',
            'courier_last_name' => 'Zé',
            'courier_phone_code' => '+55',
            'courier_phone' => '62999990000',
        ]
        && $r['limit_time']['delivery_time'] > now()->getTimestamp());

    food99Actions()->markDelivered($order->fresh(), deliveryCode: '1234');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/selfdelivery/verifyDeliveryCode') && $r['delivery_code'] === '1234');
    Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/order/order/finish'));
    expect($order->fresh()->status)->toBe('delivered')
        ->and($order->fresh()->payments->pluck('status')->all())->toBe(['paid']);
});

it('conclui na 99 a entrega própria sem código', function () {
    $ctx = food99Context();
    $ctx['branch']->update(['phone' => '(62) 3999-0000']);
    $info = food99OrderInfo($ctx, 'order-store-delivery-cash');
    fakeFood99Api($info);
    $order = receiveFood99Order($this, $ctx, $info);
    food99Actions()->accept($order);
    food99Actions()->dispatch($order->fresh());

    food99Actions()->markDelivered($order->fresh());

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/selfdelivery/delivered'));
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/order/order/finish'));
    expect($order->fresh()->status)->toBe('delivered');
});

it('não marca entregue no VEDDI se a 99 recusar a conclusão', function () {
    $ctx = food99Context();
    $ctx['branch']->update(['phone' => '(62) 3999-0000']);
    $info = food99OrderInfo($ctx, 'order-store-delivery-cash');
    fakeFood99Api($info, ['*/v1/order/order/finish' => Http::response(food99Error(12014, 'status inválido'), 200)]);
    $order = receiveFood99Order($this, $ctx, $info);
    food99Actions()->accept($order);
    food99Actions()->dispatch($order->fresh());

    expect(fn () => food99Actions()->markDelivered($order->fresh()))->toThrow(Food99ApiException::class)
        ->and($order->fresh()->status)->toBe('out_for_delivery');
});

it('despacha com o telefone da filial e separa nome e sobrenome do entregador padrão', function () {
    $ctx = food99Context();
    $ctx['branch']->update(['phone' => '(62) 3999-0000']);
    $info = food99OrderInfo($ctx, 'order-store-delivery-cash');
    fakeFood99Api($info);
    $order = receiveFood99Order($this, $ctx, $info);

    food99Actions()->accept($order);
    food99Actions()->dispatch($order->fresh());

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/selfdelivery/dispatch')
        && $r['courier_info']['courier_name'] === 'Entregador da loja'
        && $r['courier_info']['courier_first_name'] === 'Entregador'
        && $r['courier_info']['courier_last_name'] === 'da loja'
        && $r['courier_info']['courier_phone'] === '6239990000');
});

it('explica que falta telefone em vez de mandar despacho que a 99 recusa', function () {
    $ctx = food99Context();
    $info = food99OrderInfo($ctx, 'order-store-delivery-cash');
    fakeFood99Api($info);
    $order = receiveFood99Order($this, $ctx, $info);

    food99Actions()->accept($order);

    expect(fn () => food99Actions()->dispatch($order->fresh()))->toThrow(RuntimeException::class, 'telefone da filial');
    Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/selfdelivery/dispatch'));
});

it('recusa despacho de entrega 99 pela loja', function () {
    $ctx = food99Context();
    $info = food99OrderInfo($ctx);
    fakeFood99Api($info);
    $order = receiveFood99Order($this, $ctx, $info);
    food99Actions()->accept($order);

    expect(fn () => food99Actions()->dispatch($order->fresh()))->toThrow(RuntimeException::class, 'entrega deste pedido é da 99Food');
});

it('conclui retirada avisando pronto antes e conferindo o código', function () {
    $ctx = food99Context();
    $info = food99OrderInfo($ctx, 'order-pickup');
    fakeFood99Api($info);
    $order = receiveFood99Order($this, $ctx, $info);
    food99Actions()->accept($order);

    expect(fn () => food99Actions()->finishPickup($order->fresh(), takeawayCode: 'ERRADO'))->toThrow(InvalidArgumentException::class);

    Http::fake(['*' => Http::response(food99Ok(), 200)]);
    food99Actions()->finishPickup($order->fresh(), takeawayCode: 'abcd');

    expect(food99Calls())->toBe(['/v1/order/order/ready', '/v1/order/order/finish'])
        ->and($order->fresh()->status)->toBe('delivered');
});

it('cancela localmente com o motivo 9xx do detalhe e devolve o estoque', function () {
    $ctx = food99Context();
    DB::table('branch_product')->where('product_id', $ctx['product']->id)->update(['track_stock' => true, 'quantity' => 10]);
    $info = food99OrderInfo($ctx);
    fakeFood99Api(array_replace_recursive($info, ['status' => 901, 'price' => ['refund_price' => 1800]]));
    $order = receiveFood99Order($this, $ctx, $info);
    expect(DB::table('branch_product')->where('product_id', $ctx['product']->id)->value('quantity'))->toBe(8);

    postFood99Webhook($this, food99Callback($ctx['integration'], 'orderCancel', ['order_id' => $info['order_id']]))->assertOk();

    $order->refresh();
    expect($order->status)->toBe('cancelled')
        ->and($order->cancellation_reason)->toContain('Cancelado pelo cliente')
        ->and($order->food99Details()->cancellation()['refund'])->toEqual(18.0)
        ->and(DB::table('branch_product')->where('product_id', $ctx['product']->id)->value('quantity'))->toBe(10);
});

it('valida o motivo de recusa pelo tipo de entrega e exige texto no "outro"', function () {
    $ctx = food99Context();
    $info = food99OrderInfo($ctx);
    fakeFood99Api($info);
    $order = receiveFood99Order($this, $ctx, $info);

    expect(fn () => food99Actions()->cancel($order, 1060))->toThrow(InvalidArgumentException::class)
        ->and(fn () => food99Actions()->cancel($order, 1080, 'x'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => food99Actions()->cancel($order, 9999))->toThrow(InvalidArgumentException::class);

    food99Actions()->cancel($order, 1020);

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/order/order/cancel') && $r['reason_id'] === 1020 && $r['reason'] === 'Loja fechada');
    expect($order->fresh()->status)->toBe('cancelled');
});

it('confirma o dinheiro recebido do entregador 99', function () {
    $ctx = food99Context();
    $info = food99OrderInfo($ctx, overrides: ['pay_method' => 2, 'pay_channel' => 153]);
    fakeFood99Api(array_merge($info, ['shop_paid_money' => 1700]));
    $order = receiveFood99Order($this, $ctx, $info);
    expect($order->payments()->sole()->status)->toBe('pending');

    food99Actions()->accept($order);
    postFood99Webhook($this, food99Callback($ctx['integration'], 'deliveryStatus', ['order_id' => $info['order_id'], 'delivery_status' => 120]))->assertOk();
    $order->refresh();
    expect($order->food99Details()->get('shop_paid_money'))->toEqual(17.0)
        ->and((float) $order->payments()->sole()->amount)->toBe(17.0)
        ->and($order->food99Details()->canConfirmCashPayment())->toBeTrue();

    food99Actions()->confirmCashPayment($order);

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/order/order/payConfirm'));
    expect($order->payments()->sole()->status)->toBe('paid')
        ->and($order->fresh()->food99Details()->canConfirmCashPayment())->toBeFalse();
});

it('cancela itens parcialmente pela linha da 99', function () {
    $ctx = food99Context();
    $info = food99OrderInfo($ctx);
    fakeFood99Api($info);
    $order = receiveFood99Order($this, $ctx, $info);

    food99Actions()->partialCancel($order, ['7000000000000000001' => 1], 1);

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/partialCancel')
        && json_decode($r['items'], true) === [['app_item_id' => 'veddi-p'.$ctx['product']->id, 'mdu_id' => '7000000000000000001', 'cancel_amount' => 1]]);
    expect($order->fresh()->food99Details()->get('partial_cancellations'))->toHaveCount(1)
        ->and(fn () => food99Actions()->partialCancel($order->fresh(), ['7000000000000000001' => 2, '7000000000000000002' => 1]))
        ->toThrow(InvalidArgumentException::class, 'cancele o pedido inteiro');
});

it('registra o cancelamento parcial da 99 uma vez só, com o reembolso de price', function () {
    $ctx = food99Context();
    $info = food99OrderInfo($ctx);
    fakeFood99Api(array_replace_recursive($info, [
        'price' => ['refund_price' => 500, 'in_sale_refund_to_c_fee' => 50],
        'order_items' => [['submit_refund_amount' => 1]],
    ]));
    $order = receiveFood99Order($this, $ctx, $info);

    foreach ([1, 2] as $delay) {
        postFood99Webhook($this, food99Callback($ctx['integration'], 'orderPartialCancel', ['order_id' => $info['order_id']], [
            'timestamp' => now()->getTimestamp() + $delay,
        ]))->assertOk();
    }

    $history = $order->fresh()->food99Details()->get('partial_cancellations');
    expect($history)->toHaveCount(1)
        ->and($history[0]['refund'])->toEqual(5.0)
        ->and($history[0]['refund_fee'])->toEqual(0.5)
        ->and($history[0]['items'][0]['amount'])->toBe(1)
        ->and(CompanyNotification::withoutGlobalScopes()->where('title', 'like', 'Itens cancelados%')->count())->toBe(1);
});

it('espera o limite da 99 em vez de cancelar sem o motivo', function () {
    $ctx = food99Context();
    $info = food99OrderInfo($ctx);
    $limited = false;
    Http::fake(function (Request $request) use (&$limited, $info) {
        if (! str_contains($request->url(), '/v1/order/order/detail')) {
            return Http::response(food99Ok(), 200);
        }

        return Http::response($limited ? food99Error(10005) : food99Ok(array_merge($info, ['status' => 901])), 200);
    });
    $order = receiveFood99Order($this, $ctx, $info);

    $limited = true;
    postFood99Webhook($this, food99Callback($ctx['integration'], 'orderCancel', ['order_id' => $info['order_id']]))->assertOk();

    $event = Food99WebhookEvent::where('event_type', 'orderCancel')->sole();
    expect($order->fresh()->status)->not->toBe('cancelled')
        ->and($event->status)->toBe(Food99WebhookEvent::STATUS_PENDING);

    $limited = false;
    (new ProcessFood99EventJob($event->id))->handle();

    expect($order->fresh()->status)->toBe('cancelled')
        ->and($order->fresh()->cancellation_reason)->toContain('Cancelado pelo cliente');
});

it('não deixa o pedido cancelado com o estoque ainda baixado', function () {
    $ctx = food99Context();
    DB::table('branch_product')->where('product_id', $ctx['product']->id)->update(['track_stock' => true, 'quantity' => 10]);
    $info = food99OrderInfo($ctx);
    fakeFood99Api($info);
    $order = receiveFood99Order($this, $ctx, $info);
    $sync = app(Food99OrderStatusSync::class);

    $this->mock(StockService::class, fn ($mock) => $mock->shouldReceive('restoreForOrder')->once()->andThrow(new RuntimeException('falha no estoque')));
    expect(fn () => $sync->cancel($order, ['code' => 901]))->toThrow(RuntimeException::class, 'falha no estoque')
        ->and($order->fresh()->status)->not->toBe('cancelled');

    $this->app->forgetInstance(StockService::class);
    $sync->cancel($order->fresh(), ['code' => 901]);

    expect($order->fresh()->status)->toBe('cancelled')
        ->and(DB::table('branch_product')->where('product_id', $ctx['product']->id)->value('quantity'))->toBe(10);
});

it('não cancela pedido de marketplace só no VEDDI', function () {
    $ctx = food99Context();
    $info = food99OrderInfo($ctx);
    fakeFood99Api($info);
    $order = receiveFood99Order($this, $ctx, $info);

    expect(fn () => app(OrderService::class)->cancelOrderAsAdmin($order, null, 'Cliente desistiu'))->toThrow(RuntimeException::class, 'marketplace')
        ->and($order->fresh()->status)->not->toBe('cancelled');
});

it('propaga pronto à 99 quando o status muda fora das ações', function () {
    $ctx = food99Context();
    $info = food99OrderInfo($ctx);
    fakeFood99Api($info);
    $order = receiveFood99Order($this, $ctx, $info);
    food99Actions()->accept($order);
    Http::fake(['*' => Http::response(food99Ok(), 200)]);

    $order = $order->fresh();
    $order->update(['status' => 'ready']);
    OrderStatusUpdated::dispatch($order);

    expect(food99Calls())->toBe(['/v1/order/order/ready'])
        ->and($order->fresh()->food99Details()->stage())->toBe('READY');
});

it('opera o pedido 99Food pelo kanban: aceite pela API e cancelamento pelo modal', function () {
    $ctx = food99Context();
    $info = food99OrderInfo($ctx);
    fakeFood99Api($info);
    $order = receiveFood99Order($this, $ctx, $info);
    $this->actingAs(User::factory()->create(['is_super_admin' => true]));

    Livewire::test(OrdersIndex::class)
        ->call('acceptMarketplaceOrder', $order->id)
        ->call('updateOrderStatus', $order->id, 'cancelled')
        ->assertSet('food99CancelOrderId', $order->id)
        ->assertSet('food99CancelAccepted', true)
        ->set('food99CancelReason', '1030')
        ->call('confirmFood99Cancel')
        ->assertSet('food99CancelOrderId', null);

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/order/order/confirm'));
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/order/order/cancel') && $r['reason_id'] === 1030);
    expect($order->fresh()->status)->toBe('cancelled');
});

it('mostra erro da 99 na tela sem mudar o status local', function () {
    $ctx = food99Context();
    Http::fake(['*' => Http::response(food99Error(14001, 'order status invalid'), 200)]);
    $order = receiveFood99Order($this, $ctx, food99OrderInfo($ctx));
    $this->actingAs(User::factory()->create(['is_super_admin' => true]));

    Livewire::test(OrdersShow::class, ['order' => $order])
        ->call('updateStatus', 'preparing');

    expect($order->fresh()->status)->toBe('pending')
        ->and($order->fresh()->food99Details()->stage())->toBe('CREATED');
});

it('não deixa cozinha recusar pedido 99Food pelo kanban', function () {
    $ctx = food99Context();
    $info = food99OrderInfo($ctx);
    fakeFood99Api($info);
    $order = receiveFood99Order($this, $ctx, $info);
    $cook = User::factory()->create();
    $cook->companies()->attach($ctx['company']->id, ['role' => 'cozinha', 'branch_id' => $ctx['branch']->id]);
    app()->instance('current.company', $ctx['company']);

    Livewire::actingAs($cook)->test(OrdersIndex::class)
        ->set('food99CancelOrderId', $order->id)
        ->set('food99CancelReason', '1020')
        ->call('confirmFood99Cancel')
        ->assertForbidden();

    expect($order->fresh()->status)->toBe('pending');
    Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/v1/order/order/cancel'));
});
