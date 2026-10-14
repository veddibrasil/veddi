<?php

use App\Contracts\OrderServiceInterface;
use App\Livewire\Admin\Orders\Show as OrdersShow;
use App\Models\Customer;
use App\Models\IfoodOrderEvent;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

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
        // Entra aguardando aceite: o iFood cancela sozinho se a loja não confirmar no prazo.
        ->and($order->status)->toBe('pending')
        ->and($order->status_label)->toBe('Aguardando aceite');

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

test('item sem vínculo entra como avulso pelo preço do iFood, sem baixa de estoque; item ligado segue o cadastro', function () {
    ['branch' => $branch, 'product' => $product, 'integration' => $integration] = ifoodContext('job3');
    DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $product->id)
        ->update(['track_stock' => true, 'quantity' => 10]);

    // Item 1 ligado (2 × R$ 8,00 do cadastro); item 2 só existe no cardápio do iFood.
    $payload = ifoodOrderDetailsPayload('ifood-order-job3', $integration->merchant_id, 'ifood-item-coxinha-job3', 2);
    $payload['items'][] = [
        'id' => 'item-nao-cadastrado',
        'name' => 'Combo 5 Salgados',
        'quantity' => 1,
        'unitPrice' => 12.00,
        'optionsPrice' => 0.0,
        'totalPrice' => 12.00,
        'observations' => 'Sem cebola',
        'options' => [
            ['id' => 'op-coxinha', 'groupName' => 'Sabores', 'name' => 'Coxinha', 'quantity' => 5, 'unitPrice' => 0],
        ],
    ];
    $payload['total']['subTotal'] = 28.00;
    $payload['total']['orderAmount'] = 33.00;
    fakeIfoodApi($payload);

    $event = IfoodOrderEvent::create([
        'event_id' => 'evt-job-3',
        'event_type' => 'PLC',
        'source' => 'polling',
        'ifood_integration_id' => $integration->id,
        'payload' => ['orderId' => 'ifood-order-job3', 'merchantId' => $integration->merchant_id],
        'status' => 'pending',
    ]);

    runIfoodOrderJob($event->id);

    $event->refresh();
    expect($event->status)->toBe('processed')
        ->and($event->error)->toBeNull();

    $order = Order::withoutGlobalScopes()->find($event->order_id);
    expect((float) $order->subtotal)->toBe(28.0)
        ->and((float) $order->total)->toBe(33.0)
        ->and((float) Payment::where('order_id', $order->id)->sum('amount'))->toBe(33.0);

    [$linked, $unlinked] = $order->items()->orderBy('id')->get()->all();
    expect($linked->product_id)->toBe($product->id)
        ->and((float) $linked->subtotal)->toBe(16.0)
        ->and($unlinked->product_id)->toBeNull()
        ->and($unlinked->product_name)->toBe('Combo 5 Salgados')
        ->and((float) $unlinked->unit_price)->toBe(12.0)
        ->and((float) $unlinked->subtotal)->toBe(12.0)
        ->and($unlinked->notes)->toBe('Sem cebola')
        // toEqual: coluna JSON do MySQL reordena as chaves.
        ->and($unlinked->options)->toEqual([
            ['group_name' => 'Sabores', 'selections' => [['name' => 'Coxinha', 'qty' => 5, 'additional_price' => 0]]],
        ]);

    // Só o item ligado baixa estoque.
    expect(DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $product->id)->value('quantity'))->toBe(8);

    // Tela do pedido e comanda mostram o item avulso com os complementos do iFood.
    $admin = User::factory()->create(['is_super_admin' => true]);
    Livewire::actingAs($admin)->test(OrdersShow::class, ['order' => $order])
        ->assertSee('Combo 5 Salgados')
        ->assertSee('Sabores:')
        ->assertSee('5× Coxinha');
    $receipt = view('livewire.admin.orders.receipt', [
        'order' => $order->fresh(['items', 'customer', 'branch', 'payment', 'coupon']),
        'company' => $order->company,
    ])->render();
    expect($receipt)->toContain('Combo 5 Salgados')->toContain('5x Coxinha');
});

test('marcador de item avulso só vale no canal iFood: no chat o preço continua vindo do cadastro', function () {
    ['company' => $company, 'branch' => $branch, 'product' => $product] = ifoodContext('job-avulso-chat');
    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Cliente Chat',
        'phone' => '11999990000',
    ]);
    $service = app(OrderServiceInterface::class);

    $order = $service->createOrder($customer->id, $branch->id, [
        (string) $product->id => ['product_id' => $product->id, 'unlinked' => true, 'qty' => 1, 'name' => 'Coxinha', 'unit_price' => 0.01, 'subtotal' => 0.01],
    ], '', 'pix', 'pickup');

    expect((float) $order->subtotal)->toBe(8.0)
        ->and((float) $order->items->first()->unit_price)->toBe(8.0);

    expect(fn () => $service->createOrder($customer->id, $branch->id, [
        'x' => ['product_id' => null, 'unlinked' => true, 'qty' => 1, 'name' => 'Grátis', 'unit_price' => 0.01, 'subtotal' => 0.01],
    ], '', 'pix', 'pickup'))->toThrow(RuntimeException::class);
});

test('evento CAN cancela pedido existente e dispara OrderStatusUpdated', function () {
    \Illuminate\Support\Facades\Event::fake([\App\Events\OrderStatusUpdated::class]);

    $ctx = ifoodContext('job5');
    $order = ifoodKanbanOrder($ctx, 'preparing', 'ifood-order-job5');

    $event = IfoodOrderEvent::create([
        'event_id' => 'evt-job-5',
        'event_type' => 'CAN',
        'source' => 'webhook',
        'ifood_integration_id' => $ctx['integration']->id,
        'payload' => ['orderId' => 'ifood-order-job5', 'merchantId' => $ctx['integration']->merchant_id],
        'status' => 'pending',
    ]);

    runIfoodOrderJob($event->id);

    $event->refresh();
    $order->refresh();

    expect($event->status)->toBe('processed')
        ->and($event->order_id)->toBe($order->id)
        ->and($order->status)->toBe('cancelled');

    \Illuminate\Support\Facades\Event::assertDispatched(\App\Events\OrderStatusUpdated::class);
});

test('evento CAN em pedido já cancelado é idempotente', function () {
    $ctx = ifoodContext('job6');
    $order = ifoodKanbanOrder($ctx, 'cancelled', 'ifood-order-job6');

    $event = IfoodOrderEvent::create([
        'event_id' => 'evt-job-6',
        'event_type' => 'CAN',
        'source' => 'webhook',
        'ifood_integration_id' => $ctx['integration']->id,
        'payload' => ['orderId' => 'ifood-order-job6', 'merchantId' => $ctx['integration']->merchant_id],
        'status' => 'pending',
    ]);

    runIfoodOrderJob($event->id);

    $event->refresh();
    $order->refresh();

    expect($event->status)->toBe('processed')
        ->and($order->status)->toBe('cancelled');
});

test('evento CAN para pedido inexistente localmente apenas marca processado', function () {
    ['integration' => $integration] = ifoodContext('job7');

    $event = IfoodOrderEvent::create([
        'event_id' => 'evt-job-7',
        'event_type' => 'CAN',
        'source' => 'webhook',
        'ifood_integration_id' => $integration->id,
        'payload' => ['orderId' => 'ifood-order-nao-existe', 'merchantId' => $integration->merchant_id],
        'status' => 'pending',
    ]);

    runIfoodOrderJob($event->id);

    $event->refresh();
    expect($event->status)->toBe('processed')
        ->and($event->order_id)->toBeNull();
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

test('inclui taxas adicionais e benefícios do iFood no total e no pagamento', function () {
    ['integration' => $integration] = ifoodContext('fees');
    $payload = ifoodOrderDetailsPayload('order-fees', $integration->merchant_id, 'ifood-item-coxinha-fees', 2);
    $payload['total']['additionalFees'] = 1;
    $payload['total']['benefits'] = 2;
    fakeIfoodApi($payload);
    $event = IfoodOrderEvent::create(['event_id' => 'evt-fees', 'event_type' => 'PLC', 'source' => 'polling', 'ifood_integration_id' => $integration->id, 'payload' => ['orderId' => 'order-fees'], 'status' => 'pending']);
    runIfoodOrderJob($event->id);
    $order = Order::withoutGlobalScopes()->findOrFail($event->fresh()->order_id);
    expect((float) $order->service_fee)->toBe(1.0)
        ->and((float) $order->manual_discount)->toBe(2.0)
        ->and((float) $order->total)->toBe((float) $order->subtotal + (float) $order->delivery_fee - 1.0)
        ->and((float) $order->payments()->first()->amount)->toBe((float) $order->total);
});

test('CON conclui pedido e registra histórico sem duplicar ao receber outro evento de conclusão', function () {
    \Illuminate\Support\Facades\Event::fake([\App\Events\OrderStatusUpdated::class]);
    $ctx = ifoodContext('con');
    $order = ifoodKanbanOrder($ctx, 'out_for_delivery', 'order-con');
    foreach (['evt-con-1', 'evt-con-2'] as $id) {
        $event = IfoodOrderEvent::create(['event_id' => $id, 'event_type' => 'CON', 'source' => 'polling', 'ifood_integration_id' => $ctx['integration']->id, 'payload' => ['orderId' => 'order-con'], 'status' => 'pending']);
        runIfoodOrderJob($event->id);
        expect($event->fresh()->order_id)->toBe($order->id)->and($event->fresh()->status)->toBe('processed');
    }
    expect($order->fresh()->status)->toBe('delivered')->and($order->fresh()->status_label)->toBe('Concluído');
    expect(\App\Models\OrderStatusHistory::withoutGlobalScopes()->where('order_id', $order->id)->count())->toBe(1);
    \Illuminate\Support\Facades\Event::assertDispatchedTimes(\App\Events\OrderStatusUpdated::class, 1);
});

test('CON atrasado não reabre pedido cancelado', function () {
    $ctx = ifoodContext('con-cancel');
    $order = ifoodKanbanOrder($ctx, 'cancelled', 'order-con-cancel');
    $event = IfoodOrderEvent::create(['event_id' => 'evt-con-cancel', 'event_type' => 'CON', 'source' => 'polling', 'ifood_integration_id' => $ctx['integration']->id, 'payload' => ['orderId' => 'order-con-cancel'], 'status' => 'pending']);
    runIfoodOrderJob($event->id);
    expect($order->fresh()->status)->toBe('cancelled');
});
