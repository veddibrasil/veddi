<?php

namespace App\DTOs;

use Carbon\Carbon;

/**
 * Mapeia a resposta da Order API do iFood (GET /order/v1.0/orders/{id}).
 * Formato conferido contra pedidos de teste reais da loja de homologação em 28/09/2026
 * (pagamento com bandeira, taxa adicional, código de coleta, localizador do 0800,
 * agendamento). Campos que os pedidos de teste não trazem (troco, cupom, CPF na nota,
 * observações) seguem a referência pública da Order API.
 */
readonly class IfoodOrderDTO
{
    /**
     * @param  array<int, array{ifoodItemId: string, externalCode: ?string, name: string, quantity: int, unitPrice: float, observations: ?string, options: array<int, array{ifoodOptionId: string, externalCode: ?string, name: string, quantity: int, hasNestedOptions: bool}>}>  $items
     * @param  array{street: string, number: string, complement: ?string, neighborhood: string, city: string, state: string, cep: string, latitude: ?float, longitude: ?float}|null  $deliveryAddress
     * @param  array<int, array{method: string, type: ?string, prepaid: bool, value: float, brand: ?string, wallet: ?string, change_for: ?float}>  $paymentMethods
     * @param  array<int, array{value: float, target: ?string, campaign: ?string, sponsors: array<int, array{name: string, value: float, description: ?string}>}>  $benefits
     * @param  array<int, array{type: ?string, description: ?string, value: float}>  $additionalFeesList
     */
    public function __construct(
        public string $ifoodOrderId,
        public string $merchantId,
        public ?string $displayId,
        public string $orderType, // DELIVERY | TAKEOUT | DINE_IN | INDOOR
        public string $customerName,
        public ?string $customerPhone,
        public ?array $deliveryAddress,
        public array $items,
        public float $subtotal,
        public float $deliveryFee,
        public float $discount,
        public float $total,
        public string $paymentType,
        public Carbon $createdAt,
        public float $additionalFees = 0.0,
        public string $orderTiming = 'IMMEDIATE', // IMMEDIATE | SCHEDULED
        public ?string $customerId = null,
        public ?string $customerDocument = null,
        public ?string $phoneLocalizer = null,
        public ?Carbon $phoneLocalizerExpiresAt = null,
        public ?string $deliveredBy = null, // IFOOD | MERCHANT
        public ?string $pickupCode = null,
        public ?string $extraInfo = null,
        public ?string $deliveryObservations = null,
        public ?string $deliveryReference = null,
        public ?Carbon $scheduledStart = null,
        public ?Carbon $scheduledEnd = null,
        public ?Carbon $preparationStartAt = null,
        public float $prepaidAmount = 0.0,
        public float $pendingAmount = 0.0,
        public array $paymentMethods = [],
        public array $benefits = [],
        public array $additionalFeesList = [],
        public ?string $indoorTable = null,
        public bool $isTest = false,
    ) {}

    public static function fromArray(array $data): self
    {
        $delivery = $data['delivery'] ?? null;
        $address = $delivery['deliveryAddress'] ?? null;
        $total = (float) ($data['total']['orderAmount'] ?? 0.0);
        $methods = self::mapPaymentMethods($data['payments']['methods'] ?? []);
        [$prepaid, $pending] = self::resolvePaymentSplit($data['payments'] ?? [], $methods, $total);

        return new self(
            ifoodOrderId: (string) $data['id'],
            merchantId: (string) ($data['merchant']['id'] ?? ''),
            displayId: isset($data['displayId']) ? (string) $data['displayId'] : null,
            orderType: (string) ($data['orderType'] ?? 'DELIVERY'),
            customerName: (string) ($data['customer']['name'] ?? 'Cliente iFood'),
            customerPhone: $data['customer']['phone']['number'] ?? null,
            deliveryAddress: $address ? [
                'street' => (string) ($address['streetName'] ?? ''),
                'number' => (string) ($address['streetNumber'] ?? 'S/N'),
                'complement' => $address['complement'] ?? null,
                'neighborhood' => (string) ($address['neighborhood'] ?? ''),
                'city' => (string) ($address['city'] ?? ''),
                'state' => (string) ($address['state'] ?? ''),
                'cep' => (string) ($address['postalCode'] ?? ''),
                'latitude' => isset($address['coordinates']['latitude']) ? (float) $address['coordinates']['latitude'] : null,
                'longitude' => isset($address['coordinates']['longitude']) ? (float) $address['coordinates']['longitude'] : null,
            ] : null,
            items: self::mapItems($data['items'] ?? []),
            subtotal: (float) ($data['total']['subTotal'] ?? 0.0),
            deliveryFee: (float) ($data['total']['deliveryFee'] ?? 0.0),
            discount: (float) ($data['total']['benefits'] ?? $data['total']['discount'] ?? 0.0),
            total: $total,
            paymentType: (string) ($data['payments']['methods'][0]['type'] ?? 'PREPAID'),
            createdAt: self::date($data['createdAt'] ?? null) ?? now(),
            additionalFees: (float) ($data['total']['additionalFees'] ?? 0.0),
            orderTiming: (string) ($data['orderTiming'] ?? 'IMMEDIATE'),
            customerId: isset($data['customer']['id']) ? (string) $data['customer']['id'] : null,
            customerDocument: self::text($data['customer']['documentNumber'] ?? $data['customer']['taxPayerIdentificationNumber'] ?? null),
            phoneLocalizer: self::text($data['customer']['phone']['localizer'] ?? null),
            phoneLocalizerExpiresAt: self::date($data['customer']['phone']['localizerExpiration'] ?? null),
            deliveredBy: self::text($delivery['deliveredBy'] ?? null),
            pickupCode: self::text($delivery['pickupCode'] ?? $data['takeout']['pickupCode'] ?? null),
            extraInfo: self::text($data['extraInfo'] ?? null),
            deliveryObservations: self::text($delivery['observations'] ?? $data['takeout']['observations'] ?? $data['indoor']['observations'] ?? $data['dineIn']['observations'] ?? null),
            deliveryReference: self::text($address['reference'] ?? null),
            scheduledStart: self::date($data['schedule']['deliveryDateTimeStart'] ?? null),
            scheduledEnd: self::date($data['schedule']['deliveryDateTimeEnd'] ?? null),
            preparationStartAt: self::date($data['preparationStartDateTime'] ?? null),
            prepaidAmount: $prepaid,
            pendingAmount: $pending,
            paymentMethods: $methods,
            benefits: self::mapBenefits($data['benefits'] ?? []),
            additionalFeesList: self::mapAdditionalFees($data['additionalFees'] ?? []),
            indoorTable: self::text($data['indoor']['table'] ?? $data['dineIn']['table'] ?? null),
            isTest: (bool) ($data['isTest'] ?? false),
        );
    }

    public function isScheduled(): bool
    {
        return $this->orderTiming === 'SCHEDULED' && $this->scheduledStart !== null;
    }

    /** Tipo de pedido interno: entrega só quando o iFood manda DELIVERY; retirada e consumo no local não levam endereço. */
    public function localOrderType(): string
    {
        return $this->orderType === 'DELIVERY' ? 'delivery' : 'pickup';
    }

    /**
     * Snapshot gravado em orders.external_metadata. É o que a tela do pedido e as
     * comandas usam pra mostrar as informações obrigatórias do iFood.
     */
    public function toMetadata(): array
    {
        return [
            'display_id' => $this->displayId,
            'order_type' => $this->orderType,
            'order_timing' => $this->orderTiming,
            'ifood_status' => 'PLACED',
            'created_at' => $this->createdAt->toIso8601String(),
            'is_test' => $this->isTest,
            'delivered_by' => $this->deliveredBy,
            'pickup_code' => $this->pickupCode,
            'customer_phone' => $this->customerPhone,
            'phone_localizer' => $this->phoneLocalizer,
            'phone_localizer_expires_at' => $this->phoneLocalizerExpiresAt?->toIso8601String(),
            'customer_document' => $this->customerDocument,
            'extra_info' => $this->extraInfo,
            'delivery_observations' => $this->deliveryObservations,
            'delivery_reference' => $this->deliveryReference,
            'indoor_table' => $this->indoorTable,
            'schedule_start' => $this->scheduledStart?->toIso8601String(),
            'schedule_end' => $this->scheduledEnd?->toIso8601String(),
            'preparation_start_at' => $this->preparationStartAt?->toIso8601String(),
            'payment_type' => $this->paymentType,
            'prepaid_amount' => $this->prepaidAmount,
            'pending_amount' => $this->pendingAmount,
            'payment_methods' => $this->paymentMethods,
            'benefits' => $this->benefits,
            'additional_fees' => $this->additionalFeesList,
            'ifood_reported_subtotal' => $this->subtotal,
            'ifood_reported_delivery_fee' => $this->deliveryFee,
            'ifood_reported_discount' => $this->discount,
            'ifood_reported_additional_fees' => $this->additionalFees,
            'ifood_reported_total' => $this->total,
        ];
    }

    private static function mapItems(array $rawItems): array
    {
        return array_map(function (array $item) {
            return [
                'ifoodItemId' => (string) ($item['id'] ?? ''),
                'externalCode' => $item['externalCode'] ?? null,
                'name' => (string) ($item['name'] ?? ''),
                'quantity' => (int) ($item['quantity'] ?? 1),
                'unitPrice' => (float) ($item['unitPrice'] ?? 0.0),
                'observations' => self::text($item['observations'] ?? null),
                'options' => self::mapOptions($item['options'] ?? []),
            ];
        }, $rawItems);
    }

    private static function mapOptions(array $rawOptions): array
    {
        return array_map(function (array $option) {
            // Complemento-de-complemento (2+ níveis): o iFood pode aninhar 'options'
            // dentro de um option (ex.: combo com sub-escolha). O schema interno
            // (product_option_groups → product_options) só suporta 1 nível — ver
            // IfoodOrderMapper::mapToCart, que falha o evento nesse caso.
            $hasNestedOptions = ! empty($option['options']);

            return [
                'ifoodOptionId' => (string) ($option['id'] ?? ''),
                'externalCode' => $option['externalCode'] ?? null,
                'name' => (string) ($option['name'] ?? ''),
                'quantity' => (int) ($option['quantity'] ?? 1),
                'hasNestedOptions' => $hasNestedOptions,
                'customizations' => self::mapOptions($option['customizations'] ?? $option['customization'] ?? []),
            ];
        }, $rawOptions);
    }

    private static function mapPaymentMethods(array $rawMethods): array
    {
        return array_values(array_map(fn (array $method) => [
            'method' => (string) ($method['method'] ?? $method['type'] ?? ''),
            'type' => self::text($method['type'] ?? null),
            'prepaid' => (bool) ($method['prepaid'] ?? (($method['type'] ?? null) !== 'OFFLINE')),
            'value' => (float) ($method['value'] ?? 0.0),
            'brand' => self::text($method['card']['brand'] ?? null),
            'wallet' => self::text($method['wallet']['name'] ?? null),
            'change_for' => isset($method['cash']['changeFor']) ? (float) $method['cash']['changeFor'] : null,
        ], $rawMethods));
    }

    /**
     * Quanto o iFood já recebeu (prepaid) e quanto a loja cobra na entrega (pending).
     * Usa os totais do bloco payments; sem eles, soma os métodos; sem métodos, trata
     * o pedido como pago online (formato antigo dos pedidos de teste).
     *
     * @param  array<int, array{prepaid: bool, value: float}>  $methods
     * @return array{0: float, 1: float}
     */
    private static function resolvePaymentSplit(array $payments, array $methods, float $total): array
    {
        if (isset($payments['prepaid']) || isset($payments['pending'])) {
            return [(float) ($payments['prepaid'] ?? 0.0), (float) ($payments['pending'] ?? 0.0)];
        }

        $valued = array_filter($methods, fn (array $method) => $method['value'] > 0);
        if ($valued === []) {
            return [$total, 0.0];
        }

        $prepaid = array_sum(array_map(fn ($m) => $m['prepaid'] ? $m['value'] : 0.0, $valued));
        $pending = array_sum(array_map(fn ($m) => $m['prepaid'] ? 0.0 : $m['value'], $valued));

        return [round($prepaid, 2), round($pending, 2)];
    }

    private static function mapBenefits(array $rawBenefits): array
    {
        return array_values(array_map(fn (array $benefit) => [
            'value' => (float) ($benefit['value'] ?? 0.0),
            'target' => self::text($benefit['target'] ?? null),
            'campaign' => self::text($benefit['campaign']['name'] ?? null),
            'sponsors' => array_values(array_map(fn (array $sponsor) => [
                'name' => (string) ($sponsor['name'] ?? ''),
                'value' => (float) ($sponsor['value'] ?? 0.0),
                'description' => self::text($sponsor['description'] ?? null),
            ], $benefit['sponsorshipValues'] ?? [])),
        ], $rawBenefits));
    }

    private static function mapAdditionalFees(array $rawFees): array
    {
        return array_values(array_map(fn (array $fee) => [
            'type' => self::text($fee['type'] ?? null),
            'description' => self::text($fee['description'] ?? null),
            'value' => (float) ($fee['value'] ?? 0.0),
        ], $rawFees));
    }

    private static function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** Datas do iFood vêm em UTC; converte pro fuso da aplicação antes de gravar em coluna datetime. */
    private static function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return Carbon::parse($value)->setTimezone(config('app.timezone'));
    }
}
