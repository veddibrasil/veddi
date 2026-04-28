<?php

namespace App\Services\Refund;

use App\Contracts\PaymentRefundGatewayInterface;
use App\Models\Payment;
use Illuminate\Support\Facades\Log;
use StarkBank\Invoice;
use StarkBank\Request;

class StarkRefundGateway implements PaymentRefundGatewayInterface
{
    public function requestRefund(Payment $payment, float $amount, ?string $reason = null): array
    {
        $invoicePayment = Invoice::payment($payment->stark_payment_id);

        $result = Request::post('/pix-reversal/', [
            'reversals' => [[
                'amount'     => (int) round($amount * 100),
                'endToEndId' => $invoicePayment->endToEndId,
                'externalId' => 'refund-'.$payment->id,
                'reason'     => 'operationalFlaw',
            ]],
        ]);

        $reversal = $result['reversals'][0] ?? null;
        $reversalId = $reversal['id'] ?? null;

        if (! $reversalId) {
            Log::channel('payments')->error('Stark PIX reversal rejeitado', [
                'stark_payment_id' => $payment->stark_payment_id,
                'amount' => $amount,
                'raw' => $result,
            ]);

            return ['external_refund_id' => null, 'status' => 'failed', 'raw' => $result];
        }

        Log::channel('payments')->info('Stark PIX reversal criado, aguardando confirmação', [
            'reversal_id' => $reversalId,
            'stark_payment_id' => $payment->stark_payment_id,
            'end_to_end_id' => $invoicePayment->endToEndId,
            'amount' => $amount,
        ]);

        return [
            'external_refund_id' => $reversalId,
            'status' => 'in_progress',
            'raw' => $reversal,
        ];
    }
}
