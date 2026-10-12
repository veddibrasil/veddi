<?php

namespace App\Services\Refund;

use App\Contracts\PaymentRefundGatewayInterface;
use App\Contracts\RefundServiceInterface;
use App\Contracts\WalletServiceInterface;
use App\Events\OrderStatusUpdated;
use App\Jobs\ProcessRefund;
use App\Models\CompanyTransaction;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Services\Finance\BalanceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RefundService implements RefundServiceInterface
{
    public function initiateRefund(
        Order $order,
        Payment $payment,
        float $amount,
        string $requesterType,
        ?int $requesterId = null,
        ?string $reason = null,
    ): PaymentRefund {
        return DB::transaction(function () use ($order, $payment, $amount, $requesterType, $requesterId, $reason) {
            // Idempotência: não cria se já existe refund "requested" ou "in_progress" para o mesmo pagamento+valor
            $existing = PaymentRefund::where('payment_id', $payment->id)
                ->where('amount', $amount)
                ->whereIn('status', ['requested', 'in_progress', 'succeeded'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            $refund = PaymentRefund::create([
                'company_id' => $order->company_id,
                'order_id' => $order->id,
                'payment_id' => $payment->id,
                'gateway' => $payment->payment_gateway ?? $this->resolveGateway($payment),
                'amount' => $amount,
                'status' => 'requested',
                'reason' => $reason,
                'requested_by_type' => $requesterType,
                'requested_by_id' => $requesterId,
                'requested_at' => now(),
            ]);

            Log::channel('payments')->info('Estorno solicitado', [
                'refund_id' => $refund->id,
                'order_id' => $order->id,
                'payment_id' => $payment->id,
                'amount' => $amount,
                'gateway' => $refund->gateway,
                'requester_type' => $requesterType,
            ]);

            // Só depois do commit: o worker não pode pegar um estorno que ainda pode ser
            // revertido (ou concluído offline logo em seguida) nesta mesma transação.
            ProcessRefund::dispatch($refund)->onQueue('critical')->afterCommit();

            return $refund;
        });
    }

    public function recordOfflineRefund(
        Order $order,
        Payment $payment,
        string $requesterType,
        ?int $requesterId = null,
        ?string $reason = null,
        array $details = [],
    ): PaymentRefund {
        return DB::transaction(function () use ($order, $payment, $requesterType, $requesterId, $reason, $details) {
            $amount = (float) $payment->amount;

            $existing = PaymentRefund::where('payment_id', $payment->id)
                ->where('amount', $amount)
                ->whereIn('status', ['requested', 'in_progress', 'succeeded'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            // gateway=offline e sem ProcessRefund: o dinheiro já foi devolvido pela loja,
            // então o gateway nunca pode ser chamado (seria reembolso em dobro).
            $refund = PaymentRefund::create([
                'company_id' => $order->company_id,
                'order_id' => $order->id,
                'payment_id' => $payment->id,
                'gateway' => 'offline',
                'amount' => $amount,
                'status' => 'requested',
                'reason' => $reason,
                'requested_by_type' => $requesterType,
                'requested_by_id' => $requesterId,
                'requested_at' => now(),
            ]);

            $this->markSucceeded($refund, [
                'external_refund_id' => null,
                'external_status' => 'OFFLINE',
                'raw' => $details,
            ]);

            Log::channel('payments')->info('Estorno offline registrado', [
                'refund_id' => $refund->id,
                'order_id' => $order->id,
                'payment_id' => $payment->id,
                'amount' => $amount,
                'requester_type' => $requesterType,
            ]);

            return $refund->fresh();
        });
    }

    public function markSucceeded(PaymentRefund $refund, array $gatewayResponse): void
    {
        DB::transaction(function () use ($refund, $gatewayResponse) {
            $refund = PaymentRefund::whereKey($refund->id)->lockForUpdate()->first() ?? $refund;

            // Webhook repetido ou job + webhook concluindo o mesmo estorno.
            if ($refund->status === 'succeeded') {
                return;
            }

            $refund->loadMissing(['order', 'payment']);

            $refund->update([
                'status' => 'succeeded',
                'external_refund_id' => $gatewayResponse['external_refund_id'] ?? $refund->external_refund_id,
                'external_status' => $gatewayResponse['external_status'] ?? null,
                'raw_response' => $gatewayResponse['raw'] ?? null,
                'processed_at' => now(),
            ]);

            $refund->payment->update(['status' => 'refunded']);
            $refund->order->update(['status' => 'refunded']);

            app(WalletServiceInterface::class)->debitForRefund($refund->order, $refund->payment);

            // O saldo (BalanceService) sai de company_transactions: sem isto o pedido
            // estornado continuava contando em "A receber"/disponível.
            CompanyTransaction::withoutGlobalScopes()
                ->where('payment_id', $refund->payment_id)
                ->whereIn('status', ['pending', 'confirmed', 'released'])
                ->update(['status' => 'refunded', 'updated_at' => now()]);

            app(BalanceService::class)->broadcastUpdate($refund->company_id ?? $refund->order->company_id);

            OrderStatusUpdated::dispatch($refund->order->fresh());

            Log::channel('payments')->info('Estorno concluído', [
                'refund_id' => $refund->id,
                'order_id' => $refund->order_id,
                'amount' => $refund->amount,
            ]);
        });
    }

    public function markFailed(PaymentRefund $refund, string $code, string $message): void
    {
        $refund->update([
            'status' => 'failed',
            'failure_code' => $code,
            'failure_message' => $message,
            'processed_at' => now(),
        ]);

        Log::channel('discord')->error('Estorno falhou', [
            'type' => 'payments',
            'refund_id' => $refund->id,
            'order_id' => $refund->order_id,
            'code' => $code,
            'message' => $message,
        ]);
    }

    public function resolveGateway(Payment $payment): string
    {
        if ($payment->vindi_transaction_token) {
            return 'vindi';
        }

        if ($payment->asaas_payment_id) {
            return 'asaas';
        }

        return 'offline';
    }

    public function getGatewayDriver(string $gateway): PaymentRefundGatewayInterface
    {
        return match ($gateway) {
            'vindi' => app(VindiRefundGateway::class),
            'asaas' => app(AsaasRefundGateway::class),
            default => app(OfflineRefundGateway::class),
        };
    }
}
