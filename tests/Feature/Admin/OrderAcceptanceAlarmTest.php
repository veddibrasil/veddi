<?php

require_once __DIR__.'/../Audit/Flows/flows_helpers.php';

use App\Contracts\IfoodGatewayContract;
use App\Events\NewOrderPlaced;
use App\Events\OrderStatusUpdated;
use App\Livewire\Admin\Notifications;
use App\Livewire\Admin\OrderAcceptanceAlarm;
use App\Livewire\Admin\Orders\Index as OrdersIndex;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\Order\OrderAcceptance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function alarmUser(Company $company, string $role, ?int $branchId = null): User
{
    $user = User::factory()->create();
    $user->companies()->attach($company->id, ['role' => $role, 'branch_id' => $branchId]);

    return $user;
}

function alarmIfoodOrder(array $ctx): Order
{
    $order = ifoodKanbanOrder($ctx);
    $order->update(['external_metadata' => ['ifood_status' => 'PLACED', 'created_at' => now()->toIso8601String()]]);

    return $order->fresh();
}

function alarmOrderIds(User $user, Company $company): array
{
    return app(OrderAcceptance::class)->awaitingFor($user, $company)->pluck('id')->all();
}

test('chat, iFood e 99Food aguardando aceite tocam, e o pedido só sai do alarme ao ser decidido', function () {
    $ctx = food99Context();
    $info = food99OrderInfo($ctx);
    fakeFood99Api($info);
    $food99 = receiveFood99Order($this, $ctx, $info);
    $admin = alarmUser($ctx['company'], 'company_admin');

    $panel = Livewire::actingAs($admin)->test(OrderAcceptanceAlarm::class)
        ->assertSee('1 pedido aguardando aceite')
        ->assertSee('99Food #'.$food99->food99Details()->displayId());

    postFood99Webhook($this, food99Callback($ctx['integration'], 'orderConfirm', ['order_id' => $info['order_id']]))->assertOk();

    expect($food99->fresh()->status)->toBe('preparing');
    $panel->call('$refresh')->assertDontSee('data-testid="order-acceptance-alert"', false);

    $ifoodCtx = ifoodContext('alarme');
    $ifood = alarmIfoodOrder($ifoodCtx);
    expect(alarmOrderIds(alarmUser($ifoodCtx['company'], 'company_admin'), $ifoodCtx['company']))->toBe([$ifood->id]);

    $chatCtx = flowAuditContext();
    $chat = flowAuditChatOrder($chatCtx, status: 'paid');
    expect(alarmOrderIds(alarmUser($chatCtx['company'], 'company_admin'), $chatCtx['company']))->toBe([$chat->id]);
});

test('aceitar pelo alarme chama a 99Food antes de mudar o pedido e avisa as telas', function () {
    Event::fake([OrderStatusUpdated::class]);
    $ctx = food99Context();
    $info = food99OrderInfo($ctx);
    fakeFood99Api($info);
    $order = receiveFood99Order($this, $ctx, $info);
    $admin = alarmUser($ctx['company'], 'company_admin');

    Livewire::actingAs($admin)->test(OrderAcceptanceAlarm::class)
        ->call('acceptOrder', $order->id)
        ->assertHasNoErrors()
        ->assertDispatched('order-accepted')
        ->assertDontSee('data-testid="order-acceptance-alert"', false);

    expect($order->fresh()->status)->toBe('preparing');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/order/order/confirm'));
    Event::assertDispatched(OrderStatusUpdated::class);
});

test('falha da 99Food no aceite mantém o pedido tocando e mostra o erro', function () {
    $ctx = food99Context();
    $info = food99OrderInfo($ctx);
    fakeFood99Api($info, ['*/v1/order/order/confirm*' => Http::response(food99Error(10001, 'erro'), 200)]);
    $order = receiveFood99Order($this, $ctx, $info);
    $admin = alarmUser($ctx['company'], 'company_admin');

    Livewire::actingAs($admin)->test(OrderAcceptanceAlarm::class)
        ->call('acceptOrder', $order->id)
        ->assertHasErrors(['orderAcceptance'])
        ->assertSee('1 pedido aguardando aceite')
        ->tap(fn ($panel) => expect($panel->get('acceptanceErrors'))->toHaveKey($order->id))
        ->call('$refresh')
        ->assertSeeHtml('role="alert" class="text-sm text-red-600');

    expect($order->fresh()->status)->toBe('pending');
});

test('aceitar pelo alarme confirma no iFood', function () {
    $ctx = ifoodContext('aceite');
    $order = alarmIfoodOrder($ctx);
    $admin = alarmUser($ctx['company'], 'company_admin');

    $gateway = Mockery::mock(IfoodGatewayContract::class);
    $gateway->shouldReceive('confirmOrder')->once();
    app()->instance(IfoodGatewayContract::class, $gateway);

    Livewire::actingAs($admin)->test(OrderAcceptanceAlarm::class)
        ->call('acceptOrder', $order->id)
        ->assertHasNoErrors();

    expect($order->fresh()->status)->toBe('preparing');
});

test('marketplace com prazo de aceite vencido para de tocar', function () {
    $ctx = ifoodContext('vencido');
    $order = alarmIfoodOrder($ctx);
    $admin = alarmUser($ctx['company'], 'company_admin');

    expect(alarmOrderIds($admin, $ctx['company']))->toBe([$order->id]);

    $this->travel(16)->minutes();

    expect(alarmOrderIds($admin, $ctx['company']))->toBe([]);
});

test('cozinha toca e aceita pedido da própria estação', function () {
    Event::fake([NewOrderPlaced::class, OrderStatusUpdated::class]);
    $ctx = flowAuditContext();
    $ctx['category']->update(['station' => 'cozinha']);
    $order = flowAuditChatOrder($ctx, status: 'paid');
    $kitchen = alarmUser($ctx['company'], 'cozinha', $ctx['branch']->id);

    Livewire::actingAs($kitchen)->test(OrderAcceptanceAlarm::class)
        ->assertSee($order->order_number)
        ->call('acceptOrder', $order->id)
        ->assertHasNoErrors();

    expect($order->fresh()->status)->toBe('preparing')
        ->and(OrderStatusHistory::where('order_id', $order->id)->where('metadata->source', 'chat_acceptance')->sole()->user_id)->toBe($kitchen->id);
});

test('aceite em um perfil para o alarme dos outros da filial mesmo sem broadcast', function () {
    Event::fake([NewOrderPlaced::class, OrderStatusUpdated::class]);
    $ctx = flowAuditContext();
    $order = flowAuditChatOrder($ctx, status: 'paid');
    $kitchen = alarmUser($ctx['company'], 'cozinha', $ctx['branch']->id);
    $manager = alarmUser($ctx['company'], 'branch_manager', $ctx['branch']->id);

    $panel = Livewire::actingAs($manager)->test(OrderAcceptanceAlarm::class)
        ->assertSee($order->order_number)
        ->assertSee('wire:poll.3s.keep-alive', false)
        ->assertDontSee('wire:poll.15s.keep-alive', false);

    Livewire::actingAs($kitchen)->test(OrderAcceptanceAlarm::class)
        ->call('acceptOrder', $order->id)
        ->assertHasNoErrors();

    Livewire::actingAs($manager);
    $panel->call('$refresh')
        ->assertDontSee('data-testid="order-acceptance-alert"', false)
        ->assertSee('wire:poll.15s.keep-alive', false)
        ->assertDontSee('wire:poll.3s.keep-alive', false);
});

test('fila da cozinha aceita pedido do chat em vez de barrar o preparo', function () {
    Event::fake([NewOrderPlaced::class, OrderStatusUpdated::class]);
    $ctx = flowAuditContext();
    $order = flowAuditChatOrder($ctx, status: 'paid');
    $kitchen = alarmUser($ctx['company'], 'cozinha', $ctx['branch']->id);

    Livewire::actingAs($kitchen)->test(OrdersIndex::class)
        ->assertSee('Aceitar pedido')
        ->assertDontSee('Iniciar preparo')
        ->call('acceptOrder', $order->id)
        ->assertHasNoErrors();

    expect($order->fresh()->status)->toBe('preparing');
});

test('estação não toca nem aceita pedido só da outra estação', function () {
    $ctx = flowAuditContext();
    $ctx['category']->update(['station' => 'bar']);
    $order = flowAuditChatOrder($ctx, status: 'paid');
    $kitchen = alarmUser($ctx['company'], 'cozinha', $ctx['branch']->id);
    $bar = alarmUser($ctx['company'], 'bar', $ctx['branch']->id);

    expect(alarmOrderIds($bar, $ctx['company']))->toBe([$order->id])
        ->and(alarmOrderIds($kitchen, $ctx['company']))->toBe([]);

    Livewire::actingAs($kitchen)->test(OrderAcceptanceAlarm::class)
        ->call('acceptOrder', $order->id)
        ->assertForbidden();
});

test('item sem estação definida toca na cozinha e no bar', function () {
    $ctx = flowAuditContext();
    expect(ProductCategory::withoutGlobalScopes()->find($ctx['category']->id)->station)->toBeNull();
    $order = flowAuditChatOrder($ctx, status: 'paid');

    expect(alarmOrderIds(alarmUser($ctx['company'], 'cozinha'), $ctx['company']))->toBe([$order->id])
        ->and(alarmOrderIds(alarmUser($ctx['company'], 'bar'), $ctx['company']))->toBe([$order->id]);
});

test('gerente sem filial vinculada toca; com filial, só a dele', function () {
    $ctx = flowAuditContext();
    $order = flowAuditChatOrder($ctx, status: 'paid');

    expect(alarmOrderIds(alarmUser($ctx['company'], 'branch_manager'), $ctx['company']))->toBe([$order->id])
        ->and(alarmOrderIds(alarmUser($ctx['company'], 'branch_manager', $ctx['branch']->id), $ctx['company']))->toBe([$order->id]);

    $other = \App\Models\Branch::withoutGlobalScopes()->create([
        'company_id' => $ctx['company']->id,
        'name' => 'Outra filial',
        'address' => 'Rua B, 2',
        'city' => 'SP',
        'active' => true,
    ]);

    expect(alarmOrderIds(alarmUser($ctx['company'], 'branch_manager', $other->id), $ctx['company']))->toBe([]);
});

test('caixa, entrega e garçom não tocam', function (string $role) {
    $ctx = flowAuditContext();
    flowAuditChatOrder($ctx, status: 'paid');
    $user = alarmUser($ctx['company'], $role, $ctx['branch']->id);

    Livewire::actingAs($user)->test(OrderAcceptanceAlarm::class)
        ->assertSet('canAccept', false)
        ->assertDontSee('data-testid="order-acceptance-alert"', false)
        ->assertDontSee('data-testid="order-alarm-sound-off"', false);
})->with(['caixa', 'entrega', 'garcom']);

test('pedido antigo de antes do aceite obrigatório não toca, mas agendado futuro sim', function () {
    $ctx = flowAuditContext();
    $admin = alarmUser($ctx['company'], 'company_admin');
    $old = flowAuditChatOrder($ctx, status: 'paid');
    Order::whereKey($old->id)->update(['created_at' => now()->subDays(2)]);
    $scheduled = flowAuditChatOrder($ctx, status: 'scheduled');
    Order::whereKey($scheduled->id)->update(['created_at' => now()->subDays(2), 'scheduled_at' => now()->addDay()]);

    expect(alarmOrderIds($admin, $ctx['company']))->toBe([$scheduled->id]);
});

test('alarme não mostra pedido de outra empresa', function () {
    $other = flowAuditContext(['order_prefix' => 'OUT']);
    $foreign = flowAuditChatOrder($other, status: 'paid');
    $ctx = flowAuditContext();
    $own = flowAuditChatOrder($ctx, status: 'paid');
    $admin = alarmUser($ctx['company'], 'company_admin');

    Livewire::actingAs($admin)->test(OrderAcceptanceAlarm::class)
        ->assertSee($own->order_number)
        ->assertDontSee($foreign->order_number);
});

test('caixa não recebe alarme, toast nem sino no PDV', function () {
    ['company' => $company, 'branch' => $branch, 'admin' => $admin] = pdvContext();
    $cashier = alarmUser($company, 'caixa', $branch->id);
    \App\Models\PdvCashSession::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'user_id' => $cashier->id,
        'opening_amount' => 0,
    ]);

    $this->actingAs($cashier)->get(route('admin.pdv'))
        ->assertOk()
        ->assertDontSeeLivewire(OrderAcceptanceAlarm::class)
        ->assertDontSeeLivewire(\App\Livewire\Admin\NotificationBell::class)
        ->assertDontSeeLivewire(Notifications::class);
});

test('pedido de marketplace no toast continua separado do alarme', function () {
    $ctx = food99Context();
    $info = food99OrderInfo($ctx);
    fakeFood99Api($info);
    $order = receiveFood99Order($this, $ctx, $info);
    $admin = alarmUser($ctx['company'], 'company_admin');

    $broadcast = (new NewOrderPlaced($order->load('customer')))->broadcastWith();

    Livewire::actingAs($admin)->test(Notifications::class)
        ->call('onNewOrder', $broadcast)
        ->assertCount('notifications', 1)
        ->assertDontSee('data-testid="order-acceptance-alert"', false);
});
