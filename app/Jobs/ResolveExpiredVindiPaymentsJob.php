<?php

namespace App\Jobs;

use App\Contracts\TransactionServiceInterface;
use App\Contracts\WalletServiceInterface;
use App\Events\OrderStatusUpdated;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Order\OrderService;
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
            ->orderBy('updated_at')->orderBy('id')
            ->limit(50)
            ->get();

        Log::channel('payments')->info('ResolveExpiredVindiPayments: verificando pagamentos pendentes expirados', [
            'count' => $stuckPayments->count(),
        ]);

        foreach ($stuckPayments as $payment) {
            $payment->touch();
            $this->resolvePayment($vindi, $payment);
        }
        $this->expireAbandonedChats($vindi);
    }

    private function expireAbandonedChats(VindiService $vindi): void
    {
        // Global maintenance: select chat orders across tenants, then retain their company on every order lookup.
        $orders = Order::withoutGlobalScopes()->where('channel', 'chat')->where('payment_on_delivery', false)
            ->whereIn('status', ['pending', 'awaiting_payment'])
            ->where('created_at', '<=', now()->subMinutes(max(1, (int) config('payments.chat_checkout_timeout_minutes', 35))))
            ->whereDoesntHave('payments', fn ($query) => $query->where('status', 'paid'))
            ->orderBy('updated_at')->orderBy('id')->limit(50)->get();

        foreach ($orders as $order) {
            $order->touch(); // Rotate unresolved records so the next batch can progress.
            $payments = $order->payments()->whereIn('status', ['pending', 'expired'])->get();
            if ($payments->contains(fn ($payment) => $payment->expires_at?->isAfter(now()->subMinutes(5)))) {
                continue;
            }
            $safeToCancel = true;
            foreach ($payments as $payment) {
                if (! $payment->vindi_transaction_token) {
                    continue;
                }
                try {
                    $status = $vindi->getTransactionStatus($payment->vindi_transaction_token);
                    if ($status === 'Aprovada') {
                        $this->markAsPaid($payment);
                        $safeToCancel = false;
                    } elseif (! in_array($status, ['Cancelada', 'Não Aprovada', 'Reprovada', 'Aguardando Pagamento', 'Aguardando pagamento'], true)) {
                        $safeToCancel = false;
                    }
                } catch (\Throwable) {
                    $safeToCancel = false;
                }
            }
            if ($safeToCancel && app(OrderService::class)->cancelBySystem($order, 'Pagamento não confirmado dentro do prazo', 'payment_expired')) {
                $order->payments()->where('status', 'pending')->update(['status' => 'expired']);
            }
        }
    }

    private function resolvePayment(VindiService $vindi, Payment $payment): void
    {
        try {
            $statusName = $vindi->getTransactionStatus($payment->vindi_transaction_token);

            Log::channel('payments')->info('ResolveExpiredVindiPayments: status consultado', [
                'payment_id' => $payment->id,
                'vindi_status' => $statusName,
            ]);

            match ($statusName) {
                'Aprovada' => $this->markAsPaid($payment),
                'Cancelada', 'Não Aprovada', 'Reprovada' => $this->markAsExpired($payment),
                // Ainda em processamento na Yapay — aguarda próxima rodada
                default => null,
            };
        } catch (\Throwable $e) {
            Log::channel('payments')->warning('ResolveExpiredVindiPayments: falha ao consultar status', [
                'payment_id' => $payment->id,
                'exception_type' => $e::class,
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

                // Lock no pedido: concorre com cancelBySystem.
                $order = Order::withoutGlobalScopes()->whereKey($payment->order_id)->lockForUpdate()->first();
                if (! $order) {
                    return;
                }

                $payment->update([
                    'status' => 'paid',
                    'paid_at' => now(),
                    'idempotency_key' => $idempotencyKey,
                ]);

                // Mesmas regras do webhook (ProcessVindiWebhook::handlePaymentApproved): pedido
                // cancelado não reabre (estorna); pedido que já avançou mantém a etapa; agendado
                // para o futuro continua 'scheduled'.
                $closed = in_array($order->status, ['cancelled', 'refunded'], true);

                // Mesma regra do webhook: segunda tentativa paga não é estornada sozinha (o estorno
                // marcaria o pedido inteiro como estornado), só avisa para estorno manual.
                if (! $closed && $order->payments()->whereKeyNot($payment->id)->where('status', 'paid')->exists()) {
                    Log::channel('discord')->critical('Vindi: segunda cobrança paga para pedido já pago — estornar manualmente', [
                        'type' => 'payments', 'order_id' => $order->id, 'payment_id' => $payment->id,
                        'company_id' => $order->company_id, 'amount' => $payment->amount,
                    ]);
                }

                if (in_array($order->status, ['pending', 'awaiting_payment'], true)) {
                    $order->update(['status' => ($order->scheduled_at && $order->scheduled_at->isFuture()) ? 'scheduled' : 'paid']);
                }

                app(WalletServiceInterface::class)->creditForOrder($order, $payment);
                app(TransactionServiceInterface::class)->createForPayment($order, $payment);

                if ($closed) {
                    app(OrderService::class)->refundPaymentForClosedOrder($order, $payment, 'vindi_polling');

                    return;
                }

                OrderStatusUpdated::dispatch($order->fresh());

                Log::channel('payments')->info('ResolveExpiredVindiPayments: pagamento recuperado via polling', [
                    'payment_id' => $payment->id,
                    'order_id' => $order->id,
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

            // PIX do chat já está 'awaiting_payment' quando expira (ProcessOrder muda o status
            // assim que a cobrança é criada). cancelBySystem só cancela pending/awaiting_payment e
            // devolve o estoque baixado na criação do pedido.
            $order = Order::withoutGlobalScopes()->find($payment->order_id);
            if ($order && ! $order->payments()->whereKeyNot($payment->id)->where('status', 'pending')->exists()) {
                app(OrderService::class)->cancelBySystem($order, 'Pagamento não confirmado dentro do prazo', 'payment_expired');
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
            'exception_type' => $exception::class,
        ]);
    }
}
