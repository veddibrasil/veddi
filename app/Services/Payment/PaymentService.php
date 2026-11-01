<?php

namespace App\Services\Payment;

use App\Events\OrderStatusUpdated;
use App\Jobs\ProcessOrder;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentService
{
    /**
     * Enfileira o job de processamento de pagamento.
     *
     * @param  array  $cardData  Dados do cartão (holderName, number, expiryMonth, expiryYear, ccv, cpfCnpj, postalCode, addressNumber)
     */
    public function dispatchPayment(
        Order $order,
        Customer $customer,
        ?Company $company,
        string $paymentMethod,
        int $installments = 1,
        array $cardData = []
    ): void {
        ProcessOrder::dispatch($order, $customer, $company, $paymentMethod, $installments, $cardData)
            ->onQueue('critical');

        Log::channel('payments')->info('Job de pagamento enfileirado', [
            'order_id' => $order->id,
            'payment_method' => $paymentMethod,
            'installments' => $installments,
            'total' => $order->total,
            'queue' => 'critical',
        ]);
    }

    /**
     * Simula o pagamento de um pedido (apenas em modo debug).
     */
    public function simulatePayment(int $orderId): void
    {
        abort_unless(config('app.debug'), 403);

        $payment = Payment::where('order_id', $orderId)->first();

        if (! $payment) {
            Log::channel('payments')->warning('simulatePayment: nenhum Payment para o pedido', [
                'order_id' => $orderId,
            ]);

            return;
        }

        $payment->update(['status' => 'paid', 'paid_at' => now()]);

        // Simula o webhook: pedido agendado para o futuro continua 'scheduled'.
        $order = $payment->order;
        $order->update(['status' => ($order->scheduled_at && $order->scheduled_at->isFuture()) ? 'scheduled' : 'paid']);
        OrderStatusUpdated::dispatch($payment->order->fresh());

        Log::channel('payments')->warning('Pagamento simulado (debug)', [
            'order_id' => $orderId,
            'payment_id' => $payment->id,
        ]);
    }

    /**
     * Expira o pagamento atual e gera uma nova cobrança.
     */
    public function expireAndRenew(Order $order, Customer $customer, ?Company $company, string $paymentMethod): bool
    {
        $renew = DB::transaction(function () use ($order) {
            $locked = Order::withoutGlobalScopes()->where('company_id', $order->company_id)->whereKey($order->id)->lockForUpdate()->first();
            if (! $locked || $locked->payment_on_delivery || ! in_array($locked->status, ['pending', 'awaiting_payment'], true)
                || $locked->payments()->where('status', 'paid')->exists()) {
                return false;
            }
            $payment = $locked->payments()->reorder('id', 'desc')->lockForUpdate()->first();
            if (! $payment || $payment->status !== 'pending' || ! $payment->expires_at?->isPast()) {
                return false;
            }
            $payment->update(['status' => 'expired']);

            return true;
        });
        if ($renew) {
            session()->forget('payment_token_'.$order->id);
            ProcessOrder::dispatch($order->fresh(), $customer, $company, $paymentMethod)->onQueue('critical');
        }

        return $renew;
    }
}
