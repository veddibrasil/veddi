<?php

namespace App\Jobs;

use App\Contracts\RefundServiceInterface;
use App\Contracts\TransactionServiceInterface;
use App\Contracts\WalletServiceInterface;
use App\Events\OrderStatusUpdated;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentRefund;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessVindiWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    public function __construct(
        public string $transactionToken,
        public string $status,
        public array $payload,
    ) {
        $this->onQueue('critical');
    }

    public function handle(): void
    {
        match ($this->status) {
            'Aprovada' => $this->handlePaymentApproved(),
            'Cancelada', 'Não Aprovada' => $this->handlePaymentFailed(),
            'Estornada' => $this->handleRefundConfirmed(),
            default => $this->logIgnored(),
        };
    }

    private function handlePaymentApproved(): void
    {
        $idempotencyKey = hash('sha256', 'vindi:'.$this->transactionToken.':paid');

        try {
            DB::transaction(function () use ($idempotencyKey) {
                $payment = Payment::where('vindi_transaction_token', $this->transactionToken)
                    ->lockForUpdate()
                    ->first();

                if (! $payment) {
                    // Fallback: tentar pelo order_number no payload
                    $orderId = $this->payload['transaction']['order_number'] ?? null;
                    if ($orderId) {
                        $payment = Payment::where('order_id', $orderId)
                            ->whereNull('vindi_transaction_token')
                            ->lockForUpdate()
                            ->first();
                    }
                }

                if (! $payment) {
                    Log::channel('webhook')->warning('Vindi webhook: Payment não encontrado', [
                        'transaction_token' => $this->transactionToken,
                    ]);

                    return;
                }

                if ($payment->status === 'paid') {
                    Log::channel('webhook')->info('Vindi webhook: pagamento já confirmado (duplicado ignorado)', [
                        'transaction_token' => $this->transactionToken,
                        'order_id' => $payment->order_id,
                    ]);

                    return;
                }

                $order = Order::find($payment->order_id);

                if (! $order) {
                    Log::channel('webhook')->warning('Vindi webhook: pedido não encontrado', [
                        'order_id' => $payment->order_id,
                    ]);

                    return;
                }

                $payment->update([
                    'status' => 'paid',
                    'paid_at' => now(),
                    'webhook_payload' => $this->payload,
                    'idempotency_key' => $idempotencyKey,
                ]);

                $order->update(['status' => 'paid']);

                Log::channel('payments')->info('Pagamento Vindi confirmado', [
                    'order_id' => $order->id,
                    'transaction_token' => $this->transactionToken,
                    'amount' => $payment->amount,
                ]);

                app(WalletServiceInterface::class)->creditForOrder($order, $payment);
                app(TransactionServiceInterface::class)->createForPayment($order, $payment);

                OrderStatusUpdated::dispatch($order->fresh());
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            Log::channel('webhook')->info('Vindi webhook: duplicado ignorado via idempotency_key', [
                'transaction_token' => $this->transactionToken,
            ]);
        }
    }

    private function handlePaymentFailed(): void
    {
        $payment = Payment::where('vindi_transaction_token', $this->transactionToken)->first();

        if (! $payment) {
            Log::channel('webhook')->warning('Vindi webhook: Payment não encontrado para falha', [
                'transaction_token' => $this->transactionToken,
            ]);

            return;
        }

        if ($payment->status !== 'pending') {
            return;
        }

        $payment->update(['status' => 'failed']);

        $order = Order::find($payment->order_id);
        if ($order) {
            $order->update(['status' => 'cancelled']);
            OrderStatusUpdated::dispatch($order->fresh());
        }

        Log::channel('payments')->info('Pagamento Vindi falhou/cancelado', [
            'transaction_token' => $this->transactionToken,
            'status' => $this->status,
        ]);
    }

    private function handleRefundConfirmed(): void
    {
        $payment = Payment::where('vindi_transaction_token', $this->transactionToken)->first();

        if (! $payment) {
            Log::channel('webhook')->warning('Vindi webhook: Payment não encontrado para estorno', [
                'transaction_token' => $this->transactionToken,
            ]);

            return;
        }

        $refund = PaymentRefund::where('payment_id', $payment->id)
            ->whereIn('status', ['requested', 'in_progress'])
            ->latest()
            ->first();

        if (! $refund) {
            Log::channel('webhook')->warning('Vindi webhook: PaymentRefund não encontrado para estorno', [
                'payment_id' => $payment->id,
            ]);

            return;
        }

        if ($refund->status === 'succeeded') {
            return;
        }

        app(RefundServiceInterface::class)->markSucceeded($refund, [
            'external_refund_id' => $this->transactionToken,
            'external_status' => 'Estornada',
            'raw' => $this->payload,
        ]);
    }

    private function logIgnored(): void
    {
        Log::channel('webhook')->debug('Vindi webhook: status ignorado', [
            'transaction_token' => $this->transactionToken,
            'status' => $this->status,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::channel('discord')->error('Falha ao processar webhook Vindi', [
            'type' => 'webhook',
            'transaction_token' => $this->transactionToken,
            'status' => $this->status,
            'error' => $exception->getMessage(),
        ]);
    }
}
