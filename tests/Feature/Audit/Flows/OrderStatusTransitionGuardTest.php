<?php

require_once __DIR__.'/flows_helpers.php';

use App\Events\OrderStatusUpdated;
use App\Livewire\Admin\Orders\Index as OrdersIndex;
use App\Livewire\Admin\Orders\Show;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\User;
use App\Services\Order\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * AUDITORIA FLUXOS — não há máquina de estados no status do pedido.
 * Orders\Index::updateOrderStatus (kanban, inclusive arrastar card) e Orders\Show::updateStatus
 * (botões sempre visíveis, inclusive em pedido cancelado) só checam se o status de destino está
 * numa lista fixa. Um pedido cancelado — com estoque já devolvido e estorno já disparado — volta
 * para "Preparando"/"Pago"; um pedido entregue volta para "Ag. Pagamento".
 * OrderCancellationPolicy já trata cancelled/refunded/delivered como terminais, mas só no cancelamento.
 */

beforeEach(function () {
    Event::fake([OrderStatusUpdated::class]);
    Queue::fake();
});

function flowAuditCancelledPaidOrder(array $ctx): \App\Models\Order
{
    $order = flowAuditChatOrder($ctx, qty: 2, status: 'paid');

    Payment::create([
        'order_id' => $order->id,
        'vindi_transaction_token' => 'tok_pago_'.$order->id,
        'payment_gateway' => 'vindi',
        'amount' => 40,
        'status' => 'paid',
        'paid_at' => now(),
        'payment_token' => 'pt-pago-'.$order->id,
    ]);

    app(OrderService::class)->cancelOrderAsAdmin($order->fresh(), null, 'Cliente pediu para cancelar');

    expect($order->fresh()->status)->toBe('cancelled');
    expect(PaymentRefund::where('order_id', $order->id)->exists())->toBeTrue();

    return $order->fresh();
}

test('kanban não reabre pedido cancelado (com estorno em andamento) para Preparando', function () {
    $ctx = flowAuditContext(stock: 10);
    $admin = User::factory()->create(['is_super_admin' => true]);
    $order = flowAuditCancelledPaidOrder($ctx);

    Livewire::actingAs($admin)
        ->test(OrdersIndex::class)
        ->call('updateOrderStatus', $order->id, 'preparing');

    expect($order->fresh()->status)->toBe('cancelled');
});

test('tela do pedido não reabre pedido cancelado para Pago', function () {
    $ctx = flowAuditContext(stock: 10);
    $admin = User::factory()->create(['is_super_admin' => true]);
    $order = flowAuditCancelledPaidOrder($ctx);

    Livewire::actingAs($admin)
        ->test(Show::class, ['order' => $order])
        ->call('updateStatus', 'paid');

    expect($order->fresh()->status)->toBe('cancelled');
});
