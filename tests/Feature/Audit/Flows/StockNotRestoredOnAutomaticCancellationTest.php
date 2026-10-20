<?php

require_once __DIR__.'/flows_helpers.php';

use App\Events\OrderStatusUpdated;
use App\Jobs\ProcessOrder;
use App\Jobs\ProcessVindiWebhook;
use App\Jobs\ResolveExpiredVindiPaymentsJob;
use App\Models\Payment;
use App\Services\Payment\VindiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/*
 * AUDITORIA FLUXOS — estoque baixado na criação do pedido não volta quando o pedido é
 * cancelado AUTOMATICAMENTE (PIX expirado, cobrança que não foi criada, cartão recusado
 * via webhook). Só os cancelamentos manuais (cliente, admin, PDV, iFood) chamam
 * StockService::restoreForOrder(). Cada PIX não pago some com o estoque para sempre.
 */

beforeEach(function () {
    Event::fake([OrderStatusUpdated::class]);
});

test('PIX expirado (ResolveExpiredVindiPaymentsJob) cancela o pedido e devolve o estoque', function () {
    $ctx = flowAuditContext(stock: 10);
    $order = flowAuditChatOrder($ctx, qty: 3, status: 'awaiting_payment');
    expect(flowAuditStock($ctx['branch'], $ctx['product']))->toBe(7);

    Payment::create([
        'order_id' => $order->id,
        'vindi_transaction_token' => 'tok_pix_expirado',
        'payment_gateway' => 'vindi',
        'amount' => 60,
        'status' => 'pending',
        'expires_at' => now()->subMinutes(10),
        'payment_token' => 'pt-expirado',
    ]);

    $vindi = Mockery::mock(VindiService::class);
    $vindi->shouldReceive('getTransactionStatus')->andReturn('Cancelada');
    app()->forgetInstance('current.company');

    (new ResolveExpiredVindiPaymentsJob)->handle($vindi);

    expect($order->fresh()->status)->toBe('cancelled');
    expect(flowAuditStock($ctx['branch'], $ctx['product']))->toBe(10);
});

test('cobrança que esgota as tentativas (ProcessOrder::failed) cancela o pedido e devolve o estoque', function () {
    $ctx = flowAuditContext(stock: 10);
    $order = flowAuditChatOrder($ctx, qty: 4, status: 'pending');
    expect(flowAuditStock($ctx['branch'], $ctx['product']))->toBe(6);

    (new ProcessOrder($order, $ctx['customer'], $ctx['company'], 'PIX'))->failed(new RuntimeException('Vindi fora do ar'));

    expect($order->fresh()->status)->toBe('cancelled');
    expect(flowAuditStock($ctx['branch'], $ctx['product']))->toBe(10);
});

test('webhook Vindi de falha (Cancelada / Não Aprovada / Reprovada) cancela o pedido e devolve o estoque', function (string $vindiStatus) {
    $ctx = flowAuditContext(stock: 10);
    $order = flowAuditChatOrder($ctx, qty: 2, status: 'awaiting_payment');
    expect(flowAuditStock($ctx['branch'], $ctx['product']))->toBe(8);

    Payment::create([
        'order_id' => $order->id,
        'vindi_transaction_token' => 'tok_falhou_'.md5($vindiStatus),
        'payment_gateway' => 'vindi',
        'amount' => 40,
        'status' => 'pending',
        'expires_at' => now()->addMinutes(20),
        'payment_token' => 'pt-falhou',
    ]);

    (new ProcessVindiWebhook('tok_falhou_'.md5($vindiStatus), $vindiStatus, []))->handle();

    expect($order->fresh()->status)->toBe('cancelled');
    expect(flowAuditStock($ctx['branch'], $ctx['product']))->toBe(10);
})->with(['Cancelada', 'Não Aprovada', 'Reprovada']);
