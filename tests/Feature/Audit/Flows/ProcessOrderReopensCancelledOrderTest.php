<?php

require_once __DIR__.'/flows_helpers.php';

use App\Events\OrderStatusUpdated;
use App\Jobs\ProcessOrder;
use App\Models\Order;
use App\Services\Payment\PaymentOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/*
 * AUDITORIA FLUXOS — ProcessOrder::handle() confere o status antes de chamar a Vindi, mas depois
 * da cobrança criada faz `$this->order->update(['status' => 'awaiting_payment'])` sem reler.
 * Se o pedido for cancelado enquanto a chamada à Vindi está em andamento (cliente clica
 * "Trocar forma de pagamento" com o pedido ainda 'pending', admin cancela), o job reabre o
 * pedido cancelado como 'awaiting_payment' com PIX válido — estoque já devolvido.
 * (Concorrência simulada: o mock do orchestrator cancela o pedido no meio da cobrança.)
 */
test('cobrança concluída depois de o pedido ser cancelado não reabre o pedido', function () {
    Event::fake([OrderStatusUpdated::class]);
    $ctx = flowAuditContext(stock: 10);
    $order = flowAuditChatOrder($ctx, qty: 1, status: 'pending');

    $orchestrator = Mockery::mock(PaymentOrchestrator::class);
    $orchestrator->shouldReceive('process')->once()->andReturnUsing(function () use ($order) {
        // Outra requisição cancela o pedido enquanto a Vindi responde.
        Order::withoutGlobalScopes()->whereKey($order->id)->update(['status' => 'cancelled']);

        return ['id' => 'tok', 'status' => 'pending'];
    });

    (new ProcessOrder($order, $ctx['customer'], $ctx['company'], 'PIX'))->handle($orchestrator);

    expect($order->fresh()->status)->toBe('cancelled');
});
