<?php

require_once __DIR__.'/flows_helpers.php';

use App\Events\NewOrderPlaced;
use App\Events\OrderStatusUpdated;
use App\Jobs\ProcessOrder;
use App\Livewire\Chat\OrderChat;
use App\Models\Order;
use App\Services\Payment\PaymentOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
 * AUDITORIA FLUXOS — botão "← Trocar forma de pagamento" da tela do PIX.
 *
 * HasPaymentFlow::changePaymentMethod() só cancela o pedido se ele estiver 'pending'. Na tela
 * do PIX o ProcessOrder já rodou e o pedido está 'awaiting_payment' (com QR válido por 30 min),
 * então o pedido antigo fica vivo: estoque baixado, cupom consumido e PIX pagável. O cliente
 * escolhe outra forma e o chat cria um SEGUNDO pedido. Se ele pagar o PIX que já tinha copiado,
 * a loja recebe dois pedidos; se não pagar, o antigo expira e (ver achado de estoque) nunca
 * devolve o estoque.
 */

beforeEach(function () {
    Event::fake([OrderStatusUpdated::class, NewOrderPlaced::class]);
    Queue::fake();
    config()->set('payments.vindi_token_account', null); // PIX em modo simulação, sem HTTP
});

test('trocar a forma de pagamento na tela do PIX cancela o pedido anterior (não deixa dois pedidos vivos)', function () {
    $ctx = flowAuditContext(stock: 10);

    $chat = new OrderChat;
    $chat->companyId = $ctx['company']->id;
    $chat->customerId = $ctx['customer']->id;
    $chat->selectedBranchId = $ctx['branch']->id;
    $chat->orderType = 'pickup';
    $chat->taxId = '52998224725';
    $chat->cart = [
        (string) $ctx['product']->id => ['product_id' => $ctx['product']->id, 'qty' => 2, 'name' => 'Coxinha', 'price' => 20.0],
    ];

    $chat->selectPaymentMethod('PIX');
    $chat->confirmOrder();
    expect($chat->step)->toBe('PAYMENT_PIX');

    $first = Order::withoutGlobalScopes()->findOrFail($chat->orderId);

    // Worker processa a cobrança: PIX gerado, pedido 'awaiting_payment' (estado real da tela do QR).
    (new ProcessOrder($first, $ctx['customer'], $ctx['company'], 'PIX'))->handle(app(PaymentOrchestrator::class));
    expect($first->fresh()->status)->toBe('awaiting_payment');
    expect(flowAuditStock($ctx['branch'], $ctx['product']))->toBe(8);

    // Cliente clica "← Trocar forma de pagamento" e fecha em dinheiro.
    $chat->changePaymentMethod();
    $chat->selectPaymentMethod('CASH');
    $chat->confirmOrder();

    $live = Order::withoutGlobalScopes()
        ->where('customer_id', $ctx['customer']->id)
        ->whereNotIn('status', ['cancelled'])
        ->get();

    // Hoje: 2 pedidos vivos (o PIX antigo segue 'awaiting_payment' + o novo em dinheiro) e
    // estoque baixado duas vezes (6 em vez de 8).
    expect($first->fresh()->status)->toBe('cancelled');
    expect($live)->toHaveCount(1);
    expect(flowAuditStock($ctx['branch'], $ctx['product']))->toBe(8);
});
