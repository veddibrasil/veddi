<?php

namespace App\DTOs;

use Carbon\Carbon;

/**
 * Mapeia a resposta da Order API do iFood (GET /order/v1.0/orders/{id}).
 * Estrutura baseada na doc pública da Order API — validar campo a campo contra
 * payload real no sandbox antes de produção (ver Fase 8 do plano de integração).
 */
readonly class IfoodOrderDTO
{
    /**
     * @param  array<int, array{ifoodItemId: string, externalCode: ?string, name: string, quantity: int, unitPrice: float, options: array<int, array{ifoodOptionId: string, externalCode: ?string, name: string, quantity: int, hasNestedOptions: bool}>}>  $items
     * @param  array{street: string, number: string, complement: ?string, neighborhood: string, city: string, state: string, cep: string, latitude: ?float, longitude: ?float}|null  $deliveryAddress
     */
    public function __construct(
        public string $ifoodOrderId,
        public string $merchantId,
        public ?string $displayId,
        public string $orderType, // DELIVERY | TAKEOUT
        public string $customerName,
        public ?string $customerPhone,
        public ?array $deliveryAddress,
        public array $items,
        public float $subtotal,
        public float $deliveryFee,
        public float $discount,
        public float $total,
        public string $paymentType, // esperado: PREPAID
        public Carbon $createdAt,
    ) {}

    public static function fromArray(array $data): self
    {
        $delivery = $data['delivery'] ?? null;
        $address = $delivery['deliveryAddress'] ?? null;

        return new self(
            ifoodOrderId: (string) $data['id'],
            merchantId: (string) ($data['merchant']['id'] ?? ''),
            displayId: $data['displayId'] ?? null,
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
            discount: (float) ($data['total']['discount'] ?? 0.0),
            total: (float) ($data['total']['orderAmount'] ?? 0.0),
            paymentType: (string) ($data['payments']['methods'][0]['type'] ?? 'PREPAID'),
            createdAt: Carbon::parse($data['createdAt'] ?? now()),
        );
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
            ];
        }, $rawOptions);
    }
}
