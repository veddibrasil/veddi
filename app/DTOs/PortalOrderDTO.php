<?php

namespace App\DTOs;

readonly class PortalOrderDTO
{
    /**
     * @param  array<int, array{external_item_id: string, name: string, quantity: int, unit_price: float}>  $items
     */
    public function __construct(
        public string $externalOrderId,
        public string $externalMerchantId,
        public string $orderType,
        public float $subtotal,
        public float $deliveryFee,
        public float $total,
        public string $paymentMethod,
        public bool $isPaid,
        public array $items,
        public ?string $customerName = null,
        public ?string $customerPhone = null,
    ) {}
}
