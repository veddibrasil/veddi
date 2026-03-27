<?php

namespace App\Jobs;

use App\Models\Order;
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

        if (str_starts_with((string) $payment->asaas_payment_id, 'sim_')) {
            Log::channel('payments')->info('Reembolso ignorado: pagamento simulado (ambiente de desenvolvimento)', [
                'order_id'          => $this->order->id,
                'asaas_payment_id'  => $payment->asaas_payment_id,
            ]);

            return;
        }

        $payment->update(['status' => 'refunded']);

        Log::channel('payments')->info('Pagamento marcado como reembolsado', [
            'order_id'         => $this->order->id,
            'asaas_payment_id' => $payment->asaas_payment_id,
        ]);
    }
}
