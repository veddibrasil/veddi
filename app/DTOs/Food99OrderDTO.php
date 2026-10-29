<?php

namespace App\DTOs;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Pedido da 99Food (orderNew.order_info ou GET /v1/order/order/detail) normalizado: centavos
 * viram reais, ids int64 viram string e valores mascarados pela política de privacidade da 99
 * viram vazios.
 *
 * Preço depende de delivery_type: entrega 99 (1) usa o modelo 1 (order_price, items_discount,
 * shop_paid_money...); entrega da loja (2) usa o modelo 2 (delivery_price, real_pay_price,
 * customer_need_paying_money, others_fees). Retirada (0) usa os campos que vierem.
 * [VALIDAR 99Food] fórmula dos totais contra pedidos reais da loja de teste.
 */
readonly class Food99OrderDTO
{
    public const DELIVERY_TYPE_PICKUP = 0;

    public const DELIVERY_TYPE_PLATFORM = 1;

    public const DELIVERY_TYPE_STORE = 2;

    public const PAY_METHOD_ONLINE = 1;

    public const PAY_METHOD_ON_DELIVERY = 2;

    /**
     * @param  array<int, array{appItemId: string, mduId: string, name: string, quantity: int, unitPrice: float, totalPrice: float, realPrice: float, remark: ?string, isWeight: bool, weight: ?string, weightUnit: ?string, subItems: array}>  $items
     * @param  array{street: string, number: string, complement: ?string, neighborhood: string, city: string, state: string, cep: string, latitude: ?float, longitude: ?float, reference: ?string}|null  $deliveryAddress
     * @param  array<int, array{label: string, value: float, shop_subsidy: float, type: ?string}>  $promotions
     * @param  array<int, array{label: string, value: float}>  $additionalFees
     */
    public function __construct(
        public string $orderId,
        public ?string $orderIndex,
        public int $status,
        public int $deliveryType,
        public int $fulfillmentMode,
        public string $customerName,
        public ?string $customerUid,
        public ?string $virtualPhoneNumber,
        public ?string $locator,
        public ?string $cpf,
        public ?array $deliveryAddress,
        public array $items,
        public float $itemsPrice,
        public float $itemsDiscount,
        public float $deliveryFee,
        public float $deliveryDiscount,
        public float $serviceFee,
        public float $reportedTotal,
        public int $payMethod,
        public ?int $payChannel,
        public ?float $changeFor,
        public float $pendingAmount,
        public bool $pendingEstimated,
        public float $shopPaidMoney,
        public float $shopSubsidy,
        public array $promotions,
        public array $additionalFees,
        public ?string $remark,
        public bool $needCutlery,
        public int $cancelPreference,
        public ?string $handoverCode,
        public ?string $takeawayCode,
        public ?string $pickupCode,
        public ?string $handoverPageUrl,
        public CarbonInterface $placedAt,
        public ?int $expectedCookEta,
        public ?int $expectedArrivedEta,
        public string $timezone,
        public bool $isTest,
        public array $rawPrices,
    ) {}

    public static function fromArray(array $info): self
    {
        $deliveryType = (int) ($info['delivery_type'] ?? self::DELIVERY_TYPE_PLATFORM);
        $fulfillmentMode = (int) ($info['fulfillment_mode'] ?? ($deliveryType === self::DELIVERY_TYPE_PICKUP ? 1 : 0));
        $price = is_array($info['price'] ?? null) ? $info['price'] + $info : $info;
        $others = is_array($price['others_fees'] ?? null) ? $price['others_fees'] : [];
        $address = is_array($info['receive_address'] ?? null) ? $info['receive_address'] : [];
        $payMethod = (int) ($info['pay_method'] ?? self::PAY_METHOD_ONLINE);

        $itemsPrice = self::money($price['order_price'] ?? 0);
        $itemsDiscount = self::money($price['items_discount'] ?? 0);
        $deliveryDiscount = self::money($price['delivery_discount'] ?? 0);
        $shopPaidMoney = self::money($price['shop_paid_money'] ?? 0);
        $mealTopUp = self::money($others['meal_top_up_price'] ?? $price['meal_top_up_price'] ?? 0);
        $servicePrice = self::money($others['service_price'] ?? $price['service_price'] ?? 0);
        $smallOrder = self::money($others['small_order_price'] ?? 0);

        if ($deliveryType === self::DELIVERY_TYPE_STORE) {
            // Modelo 2: a loja entrega e cobra o frete; delivery_price já vem com o desconto de entrega.
            $deliveryFee = self::money($price['delivery_price'] ?? 0);
            $serviceFee = round($servicePrice + $mealTopUp + $smallOrder, 2);
            $reportedTotal = isset($price['real_pay_price'])
                ? self::money($price['real_pay_price'])
                : round($itemsPrice - $itemsDiscount + $deliveryFee + $serviceFee, 2);
        } else {
            // Modelo 1 (entrega 99) e retirada: o frete é da 99, fora do valor da loja.
            $deliveryFee = 0.0;
            $serviceFee = round($servicePrice + $mealTopUp, 2);
            $reportedTotal = round($itemsPrice - $itemsDiscount + $serviceFee, 2);
        }

        [$pending, $estimated] = self::pendingAmount($payMethod, $deliveryType, $price, $shopPaidMoney, $reportedTotal);
        $promotions = self::promotions($info['promotions'] ?? []);

        return new self(
            orderId: (string) ($info['order_id'] ?? ''),
            orderIndex: self::text($info['order_index'] ?? null),
            status: (int) ($info['status'] ?? 100),
            deliveryType: $deliveryType,
            fulfillmentMode: $fulfillmentMode,
            customerName: self::customerName($address, $info),
            customerUid: self::customerUid($info, $address),
            virtualPhoneNumber: self::text($info['virtual_phone_number'] ?? null),
            locator: self::text($info['locator'] ?? null),
            cpf: self::text($address['cpf'] ?? $info['cpf'] ?? null),
            deliveryAddress: $fulfillmentMode === 1 ? null : self::address($address),
            items: self::items($info['order_items'] ?? []),
            itemsPrice: $itemsPrice,
            itemsDiscount: $itemsDiscount,
            deliveryFee: $deliveryFee,
            deliveryDiscount: $deliveryDiscount,
            serviceFee: $serviceFee,
            reportedTotal: $reportedTotal,
            payMethod: $payMethod,
            payChannel: isset($info['pay_channel']) ? (int) $info['pay_channel'] : null,
            changeFor: isset($info['change_for']) && (int) $info['change_for'] > 0 ? self::money($info['change_for']) : null,
            pendingAmount: $pending,
            pendingEstimated: $estimated,
            shopPaidMoney: $shopPaidMoney,
            shopSubsidy: round(array_sum(array_column($promotions, 'shop_subsidy')), 2),
            promotions: $promotions,
            additionalFees: array_values(array_filter([
                ['label' => 'Taxa de serviço', 'value' => $servicePrice],
                ['label' => 'Complemento de pedido mínimo', 'value' => $mealTopUp],
                ['label' => 'Taxa de pedido pequeno', 'value' => $smallOrder],
                ['label' => 'Gorjeta', 'value' => self::money($others['total_tip_money'] ?? 0)],
            ], fn (array $fee) => $fee['value'] > 0)),
            remark: self::text($info['remark'] ?? null),
            needCutlery: in_array($info['need_cutlery'] ?? false, [true, 1, '1'], true),
            cancelPreference: (int) ($info['c_cancel_preference'] ?? 0),
            handoverCode: self::text($info['handover_code'] ?? null),
            takeawayCode: self::text($info['takeaway_code'] ?? null),
            pickupCode: self::text($info['pickup_code'] ?? null),
            handoverPageUrl: self::text($info['handover_page_url'] ?? null),
            placedAt: self::timestamp($info['pay_time'] ?? null) ?? self::timestamp($info['create_time'] ?? null) ?? now(),
            expectedCookEta: isset($info['expected_cook_eta']) && (int) $info['expected_cook_eta'] > 0 ? (int) $info['expected_cook_eta'] : null,
            expectedArrivedEta: isset($info['expected_arrived_eta']) && (int) $info['expected_arrived_eta'] > 0 ? (int) $info['expected_arrived_eta'] : null,
            timezone: (string) ($info['timezone'] ?? config('food99.timezone', 'America/Sao_Paulo')),
            isTest: in_array($info['is_test'] ?? false, [true, 1, '1'], true),
            rawPrices: array_filter(array_intersect_key($price, array_flip([
                'order_price', 'items_discount', 'delivery_discount', 'delivery_price', 'shop_paid_money',
                'refund_price', 'meal_top_up_price', 'service_price', 'store_charged_delivery_price',
                'in_sale_refund_to_c_fee', 'real_price', 'real_pay_price', 'customer_need_paying_money',
                'logistics_cost',
            ])) + ($others !== [] ? ['others_fees' => $others] : []), fn ($value) => $value !== null),
        );
    }

    public function isPickup(): bool
    {
        return $this->fulfillmentMode === 1 || $this->deliveryType === self::DELIVERY_TYPE_PICKUP;
    }

    public function localOrderType(): string
    {
        return $this->isPickup() ? 'pickup' : 'delivery';
    }

    public function prepaidAmount(): float
    {
        return $this->payMethod === self::PAY_METHOD_ONLINE ? $this->reportedTotal : max(0.0, round($this->reportedTotal - $this->pendingAmount, 2));
    }

    /** Pagamento pra exibir (tela e cupom): forma, valor, se já foi pago e o troco. */
    public function paymentMethods(): array
    {
        $online = $this->payMethod === self::PAY_METHOD_ONLINE;
        $value = $online ? $this->reportedTotal : ($this->pendingAmount > 0 ? $this->pendingAmount : $this->reportedTotal);

        return [[
            'label' => self::payChannelLabel($this->payChannel, $this->payMethod),
            'value' => $value,
            'prepaid' => $online,
            'change_for' => $this->changeFor,
        ]];
    }

    /** Snapshot gravado em orders.external_metadata (ver Food99OrderDetails). */
    public function toMetadata(): array
    {
        return [
            'order_index' => $this->orderIndex,
            'display_id' => $this->orderIndex,
            'food99_order_id' => $this->orderId,
            'food99_status' => $this->status,
            'food99_stage' => 'CREATED',
            'delivery_type' => $this->deliveryType,
            'fulfillment_mode' => $this->fulfillmentMode,
            'placed_at' => $this->placedAt->toIso8601String(),
            'timezone' => $this->timezone,
            'is_test' => $this->isTest,
            'handover_code' => $this->handoverCode,
            'takeaway_code' => $this->takeawayCode,
            'pickup_code' => $this->pickupCode,
            'locator' => $this->locator,
            'virtual_phone_number' => $this->virtualPhoneNumber,
            'handover_page_url' => $this->handoverPageUrl,
            'customer_document' => $this->cpf,
            'remark' => $this->remark,
            'need_cutlery' => $this->needCutlery,
            'c_cancel_preference' => $this->cancelPreference,
            'expected_cook_eta' => $this->expectedCookEta,
            'expected_arrived_eta' => $this->expectedArrivedEta,
            'pay_method' => $this->payMethod,
            'pay_channel' => $this->payChannel,
            'change_for' => $this->changeFor,
            'prepaid_amount' => $this->prepaidAmount(),
            'pending_amount' => $this->pendingAmount,
            'pending_estimated' => $this->pendingEstimated,
            'shop_paid_money' => $this->shopPaidMoney,
            'shop_subsidy' => $this->shopSubsidy,
            'payment_methods' => $this->paymentMethods(),
            'promotions' => $this->promotions,
            'additional_fees' => $this->additionalFees,
            'reported_items_price' => $this->itemsPrice,
            'reported_items_discount' => $this->itemsDiscount,
            'reported_delivery_fee' => $this->deliveryFee,
            'reported_delivery_discount' => $this->deliveryDiscount,
            'reported_service_fee' => $this->serviceFee,
            'reported_total' => $this->reportedTotal,
            'raw_prices' => $this->rawPrices,
        ];
    }

    public static function payChannelLabel(?int $payChannel, int $payMethod = self::PAY_METHOD_ONLINE): string
    {
        return match (true) {
            $payChannel === 150 => 'Cartão',
            $payChannel === 153 => 'Dinheiro',
            $payChannel === 154 => 'Maquininha',
            in_array($payChannel, [212, 280], true) => 'PIX',
            $payChannel !== null && $payChannel >= 257 && $payChannel <= 260 => 'Vale-refeição',
            $payChannel === 262 => 'Maquininha (crédito)',
            $payChannel === 263 => 'Maquininha (débito)',
            $payChannel === 264 => 'Maquininha (vale-refeição)',
            $payChannel === 190 => '99Pay',
            $payChannel === 120 => 'Carteira 99',
            default => $payMethod === self::PAY_METHOD_ON_DELIVERY ? 'Pagamento na entrega' : 'Pago online',
        };
    }

    /** Dinheiro: pay_channel 153, ou pagamento na entrega sem canal informado. */
    public function isCash(): bool
    {
        return $this->payChannel === 153 || ($this->payChannel === null && $this->payMethod === self::PAY_METHOD_ON_DELIVERY);
    }

    /**
     * Quanto a loja recebe na entrega. Entrega 99 em dinheiro: shop_paid_money (o entregador paga
     * a loja), que só vem depois de atribuído — até lá usa o total, marcado como estimado.
     *
     * @return array{0: float, 1: bool}
     */
    private static function pendingAmount(int $payMethod, int $deliveryType, array $price, float $shopPaidMoney, float $total): array
    {
        if ($payMethod !== self::PAY_METHOD_ON_DELIVERY) {
            return [0.0, false];
        }

        if ($deliveryType === self::DELIVERY_TYPE_PLATFORM) {
            return $shopPaidMoney > 0 ? [$shopPaidMoney, false] : [$total, true];
        }

        $due = self::money($price['customer_need_paying_money'] ?? 0);

        return $due > 0 ? [$due, false] : [$total, true];
    }

    private static function items(array $rawItems): array
    {
        return array_values(array_map(fn (array $item) => [
            'appItemId' => (string) ($item['app_item_id'] ?? ''),
            'mduId' => (string) ($item['mdu_id'] ?? ''),
            'name' => (string) ($item['name'] ?? 'Item 99Food'),
            'quantity' => max(1, (int) ($item['amount'] ?? 1)),
            'unitPrice' => self::money($item['sku_price'] ?? 0),
            'totalPrice' => self::money($item['total_price'] ?? 0),
            'realPrice' => self::money($item['real_price'] ?? 0),
            'remark' => self::text($item['remark'] ?? null),
            // [VALIDAR 99Food] valor de sales_type na venda por peso.
            'isWeight' => in_array($item['sales_type'] ?? null, ['weight', 2, '2'], true),
            'weight' => isset($item['weight']) ? (string) $item['weight'] : null,
            'weightUnit' => self::text($item['weight_unit'] ?? null),
            'subItems' => self::subItems($item['sub_item_list'] ?? []),
        ], array_filter($rawItems, 'is_array')));
    }

    /** Subitens (complementos), recursivos. amount é por unidade do item pai. */
    private static function subItems(mixed $rawSubItems): array
    {
        if (! is_array($rawSubItems)) {
            return [];
        }

        return array_values(array_map(fn (array $sub) => [
            'appItemId' => (string) ($sub['app_item_id'] ?? ''),
            'groupId' => isset($sub['app_content_id']) ? (string) $sub['app_content_id'] : null,
            'name' => (string) ($sub['name'] ?? ''),
            'quantity' => max(1, (int) ($sub['amount'] ?? 1)),
            'unitPrice' => self::money($sub['sku_price'] ?? 0),
            'subItems' => self::subItems($sub['sub_item_list'] ?? []),
        ], array_filter($rawSubItems, 'is_array')));
    }

    /** @return array<int, array{label: string, value: float, shop_subsidy: float, type: ?string}> */
    private static function promotions(mixed $rawPromotions): array
    {
        if (! is_array($rawPromotions)) {
            return [];
        }

        return array_values(array_filter(array_map(fn (array $promo) => [
            'label' => self::text($promo['promo_name'] ?? $promo['name'] ?? null) ?? 'Promoção',
            'type' => isset($promo['promo_type']) ? (string) $promo['promo_type'] : null,
            'value' => self::money($promo['promo_discount'] ?? 0),
            'shop_subsidy' => self::money($promo['shop_subside_price'] ?? 0),
        ], array_filter($rawPromotions, 'is_array')), fn (array $promo) => $promo['value'] > 0 || $promo['shop_subsidy'] > 0));
    }

    private static function address(array $address): ?array
    {
        $street = self::text($address['street_name'] ?? null) ?? self::text($address['poi_address'] ?? null) ?? self::text($address['poi_display_name'] ?? null);

        if ($street === null) {
            return null;
        }

        return [
            'street' => $street,
            'number' => self::text($address['street_number'] ?? null) ?? self::text($address['house_number'] ?? null) ?? 'S/N',
            'complement' => self::text($address['complement'] ?? null),
            'neighborhood' => self::text($address['district'] ?? null) ?? '',
            'city' => self::text($address['city'] ?? null) ?? '',
            'state' => self::text($address['state'] ?? null) ?? '',
            'cep' => preg_replace('/\D/', '', (string) ($address['postal_code'] ?? '')),
            'latitude' => is_numeric($address['poi_lat'] ?? null) ? (float) $address['poi_lat'] : null,
            'longitude' => is_numeric($address['poi_lng'] ?? null) ? (float) $address['poi_lng'] : null,
            'reference' => self::text($address['reference'] ?? null),
        ];
    }

    private static function customerName(array $address, array $info): string
    {
        $name = trim(implode(' ', array_filter([
            self::text($address['first_name'] ?? null),
            self::text($address['last_name'] ?? null),
        ])));

        return $name !== '' ? $name : (self::text($address['name'] ?? $info['customer_name'] ?? null) ?? 'Cliente 99Food');
    }

    /** uid 0 = cliente mascarado pela política de privacidade: sem identificador estável. */
    private static function customerUid(array $info, array $address): ?string
    {
        $uid = $info['uid'] ?? $address['uid'] ?? null;

        return is_numeric($uid) && (string) $uid !== '0' ? (string) $uid : null;
    }

    private static function money(mixed $cents): float
    {
        return is_numeric($cents) ? round(((float) $cents) / 100, 2) : 0.0;
    }

    private static function timestamp(mixed $value): ?CarbonInterface
    {
        return is_numeric($value) && (int) $value > 0
            ? Carbon::createFromTimestamp((int) $value, config('app.timezone'))
            : null;
    }

    /** Texto limpo; valor mascarado pela 99 ("privacy protection", "***") vira null. */
    private static function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' || preg_match('/privacy|\*{3}/i', $value) === 1 ? null : $value;
    }
}
