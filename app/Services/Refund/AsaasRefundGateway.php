<?php

namespace App\Services\Refund;

use App\Contracts\AsaasServiceInterface;
use App\Contracts\PaymentRefundGatewayInterface;
use App\Models\Payment;

class AsaasRefundGateway implements PaymentRefundGatewayInterface
{
    public function __construct(private readonly AsaasServiceInterface $asaas) {}

    public function requestRefund(Payment $payment, float $amount, ?string $reason = null): array
    {
        $raw = $this->asaas->refundPayment($payment->asaas_payment_id, $amount);

        $status = $raw['status'] ?? '';
        $succeeded = in_array($status, ['REFUND_REQUESTED', 'REFUNDED', 'REFUND_IN_PROGRESS']);

        return [
            'external_refund_id' => $raw['id'] ?? null,
            'status' => $succeeded ? 'in_progress' : 'failed',
            'raw' => $raw,
        ];
    }
}
