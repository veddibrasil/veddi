<?php

namespace App\Jobs;

use App\Events\OrderStatusUpdated;
use App\Models\Order;
use App\Services\AsaasService;
use App\Services\WalletService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RefundPayment implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(public Order $order)
    {
        $this->onQueue('critical');
    }

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
            Log::channel('payments')->info('Reembolso simulado (ambiente de desenvolvimento)', [
                'order_id'         => $this->order->id,
                'asaas_payment_id' => $payment->asaas_payment_id,
            ]);

            $payment->update(['status' => 'refunded']);
            $this->order->update(['status' => 'refunded']);
            OrderStatusUpdated::dispatch($this->order);

            return;
        }

        $result = app(AsaasService::class)->refundPayment($payment->asaas_payment_id);

        $refundStatuses = ['REFUND_REQUESTED', 'REFUNDED', 'REFUND_IN_PROGRESS'];

        if (! in_array($result['status'] ?? '', $refundStatuses)) {
            Log::channel('discord')->error('Resposta inesperada do Asaas ao reembolsar', [
                'type'       => 'payments',
                'order_id'         => $this->order->id,
                'asaas_payment_id' => $payment->asaas_payment_id,
                'result'           => $result,
            ]);

            return;
        }

        $payment->update(['status' => 'refunded']);
        $this->order->update(['status' => 'refunded']);

        app(WalletService::class)->debitForRefund($this->order, $payment);

        OrderStatusUpdated::dispatch($this->order);

        Log::channel('payments')->info('Reembolso processado com sucesso', [
            'order_id'         => $this->order->id,
            'asaas_payment_id' => $payment->asaas_payment_id,
        ]);
    }
}
