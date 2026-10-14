<?php

use App\DTOs\IfoodOrderDTO;
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
        ->and($item['options'][$group->id]['selections'])->toHaveKey($option->id)
        // Nomes vão no snapshot: tela do pedido e comandas leem group_name e name.
        ->and($item['options'][$group->id]['group_name'])->toBe('Ponto da carne')
        ->and($item['options'][$group->id]['selections'][$option->id]['name'])->toBe('Mal passado');
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

test('item sem vínculo entra como avulso com nome, preço e complementos do iFood', function () {
    ['branch' => $branch, 'integration' => $integration] = ifoodContext('map3');

    $payload = ifoodOrderDetailsPayload('ifood-order-map3', $integration->merchant_id, 'item-desconhecido', 2);
    $payload['items'][0]['name'] = 'Meio Cento de Salgado (50 unidades)';
    $payload['items'][0]['unitPrice'] = 39.90;
    $payload['items'][0]['optionsPrice'] = 3.00;
    $payload['items'][0]['totalPrice'] = 82.80;
    $payload['items'][0]['observations'] = 'Bem frito';
    $payload['items'][0]['options'] = [
        ['id' => 'op-coxinha', 'groupName' => 'Sabores', 'name' => 'Coxinha', 'quantity' => 25, 'unitPrice' => 0],
        ['id' => 'op-kibe', 'groupName' => 'Sabores', 'name' => 'Kibe', 'quantity' => 25, 'unitPrice' => 0],
        ['id' => 'op-molho', 'groupName' => 'Molhos', 'name' => 'Maionese caseira', 'quantity' => 1, 'unitPrice' => 1.50],
    ];

    $cart = app(IfoodOrderMapper::class)->mapToCart(IfoodOrderDTO::fromArray($payload), $branch->id);

    expect(array_values($cart)[0])->toBe([
        'product_id' => null,
        'unlinked' => true,
        'qty' => 2,
        'name' => 'Meio Cento de Salgado (50 unidades)',
        'unit_price' => 41.40,
        'subtotal' => 82.80,
        'options' => [
            ['group_name' => 'Sabores', 'selections' => [
                ['name' => 'Coxinha', 'qty' => 25, 'additional_price' => 0.0],
                ['name' => 'Kibe', 'qty' => 25, 'additional_price' => 0.0],
            ]],
            ['group_name' => 'Molhos', 'selections' => [
                ['name' => 'Maionese caseira', 'qty' => 1, 'additional_price' => 1.5],
            ]],
        ],
        'notes' => 'Bem frito',
    ]);
});

test('item avulso sem totalPrice no payload usa preço unitário × quantidade + optionsPrice', function () {
    ['branch' => $branch, 'integration' => $integration] = ifoodContext('map3b');

    $payload = ifoodOrderDetailsPayload('ifood-order-map3b', $integration->merchant_id, 'item-desconhecido', 3);
    $payload['items'][0]['optionsPrice'] = 1.50;

    $item = array_values(app(IfoodOrderMapper::class)->mapToCart(IfoodOrderDTO::fromArray($payload), $branch->id))[0];

    expect($item['subtotal'])->toBe(25.50)
        ->and($item['unit_price'])->toBe(8.50)
        ->and($item['options'])->toBe([]);
});

test('item ligado com complemento aninhado (2+ níveis) entra como avulso, levando o sub-complemento', function () {
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

    $item = array_values(app(IfoodOrderMapper::class)->mapToCart(IfoodOrderDTO::fromArray($payload), $branch->id))[0];

    expect($item['product_id'])->toBeNull()
        ->and($item['unlinked'])->toBeTrue()
        ->and(array_column($item['options'][0]['selections'], 'name'))->toBe(['Combo', 'Combo / Sub-opção']);
});

test('item ligado com complemento sem opção equivalente no produto entra como avulso', function () {
    ['branch' => $branch, 'integration' => $integration] = ifoodContext('map5');

    $payload = ifoodOrderDetailsPayload('ifood-order-map5', $integration->merchant_id, 'ifood-item-coxinha-map5', 1);
    $payload['items'][0]['options'] = [
        ['id' => 'ifood-option-inexistente', 'name' => 'Opção fantasma', 'quantity' => 1],
    ];

    $item = array_values(app(IfoodOrderMapper::class)->mapToCart(IfoodOrderDTO::fromArray($payload), $branch->id))[0];

    expect($item['product_id'])->toBeNull()
        ->and($item['unlinked'])->toBeTrue()
        ->and($item['options'][0]['group_name'])->toBe('Complementos')
        ->and($item['options'][0]['selections'][0]['name'])->toBe('Opção fantasma');
});

test('item ligado com escolha acima do máximo do grupo entra como avulso em vez de falhar o pedido', function () {
    ['branch' => $branch, 'product' => $product, 'integration' => $integration] = ifoodContext('map8');

    $group = ProductOptionGroup::create([
        'company_id' => $product->company_id,
        'name' => 'Sabor',
        'total_qty' => 1,
        'fixed' => false,
    ]);
    $group->products()->attach($product->id);
    ProductOption::create([
        'product_option_group_id' => $group->id,
        'ifood_option_id' => 'ifood-option-frango-map8',
        'name' => 'Frango',
        'additional_price' => 0,
    ]);

    $payload = ifoodOrderDetailsPayload('ifood-order-map8', $integration->merchant_id, 'ifood-item-coxinha-map8', 1);
    $payload['items'][0]['options'] = [
        ['id' => 'ifood-option-frango-map8', 'name' => 'Frango', 'quantity' => 3],
    ];

    $item = array_values(app(IfoodOrderMapper::class)->mapToCart(IfoodOrderDTO::fromArray($payload), $branch->id))[0];

    expect($item['product_id'])->toBeNull()
        ->and($item['unlinked'])->toBeTrue();
});

test('mapeia customizações do combo sem perder preço ou vínculo com o complemento pai', function () {
    ['branch' => $branch, 'product' => $product, 'integration' => $integration] = ifoodContext('combo');
    $group = ProductOptionGroup::create(['company_id' => $product->company_id, 'name' => 'Combo', 'total_qty' => 10, 'fixed' => false]);
    $group->products()->attach($product->id);
    $parent = ProductOption::create(['product_option_group_id' => $group->id, 'ifood_option_id' => 'parent', 'name' => 'Sanduíche', 'additional_price' => 2]);
    $child = ProductOption::create(['product_option_group_id' => $group->id, 'ifood_option_id' => 'child', 'name' => 'Sanduíche / Queijo', 'additional_price' => 1]);
    $payload = ifoodOrderDetailsPayload('combo-order', $integration->merchant_id, 'ifood-item-coxinha-combo', 1);
    $payload['items'][0]['options'] = [['id' => 'parent', 'name' => 'Sanduíche', 'quantity' => 1, 'customizations' => [['id' => 'child', 'name' => 'Queijo', 'quantity' => 2]]]];
    $cart = app(IfoodOrderMapper::class)->mapToCart(IfoodOrderDTO::fromArray($payload), $branch->id);
    $item = array_values($cart)[0];
    expect($item['options'][$group->id]['selections'][$child->id]['qty'])->toBe(2)
        ->and($item['options'][$group->id]['selections'][$child->id]['ifood_parents'])->toBe(['parent']);
    $priced = app(\App\Services\Order\CartOptionPricing::class)->resolve($product, $item);
    expect($priced['extra'])->toBe(4.0);
    $payload['items'][0]['options'][0]['customizations'][0]['id'] = 'unknown';
    $unlinked = array_values(app(IfoodOrderMapper::class)->mapToCart(IfoodOrderDTO::fromArray($payload), $branch->id))[0];
    expect($unlinked['product_id'])->toBeNull()->and($unlinked['unlinked'])->toBeTrue();
});
