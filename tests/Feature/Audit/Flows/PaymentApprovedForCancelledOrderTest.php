<?php

require_once __DIR__.'/flows_helpers.php';

use App\Events\OrderStatusUpdated;
use App\Jobs\ProcessVindiWebhook;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Services\Order\OrderService;
use App\Services\Payment\PaymentOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
 * AUDITORIA FLUXOS — confirmação de pagamento chegando para pedido JÁ CANCELADO.
 *
 * O cliente pode cancelar um pedido 'awaiting_payment' no chat (OrderCancellationPolicy
 * permite) mas o PIX continua válido na Vindi. Se ele (ou alguém com o copia-e-cola) pagar
 * depois, ProcessVindiWebhook::handlePaymentApproved não olha o status do pedido: marca
 * 'paid', credita a carteira e "ressuscita" o pedido como pago — sem baixar de novo o
 * estoque que o cancelamento devolveu, sem histórico, depois de o cliente ter visto
 * "Pedido cancelado". O mesmo vale para processCreditCard() (cartão aprovado em pedido
 * cancelado pelo admin enquanto o cliente estava no formulário).
 *
 * Esperado (proposta): pedido cancelado continua cancelado e o valor capturado é estornado
 * (mesma regra de OrderService::cancelOrderAsAdmin: "cancelar não pode deixar pagamento
 * capturado sem estorno"). Alternativa de produto: reabrir com nova baixa de estoque e
 * alerta — mas nunca reabrir em silêncio como hoje.
 */

beforeEach(function () {
    Event::fake([OrderStatusUpdated::class]);
    Queue::fake();
});

test('webhook Vindi Aprovada para pedido cancelado pelo cliente não o reabre como pago', function () {
    $ctx = flowAuditContext(stock: 10);
    $order = flowAuditChatOrder($ctx, qty: 3, status: 'awaiting_payment');

    Payment::create([
        'order_id' => $order->id,
        'vindi_transaction_token' => 'tok_pix_pago_depois',
        'payment_gateway' => 'vindi',
        'amount' => 60,
        'status' => 'pending',
        'expires_at' => now()->addMinutes(20),
        'payment_token' => 'pt-pago-depois',
    ]);

    // Cliente cancela no chat (permitido em awaiting_payment, sem estorno porque nada foi pago).
    app(OrderService::class)->cancelOrder($order->fresh(), $ctx['customer']->id);
    expect($order->fresh()->status)->toBe('cancelled');
    expect(flowAuditStock($ctx['branch'], $ctx['product']))->toBe(10);

    // O PIX continuava válido e é pago depois.
    app()->forgetInstance('current.company');
    (new ProcessVindiWebhook('tok_pix_pago_depois', 'Aprovada', ['transaction' => ['price_payment' => 60]]))->handle();

    $order->refresh();

    // Hoje: status 'paid' com estoque já devolvido (10) — pedido fantasma para a cozinha.
    expect($order->status)->toBe('cancelled');
    expect(PaymentRefund::where('order_id', $order->id)->exists())->toBeTrue();
});

test('cartão aprovado em pedido já cancelado pelo admin não reabre o pedido', function () {
    $ctx = flowAuditContext(stock: 10);
    $order = flowAuditChatOrder($ctx, qty: 2, status: 'pending', paymentMethod: 'CARD');

    // Admin cancela enquanto o cliente ainda está no formulário do cartão.
    app(OrderService::class)->cancelOrderAsAdmin($order->fresh(), null, 'Acabou o produto');
    expect($order->fresh()->status)->toBe('cancelled');

    // Cliente envia o cartão (modo simulação aprova; com Vindi real o comportamento é o mesmo).
    config()->set('payments.vindi_token_account', null);
    $result = app(PaymentOrchestrator::class)->processCreditCard($order->fresh(), $ctx['customer'], $ctx['company'], [
        'holderName' => 'CLIENTE', 'number' => '4111111111111111', 'expiryMonth' => '12',
        'expiryYear' => '2030', 'ccv' => '123',
    ]);

    // O ideal é nem cobrar; no mínimo o pedido não pode voltar a 'paid' em silêncio.
    expect($result['approved'] && $order->fresh()->status === 'paid')->toBeFalse();
});
