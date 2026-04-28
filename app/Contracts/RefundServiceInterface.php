<?php

namespace App\Contracts;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentRefund;

interface RefundServiceInterface
{
    /**
     * Initiate a refund for a paid order. Creates PaymentRefund(status=requested)
     * and dispatches ProcessRefund job. Idempotent per payment+amount+status.
     */
    public function initiateRefund(
        Order $order,
        Payment $payment,
        float $amount,
        string $requesterType,
        ?int $requesterId = null,
        ?string $reason = null,
    ): PaymentRefund;

    /**
     * Mark a refund as succeeded and apply wallet debit.
     */
    public function markSucceeded(PaymentRefund $refund, array $gatewayResponse): void;

    /**
     * Mark a refund as failed.
     */
    public function markFailed(PaymentRefund $refund, string $code, string $message): void;
}
