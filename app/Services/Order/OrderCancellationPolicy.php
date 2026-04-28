<?php

namespace App\Services\Order;

use App\Models\Order;
use RuntimeException;

class OrderCancellationPolicy
{
    /**
     * Statuses that can be cancelled without triggering a refund.
     * Payment has not been confirmed yet.
     */
    private const PRE_PAYMENT_STATUSES = ['pending', 'awaiting_payment'];

    /**
     * Statuses that require a refund when cancelled by customer.
     */
    private const CUSTOMER_CANCELLABLE_WITH_REFUND = ['paid', 'preparing'];

    /**
     * Admin can cancel any status that isn't already terminal.
     */
    private const TERMINAL_STATUSES = ['cancelled', 'refunded', 'delivered'];

    /**
     * Validate that a customer may cancel this order.
     *
     * @throws RuntimeException
     */
    public function authorizeCustomerCancel(Order $order): void
    {
        $cancellable = array_merge(self::PRE_PAYMENT_STATUSES, self::CUSTOMER_CANCELLABLE_WITH_REFUND);

        if (! in_array($order->status, $cancellable)) {
            throw new RuntimeException('Pedido não pode ser cancelado no momento.');
        }
    }

    /**
     * Validate that an admin may cancel this order.
     *
     * @throws RuntimeException
     */
    public function authorizeAdminCancel(Order $order): void
    {
        if (in_array($order->status, self::TERMINAL_STATUSES)) {
            throw new RuntimeException("Pedido já está em status terminal ({$order->status}) e não pode ser cancelado.");
        }
    }

    /**
     * Whether cancelling this order should trigger a payment refund.
     * Only applies when payment has been confirmed (paid status or beyond).
     */
    public function requiresRefund(Order $order): bool
    {
        if (in_array($order->status, self::PRE_PAYMENT_STATUSES)) {
            return false;
        }

        if ($order->payment_method === 'CASH') {
            return false;
        }

        $payment = $order->payment;

        return $payment && $payment->status === 'paid';
    }
}
