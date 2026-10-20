<?php

use App\Livewire\Admin\Orders\Show;
use App\Livewire\Admin\Pdv\Terminal;
use App\Models\CompanyNotification;
use App\Models\Order;
use App\Models\PaymentRefund;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * AUDITORIA FLUXOS — OrderCancellationPolicy::requiresRefund() pula dinheiro com
 * `$order->payment_method === 'CASH'`, mas OrderService::createOrder() grava o método em
 * minúsculas ('cash'). Venda em dinheiro do PDV cancelada pelo painel (Pedidos → Cancelar)
 * cria PaymentRefund do pagamento em espécie, o ProcessRefund cai no OfflineRefundGateway,
 * marca 'failed' e manda para a empresa a notificação "Estorno falhou".
 */
test('cancelar no painel uma venda do PDV paga em dinheiro não dispara estorno de gateway', function () {
    ['admin' => $admin, 'product' => $product] = pdvContext(); // helper global de tests/Pest.php

    $this->actingAs($admin);

    $orderId = Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('paymentMethod', 'cash')
        ->set('cashReceivedInput', '8.00')
        ->call('processOrder')
        ->get('lastOrderId');

    $order = Order::withoutGlobalScopes()->findOrFail($orderId);
    expect($order->payment_method)->toBe('cash');

    Livewire::test(Show::class, ['order' => $order])
        ->set('cancelReason', 'Cliente desistiu da compra')
        ->call('confirmCancel');

    expect($order->fresh()->status)->toBe('cancelled');
    // Hoje: 1 PaymentRefund 'failed' + notificação "Estorno falhou".
    expect(PaymentRefund::where('order_id', $order->id)->count())->toBe(0);
    expect(CompanyNotification::withoutGlobalScopes()->where('type', 'refund_failed')->count())->toBe(0);
});
