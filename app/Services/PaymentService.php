<?php

namespace App\Services;

use App\Events\OrderStatusUpdated;
use App\Jobs\ProcessOrder;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\Log;

class PaymentService
{
    /**
     * Enfileira o job de processamento de pagamento.
     *
     * @param  array  $cardData  Dados do cartão (holderName, number, expiryMonth, expiryYear, ccv, cpfCnpj, postalCode, addressNumber)
     */
    public function dispatchPayment(
        Order $order,
        Customer $customer,
        ?Company $company,
        string $paymentMethod,
        int $installments = 1,
        array $cardData = []
    ): void {
        
        ProcessOrder::dispatch($order, $customer, $company, $paymentMethod, $installments, $cardData);

        Log::channel('payments')->info('Job de pagamento enfileirado', [
            'order_id'       => $order->id,
            'payment_method' => $paymentMethod,
            'installments'   => $installments,
            'total'          => $order->total,
            'queue'          => 'high',
        ]);
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
        OrderStatusUpdated::dispatch($payment->order->fresh());
    }

    /**
     * Expira o pagamento atual e gera uma nova cobrança.
     */
    public function expireAndRenew(Order $order, Customer $customer, ?Company $company, string $paymentMethod): void
    {
        $order->payment?->update(['status' => 'expired']);

        session()->forget('payment_token_' . $order->id);

        if (in_array($order->status, ['pending', 'awaiting_payment'])) {
            ProcessOrder::dispatch($order, $customer, $company, $paymentMethod)->onQueue('high');

            Log::channel('payments')->info('Cobrança expirada — nova gerada', [
                'order_id'       => $order->id,
                'payment_method' => $paymentMethod,
            ]);
        }
    }
}
