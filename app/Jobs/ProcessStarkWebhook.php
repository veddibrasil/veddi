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

class ProcessStarkWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    public function __construct(
        public string $event,
        public array $payload,
    ) {
        $this->onQueue('critical');
    }

    public function handle(): void
    {
        $log = $this->payload['event']['log'] ?? $this->payload['log'] ?? [];

        // Pix reversal: log contém chave "pixReversal"
        if (isset($log['pixReversal'])) {
            $this->handlePixReversal($log['pixReversal']);

            return;
        }

        // Invoice eventos: "created" | "credited" | "overdue" | "expired" | "canceled"
        if ($this->event !== 'credited') {
            Log::channel('webhook')->debug('Stark webhook: evento ignorado', [
                'event' => $this->event,
            ]);

            return;
        }

        $invoiceId = $log['invoice']['id'] ?? null;

        if (! $invoiceId) {
            Log::channel('webhook')->warning('Stark webhook: invoice ID ausente no payload', [
                'event' => $this->event,
                'payload' => $this->payload,
            ]);

            return;
        }

        $idempotencyKey = hash('sha256', 'stark:'.$invoiceId.':paid');

        try {
            DB::transaction(function () use ($invoiceId, $idempotencyKey) {
                // lockForUpdate serializes concurrent jobs on the same payment row
                $payment = Payment::where('stark_payment_id', $invoiceId)
                    ->lockForUpdate()
                    ->first();

                if (! $payment) {
                    Log::channel('webhook')->warning('Stark webhook: Payment não encontrado', [
                        'event' => $this->event,
                        'invoice_id' => $invoiceId,
                    ]);

                    return;
                }

                if ($payment->status === 'paid') {
                    Log::channel('webhook')->info('Stark webhook: pagamento já confirmado (duplicado ignorado)', [
                        'invoice_id' => $invoiceId,
                        'order_id' => $payment->order_id,
                    ]);

                    return;
                }

                $order = Order::find($payment->order_id);

                if (! $order) {
                    Log::channel('webhook')->warning('Stark webhook: pedido não encontrado', [
                        'event' => $this->event,
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

                Log::channel('payments')->info('Pagamento PIX Stark confirmado', [
                    'order_id' => $order->id,
                    'invoice_id' => $invoiceId,
                    'amount' => $payment->amount,
                ]);

                app(WalletServiceInterface::class)->creditForOrder($order, $payment);
                app(TransactionServiceInterface::class)->createForPayment($order, $payment);

                OrderStatusUpdated::dispatch($order->fresh());
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            Log::channel('webhook')->info('Stark webhook: duplicado ignorado via idempotency_key', [
                'invoice_id' => $invoiceId,
            ]);

            return;
        }
    }

    private function handlePixReversal(array $reversal): void
    {
        $externalId = $reversal['externalId'] ?? null;
        $reversalId = $reversal['id'] ?? null;
        $status = $reversal['status'] ?? null;

        // externalId formato: "refund-{paymentRefund_id}"
        $refundId = $externalId && str_starts_with($externalId, 'refund-')
            ? (int) substr($externalId, 7)
            : null;

        if (! $refundId) {
            Log::channel('payments')->warning('Stark pix-reversal: externalId não reconhecido', [
                'external_id' => $externalId,
                'reversal_id' => $reversalId,
            ]);

            return;
        }

        $refund = PaymentRefund::find($refundId);

        if (! $refund) {
            Log::channel('payments')->warning('Stark pix-reversal: PaymentRefund não encontrado', [
                'refund_id' => $refundId,
                'reversal_id' => $reversalId,
            ]);

            return;
        }

        // Idempotência
        if ($refund->status === 'succeeded') {
            Log::channel('payments')->info('Stark pix-reversal: já processado (duplicado ignorado)', [
                'refund_id' => $refundId,
            ]);

            return;
        }

        $refundService = app(RefundServiceInterface::class);

        if ($status === 'success') {
            $refundService->markSucceeded($refund, [
                'external_refund_id' => $reversalId,
                'external_status' => 'success',
                'raw' => $reversal,
            ]);

            return;
        }

        $refundService->markFailed(
            $refund,
            'STARK_REVERSAL_'.(strtoupper($status ?? 'FAILED')),
            $reversal['description'] ?? 'PIX reversal falhou no Stark Bank',
        );

        Log::channel('discord')->error('Stark pix-reversal falhou', [
            'type' => 'payments',
            'refund_id' => $refundId,
            'reversal_id' => $reversalId,
            'status' => $status,
            'raw' => $reversal,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::channel('discord')->error('Falha ao processar webhook Stark', [
            'type' => 'webhook',
            'event' => $this->event,
            'error' => $exception->getMessage(),
        ]);
    }
}
