<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\AbacatePayService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RefundPayment implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(public Order $order) {}

    public function handle(): void
    {
        $this->order->loadMissing(['payment', 'company']);

        $payment = $this->order->payment;

        if (! $payment || $payment->status !== 'paid') {
            Log::channel('payments')->info('Reembolso ignorado: pagamento não está pago', [
                'order_id'       => $this->order->id,
                'payment_status' => $payment?->status,
            ]);

            return;
        }

        if (str_starts_with($payment->abacatepay_billing_id, 'sim_')) {
            Log::channel('payments')->info('Reembolso ignorado: billing simulado (ambiente de desenvolvimento)', [
                'order_id'   => $this->order->id,
                'billing_id' => $payment->abacatepay_billing_id,
            ]);

            return;
        }

        (new AbacatePayService($this->order->company))
            ->refundBilling($payment->abacatepay_billing_id);

        $payment->update(['status' => 'refunded']);

        Log::channel('payments')->info('Pagamento marcado como reembolsado', [
            'order_id'   => $this->order->id,
            'billing_id' => $payment->abacatepay_billing_id,
        ]);
    }
}
