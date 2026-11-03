<?php

namespace App\Services\Payment;

use App\Events\OrderStatusUpdated;
use App\Jobs\ProcessOrder;
use App\Jobs\ProcessVindiWebhook;
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
        if ($this->currentVindiChargeApproved($order)) {
            return false;
        }

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

    /**
     * O QR da Vindi continua pagável depois do prazo do chat (vale 24 h no portal). Antes de
     * trocar a cobrança, pergunta à Vindi se a atual já foi paga (webhook atrasado ou perdido):
     * paga, confirma pelo mesmo fluxo do webhook e não gera um segundo PIX pagável.
     * Vindi sem resposta segue o fluxo antigo (renova) para não travar o cliente.
     */
    private function currentVindiChargeApproved(Order $order): bool
    {
        $payment = $order->payments()->reorder('id', 'desc')->first();
        $token = (string) $payment?->vindi_transaction_token;

        if (! $payment || $payment->status !== 'pending' || $payment->payment_gateway !== 'vindi' || $token === ''
            || empty(config('payments.vindi_token_account')) || str_starts_with($token, 'sim_vindi_')) {
            return false;
        }

        try {
            if (app(VindiService::class)->getTransactionStatus($token) !== 'Aprovada') {
                return false;
            }
        } catch (\Throwable $e) {
            Log::channel('payments')->warning('Renovação PIX: falha ao consultar a cobrança atual na Vindi', [
                'order_id' => $order->id,
                'payment_id' => $payment->id,
                'exception_type' => $e::class,
            ]);

            return false;
        }

        // Já sabemos que foi paga: nunca renova, mesmo se a confirmação local falhar agora
        // (o webhook retentado e o ResolveExpiredVindiPaymentsJob concluem depois).
        try {
            ProcessVindiWebhook::dispatchSync($token, 'Aprovada', []);
        } catch (\Throwable $e) {
            Log::channel('discord')->critical('Renovação PIX: cobrança paga na Vindi sem confirmação local', [
                'type' => 'payments',
                'order_id' => $order->id,
                'payment_id' => $payment->id,
                'exception_type' => $e::class,
            ]);
        }

        return true;
    }
}
