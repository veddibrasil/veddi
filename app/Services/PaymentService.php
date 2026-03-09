<?php

namespace App\Services;

use App\Jobs\ProcessOrder;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;

class PaymentService
{
    /**
     * Enfileira o job de processamento de pagamento.
     */
    public function dispatchPayment(Order $order, Customer $customer, ?Company $company, string $paymentMethod): void
    {
        ProcessOrder::dispatch($order, $customer, $company, $paymentMethod);
    }

    /**
     * Simula o pagamento de um pedido (apenas em modo debug).
     */
    public function simulatePayment(int $orderId): void
    {
        abort_unless(config('app.debug'), 403);

        $payment = Payment::where('order_id', $orderId)->first();

        if (! $payment) {
            return;
        }

        $payment->update(['status' => 'paid', 'paid_at' => now()]);
        $payment->order->update(['status' => 'paid']);
    }

    /**
     * Expira o pagamento atual e gera uma nova cobrança.
     */
    public function expireAndRenew(Order $order, Customer $customer, ?Company $company, string $paymentMethod): void
    {
        $order->payment?->update(['status' => 'expired']);

        session()->forget('payment_token_' . $order->id);

        if (in_array($order->status, ['pending', 'awaiting_payment'])) {
            ProcessOrder::dispatch($order, $customer, $company, $paymentMethod);
        }
    }
}
