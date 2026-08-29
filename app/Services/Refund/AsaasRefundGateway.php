<?php

namespace App\Services\Refund;

use App\Contracts\AsaasServiceInterface;
use App\Contracts\PaymentRefundGatewayInterface;
use App\Models\Payment;
use Illuminate\Support\Facades\Log;

class AsaasRefundGateway implements PaymentRefundGatewayInterface
{
    public function __construct(private readonly AsaasServiceInterface $asaas) {}

    public function requestRefund(Payment $payment, float $amount, ?string $reason = null): array
    {
        $asaasPaymentId = $payment->asaas_payment_id;

        if (blank($asaasPaymentId)) {
            Log::channel('payments')->error('Asaas estorno falhou: asaas_payment_id ausente', [
                'payment_id' => $payment->id,
            ]);

            return [
                'external_refund_id' => null,
                'status' => 'failed',
                'raw' => [
                    'errors' => [
                        ['description' => 'Pagamento Asaas sem ID externo para estorno.'],
                    ],
                ],
            ];
        }

        $raw = $this->asaas->refundPayment($asaasPaymentId, $amount);

        $status = $raw['status'] ?? '';
        $succeeded = in_array($status, ['REFUND_REQUESTED', 'REFUNDED', 'REFUND_IN_PROGRESS']);

        return [
            'external_refund_id' => $raw['id'] ?? null,
            'status' => $succeeded ? 'in_progress' : 'failed',
            'raw' => $raw,
        ];
    }
}
