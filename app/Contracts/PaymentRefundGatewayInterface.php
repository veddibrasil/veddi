<?php

namespace App\Contracts;

use App\Models\Payment;

interface PaymentRefundGatewayInterface
{
    /**
     * Request a refund via the gateway.
     *
     * @return array{external_refund_id?: string, status: string, raw: array}
     */
    public function requestRefund(Payment $payment, float $amount, ?string $reason = null): array;
}
