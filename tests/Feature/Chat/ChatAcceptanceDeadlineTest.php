<?php

require_once __DIR__.'/endings_helpers.php';

use App\Events\NewOrderPlaced;
use App\Events\OrderStatusUpdated;
use App\Jobs\ExpireUnacceptedChatOrders;
use App\Livewire\Admin\Settings\CompanySettings;
use App\Models\Order;
use App\Models\PaymentRefund;
use App\Services\Order\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake([NewOrderPlaced::class, OrderStatusUpdated::class]);
    Queue::fake();
});

function runChatAcceptanceDeadline(): void
{
    app()->forgetInstance('current.company');
    (new ExpireUnacceptedChatOrders)->handle(app(OrderService::class));
}

test('aceite com prazo vem desligado e não afeta pedidos antigos ou de outra empresa', function () {
    $ctx = flowAuditContext();
    $order = flowAuditChatOrder($ctx, status: 'paid');
    expect($order->chat_acceptance_expires_at)->toBeNull()->and((int) $ctx['company']->fresh()->chat_acceptance_timeout_minutes)->toBe(0);
    $other = flowAuditContext(['chat_acceptance_timeout_minutes' => 10]);
    $otherOrder = flowAuditChatOrder($other, status: 'paid');
    $this->travel(11)->minutes();
    runChatAcceptanceDeadline();
    expect($order->fresh()->status)->toBe('paid')->and($otherOrder->fresh()->status)->toBe('cancelled');
});

test('sem aceite cancela uma vez, devolve estoque, solicita estorno e avisa cliente', function () {
    $ctx = flowAuditContext(['chat_acceptance_timeout_minutes' => 10]);
    $order = flowAuditChatOrder($ctx, status: 'paid');
    endingsPayment($order, ['status' => 'paid', 'vindi_transaction_token' => 'sim_vindi_acceptance']);
    expect($order->chat_acceptance_expires_at)->not->toBeNull();
    $chat = endingsChat($ctx, $order);
    $this->travel(11)->minutes();
    runChatAcceptanceDeadline();
    runChatAcceptanceDeadline();
    expect($order->fresh()->status)->toBe('cancelled')->and(flowAuditStock($ctx['branch'], $ctx['product']))->toBe(10);
    expect(PaymentRefund::where('order_id', $order->id)->count())->toBe(1);
    $chat->checkPaymentStatus();
    expect($chat->step)->toBe('ORDER_CANCELLED')->and(collect($chat->messages)->last()['text'])->toContain('não aceitou');
    Event::assertDispatched(OrderStatusUpdated::class, fn ($event) => $event->order->id === $order->id && $event->order->status === 'cancelled');
});

test('aceite pelo preparo elimina prazo e job não cancela', function () {
    $ctx = flowAuditContext(['chat_acceptance_timeout_minutes' => 10]);
    $order = flowAuditChatOrder($ctx, status: 'paid');
    $stale = $order->fresh();
    $order->update(['status' => 'preparing']);
    $this->travel(11)->minutes();
    expect($order->fresh()->chat_accepted_at)->not->toBeNull()->and($order->fresh()->chat_acceptance_expires_at)->toBeNull();
    expect(app(OrderService::class)->cancelUnacceptedChat($stale))->toBeFalse();
    runChatAcceptanceDeadline();
    expect($order->fresh()->status)->toBe('preparing');
});

test('aceite obsoleto não reabre pedido já cancelado pelo prazo', function () {
    $ctx = flowAuditContext(['chat_acceptance_timeout_minutes' => 1]);
    $order = flowAuditChatOrder($ctx, status: 'paid');
    $stale = $order->fresh();
    $this->travel(2)->minutes();
    runChatAcceptanceDeadline();
    expect(fn () => $stale->update(['status' => 'preparing']))->toThrow(\Illuminate\Validation\ValidationException::class);
    expect($order->fresh()->status)->toBe('cancelled');
});

test('pagamento ao receber conta prazo da criação sem estorno', function () {
    $ctx = flowAuditContext(['chat_acceptance_timeout_minutes' => 1], stock: null);
    $chat = endingsChat($ctx);
    $chat->selectPaymentMethod('CASH');
    $chat->confirmOrder();
    $order = Order::findOrFail($chat->orderId);
    expect($order->chat_acceptance_expires_at)->not->toBeNull();
    $this->travel(2)->minutes();
    runChatAcceptanceDeadline();
    expect($order->fresh()->status)->toBe('cancelled')->and(PaymentRefund::count())->toBe(0);
});

test('prazo de agendado começa no horário marcado e nenhum PDV ou marketplace ganha prazo', function () {
    $ctx = flowAuditContext(['chat_acceptance_timeout_minutes' => 10]);
    $scheduled = flowAuditChatOrder($ctx);
    $scheduled->update(['scheduled_at' => now()->addDay(), 'status' => 'scheduled']);
    expect($scheduled->chat_acceptance_expires_at->equalTo($scheduled->scheduled_at->copy()->addMinutes(10)))->toBeTrue();
    foreach (['pdv', 'ifood', '99food'] as $channel) {
        $order = flowAuditChatOrder($ctx, qty: 1);
        $order->update(['channel' => $channel, 'status' => 'paid']);
        expect($order->chat_acceptance_expires_at)->toBeNull();
    }
    $this->travel(11)->minutes();
    runChatAcceptanceDeadline();
    expect($scheduled->fresh()->status)->toBe('scheduled');
});

test('desativar prazo impede cancelamento e configurações persistem por empresa', function () {
    $colors = array_fill_keys(['primary_color', 'primary_color_dark', 'primary_color_light', 'secondary_color', 'secondary_color_light', 'accent_color'], '#123456');
    $ctx = flowAuditContext(['chat_acceptance_timeout_minutes' => 10, ...$colors]);
    $other = flowAuditContext();
    app()->instance('current.company', $ctx['company']);
    $order = flowAuditChatOrder($ctx, status: 'paid');
    $admin = \App\Models\User::factory()->create();
    $this->actingAs($admin);
    Livewire::test(CompanySettings::class)->set('chatAcceptanceEnabled', true)->set('chatAcceptanceMinutes', 7)->call('save')->assertHasNoErrors();
    expect($ctx['company']->fresh()->chat_acceptance_timeout_minutes)->toBe(7)->and((int) $other['company']->fresh()->chat_acceptance_timeout_minutes)->toBe(0);
    $ctx['company']->update(['chat_acceptance_timeout_minutes' => 0]);
    $this->travel(11)->minutes();
    runChatAcceptanceDeadline();
    expect($order->fresh()->status)->toBe('paid');
});
