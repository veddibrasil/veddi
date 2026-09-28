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
     * Registra um reembolso já feito fora do gateway (dinheiro devolvido pela loja).
     * Nunca aciona o gateway: cria o PaymentRefund com gateway=offline e já o conclui.
     */
    public function recordOfflineRefund(
        Order $order,
        Payment $payment,
        string $requesterType,
        ?int $requesterId = null,
        ?string $reason = null,
        array $details = [],
    ): PaymentRefund;

    /**
     * Mark a refund as succeeded and apply wallet debit. Idempotent.
     */
    public function markSucceeded(PaymentRefund $refund, array $gatewayResponse): void;

    /**
     * Mark a refund as failed.
     */
    public function markFailed(PaymentRefund $refund, string $code, string $message): void;
}
