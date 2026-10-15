<?php

use App\Events\OrderStatusUpdated;
use App\Jobs\ResolveExpiredVindiPaymentsJob;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Payment\VindiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake([OrderStatusUpdated::class]);
});

/** Payment Vindi pendente que expirou há mais que a margem do job (5 min). */
function expiredVindiPayment(Order $order, string $token = 'vindi_tok_expirado'): Payment
{
    return Payment::create([
        'order_id' => $order->id,
        'vindi_transaction_token' => $token,
        'payment_gateway' => 'vindi',
        'amount' => 50,
        'status' => 'pending',
        'expires_at' => now()->subMinutes(10),
        'payment_token' => 'token-'.$token,
    ]);
}

function runResolveExpiredVindi(string $vindiStatus): void
{
    $vindi = Mockery::mock(VindiService::class);
    $vindi->shouldReceive('getTransactionStatus')->andReturn($vindiStatus);

    // Fora de request: o job roda sem empresa corrente.
    app()->forgetInstance('current.company');

    (new ResolveExpiredVindiPaymentsJob)->handle($vindi);
}

// ── Expiração ─────────────────────────────────────────────────────────────────

test('PIX expirado cancela o pedido do chat que já estava aguardando pagamento', function (string $orderStatus) {
    ['order' => $order] = whatsappOrderContext(['status' => $orderStatus]);
    $payment = expiredVindiPayment($order);

    runResolveExpiredVindi('Cancelada');

    expect($payment->fresh()->status)->toBe('expired')
        ->and($order->fresh()->status)->toBe('cancelled');
    Event::assertDispatched(OrderStatusUpdated::class, fn ($e) => $e->order->id === $order->id && $e->order->status === 'cancelled');
})->with(['pending', 'awaiting_payment']);

test('PIX expirado não mexe em pedido que já saiu da espera do pagamento', function (string $orderStatus) {
    ['order' => $order] = whatsappOrderContext(['status' => $orderStatus]);
    $payment = expiredVindiPayment($order);

    runResolveExpiredVindi('Cancelada');

    expect($payment->fresh()->status)->toBe('expired')
        ->and($order->fresh()->status)->toBe($orderStatus);
    Event::assertNotDispatched(OrderStatusUpdated::class);
})->with(['cancelled', 'paid']);

// ── Pagamento recuperado por polling ──────────────────────────────────────────

test('pagamento recuperado por polling mantém pedido agendado para o futuro como scheduled', function () {
    ['order' => $order] = whatsappOrderContext([
        'status' => 'awaiting_payment',
        'scheduled_at' => now()->addDay(),
    ]);
    $payment = expiredVindiPayment($order);

    runResolveExpiredVindi('Aprovada');

    expect($payment->fresh()->status)->toBe('paid')
        ->and($order->fresh()->status)->toBe('scheduled');
    Event::assertDispatched(OrderStatusUpdated::class, fn ($e) => $e->order->status === 'scheduled');
});

test('pagamento recuperado por polling marca paid quando o pedido não é agendado', function () {
    ['order' => $order] = whatsappOrderContext(['status' => 'awaiting_payment']);
    expiredVindiPayment($order);

    runResolveExpiredVindi('Aprovada');

    expect($order->fresh()->status)->toBe('paid');
});
