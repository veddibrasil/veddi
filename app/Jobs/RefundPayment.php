<?php

namespace App\Jobs;

use App\Contracts\RefundServiceInterface;
use App\Models\Order;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Legacy adapter — kept for backwards compatibility with any queued jobs already dispatched.
 * New code should dispatch ProcessRefund directly via RefundService::initiateRefund().
 */
class RefundPayment implements ShouldQueue
{
    use Queueable;

    public array $backoff = [60, 300, 900];

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(24);
    }

    public function __construct(public Order $order)
    {
        $this->onQueue('critical');
    }

    public function handle(RefundServiceInterface $refundService): void
    {
        $this->order->loadMissing('payment');

        $payment = $this->order->payment;

        if (! $payment || $payment->status !== 'paid') {
            Log::channel('payments')->info('RefundPayment ignorado: pagamento não está pago', [
                'order_id' => $this->order->id,
                'payment_status' => $payment?->status,
            ]);

            return;
        }

        $refundService->initiateRefund(
            $this->order,
            $payment,
            (float) $payment->amount,
            'system',
            null,
            'customer_request',
        );
    }
}
