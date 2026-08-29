<?php

namespace App\Services\Refund;

use App\Contracts\PaymentRefundGatewayInterface;
use App\Models\Payment;
use Illuminate\Support\Facades\Log;

class OfflineRefundGateway implements PaymentRefundGatewayInterface
{
    public function requestRefund(Payment $payment, float $amount, ?string $reason = null): array
    {
        Log::channel('payments')->warning('Estorno automático indisponível para pagamento offline/manual', [
            'payment_id' => $payment->id,
            'payment_gateway' => $payment->payment_gateway,
            'amount' => $amount,
        ]);

        return [
            'external_refund_id' => null,
            'status' => 'failed',
            'raw' => [
                'errors' => [
                    ['description' => 'Pagamento offline/manual requer estorno operacional fora do gateway.'],
                ],
            ],
        ];
    }
}
