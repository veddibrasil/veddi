<?php

require_once __DIR__.'/../Audit/Flows/flows_helpers.php';

use App\Contracts\IfoodGatewayContract;
use App\Events\NewOrderPlaced;
use App\Events\OrderStatusUpdated;
use App\Livewire\Admin\OrderAcceptanceAlarm;
use App\Livewire\Admin\Orders\Index as OrdersIndex;
use App\Livewire\Admin\Orders\Show;
use App\Models\Company;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Pedido aguardando aceite em cada canal, com um admin da empresa.
 *
 * @return array{order: Order, company: Company, admin: User, modal: string}
 */
function acceptanceScenario(object $test, string $channel): array
{
    if ($channel === 'chat') {
        $ctx = flowAuditContext(['chat_acceptance_timeout_minutes' => 10]);
        $order = flowAuditChatOrder($ctx, status: 'paid');
        $modal = 'cancelOrderId';
    } elseif ($channel === 'ifood') {
        $ctx = ifoodContext('padrao'.uniqid());
        $order = ifoodKanbanOrder($ctx, 'pending');
        $order->update(['external_metadata' => ['ifood_status' => 'PLACED', 'created_at' => now()->toIso8601String()]]);
        $modal = 'ifoodCancelOrderId';
    } elseif ($channel === 'keeta') {
        $ctx = keetaContext('padrao'.uniqid());
        $order = receiveKeetaOrder($test, $ctx, keetaOrder($ctx));
        $modal = 'keetaCancelOrderId';
    } else {
        $ctx = food99Context();
        $info = food99OrderInfo($ctx);
        fakeFood99Api($info);
        $order = receiveFood99Order($test, $ctx, $info);
        $modal = 'food99CancelOrderId';
    }

    $admin = User::factory()->create();
    $admin->companies()->attach($ctx['company']->id, ['role' => 'company_admin']);

    return ['order' => $order->fresh(), 'company' => $ctx['company'], 'admin' => $admin, 'modal' => $modal];
}

function acceptanceIfoodGateway(): void
{
    $gateway = Mockery::mock(IfoodGatewayContract::class);
    $gateway->shouldReceive('getCancellationReasons')->andReturn([['code' => '503', 'description' => 'Item indisponível']]);
    $gateway->shouldReceive('confirmOrder');
    app()->instance(IfoodGatewayContract::class, $gateway);
}

test('o pedido segue o mesmo contrato de aceite em todo canal', function (string $channel) {
    ['order' => $order] = acceptanceScenario($this, $channel);

    expect($order->awaitingAcceptance())->toBeTrue()
        ->and($order->acceptanceExpired())->toBeFalse()
        ->and($order->acceptanceDeadline())->not->toBeNull()
        ->and($order->status_label)->toBe('Aguardando aceite')
        ->and(Order::whereKey($order->id)->whereAwaitingAcceptance()->exists())->toBeTrue();
})->with(['chat', 'ifood', '99food', 'keeta']);

test('kanban junta todo canal na coluna de aceite, com aceitar e recusar', function (string $channel) {
    ['order' => $order, 'admin' => $admin] = acceptanceScenario($this, $channel);

    Livewire::actingAs($admin)->test(OrdersIndex::class)
        ->set('viewMode', 'kanban')
        ->assertViewHas('kanbanColumns', function ($columns) use ($order) {
            $placed = $columns->filter(fn ($column) => $column['orders']->contains('id', $order->id))->keys()->all();

            return $placed === ['awaiting_acceptance'] && $columns->keys()->first() === 'awaiting_acceptance';
        })
        ->assertSee('Aceitar pedido')
        ->assertSee('Recusar');
})->with(['chat', 'ifood', '99food', 'keeta']);

test('tela do pedido mostra o mesmo bloco de aceite', function (string $channel) {
    ['order' => $order, 'admin' => $admin] = acceptanceScenario($this, $channel);

    Livewire::actingAs($admin)->test(Show::class, ['order' => $order])
        ->assertSeeHtml('data-testid="order-acceptance"')
        ->assertSeeHtml('acceptOrder('.$order->id.')')
        ->assertSeeHtml('rejectOrder('.$order->id.')')
        ->assertSee('Aguardando aceite');
})->with(['chat', 'ifood', '99food', 'keeta']);

test('fila da cozinha usa o mesmo aceite e não cita outra plataforma', function (string $channel) {
    ['order' => $order, 'company' => $company] = acceptanceScenario($this, $channel);
    $kitchen = User::factory()->create();
    $kitchen->companies()->attach($company->id, ['role' => 'cozinha']);

    Livewire::actingAs($kitchen)->test(OrdersIndex::class)
        ->assertSeeHtml('acceptOrder('.$order->id.')')
        ->assertDontSeeHtml('rejectOrder('.$order->id.')')
        ->assertDontSee('Iniciar preparo')
        ->assertDontSee('Aceitar no ');
})->with(['chat', '99food', 'keeta']);

test('recusar abre o modal do canal no kanban e na tela do pedido', function (string $channel) {
    ['order' => $order, 'admin' => $admin, 'modal' => $modal] = acceptanceScenario($this, $channel);
    acceptanceIfoodGateway();

    Livewire::actingAs($admin)->test(OrdersIndex::class)
        ->call('rejectOrder', $order->id)
        ->assertSet($modal, $order->id)
        ->assertSet('rejectingOrder', true);

    $show = Livewire::test(Show::class, ['order' => $order])->call('rejectOrder', $order->id);
    expect($show->get('rejectingOrder'))->toBeTrue();
})->with(['chat', 'ifood', '99food', 'keeta']);

test('recusar pelo alarme leva à tela do pedido com o modal aberto', function () {
    ['order' => $order, 'admin' => $admin] = acceptanceScenario($this, 'chat');

    Livewire::actingAs($admin)->test(OrderAcceptanceAlarm::class)
        ->call('rejectOrder', $order->id)
        ->assertRedirect(route('admin.orders.show', ['order' => $order, 'recusar' => 1]));

    Livewire::withQueryParams(['recusar' => 1])->test(Show::class, ['order' => $order])
        ->assertSet('showCancelModal', true)
        ->assertSet('rejectingOrder', true);
});

test('cozinha aceita no alarme, mas não recusa', function () {
    ['order' => $order, 'company' => $company] = acceptanceScenario($this, 'chat');
    $kitchen = User::factory()->create();
    $kitchen->companies()->attach($company->id, ['role' => 'cozinha']);

    Livewire::actingAs($kitchen)->test(OrderAcceptanceAlarm::class)
        ->assertSet('canReject', false)
        ->assertDontSeeHtml('rejectOrder('.$order->id.')')
        ->call('rejectOrder', $order->id)
        ->assertForbidden();
});

test('levar pra preparando aceita em todo canal', function (string $channel) {
    Event::fake([NewOrderPlaced::class, OrderStatusUpdated::class]);
    ['order' => $order, 'admin' => $admin] = acceptanceScenario($this, $channel);
    acceptanceIfoodGateway();

    Livewire::actingAs($admin)->test(OrdersIndex::class)
        ->call('updateOrderStatus', $order->id, 'preparing')
        ->assertHasNoErrors();

    expect($order->fresh()->status)->toBe('preparing')
        ->and($order->fresh()->awaitingAcceptance())->toBeFalse();
})->with(['chat', 'ifood', '99food', 'keeta']);

test('alarme continua até aceitar e para no aceite em qualquer tela', function (string $channel, string $surface) {
    Event::fake([NewOrderPlaced::class, OrderStatusUpdated::class]);
    ['order' => $order, 'admin' => $admin] = acceptanceScenario($this, $channel);
    acceptanceIfoodGateway();

    $alarm = Livewire::actingAs($admin)->test(OrderAcceptanceAlarm::class)
        ->assertSee('pedido aguardando aceite');
    foreach (range(1, 3) as $poll) {
        $alarm->call('$refresh')->assertSee('pedido aguardando aceite');
        expect($alarm->get('awaitingOrders')->pluck('id')->all())->toBe([$order->id]);
    }

    $component = match ($surface) {
        'alarm' => $alarm,
        'index' => Livewire::test(OrdersIndex::class),
        'show' => Livewire::test(Show::class, ['order' => $order]),
    };
    $component->call('acceptOrder', $order->id)->assertStatus(200)->assertHasNoErrors()
        ->assertDispatched('order-accepted');

    $alarm->call('$refresh')->assertDontSeeHtml('data-testid="order-acceptance-alert"');
    expect($order->fresh()->status)->toBe('preparing')
        ->and($alarm->get('awaitingOrders'))->toBeEmpty();
})->with(['chat', 'ifood', '99food', 'keeta'])->with(['alarm', 'index', 'show']);

test('aceitar um pedido mantém o alarme dos outros pedidos pendentes', function () {
    Event::fake([NewOrderPlaced::class, OrderStatusUpdated::class]);
    ['order' => $first, 'admin' => $admin] = acceptanceScenario($this, 'chat');
    $second = $first->replicate(['order_number']);
    $second->save();

    $alarm = Livewire::actingAs($admin)->test(OrderAcceptanceAlarm::class)
        ->assertSee('2 pedidos aguardando aceite')
        ->call('acceptOrder', $first->id)->assertStatus(200)->assertHasNoErrors()
        ->assertSee('1 pedido aguardando aceite');
    expect($alarm->get('awaitingOrders')->pluck('id')->all())->toBe([$second->id]);

    $alarm->call('acceptOrder', $second->id)->assertStatus(200)->assertHasNoErrors()
        ->assertDontSeeHtml('data-testid="order-acceptance-alert"');
    expect($alarm->get('awaitingOrders'))->toBeEmpty();
});

test('soltar o pedido na coluna do próprio status não muda nada sem aceite', function () {
    ['order' => $order, 'admin' => $admin] = acceptanceScenario($this, 'chat');

    Livewire::actingAs($admin)->test(OrdersIndex::class)
        ->call('updateOrderStatus', $order->id, 'paid')
        ->assertHasErrors(['orderAcceptance']);

    expect($order->fresh()->chat_accepted_at)->toBeNull();
});

test('kanban não some com pedido antigo sem aceite nem com iFood sem snapshot', function () {
    $ctx = flowAuditContext();
    $old = flowAuditChatOrder($ctx, status: 'paid');
    Order::whereKey($old->id)->update(['created_at' => now()->subDays(3)]);
    $noSnapshot = flowAuditChatOrder($ctx, status: 'paid');
    Order::whereKey($noSnapshot->id)->update(['channel' => 'ifood', 'external_metadata' => null]);
    $admin = User::factory()->create(['is_super_admin' => true]);

    Livewire::actingAs($admin)->test(OrdersIndex::class)
        ->set('viewMode', 'kanban')
        ->assertViewHas('kanbanColumns', fn ($columns) => $columns['paid']['orders']->pluck('id')->sort()->values()->all() === [$old->id, $noSnapshot->id]
            && $columns['paid']['total'] === 2
            && $columns['awaiting_acceptance']['total'] === 0);
});

test('lista filtra aguardando aceite de todo canal', function () {
    ['order' => $order, 'admin' => $admin, 'company' => $company] = acceptanceScenario($this, 'chat');
    $accepted = Order::create($order->only(['company_id', 'branch_id', 'customer_id', 'subtotal', 'total', 'payment_method', 'order_type']) + ['status' => 'preparing', 'delivery_fee' => 0, 'discount' => 0, 'fee' => 0, 'net_value' => 0, 'channel' => 'chat']);

    Livewire::actingAs($admin)->test(OrdersIndex::class)
        ->set('statusFilter', 'awaiting_acceptance')
        ->assertViewHas('orders', fn ($orders) => collect($orders->items())->pluck('id')->all() === [$order->id]);

    expect($accepted->awaitingAcceptance())->toBeFalse();
});

test('prazo longo de agendado aparece como horário, não como contagem', function () {
    $ctx = flowAuditContext(['chat_acceptance_timeout_minutes' => 10]);
    $order = flowAuditChatOrder($ctx);
    $order->update(['scheduled_at' => now()->addDay(), 'status' => 'scheduled']);
    $admin = User::factory()->create(['is_super_admin' => true]);

    Livewire::actingAs($admin)->test(Show::class, ['order' => $order->fresh()])
        ->assertSee('até '.$order->fresh()->chat_acceptance_expires_at->format('d/m H:i'))
        ->assertDontSeeHtml('acceptanceCountdown');
});
