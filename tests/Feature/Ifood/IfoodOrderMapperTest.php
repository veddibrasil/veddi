<?php

use App\DTOs\IfoodOrderDTO;
use App\Exceptions\IfoodMappingException;
use App\Models\ProductOption;
use App\Models\ProductOptionGroup;
use App\Services\Ifood\IfoodOrderMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('mapToCart resolve item mapeado via branch_product.ifood_item_id', function () {
    ['branch' => $branch, 'product' => $product, 'integration' => $integration] = ifoodContext('map1');

    $dto = IfoodOrderDTO::fromArray(
        ifoodOrderDetailsPayload('ifood-order-map1', $integration->merchant_id, 'ifood-item-coxinha-map1', 3)
    );

    $cart = app(IfoodOrderMapper::class)->mapToCart($dto, $branch->id);

    expect($cart)->toHaveCount(1);
    $item = array_values($cart)[0];
    expect($item['product_id'])->toBe($product->id)
        ->and($item['qty'])->toBe(3)
        ->and($item['options'])->toBe([]);
});

test('mapToCart resolve complemento de 1 nível via product_options.ifood_option_id', function () {
    ['branch' => $branch, 'product' => $product, 'integration' => $integration] = ifoodContext('map2');

    $group = ProductOptionGroup::create([
        'company_id' => $product->company_id,
        'name' => 'Ponto da carne',
        'total_qty' => 1,
        'fixed' => true,
    ]);
    $group->products()->attach($product->id);

    $option = ProductOption::create([
        'product_option_group_id' => $group->id,
        'ifood_option_id' => 'ifood-option-mal-passado',
        'name' => 'Mal passado',
        'additional_price' => 0,
    ]);

    $payload = ifoodOrderDetailsPayload('ifood-order-map2', $integration->merchant_id, 'ifood-item-coxinha-map2', 1);
    $payload['items'][0]['options'] = [
        ['id' => 'ifood-option-mal-passado', 'name' => 'Mal passado', 'quantity' => 1],
    ];

    $dto = IfoodOrderDTO::fromArray($payload);
    $cart = app(IfoodOrderMapper::class)->mapToCart($dto, $branch->id);

    $item = array_values($cart)[0];
    expect($item['options'])->toHaveKey($group->id)
        ->and($item['options'][$group->id]['selections'])->toHaveKey($option->id);
});

test('mapToCart resolve item via externalCode quando ifoodItemId não bate (pedido de teste do iFood troca o id)', function () {
    ['branch' => $branch, 'product' => $product, 'integration' => $integration] = ifoodContext('map6');

    $payload = ifoodOrderDetailsPayload('ifood-order-map6', $integration->merchant_id, 'id-efemero-do-pedido-de-teste');
    $payload['items'][0]['name'] = 'PEDIDO DE TESTE - NÃO ENTREGAR';
    $payload['items'][0]['externalCode'] = "veddi-p{$product->id}";

    $dto = IfoodOrderDTO::fromArray($payload);
    $cart = app(IfoodOrderMapper::class)->mapToCart($dto, $branch->id);

    expect(array_values($cart)[0]['product_id'])->toBe($product->id);
});

test('mapToCart resolve complemento via externalCode quando ifoodOptionId não bate', function () {
    ['branch' => $branch, 'product' => $product, 'integration' => $integration] = ifoodContext('map7');

    $group = ProductOptionGroup::create([
        'company_id' => $product->company_id,
        'name' => 'Ponto da carne',
        'total_qty' => 1,
        'fixed' => true,
    ]);
    $group->products()->attach($product->id);

    $option = ProductOption::create([
        'product_option_group_id' => $group->id,
        'ifood_option_id' => 'ifood-option-mal-passado-map7',
        'name' => 'Mal passado',
        'additional_price' => 0,
    ]);

    $payload = ifoodOrderDetailsPayload('ifood-order-map7', $integration->merchant_id, 'ifood-item-coxinha-map7', 1);
    $payload['items'][0]['options'] = [
        ['id' => 'id-efemero-opcao', 'externalCode' => "veddi-option-{$option->id}", 'name' => 'Mal passado', 'quantity' => 1],
    ];

    $dto = IfoodOrderDTO::fromArray($payload);
    $cart = app(IfoodOrderMapper::class)->mapToCart($dto, $branch->id);

    $item = array_values($cart)[0];
    expect($item['options'])->toHaveKey($group->id)
        ->and($item['options'][$group->id]['selections'])->toHaveKey($option->id);
});

test('mapToCart falha quando item não está mapeado pra nenhum produto', function () {
    ['branch' => $branch, 'integration' => $integration] = ifoodContext('map3');

    $dto = IfoodOrderDTO::fromArray(
        ifoodOrderDetailsPayload('ifood-order-map3', $integration->merchant_id, 'item-desconhecido')
    );

    expect(fn () => app(IfoodOrderMapper::class)->mapToCart($dto, $branch->id))
        ->toThrow(IfoodMappingException::class);
});

test('mapToCart falha quando complemento tem aninhamento de 2+ níveis (não suportado)', function () {
    ['branch' => $branch, 'integration' => $integration] = ifoodContext('map4');

    $payload = ifoodOrderDetailsPayload('ifood-order-map4', $integration->merchant_id, 'ifood-item-coxinha-map4', 1);
    $payload['items'][0]['options'] = [
        [
            'id' => 'ifood-option-combo',
            'name' => 'Combo',
            'quantity' => 1,
            // complemento-de-complemento: option com suas próprias 'options' aninhadas
            'options' => [
                ['id' => 'ifood-option-sub', 'name' => 'Sub-opção', 'quantity' => 1],
            ],
        ],
    ];

    $dto = IfoodOrderDTO::fromArray($payload);

    expect(fn () => app(IfoodOrderMapper::class)->mapToCart($dto, $branch->id))
        ->toThrow(IfoodMappingException::class);
});

test('mapToCart falha quando complemento não está mapeado pra nenhuma opção interna', function () {
    ['branch' => $branch, 'integration' => $integration] = ifoodContext('map5');

    $payload = ifoodOrderDetailsPayload('ifood-order-map5', $integration->merchant_id, 'ifood-item-coxinha-map5', 1);
    $payload['items'][0]['options'] = [
        ['id' => 'ifood-option-inexistente', 'name' => 'Opção fantasma', 'quantity' => 1],
    ];

    $dto = IfoodOrderDTO::fromArray($payload);

    expect(fn () => app(IfoodOrderMapper::class)->mapToCart($dto, $branch->id))
        ->toThrow(IfoodMappingException::class);
});
