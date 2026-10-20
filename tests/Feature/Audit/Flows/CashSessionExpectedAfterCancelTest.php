<?php

use App\Livewire\Admin\Pdv\Terminal;
use App\Models\Order;
use App\Models\PdvCashSession;
use App\Services\Pdv\CashClosingReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * AUDITORIA FLUXOS — fechamento de caixa do PDV: venda em dinheiro cancelada no próprio PDV
 * (dinheiro devolvido ao cliente) continua no "esperado em caixa". CashClosingReportService::breakdown()
 * soma payments cash 'paid' da sessão sem excluir pedido cancelado — diferente de paymentTotals()
 * e orderStats() do mesmo relatório, que excluem. O operador fecha o caixa com "falta" fantasma
 * (e é obrigado a justificar se passar de R$ 5) e o relatório não fecha consigo mesmo.
 */
test('venda em dinheiro cancelada no PDV sai do valor esperado em caixa', function () {
    ['admin' => $admin, 'product' => $product] = pdvContext(); // helper global de tests/Pest.php (caixa aberto com R$ 0)

    $this->actingAs($admin);

    $component = Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('paymentMethod', 'cash')
        ->set('cashReceivedInput', '8.00')
        ->call('processOrder');

    $orderId = $component->get('lastOrderId');

    $component->set('cancelReasonCode', 'customer_gave_up')->call('cancelLastOrder');
    expect(Order::withoutGlobalScopes()->find($orderId)->status)->toBe('cancelled');

    $session = PdvCashSession::withoutGlobalScopes()->firstOrFail();
    $breakdown = app(CashClosingReportService::class)->breakdown($session);
    $session->update(['closing_amount' => 0, 'closed_at' => now()]);
    $report = app(CashClosingReportService::class)->build($session->fresh());

    expect($report['payments']['cash'])->toBe(0.0); // o próprio relatório já exclui o cancelado aqui
    // Hoje: esperado 8,00 (abertura 0 + venda cancelada 8) → diferença de -8,00 no fechamento.
    expect($breakdown['expected'])->toBe(0.0);
});
