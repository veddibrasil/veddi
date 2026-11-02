<?php

require_once __DIR__.'/endings_helpers.php';

use App\Events\NewOrderPlaced;
use App\Events\OrderStatusUpdated;
use App\Livewire\Admin\Notifications;
use App\Livewire\Admin\Orders\Index as OrdersIndex;
use App\Livewire\Admin\Orders\Show;
use App\Models\OrderStatusHistory;
use App\Models\User;
use App\Services\Order\ChatOrderAcceptance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake([NewOrderPlaced::class, OrderStatusUpdated::class]);
    Queue::fake();
});

test('fluxo completo do chat até aceite pelo operador e atualização do cliente', function () {
    $ctx = flowAuditContext(['chat_acceptance_timeout_minutes' => 10]);
    $operator = User::factory()->create();
    $operator->companies()->attach($ctx['company']->id, ['role' => 'company_admin']);
    $chat = endingsChat($ctx);
    $chat->selectPaymentMethod('CARD', true);
    $chat->confirmOrder();
    $order = \App\Models\Order::findOrFail($chat->orderId);

    expect($chat->step)->toBe('ORDER_CONFIRMED')
        ->and($order->status_label)->toBe('Aguardando aceite')
        ->and($order->chat_accepted_at)->toBeNull();
    Event::assertDispatched(NewOrderPlaced::class, fn ($event) => $event->order->id === $order->id);

    $panel = Livewire::actingAs($operator)->test(Notifications::class)
        ->call('onNewOrder', (new NewOrderPlaced($order))->broadcastWith())
        ->assertSee('pedido do chat aguardando aceite');
    Livewire::test(OrdersIndex::class)->call('updateOrderStatus', $order->id, 'ready')
        ->assertHasErrors(['chatAcceptance']);
    expect($order->fresh()->status)->toBe('awaiting_payment');

    $panel->call('acceptChatOrder', $order->id)->assertHasNoErrors()
        ->assertDontSee('data-testid="chat-awaiting-alert"', false);
    $chat->checkPaymentStatus();
    expect($order->fresh()->status)->toBe('preparing')
        ->and($order->fresh()->chat_accepted_at)->not->toBeNull()
        ->and($order->fresh()->chat_acceptance_expires_at)->toBeNull()
        ->and($order->payments()->count())->toBe(0)
        ->and($chat->lastNotifiedStatus)->toBe('preparing');
    expect(OrderStatusHistory::where('order_id', $order->id)->where('metadata->source', 'chat_acceptance')->sole()->user_id)->toBe($operator->id);

    Livewire::test(Show::class, ['order' => $order->fresh()])->call('updateStatus', 'ready')->assertHasNoErrors();
    $chat->checkPaymentStatus();
    expect($order->fresh()->status)->toBe('ready')->and($chat->lastNotifiedStatus)->toBe('ready');
});

test('pedido pago exige aceite no detalhe e no kanban mesmo sem prazo configurado', function (string $target) {
    $ctx = flowAuditContext();
    $order = flowAuditChatOrder($ctx, status: 'paid');
    $admin = User::factory()->create(['is_super_admin' => true]);
    expect($order->status_label)->toBe('Aguardando aceite');

    Livewire::actingAs($admin)->test(Show::class, ['order' => $order])
        ->assertSee('Aceitar pedido')->call('updateStatus', $target)->assertHasErrors(['chatAcceptance']);
    Livewire::test(OrdersIndex::class)->call('updateOrderStatus', $order->id, $target)->assertHasErrors(['chatAcceptance']);
    expect($order->fresh()->status)->toBe('paid')->and($order->fresh()->chat_accepted_at)->toBeNull();
})->with(['preparing', 'ready', 'delivered', 'awaiting_payment']);

test('aceitar no detalhe registra operador, libera preparo e não duplica aceite', function () {
    $ctx = flowAuditContext(['chat_acceptance_timeout_minutes' => 10]);
    $order = flowAuditChatOrder($ctx, status: 'paid');
    $admin = User::factory()->create(['is_super_admin' => true]);

    Livewire::actingAs($admin)->test(Show::class, ['order' => $order])
        ->call('acceptChatOrder')->assertHasNoErrors()->assertDispatched('chat-order-accepted')
        ->call('updateStatus', 'ready')->assertHasNoErrors();
    $acceptedAt = $order->fresh()->chat_accepted_at;
    app(ChatOrderAcceptance::class)->accept($order->fresh(), $admin);

    expect($order->fresh()->status)->toBe('ready')->and($acceptedAt)->not->toBeNull()
        ->and($order->fresh()->chat_accepted_at->equalTo($acceptedAt))->toBeTrue()
        ->and($order->fresh()->chat_acceptance_expires_at)->toBeNull();
    $history = OrderStatusHistory::where('order_id', $order->id)->where('metadata->source', 'chat_acceptance')->get();
    expect($history)->toHaveCount(1)->and($history->first()->user_id)->toBe($admin->id);
});

test('alerta persiste após fechar toast e desaparece ao aceitar no painel', function () {
    $ctx = flowAuditContext();
    $order = flowAuditChatOrder($ctx, status: 'paid');
    $admin = User::factory()->create(['is_super_admin' => true]);

    $panel = Livewire::actingAs($admin)->test(Notifications::class)
        ->assertSee('pedido do chat aguardando aceite')->assertSee($order->order_number)
        ->call('onNewOrder', (new NewOrderPlaced($order))->broadcastWith())
        ->assertSet('notifications.0.channel', 'chat');
    $panel->call('dismiss', $panel->get('notifications')[0]['id'])->assertSet('notifications', [])
        ->assertSee('pedido do chat aguardando aceite')
        ->call('acceptChatOrder', $order->id)->assertHasNoErrors()
        ->assertDontSee('data-testid="chat-awaiting-alert"', false);
    expect($order->fresh()->status)->toBe('preparing');
});

test('kanban aceita pagamento na entrega sem registrar pagamento antecipado', function () {
    $ctx = flowAuditContext();
    $order = flowAuditChatOrder($ctx);
    $order->update(['payment_on_delivery' => true, 'status' => 'awaiting_payment']);
    $admin = User::factory()->create(['is_super_admin' => true]);

    Livewire::actingAs($admin)->test(OrdersIndex::class)->call('acceptChatOrder', $order->id)->assertHasNoErrors();
    expect($order->fresh()->status)->toBe('preparing')->and($order->payments()->count())->toBe(0);
});

test('pedido online não pago não oferece aceite e rejeita chamada direta', function () {
    $ctx = flowAuditContext();
    $order = flowAuditChatOrder($ctx, status: 'awaiting_payment');
    $admin = User::factory()->create(['is_super_admin' => true]);

    Livewire::actingAs($admin)->test(Notifications::class)->assertDontSee('data-testid="chat-awaiting-alert"', false);
    Livewire::test(Show::class, ['order' => $order])->assertDontSee('wire:click="acceptChatOrder"', false)
        ->call('acceptChatOrder')->assertHasErrors(['chatAcceptance']);
    expect($order->fresh()->status)->toBe('awaiting_payment');
});

test('aceite vencido ou cancelado não pode iniciar preparo', function (bool $cancelled) {
    $ctx = flowAuditContext(['chat_acceptance_timeout_minutes' => 1]);
    $order = flowAuditChatOrder($ctx, status: 'paid');
    $admin = User::factory()->create(['is_super_admin' => true]);
    $this->travel(2)->minutes();
    if ($cancelled) {
        app(\App\Services\Order\OrderService::class)->cancelUnacceptedChat($order);
    }

    Livewire::actingAs($admin)->test(OrdersIndex::class)->call('acceptChatOrder', $order->id)->assertHasErrors(['chatAcceptance']);
    expect($order->fresh()->status)->toBe($cancelled ? 'cancelled' : 'paid');
})->with([true, false]);

test('aceite de agendado preserva horário e status até iniciar preparo', function () {
    $ctx = flowAuditContext();
    $order = flowAuditChatOrder($ctx);
    $order->update(['scheduled_at' => now()->addDay(), 'status' => 'scheduled']);
    $admin = User::factory()->create(['is_super_admin' => true]);

    Livewire::actingAs($admin)->test(Show::class, ['order' => $order])->call('acceptChatOrder')->assertHasNoErrors();
    expect($order->fresh()->status)->toBe('scheduled')->and($order->fresh()->chat_accepted_at)->not->toBeNull();
    Livewire::test(Show::class, ['order' => $order->fresh()])->call('updateStatus', 'preparing')->assertHasNoErrors();
    expect($order->fresh()->status)->toBe('preparing');
});

test('alerta e aceite respeitam empresa, filial, permissão e estação', function () {
    $ctx = flowAuditContext();
    $order = flowAuditChatOrder($ctx, status: 'paid');
    $other = flowAuditContext(['order_prefix' => 'OUTRA']);
    $foreignOrder = flowAuditChatOrder($other, status: 'paid');
    app()->instance('current.company', $ctx['company']);
    $manager = User::factory()->create();
    $manager->companies()->attach($ctx['company']->id, ['role' => 'branch_manager', 'branch_id' => $ctx['branch']->id]);

    $panel = Livewire::actingAs($manager)->test(Notifications::class)->assertSee($order->order_number)->assertDontSee($foreignOrder->order_number);
    expect(fn () => $panel->call('acceptChatOrder', $foreignOrder->id))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

    $manager->companies()->updateExistingPivot($ctx['company']->id, ['branch_id' => $other['branch']->id]);
    Livewire::actingAs($manager->fresh())->test(Notifications::class)->assertDontSee('data-testid="chat-awaiting-alert"', false)
        ->call('acceptChatOrder', $order->id)->assertForbidden();

    $kitchen = User::factory()->create();
    $kitchen->companies()->attach($ctx['company']->id, ['role' => 'cozinha', 'branch_id' => $ctx['branch']->id]);
    Livewire::actingAs($kitchen)->test(Notifications::class)->assertDontSee('data-testid="chat-awaiting-alert"', false)
        ->call('acceptChatOrder', $order->id)->assertForbidden();

    Livewire::actingAs(User::factory()->create())->test(Notifications::class)
        ->assertDontSee('data-testid="chat-awaiting-alert"', false)
        ->call('acceptChatOrder', $order->id)->assertForbidden();
    expect($order->fresh()->status)->toBe('paid');
});

test('pdv e marketplaces não entram no alerta de chat nem podem ser aceitos por essa ação', function (string $channel) {
    $ctx = flowAuditContext();
    $order = flowAuditChatOrder($ctx);
    $order->update(['channel' => $channel, 'status' => 'paid']);
    $admin = User::factory()->create(['is_super_admin' => true]);

    Livewire::actingAs($admin)->test(Notifications::class)->assertDontSee('data-testid="chat-awaiting-alert"', false)
        ->call('acceptChatOrder', $order->id)->assertHasErrors(['chatAcceptance']);
    expect($order->fresh()->status)->toBe('paid')->and($order->fresh()->chat_accepted_at)->toBeNull();
})->with(['pdv', 'ifood', '99food']);
