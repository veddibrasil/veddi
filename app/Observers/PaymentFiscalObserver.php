<?php

namespace App\Observers;

use App\Models\Order;
use App\Models\Payment;
use App\Services\Fiscal\FiscalAutoIssueDispatcher;

/**
 * Pagamento recebido depois da etapa ("a receber" do PDV, "pagar na entrega" do chat): o pedido
 * pode já estar entregue, sem mudar de status. Recebido o pagamento, a NFC-e automática sai.
 */
class PaymentFiscalObserver
{
    public function __construct(private readonly FiscalAutoIssueDispatcher $dispatcher) {}

    public function saved(Payment $payment): void
    {
        if ($payment->status !== 'paid' || ! $payment->order_id || ! ($payment->wasRecentlyCreated || $payment->wasChanged('status'))) {
            return;
        }

        // Sem escopo de empresa: roda em webhook e job, sem tenant ligado. payments não tem
        // company_id; o pedido vem pela chave estrangeira do próprio pagamento.
        $order = Order::withoutGlobalScopes()->find($payment->order_id);
        if ($order) {
            $this->dispatcher->consider($order);
        }
    }
}
