<?php

use App\DTOs\Food99OrderDTO;

function food99FixtureInfo(string $name): array
{
    $raw = str_replace('{PRODUCT_ID}', '1', file_get_contents(base_path("tests/Fixtures/food99/{$name}.json")));

    return json_decode($raw, true, 512, JSON_BIGINT_AS_STRING);
}

it('normaliza entrega 99 paga online (modelo 1) em reais', function () {
    $dto = Food99OrderDTO::fromArray(food99FixtureInfo('order-platform-delivery'));

    expect($dto->orderId)->toBe('5764607523034934815')
        ->and($dto->orderIndex)->toBe('12')
        ->and($dto->deliveryType)->toBe(1)
        ->and($dto->isPickup())->toBeFalse()
        ->and($dto->itemsPrice)->toBe(20.0)
        ->and($dto->itemsDiscount)->toBe(2.0)
        ->and($dto->deliveryFee)->toBe(0.0)
        ->and($dto->reportedTotal)->toBe(18.0)
        ->and($dto->prepaidAmount())->toBe(18.0)
        ->and($dto->pendingAmount)->toBe(0.0)
        ->and($dto->shopSubsidy)->toBe(2.0)
        ->and($dto->customerUid)->toBe('650000000000123456')
        ->and($dto->customerName)->toBe('Maria Souza')
        ->and($dto->deliveryAddress['street'])->toBe('Av. T-2')
        ->and($dto->deliveryAddress['cep'])->toBe('74210010')
        ->and($dto->needCutlery)->toBeTrue()
        ->and($dto->items[0]['mduId'])->toBe('7000000000000000001')
        ->and($dto->items[0]['unitPrice'])->toBe(8.0)
        ->and($dto->items[1]['subItems'][0]['groupId'])->toBe('g1')
        ->and($dto->paymentMethods()[0]['label'])->toBe('Cartão');
});

it('normaliza entrega própria em dinheiro (modelo 2) com troco e endereço mascarado', function () {
    $dto = Food99OrderDTO::fromArray(food99FixtureInfo('order-store-delivery-cash'));

    expect($dto->deliveryType)->toBe(2)
        ->and($dto->deliveryFee)->toBe(5.0)
        ->and($dto->reportedTotal)->toBe(21.0)
        ->and($dto->pendingAmount)->toBe(21.0)
        ->and($dto->pendingEstimated)->toBeFalse()
        ->and($dto->prepaidAmount())->toBe(0.0)
        ->and($dto->changeFor)->toBe(50.0)
        ->and($dto->isCash())->toBeTrue()
        ->and($dto->customerUid)->toBeNull()
        ->and($dto->customerName)->toBe('João')
        ->and($dto->deliveryAddress['street'])->toBe('Rua das Flores, 10 - Centro')
        ->and($dto->deliveryAddress['number'])->toBe('S/N')
        ->and($dto->paymentMethods()[0])->toMatchArray(['label' => 'Dinheiro', 'value' => 21.0, 'prepaid' => false, 'change_for' => 50.0]);
});

it('usa o total como estimativa enquanto a 99 não informa shop_paid_money', function () {
    $info = food99FixtureInfo('order-platform-delivery');
    $info['pay_method'] = 2;
    $info['pay_channel'] = 153;

    $dto = Food99OrderDTO::fromArray($info);

    expect($dto->pendingAmount)->toBe(18.0)
        ->and($dto->pendingEstimated)->toBeTrue();

    $info['shop_paid_money'] = 1700;
    expect(Food99OrderDTO::fromArray($info)->pendingAmount)->toBe(17.0);
});

it('trata retirada sem endereço', function () {
    $dto = Food99OrderDTO::fromArray(food99FixtureInfo('order-pickup'));

    expect($dto->isPickup())->toBeTrue()
        ->and($dto->localOrderType())->toBe('pickup')
        ->and($dto->deliveryAddress)->toBeNull()
        ->and($dto->takeawayCode)->toBe('ABCD')
        ->and($dto->paymentMethods()[0]['label'])->toBe('PIX');
});

it('aceita o preço aninhado em price e campos do modelo legado sem quebrar', function () {
    $dto = Food99OrderDTO::fromArray([
        'order_id' => '1',
        'delivery_type' => 1,
        'price' => ['order_price' => 1000, 'items_discount' => 100, 'service_price' => 99, 'origin_price' => 1234],
        'order_items' => [],
    ]);

    expect($dto->itemsPrice)->toBe(10.0)
        ->and($dto->serviceFee)->toBe(0.99)
        ->and($dto->reportedTotal)->toBe(9.99)
        ->and($dto->customerName)->toBe('Cliente 99Food');
});
