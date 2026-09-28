<?php

use App\Contracts\IfoodGatewayContract;
use App\Jobs\PropagateIfoodStatusJob;
use App\Livewire\Admin\Orders\Index as OrdersIndex;
use App\Livewire\Admin\Orders\Show as OrdersShow;
use App\Models\CompanyNotification;
use App\Models\IfoodOrderEvent;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;
use App\Services\Ifood\IfoodOrderActionService;
use App\Services\Ifood\IfoodOrderPollingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/** Pedido iFood com snapshot mínimo (etapa, tipo e entregador). */
function ifoodFlowOrder(array $ctx, string $status, array $metadata = [], string $externalOrderId = 'ifood-flow-1'): Order
{
    $order = ifoodKanbanOrder($ctx, $status, $externalOrderId);
    $order->update(['external_metadata' => array_merge([
        'display_id' => '1846',
        'order_type' => 'DELIVERY',
        'order_timing' => 'IMMEDIATE',
        'delivered_by' => 'MERCHANT',
        'ifood_status' => 'PLACED',
        'created_at' => now()->toIso8601String(),
    ], $metadata)]);

    return $order->fresh();
}

function ifoodFlowEvent(array $ctx, string $type, string $orderId, array $metadata = [], string $suffix = ''): IfoodOrderEvent
{
    $event = IfoodOrderEvent::create([
        'event_id' => "evt-{$type}-{$orderId}{$suffix}",
        'event_type' => $type,
        'source' => 'polling',
        'ifood_integration_id' => $ctx['integration']->id,
        'payload' => ['orderId' => $orderId, 'metadata' => $metadata, 'createdAt' => now()->utc()->toIso8601String()],
        'status' => 'pending',
    ]);

    runIfoodOrderJob($event->id);

    return $event->fresh();
}

function ifoodGatewayMock(): Mockery\MockInterface
{
    $gateway = Mockery::mock(IfoodGatewayContract::class);
    $gateway->shouldReceive('getCancellationReasons')->andReturn([
        ['code' => '501', 'description' => 'Problemas de sistema'],
        ['code' => '503', 'description' => 'Item indisponível'],
    ]);
    app()->instance(IfoodGatewayContract::class, $gateway);

    return $gateway;
}

test('confirmação, pronto e despacho feitos fora do VEDDI atualizam o pedido local', function () {
    $ctx = ifoodContext('flow1');
    $order = ifoodFlowOrder($ctx, 'pending');

    expect(ifoodFlowEvent($ctx, 'CFM', 'ifood-flow-1')->status)->toBe('processed');
    expect($order->fresh()->status)->toBe('preparing')
        ->and($order->fresh()->ifoodDetails()->status())->toBe('CONFIRMED');

    ifoodFlowEvent($ctx, 'RTP', 'ifood-flow-1');
    expect($order->fresh()->status)->toBe('ready');

    ifoodFlowEvent($ctx, 'DSP', 'ifood-flow-1');
    expect($order->fresh()->status)->toBe('out_for_delivery');

    // CFM atrasado/repetido nunca faz o pedido voltar de etapa.
    ifoodFlowEvent($ctx, 'CFM', 'ifood-flow-1', [], '-late');
    expect($order->fresh()->status)->toBe('out_for_delivery')
        ->and($order->fresh()->ifoodDetails()->status())->toBe('DISPATCHED');

    $history = OrderStatusHistory::withoutGlobalScopes()->where('order_id', $order->id)->orderBy('id')->get();
    expect($history->pluck('to_status')->all())->toBe(['preparing', 'ready', 'out_for_delivery'])
        ->and($history->first()->metadata['source'])->toBe('ifood_event');
});

test('evento de etapa não chama a API do iFood de volta', function () {
    $ctx = ifoodContext('flow2');
    $order = ifoodFlowOrder($ctx, 'preparing', ['ifood_status' => 'CONFIRMED']);

    ifoodFlowEvent($ctx, 'RTP', 'ifood-flow-1');

    $gateway = Mockery::mock(IfoodGatewayContract::class);
    $gateway->shouldNotReceive('updateOrderStatus');
    (new PropagateIfoodStatusJob($order->id, 'ready'))->handle($gateway);
});

test('pedido agendado confirmado continua agendado até iniciar o preparo', function () {
    $ctx = ifoodContext('flow3');
    $order = ifoodFlowOrder($ctx, 'scheduled', ['order_timing' => 'SCHEDULED']);

    ifoodFlowEvent($ctx, 'CFM', 'ifood-flow-1');
    expect($order->fresh()->status)->toBe('scheduled')
        ->and($order->fresh()->ifoodDetails()->canStartPreparation())->toBeTrue();

    ifoodFlowEvent($ctx, 'PRS', 'ifood-flow-1');
    expect($order->fresh()->status)->toBe('preparing');
});

test('cancelamento recusado pelo iFood (CARF) aparece no pedido e avisa a loja', function () {
    $ctx = ifoodContext('flow4');
    $order = ifoodFlowOrder($ctx, 'preparing', ['ifood_status' => 'CONFIRMED']);
    $gateway = ifoodGatewayMock();
    $gateway->shouldReceive('requestCancellation')->once();

    app(IfoodOrderActionService::class)->requestCancellation($order, '501');
    expect($order->fresh()->ifoodDetails()->cancellationRequest())->toMatchArray(['status' => 'requested', 'reason' => 'Problemas de sistema']);

    ifoodFlowEvent($ctx, 'CAR', 'ifood-flow-1');
    ifoodFlowEvent($ctx, 'CARF', 'ifood-flow-1', ['CANCELLATION_REQUEST_FAILED_REASON' => 'Pedido já saiu para entrega']);

    $order->refresh();
    expect($order->status)->toBe('preparing')
        ->and($order->ifoodDetails()->cancellationRequest())->toMatchArray(['status' => 'failed', 'failure_reason' => 'Pedido já saiu para entrega']);

    $notification = CompanyNotification::where('company_id', $ctx['company']->id)->where('type', 'ifood')->first();
    expect($notification->title)->toContain('recusou o cancelamento')
        ->and($notification->subtitle)->toContain('Pedido já saiu para entrega');

    $this->actingAs(User::factory()->create(['is_super_admin' => true]));
    Livewire::test(OrdersShow::class, ['order' => $order])
        ->assertSee('O iFood recusou o cancelamento. O pedido continua ativo.')
        ->assertSee('Pedido já saiu para entrega');
});

test('cancelamento confirmado (CAN) grava origem e motivo e devolve o estoque', function () {
    $ctx = ifoodContext('flow5');
    $order = ifoodFlowOrder($ctx, 'preparing', ['ifood_status' => 'CONFIRMED']);

    ifoodFlowEvent($ctx, 'CAN', 'ifood-flow-1', [
        'CANCEL_ORIGIN' => 'CONSUMER', 'CANCEL_CODE' => '818', 'CANCEL_CODE_DESCRIPTION' => 'Cliente desistiu',
    ]);

    $order->refresh();
    expect($order->status)->toBe('cancelled')
        ->and($order->cancellation_reason)->toBe('Cancelado no iFood pelo cliente: Cliente desistiu (código 818)')
        ->and($order->ifoodDetails()->status())->toBe('CANCELLED');
});

test('evento de etapa que chega antes do pedido ser criado volta pra fila', function () {
    $ctx = ifoodContext('flow6');
    IfoodOrderEvent::create([
        'event_id' => 'evt-plc-flow6', 'event_type' => 'PLC', 'source' => 'webhook',
        'ifood_integration_id' => $ctx['integration']->id, 'payload' => ['orderId' => 'ifood-flow-6'], 'status' => 'processing',
    ]);
    $event = IfoodOrderEvent::create([
        'event_id' => 'evt-cfm-flow6', 'event_type' => 'CFM', 'source' => 'webhook',
        'ifood_integration_id' => $ctx['integration']->id, 'payload' => ['orderId' => 'ifood-flow-6'], 'status' => 'pending',
    ]);

    expect(fn () => runIfoodOrderJob($event->id))->toThrow(RuntimeException::class);
    expect($event->fresh()->status)->toBe('pending')
        ->and($event->fresh()->attempts)->toBe(1);
});

test('polling reprocessa evento que ficou pendente por falha transitória', function () {
    $ctx = ifoodContext('flow7');
    $order = ifoodFlowOrder($ctx, 'pending', [], 'ifood-flow-7');
    IfoodOrderEvent::create([
        'event_id' => 'evt-cfm-flow7', 'event_type' => 'CFM', 'source' => 'polling',
        'ifood_integration_id' => $ctx['integration']->id, 'payload' => ['orderId' => 'ifood-flow-7'], 'status' => 'pending', 'attempts' => 1,
    ]);

    $gateway = Mockery::mock(IfoodGatewayContract::class);
    $gateway->shouldReceive('pollEvents')->andReturn([
        ['id' => 'evt-cfm-flow7', 'code' => 'CFM', 'orderId' => 'ifood-flow-7', 'createdAt' => now()->toIso8601String()],
    ]);
    $gateway->shouldReceive('acknowledgeEvents')->once()->with(Mockery::any(), ['evt-cfm-flow7']);
    app()->instance(IfoodGatewayContract::class, $gateway);

    app(IfoodOrderPollingService::class)->pollFor($ctx['integration']);

    expect($order->fresh()->status)->toBe('preparing');
});

test('despacho exige aviso de pronto antes', function () {
    $ctx = ifoodContext('flow8');
    $order = ifoodFlowOrder($ctx, 'preparing', ['ifood_status' => 'CONFIRMED']);
    $gateway = ifoodGatewayMock();
    $gateway->shouldNotReceive('updateOrderStatus');

    expect(fn () => app(IfoodOrderActionService::class)->dispatch($order))
        ->toThrow(RuntimeException::class, 'Marque o pedido como pronto antes de despachar');
    expect($order->fresh()->status)->toBe('preparing');
});

test('pronto e despacho chamam o iFood na ordem e avançam o pedido', function () {
    $ctx = ifoodContext('flow9');
    $order = ifoodFlowOrder($ctx, 'preparing', ['ifood_status' => 'CONFIRMED']);
    $gateway = ifoodGatewayMock();
    $gateway->shouldReceive('updateOrderStatus')->once()->ordered()->with(Mockery::any(), 'ifood-flow-1', 'ready');
    $gateway->shouldReceive('updateOrderStatus')->once()->ordered()->with(Mockery::any(), 'ifood-flow-1', 'out_for_delivery');

    app(IfoodOrderActionService::class)->markReady($order, null);
    expect($order->fresh()->status)->toBe('ready');

    app(IfoodOrderActionService::class)->dispatch($order->fresh(), null);
    expect($order->fresh()->status)->toBe('out_for_delivery')
        ->and($order->fresh()->ifoodDetails()->status())->toBe('DISPATCHED');
});

test('entrega pelo iFood e retirada não têm despacho pela loja', function (array $metadata, string $message) {
    $ctx = ifoodContext('flow10'.md5(json_encode($metadata)));
    $order = ifoodFlowOrder($ctx, 'ready', array_merge(['ifood_status' => 'READY_TO_PICKUP'], $metadata));
    $gateway = ifoodGatewayMock();
    $gateway->shouldNotReceive('updateOrderStatus');

    expect(fn () => app(IfoodOrderActionService::class)->dispatch($order))->toThrow(RuntimeException::class, $message);
    expect($order->fresh()->ifoodDetails()->waitingMessage())->not->toBeNull();
})->with([
    'entrega iFood' => [['delivered_by' => 'IFOOD'], 'A entrega deste pedido é feita pelo iFood'],
    'retirada' => [['order_type' => 'TAKEOUT', 'delivered_by' => null], 'Pedido de retirada ou consumo no local não tem despacho'],
]);

test('tela do pedido: "A caminho" antes do pronto mostra erro e não chama o iFood', function () {
    $ctx = ifoodContext('flow11');
    $order = ifoodFlowOrder($ctx, 'preparing', ['ifood_status' => 'CONFIRMED']);
    $gateway = ifoodGatewayMock();
    $gateway->shouldNotReceive('updateOrderStatus');
    $this->actingAs(User::factory()->create(['is_super_admin' => true]));

    Livewire::test(OrdersShow::class, ['order' => $order])
        ->call('updateStatus', 'out_for_delivery')
        ->assertSee('Marque o pedido como pronto antes de despachar');

    expect($order->fresh()->status)->toBe('preparing');
});

test('tela do pedido: conclusão e "pago" não são feitos pela loja em pedido iFood', function () {
    $ctx = ifoodContext('flow12');
    $order = ifoodFlowOrder($ctx, 'out_for_delivery', ['ifood_status' => 'DISPATCHED']);
    ifoodGatewayMock();
    $this->actingAs(User::factory()->create(['is_super_admin' => true]));

    Livewire::test(OrdersShow::class, ['order' => $order])
        ->call('updateStatus', 'delivered')
        ->assertHasErrors('status');

    expect($order->fresh()->status)->toBe('out_for_delivery');
});

test('tela do pedido iFood mostra informações obrigatórias e esconde ações que não se aplicam', function () {
    $ctx = ifoodContext('flow13');
    $order = ifoodFlowOrder($ctx, 'pending', [
        'pickup_code' => '0951',
        'customer_phone' => '0800 700 3021',
        'phone_localizer' => '29369477',
        'customer_document' => '12345678909',
        'delivery_observations' => 'Portão azul',
        'payment_methods' => [['method' => 'CASH', 'type' => 'OFFLINE', 'prepaid' => false, 'value' => 25.0, 'brand' => null, 'wallet' => null, 'change_for' => 50.0]],
        'pending_amount' => 25.0,
        'benefits' => [['value' => 3.0, 'target' => 'CART', 'campaign' => null, 'sponsors' => [['name' => 'IFOOD', 'value' => 3.0, 'description' => null]]]],
    ]);
    $order->payments()->create(['payment_gateway' => 'ifood', 'amount' => 25, 'status' => 'pending', 'payment_token' => 'tok-flow13']);
    $order->items()->create(['product_id' => $ctx['product']->id, 'product_name' => 'Coxinha', 'unit_price' => 10, 'quantity' => 2, 'subtotal' => 20, 'notes' => 'Sem cebola']);
    $this->actingAs(User::factory()->create(['is_super_admin' => true]));

    Livewire::test(OrdersShow::class, ['order' => $order])
        ->assertSee('#1846')
        ->assertSee('Aguardando aceite')
        ->assertSee('Aceitar pedido')
        ->assertSee('0951')
        ->assertSee('29369477')
        ->assertSee('12345678909')
        ->assertSee('Portão azul')
        ->assertSee('Troco para R$ 50,00')
        ->assertSee('Cobrar do cliente: R$ 25,00')
        ->assertSee('R$ 3,00 pago pelo iFood')
        ->assertSee('Obs: Sem cebola')
        ->assertDontSee('Iniciar Reembolso')
        ->assertDontSee('Editar itens')
        ->assertDontSee('Ag. Pagamento')
        ->assertDontSee('11999990000');
});

test('kanban com filtro iFood começa pelos pedidos aguardando aceite e alerta o prazo', function () {
    $ctx = ifoodContext('flow14');
    $order = ifoodFlowOrder($ctx, 'pending');
    $gateway = ifoodGatewayMock();
    $gateway->shouldReceive('confirmOrder')->once();
    $this->actingAs(User::factory()->create(['is_super_admin' => true]));

    Livewire::test(OrdersIndex::class)
        ->set('viewMode', 'kanban')
        ->set('channelFilter', 'ifood')
        ->assertViewHas('kanbanColumns', fn ($columns) => $columns->keys()->all() === ['pending', 'preparing', 'ready', 'out_for_delivery', 'delivered', 'cancelled'])
        ->assertSee('1 pedido iFood aguardando aceite')
        ->assertSee('iFood #1846')
        ->call('acceptIfoodOrder', $order->id);

    expect($order->fresh()->status)->toBe('preparing');
});

test('kanban: arrastar pedido iFood pra "A caminho" antes do pronto não despacha', function () {
    $ctx = ifoodContext('flow15');
    $order = ifoodFlowOrder($ctx, 'preparing', ['ifood_status' => 'CONFIRMED']);
    $gateway = ifoodGatewayMock();
    $gateway->shouldNotReceive('updateOrderStatus');
    $this->actingAs(User::factory()->create(['is_super_admin' => true]));

    Livewire::test(OrdersIndex::class)
        ->call('updateOrderStatus', $order->id, 'out_for_delivery')
        ->assertSee('Marque o pedido como pronto antes de despachar');

    expect($order->fresh()->status)->toBe('preparing');
});

test('pedido não aceito com prazo vencido não oferece aceitar nem entra no alerta', function () {
    $ctx = ifoodContext('flow16');
    $order = ifoodFlowOrder($ctx, 'pending', ['created_at' => now()->subHour()->toIso8601String()]);
    ifoodGatewayMock();
    $this->actingAs(User::factory()->create(['is_super_admin' => true]));

    expect($order->ifoodDetails()->confirmationExpired())->toBeTrue();

    Livewire::test(OrdersShow::class, ['order' => $order])
        ->assertSee('Prazo de aceite encerrado')
        ->assertDontSee('Aceitar pedido');

    Livewire::test(OrdersIndex::class)
        ->assertDontSee('aguardando aceite</p>', false)
        ->assertDontSee('pedido iFood aguardando aceite');
});
