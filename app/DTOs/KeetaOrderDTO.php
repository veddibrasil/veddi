<?php

namespace App\DTOs;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Pedido da Keeta (GET /v1/orders/{id}, schema Order do Open Delivery) normalizado. Valores
 * vêm em reais com até 4 casas (viram 2), ids int64 como string e dados pessoais
 * criptografados (prefixo ENC_) viram vazios, a menos que venham em $decrypted (batchDecrypt,
 * só entrega própria).
 *
 * Valores locais seguem a regra dos outros marketplaces: o pedido do VEDDI mostra o que é da
 * loja. Frete e taxas recebidos pela Keeta (receivedBy MARKETPLACE/LOGISTIC_SERVICES) ficam
 * fora do total local e entram só no snapshot (keeta_order_amount).
 * [VALIDAR Keeta] fórmula dos totais contra pedidos reais da loja de teste.
 */
readonly class KeetaOrderDTO
{
    public const DELIVERED_BY_MERCHANT = 'MERCHANT';

    public const DELIVERED_BY_MARKETPLACE = 'MARKETPLACE';

    /** Rótulos das formas de pagamento (payments.methods[].method). */
    public const METHOD_LABELS = [
        'CREDIT' => 'Cartão de crédito',
        'DEBIT' => 'Cartão de débito',
        'CREDIT_DEBIT' => 'Cartão',
        'MEAL_VOUCHER' => 'Vale-refeição',
        'FOOD_VOUCHER' => 'Vale-alimentação',
        'DIGITAL_WALLET' => 'Carteira digital',
        'PIX' => 'PIX',
        'CASH' => 'Dinheiro',
        'COUPON' => 'Cupom',
        'REDEEM' => 'Resgate',
        'PREPAID_REDEEM' => 'Resgate pré-pago',
        'OTHER' => 'Outro',
    ];

    /**
     * @param  array<int, array{id: string, externalCode: string, name: string, quantity: float, unit: string, unitPrice: float, optionsPrice: float, totalPrice: float, remark: ?string, options: array<int, array{id: string, externalCode: string, name: string, quantity: float, unitPrice: float, totalPrice: float, remark: ?string}>}>  $items
     * @param  array{street: string, number: string, complement: ?string, neighborhood: string, city: string, state: string, cep: string, latitude: ?float, longitude: ?float, reference: ?string, formatted: ?string}|null  $deliveryAddress
     * @param  array<int, array{label: string, value: float, shop_subsidy: float, target: string, code: ?string}>  $promotions
     * @param  array<int, array{label: string, value: float, type: string, received_by: string}>  $fees
     * @param  array<int, array{label: string, value: float, prepaid: bool, change_for: ?float, method: string, brand: ?string}>  $paymentMethods
     */
    public function __construct(
        public string $orderId,
        public string $displayId,
        public string $type,
        public string $orderTiming,
        public ?string $merchantRef,
        public ?string $customerId,
        public string $customerName,
        public ?string $customerPhone,
        public ?string $customerDocument,
        public ?int $customerOrdersCount,
        public ?string $deliveredBy,
        public ?array $deliveryAddress,
        public ?string $pickupCode,
        public array $items,
        public float $itemsPrice,
        public float $itemsDiscount,
        public float $deliveryFee,
        public float $serviceFee,
        public float $reportedTotal,
        public float $orderAmount,
        public float $marketplaceFees,
        public float $prepaid,
        public float $pending,
        public array $paymentMethods,
        public array $promotions,
        public array $fees,
        public float $shopSubsidy,
        public ?string $remark,
        public CarbonInterface $placedAt,
        public ?CarbonInterface $preparationStartAt,
        public ?CarbonInterface $estimatedDeliveryAt,
        public ?CarbonInterface $scheduledStart,
        public ?CarbonInterface $scheduledEnd,
        public ?string $lastEvent,
        public array $rawTotals,
    ) {}

    /**
     * @param  array<string, string>  $decrypted  cipherText ENC_ => texto (batchDecrypt)
     */
    public static function fromArray(array $order, array $decrypted = []): self
    {
        $text = fn (mixed $value) => self::text($value, $decrypted);
        $type = strtoupper((string) ($order['type'] ?? 'DELIVERY'));
        $delivery = is_array($order['delivery'] ?? null) ? $order['delivery'] : [];
        $customer = is_array($order['customer'] ?? null) ? $order['customer'] : [];
        $total = is_array($order['total'] ?? null) ? $order['total'] : [];
        $payments = is_array($order['payments'] ?? null) ? $order['payments'] : [];
        $deliveredBy = $type === 'DELIVERY' ? strtoupper((string) ($delivery['deliveredBy'] ?? self::DELIVERED_BY_MARKETPLACE)) : null;
        $selfDelivery = $deliveredBy === self::DELIVERED_BY_MERCHANT;

        $items = self::items($order['items'] ?? [], $text);
        $promotions = self::promotions($order['discounts'] ?? []);
        $fees = self::fees($order['otherFees'] ?? []);

        $itemsPrice = self::money($total['itemsPrice'] ?? null) ?: round(array_sum(array_column($items, 'totalPrice')), 2);
        $itemsDiscount = round(array_sum(array_map(
            fn (array $promo) => in_array($promo['target'], ['CART', 'ITEM'], true) ? $promo['value'] : 0.0,
            $promotions,
        )), 2);
        $deliveryDiscount = round(array_sum(array_map(fn (array $promo) => $promo['target'] === 'DELIVERY_FEE' ? $promo['value'] : 0.0, $promotions)), 2);

        $merchantFee = fn (array $fee) => $fee['received_by'] === 'MERCHANT';
        $deliveryFee = $selfDelivery
            ? max(0.0, round(array_sum(array_map(fn (array $fee) => $fee['type'] === 'DELIVERY_FEE' && $merchantFee($fee) ? $fee['value'] : 0.0, $fees)) - $deliveryDiscount, 2))
            : 0.0;
        $serviceFee = round(array_sum(array_map(fn (array $fee) => $fee['type'] !== 'DELIVERY_FEE' && $merchantFee($fee) ? $fee['value'] : 0.0, $fees)), 2);
        $marketplaceFees = round(array_sum(array_map(fn (array $fee) => $merchantFee($fee) ? 0.0 : $fee['value'], $fees)), 2);

        $orderAmount = self::money($total['orderAmount'] ?? null);
        $reportedTotal = max(0.0, round($itemsPrice - $itemsDiscount + $deliveryFee + $serviceFee, 2));

        $schedule = is_array($order['schedule'] ?? null) ? $order['schedule'] : [];

        return new self(
            orderId: (string) ($order['id'] ?? ''),
            displayId: (string) ($order['displayId'] ?? $order['id'] ?? ''),
            type: $type,
            orderTiming: strtoupper((string) ($order['orderTiming'] ?? 'INSTANT')),
            merchantRef: isset($order['merchant']['id']) ? (string) $order['merchant']['id'] : null,
            customerId: isset($customer['id']) && (string) $customer['id'] !== '' ? mb_substr((string) $customer['id'], 0, 64) : null,
            customerName: $text($customer['name'] ?? null) ?? 'Cliente Keeta',
            customerPhone: $text($customer['phone']['number'] ?? null),
            customerDocument: $text($customer['documentNumber'] ?? null),
            customerOrdersCount: isset($customer['ordersCountOnMerchant']) ? (int) $customer['ordersCountOnMerchant'] : null,
            deliveredBy: $deliveredBy,
            deliveryAddress: $type === 'DELIVERY' ? self::address(is_array($delivery['deliveryAddress'] ?? null) ? $delivery['deliveryAddress'] : [], $text) : null,
            pickupCode: $text($delivery['pickupCode'] ?? null),
            items: $items,
            itemsPrice: $itemsPrice,
            itemsDiscount: $itemsDiscount,
            deliveryFee: $deliveryFee,
            serviceFee: $serviceFee,
            reportedTotal: $reportedTotal,
            orderAmount: $orderAmount,
            marketplaceFees: $marketplaceFees,
            prepaid: self::money($payments['prepaid'] ?? null),
            pending: self::money($payments['pending'] ?? null),
            paymentMethods: self::paymentMethods($payments['methods'] ?? []),
            promotions: $promotions,
            fees: $fees,
            shopSubsidy: round(array_sum(array_column($promotions, 'shop_subsidy')), 2),
            remark: $text($order['extraInfo'] ?? null),
            placedAt: self::date($order['createdAt'] ?? null) ?? now(),
            preparationStartAt: self::date($order['preparationStartDateTime'] ?? null),
            estimatedDeliveryAt: self::date($delivery['estimatedDeliveryDateTime'] ?? null),
            scheduledStart: self::date($schedule['scheduledDateTimeStart'] ?? null),
            scheduledEnd: self::date($schedule['scheduledDateTimeEnd'] ?? null),
            lastEvent: isset($order['lastEvent']) ? strtoupper((string) $order['lastEvent']) : null,
            rawTotals: array_filter([
                'items_price' => self::money($total['itemsPrice'] ?? null),
                'other_fees' => self::money($total['otherFees'] ?? null),
                'discount' => self::money($total['discount'] ?? null),
                'order_amount' => $orderAmount,
                'prepaid' => self::money($payments['prepaid'] ?? null),
                'pending' => self::money($payments['pending'] ?? null),
            ], fn ($value) => $value !== 0.0),
        );
    }

    /** Textos ENC_ do pedido que a loja precisa ler (entrega própria): telefone e endereço. */
    public static function encryptedValues(array $order): array
    {
        $address = $order['delivery']['deliveryAddress'] ?? [];

        return array_values(array_filter([
            $order['customer']['phone']['number'] ?? null,
            $address['district'] ?? null,
            $address['number'] ?? null,
            $address['complement'] ?? null,
            $address['formattedAddress'] ?? null,
        ], fn ($value) => is_string($value) && str_starts_with($value, 'ENC_')));
    }

    public function isPickup(): bool
    {
        return $this->type !== 'DELIVERY';
    }

    public function isSelfDelivery(): bool
    {
        return $this->deliveredBy === self::DELIVERED_BY_MERCHANT;
    }

    public function isScheduled(): bool
    {
        return $this->orderTiming === 'SCHEDULED' && $this->scheduledStart !== null;
    }

    public function localOrderType(): string
    {
        return $this->isPickup() ? 'pickup' : 'delivery';
    }

    /** Parte que a loja cobra na entrega, limitada ao total local. */
    public function pendingAmount(): float
    {
        return min($this->reportedTotal, $this->pending);
    }

    public function prepaidAmount(): float
    {
        return max(0.0, round($this->reportedTotal - $this->pendingAmount(), 2));
    }

    /** Snapshot gravado em orders.external_metadata (ver KeetaOrderDetails). */
    public function toMetadata(): array
    {
        return [
            'display_id' => $this->displayId,
            'keeta_order_id' => $this->orderId,
            'keeta_stage' => 'CREATED',
            'keeta_last_event' => $this->lastEvent,
            'order_type' => $this->type,
            'order_timing' => $this->orderTiming,
            'delivered_by' => $this->deliveredBy,
            'placed_at' => $this->placedAt->toIso8601String(),
            'preparation_start_at' => $this->preparationStartAt?->toIso8601String(),
            'estimated_delivery_at' => $this->estimatedDeliveryAt?->toIso8601String(),
            'scheduled_start' => $this->scheduledStart?->toIso8601String(),
            'scheduled_end' => $this->scheduledEnd?->toIso8601String(),
            'pickup_code' => $this->pickupCode,
            'customer_phone' => $this->customerPhone,
            'customer_document' => $this->customerDocument,
            'customer_orders_count' => $this->customerOrdersCount,
            'remark' => $this->remark,
            'prepaid_amount' => $this->prepaidAmount(),
            'pending_amount' => $this->pendingAmount(),
            'payment_methods' => $this->paymentMethods,
            'promotions' => $this->promotions,
            'additional_fees' => array_values(array_map(
                fn (array $fee) => ['label' => $fee['label'], 'value' => $fee['value']],
                array_filter($this->fees, fn (array $fee) => $fee['received_by'] === 'MERCHANT' && $fee['type'] !== 'DELIVERY_FEE'),
            )),
            'marketplace_fees' => $this->marketplaceFees,
            'shop_subsidy' => $this->shopSubsidy,
            'reported_items_price' => $this->itemsPrice,
            'reported_items_discount' => $this->itemsDiscount,
            'reported_delivery_fee' => $this->deliveryFee,
            'reported_service_fee' => $this->serviceFee,
            'reported_total' => $this->reportedTotal,
            'keeta_order_amount' => $this->orderAmount,
            'raw_totals' => $this->rawTotals,
            // Linhas da Keeta: o id do item é o que vai em invalidItems/outOfStockItems no cancelamento.
            'order_items' => array_map(fn (array $item) => [
                'id' => $item['id'],
                'external_code' => $item['externalCode'],
                'name' => $item['name'],
                'quantity' => $item['quantity'],
            ], $this->items),
        ];
    }

    private static function items(mixed $rawItems, callable $text): array
    {
        return array_values(array_map(fn (array $item) => [
            'id' => (string) ($item['id'] ?? ''),
            'externalCode' => (string) ($item['externalCode'] ?? ''),
            'name' => (string) ($item['name'] ?? 'Item Keeta'),
            'quantity' => max(0.001, (float) ($item['quantity'] ?? 1)),
            'unit' => strtoupper((string) ($item['unit'] ?? 'UN')),
            'unitPrice' => self::money($item['unitPrice'] ?? null),
            'optionsPrice' => self::money($item['optionsPrice'] ?? null),
            'totalPrice' => self::money($item['totalPrice'] ?? null),
            'remark' => $text($item['specialInstructions'] ?? null),
            'options' => array_values(array_map(fn (array $option) => [
                'id' => (string) ($option['id'] ?? ''),
                'externalCode' => (string) ($option['externalCode'] ?? ''),
                'name' => (string) ($option['name'] ?? 'Complemento'),
                'quantity' => max(0.001, (float) ($option['quantity'] ?? 1)),
                'unitPrice' => self::money($option['unitPrice'] ?? null),
                'totalPrice' => self::money($option['totalPrice'] ?? null),
                'remark' => $text($option['specialInstructions'] ?? null),
            ], array_filter(is_array($item['options'] ?? null) ? $item['options'] : [], 'is_array'))),
        ], array_filter(is_array($rawItems) ? $rawItems : [], 'is_array')));
    }

    private static function promotions(mixed $rawDiscounts): array
    {
        return array_values(array_filter(array_map(function (array $discount) {
            $sponsors = array_filter(is_array($discount['sponsorshipValues'] ?? null) ? $discount['sponsorshipValues'] : [], 'is_array');
            $shop = array_sum(array_map(
                fn (array $sponsor) => in_array(strtoupper((string) ($sponsor['name'] ?? '')), ['MERCHANT', 'CHAIN'], true) ? self::money($sponsor['amount'] ?? null) : 0.0,
                $sponsors,
            ));
            $code = collect($sponsors)->pluck('discountCode')->filter()->first();
            $target = strtoupper((string) ($discount['target'] ?? 'CART'));

            return [
                'label' => match ($target) {
                    'DELIVERY_FEE' => 'Desconto no frete',
                    'ITEM' => 'Desconto no item',
                    default => 'Desconto',
                }.($code ? " ({$code})" : ''),
                'value' => self::money($discount['amount'] ?? null),
                'shop_subsidy' => round($shop, 2),
                'target' => $target,
                'code' => $code !== null ? (string) $code : null,
            ];
        }, array_filter(is_array($rawDiscounts) ? $rawDiscounts : [], 'is_array')), fn (array $promo) => $promo['value'] > 0));
    }

    private static function fees(mixed $rawFees): array
    {
        return array_values(array_filter(array_map(fn (array $fee) => [
            'label' => (string) ($fee['name'] ?? match (strtoupper((string) ($fee['type'] ?? ''))) {
                'DELIVERY_FEE' => 'Taxa de entrega',
                'SERVICE_FEE' => 'Taxa de serviço',
                'TIP' => 'Gorjeta',
                'MIN_ORDER_FEE' => 'Taxa de pedido mínimo',
                default => 'Taxa',
            }),
            'value' => self::money($fee['price'] ?? null),
            'type' => strtoupper((string) ($fee['type'] ?? '')),
            'received_by' => strtoupper((string) ($fee['receivedBy'] ?? 'MARKETPLACE')),
        ], array_filter(is_array($rawFees) ? $rawFees : [], 'is_array')), fn (array $fee) => $fee['value'] > 0));
    }

    private static function paymentMethods(mixed $rawMethods): array
    {
        return array_values(array_map(function (array $method) {
            $code = strtoupper((string) ($method['method'] ?? 'OTHER'));
            $changeFor = is_numeric($method['changeFor'] ?? null) && (float) $method['changeFor'] > 0 ? round((float) $method['changeFor'], 2) : null;

            return [
                'label' => (self::METHOD_LABELS[$code] ?? 'Pagamento').(isset($method['brand']) && $method['brand'] !== 'OTHER' ? ' '.ucfirst(strtolower((string) $method['brand'])) : ''),
                'value' => round((float) ($method['value'] ?? 0), 2),
                'prepaid' => strtoupper((string) ($method['type'] ?? 'PREPAID')) === 'PREPAID',
                'change_for' => $changeFor,
                'method' => $code,
                'brand' => isset($method['brand']) ? (string) $method['brand'] : null,
            ];
        }, array_filter(is_array($rawMethods) ? $rawMethods : [], 'is_array')));
    }

    private static function address(array $address, callable $text): ?array
    {
        $street = $text($address['street'] ?? null);
        $formatted = $text($address['formattedAddress'] ?? null);

        if ($street === null && $formatted === null) {
            return null;
        }

        $coordinates = is_array($address['coordinates'] ?? null) ? $address['coordinates'] : [];

        return [
            'street' => $street ?? $formatted,
            'number' => $text($address['number'] ?? null) ?? 'S/N',
            'complement' => $text($address['complement'] ?? null),
            'neighborhood' => $text($address['district'] ?? null) ?? '',
            'city' => $text($address['city'] ?? null) ?? '',
            'state' => $text($address['state'] ?? null) ?? '',
            'cep' => preg_replace('/\D/', '', (string) ($address['postalCode'] ?? '')),
            'latitude' => is_numeric($coordinates['latitude'] ?? null) ? (float) $coordinates['latitude'] : null,
            'longitude' => is_numeric($coordinates['longitude'] ?? null) ? (float) $coordinates['longitude'] : null,
            'reference' => $text($address['reference'] ?? null),
            'formatted' => $formatted,
        ];
    }

    /** Price {value, currency} ou número: reais com até 4 casas → 2 casas. */
    private static function money(mixed $price): float
    {
        $value = is_array($price) ? ($price['value'] ?? null) : $price;

        return is_numeric($value) ? round((float) $value, 2) : 0.0;
    }

    private static function date(mixed $value): ?CarbonInterface
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }

    /** Texto limpo; dado criptografado (ENC_) sem tradução vira null. */
    private static function text(mixed $value, array $decrypted = []): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);
        if (str_starts_with($value, 'ENC_')) {
            $value = trim((string) ($decrypted[$value] ?? ''));
        }

        return $value === '' || str_starts_with($value, 'ENC_') ? null : $value;
    }
}
