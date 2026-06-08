<?php

namespace App\Services\Refund;

use App\Contracts\PaymentRefundGatewayInterface;
use App\Contracts\RefundServiceInterface;
use App\Contracts\WalletServiceInterface;
use App\Events\OrderStatusUpdated;
use App\Jobs\ProcessRefund;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentRefund;
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

            ProcessRefund::dispatch($refund)->onQueue('critical');

            return $refund;
        });
    }

    public function markSucceeded(PaymentRefund $refund, array $gatewayResponse): void
    {
        DB::transaction(function () use ($refund, $gatewayResponse) {
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

        return 'asaas'; // legado
    }

    public function getGatewayDriver(string $gateway): PaymentRefundGatewayInterface
    {
        return match ($gateway) {
            'vindi' => app(VindiRefundGateway::class),
            'stark' => app(StarkRefundGateway::class), // legado: pagamentos Stark anteriores à migração Vindi
            default => app(AsaasRefundGateway::class),
        };
    }
}
