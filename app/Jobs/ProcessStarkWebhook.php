<?php

namespace App\Jobs;

use App\Contracts\TransactionServiceInterface;
use App\Contracts\WalletServiceInterface;
use App\Events\OrderStatusUpdated;
use App\Models\Order;
use App\Models\Payment;
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
        // Stark Bank Invoice eventos: "created" | "credited" | "overdue" | "expired" | "canceled"
        // Somente "credited" indica pagamento confirmado
        if ($this->event !== 'credited') {
            Log::channel('webhook')->debug('Stark webhook: evento ignorado', [
                'event' => $this->event,
            ]);

            return;
        }

        $invoiceId = $this->payload['event']['log']['invoice']['id']
            ?? $this->payload['log']['invoice']['id']
            ?? null;

        if (! $invoiceId) {
            Log::channel('webhook')->warning('Stark webhook: invoice ID ausente no payload', [
                'event' => $this->event,
                'payload' => $this->payload,
            ]);

            return;
        }

        $payment = Payment::where('stark_payment_id', $invoiceId)->first();

        if (! $payment) {
            Log::channel('webhook')->warning('Stark webhook: Payment não encontrado', [
                'event' => $this->event,
                'invoice_id' => $invoiceId,
            ]);

            return;
        }

        // Idempotência: ignora duplicatas
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

        DB::transaction(function () use ($order, $payment, $invoiceId) {
            $payment->update([
                'status' => 'paid',
                'paid_at' => now(),
                'webhook_payload' => $this->payload,
            ]);

            $order->update(['status' => 'paid']);

            Log::channel('payments')->info('Pagamento PIX Stark confirmado', [
                'order_id' => $order->id,
                'invoice_id' => $invoiceId,
                'amount' => $payment->amount,
            ]);

            app(WalletServiceInterface::class)->creditForOrder($order, $payment);
        });

        // Cria transação de escrow para controle de liberação (D+2 para PIX)
        try {
            app(TransactionServiceInterface::class)->createForPayment($order, $payment);
        } catch (\Throwable $e) {
            Log::channel('discord')->error('Stark: Falha ao criar CompanyTransaction (não-fatal)', [
                'type' => 'payments',
                'order_id' => $order->id,
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
            // Não propaga: WalletEntry já criada com sucesso
        }

        OrderStatusUpdated::dispatch($order->fresh());
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
