<?php

namespace App\Contracts;

use App\Models\Coupon;
use App\Models\Order;
use Carbon\Carbon;

interface OrderServiceInterface
{
    public function createOrder(
        int $customerId,
        int $branchId,
        array $cart,
        string $notes,
        string $paymentMethod,
        string $orderType,
        string $status = 'pending',
        float $deliveryFee = 0.0,
        ?Coupon $coupon = null,
        ?Carbon $scheduledAt = null,
        float $extraDiscount = 0.0,
    ): Order;

    public function cancelOrder(Order $order, int $customerId): void;

    public function buildOrderSummaryFromOrder(Order $order): string;
}
