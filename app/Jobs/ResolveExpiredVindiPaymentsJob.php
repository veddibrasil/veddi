<?php

namespace App\Jobs;

use App\Contracts\TransactionServiceInterface;
use App\Contracts\WalletServiceInterface;
use App\Events\OrderStatusUpdated;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Payment\VindiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Resolve pagamentos Vindi presos em status 'pending' após expiração.
 *
 * Cenário: o cliente pagou o PIX/cartão mas o webhook não chegou (timeout, fila, etc).
 * O job consulta o status real na Yapay e processa o resultado correto.
 *
 * Roda a cada 15 minutos. Processa apenas payments com expires_at < now() - 5min
 * para dar margem ao webhook chegar antes do polling interferir.
 */
class ResolveExpiredVindiPaymentsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(VindiService $vindi): void
    {
        // Busca pagamentos Vindi pendentes que expiraram há pelo menos 5 minutos.
        // A margem evita race condition com o webhook legítimo.
        $cutoff = now()->subMinutes(5);

        $stuckPayments = Payment::where('payment_gateway', 'vindi')
            ->where('status', 'pending')
            ->whereNotNull('vindi_transaction_token')
            ->where('expires_at', '<', $cutoff)
            ->whereNotNull('expires_at')
            ->limit(50)
            ->get();

        if ($stuckPayments->isEmpty()) {
            return;
        }

        Log::channel('payments')->info('ResolveExpiredVindiPayments: verificando pagamentos pendentes expirados', [
            'count' => $stuckPayments->count(),
        ]);

        foreach ($stuckPayments as $payment) {
            $this->resolvePayment($vindi, $payment);
        }
    }

    private function resolvePayment(VindiService $vindi, Payment $payment): void
    {
        try {
            $statusName = $vindi->getTransactionStatus($payment->vindi_transaction_token);

            Log::channel('payments')->info('ResolveExpiredVindiPayments: status consultado', [
                'payment_id' => $payment->id,
                'vindi_token' => $payment->vindi_transaction_token,
                'vindi_status' => $statusName,
            ]);

            match ($statusName) {
                'Aprovada' => $this->markAsPaid($payment),
                'Cancelada', 'Não Aprovada', 'Reprovada', 'unknown' => $this->markAsExpired($payment),
                // Ainda em processamento na Yapay — aguarda próxima rodada
                default => null,
            };
        } catch (\Throwable $e) {
            Log::channel('payments')->warning('ResolveExpiredVindiPayments: falha ao consultar status', [
                'payment_id' => $payment->id,
                'vindi_token' => $payment->vindi_transaction_token,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function markAsPaid(Payment $payment): void
    {
        $idempotencyKey = hash('sha256', 'vindi:'.$payment->vindi_transaction_token.':paid');

        try {
            DB::transaction(function () use ($payment, $idempotencyKey) {
                $payment = Payment::where('id', $payment->id)
                    ->lockForUpdate()
                    ->first();

                if (! $payment || $payment->status === 'paid') {
                    return;
                }

                $order = Order::find($payment->order_id);
                if (! $order) {
                    return;
                }

                $payment->update([
                    'status' => 'paid',
                    'paid_at' => now(),
                    'idempotency_key' => $idempotencyKey,
                ]);

                $order->update(['status' => 'paid']);

                app(WalletServiceInterface::class)->creditForOrder($order, $payment);
                app(TransactionServiceInterface::class)->createForPayment($order, $payment);

                OrderStatusUpdated::dispatch($order->fresh());

                Log::channel('payments')->info('ResolveExpiredVindiPayments: pagamento recuperado via polling', [
                    'payment_id' => $payment->id,
                    'order_id' => $order->id,
                    'vindi_token' => $payment->vindi_transaction_token,
                ]);
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // Webhook chegou simultaneamente — ignorar, já processado
        }
    }

    private function markAsExpired(Payment $payment): void
    {
        DB::transaction(function () use ($payment) {
            $payment = Payment::where('id', $payment->id)
                ->lockForUpdate()
                ->first();

            if (! $payment || $payment->status !== 'pending') {
                return;
            }

            $payment->update(['status' => 'expired']);

            $order = Order::find($payment->order_id);
            if ($order && $order->status === 'pending') {
                $order->update(['status' => 'cancelled']);
                OrderStatusUpdated::dispatch($order->fresh());
            }

            Log::channel('payments')->info('ResolveExpiredVindiPayments: pagamento expirado resolvido', [
                'payment_id' => $payment->id,
                'order_id' => $payment->order_id,
            ]);
        });
    }

    public function failed(\Throwable $exception): void
    {
        Log::channel('discord')->error('Job de resolução de pagamentos expirados Vindi falhou', [
            'type' => 'payments',
            'error' => $exception->getMessage(),
        ]);
    }
}
