<?php

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductOption;
use App\Models\ProductOptionGroup;
use App\Services\Order\CartOptionPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function cartOptionPricingProduct(Company $company, array $overrides = []): Product
{
    $category = ProductCategory::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Categoria',
        'active' => true,
        'sort_order' => 1,
    ]);

    return Product::withoutGlobalScopes()->create(array_merge([
        'company_id' => $company->id,
        'product_category_id' => $category->id,
        'name' => 'Produto',
        'price' => 20.0,
        'active' => true,
        'sort_order' => 1,
    ], $overrides));
}

/** Cria um grupo de opções e o vincula ao produto via pivot (option_group_product). */
function cartOptionGroupFor(Product $product, Company $company, array $overrides = []): ProductOptionGroup
{
    $group = ProductOptionGroup::create(array_merge([
        'company_id' => $company->id,
        'name' => 'Grupo',
        'total_qty' => 5,
        'min_qty' => 0,
        'fixed' => false,
    ], $overrides));

    $product->optionGroups()->attach($group->id, ['sort_order' => 0]);

    return $group;
}

test('resolve soma additional_price das opções selecionadas ignorando o valor enviado pelo client', function () {
    $company = Company::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'order_prefix' => 'A', 'active' => true]);
    $product = cartOptionPricingProduct($company);
    $group = cartOptionGroupFor($product, $company, ['name' => 'Adicionais']);

    $option = ProductOption::create([
        'product_option_group_id' => $group->id,
        'name' => 'Bacon',
        'additional_price' => 5.00,
    ]);

    $item = [
        'options' => [
            $group->id => [
                'selections' => [
                    // Client tenta forjar additional_price = 0 — deve ser ignorado.
                    $option->id => ['qty' => 2, 'additional_price' => 0],
                ],
            ],
        ],
    ];

    $result = app(CartOptionPricing::class)->resolve($product, $item);

    expect($result['extra'])->toBe(10.0); // 2 x 5.00 do banco, não o 0 forjado
    expect($result['options'][$group->id]['selections'][$option->id]['additional_price'])->toBe(5.0);
});

test('resolve lança exceção para grupo de opção que não pertence ao produto', function () {
    $company = Company::create(['name' => 'B', 'slug' => 'b-'.uniqid(), 'order_prefix' => 'B', 'active' => true]);
    $product = cartOptionPricingProduct($company);
    $otherProduct = cartOptionPricingProduct($company);

    // Grupo existe, mas está vinculado a OUTRO produto, não ao $product.
    $foreignGroup = cartOptionGroupFor($otherProduct, $company, ['name' => 'Grupo de outro produto']);

    $item = ['options' => [$foreignGroup->id => ['selections' => []]]];

    expect(fn () => app(CartOptionPricing::class)->resolve($product, $item))
        ->toThrow(RuntimeException::class);
});

test('resolve lança exceção para opção de outro produto/empresa injetada no grupo certo', function () {
    $companyA = Company::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'order_prefix' => 'C', 'active' => true]);
    $companyB = Company::create(['name' => 'D', 'slug' => 'd-'.uniqid(), 'order_prefix' => 'D', 'active' => true]);

    $productA = cartOptionPricingProduct($companyA);
    $productB = cartOptionPricingProduct($companyB);

    $groupA = cartOptionGroupFor($productA, $companyA, ['name' => 'Grupo A']);

    // Opção pertence a um grupo de OUTRO produto (empresa B) — tentativa de
    // injeção cross-company usando o id do grupo A (legítimo) com uma opção
    // que na verdade não é filha dele.
    $groupB = cartOptionGroupFor($productB, $companyB, ['name' => 'Grupo B']);
    $foreignOption = ProductOption::create([
        'product_option_group_id' => $groupB->id,
        'name' => 'Opção de outra empresa',
        'additional_price' => 99.00,
    ]);

    $item = [
        'options' => [
            $groupA->id => [
                'selections' => [
                    $foreignOption->id => ['qty' => 1],
                ],
            ],
        ],
    ];

    expect(fn () => app(CartOptionPricing::class)->resolve($productA, $item))
        ->toThrow(RuntimeException::class);
});

test('resolve aplica preço de variante usando o primeiro grupo do produto quando is_variant', function () {
    $company = Company::create(['name' => 'E', 'slug' => 'e-'.uniqid(), 'order_prefix' => 'E', 'active' => true]);
    $product = cartOptionPricingProduct($company, ['is_variant' => true, 'price' => 0]);
    $variantGroup = cartOptionGroupFor($product, $company, ['name' => 'Tamanho', 'total_qty' => 1, 'min_qty' => 1]);

    $variantOption = ProductOption::create([
        'product_option_group_id' => $variantGroup->id,
        'name' => 'Grande',
        'additional_price' => 15.00,
    ]);

    $item = ['options' => [$variantGroup->id => ['selections' => [$variantOption->id => ['qty' => 1]]]]];

    $result = app(CartOptionPricing::class)->resolve($product, $item);

    expect($result['extra'])->toBe(15.0);
});

test('resolve rejeita quantidade acima do máximo da opção', function () {
    $company = Company::create(['name' => 'F', 'slug' => 'f-'.uniqid(), 'order_prefix' => 'F', 'active' => true]);
    $product = cartOptionPricingProduct($company);
    $group = cartOptionGroupFor($product, $company, ['name' => 'Molhos', 'total_qty' => 2]);

    $option = ProductOption::create([
        'product_option_group_id' => $group->id,
        'name' => 'Molho barbecue',
        'additional_price' => 1.00,
        'max_qty' => 1,
    ]);

    // Excede max_qty da própria opção (1)
    $item = ['options' => [$group->id => ['selections' => [$option->id => ['qty' => 2]]]]];
    expect(fn () => app(CartOptionPricing::class)->resolve($product, $item))->toThrow(RuntimeException::class);
});

test('resolve rejeita quantidade total do grupo acima do máximo configurado', function () {
    $company = Company::create(['name' => 'H', 'slug' => 'h-'.uniqid(), 'order_prefix' => 'H', 'active' => true]);
    $product = cartOptionPricingProduct($company);
    $group = cartOptionGroupFor($product, $company, ['name' => 'Molhos', 'total_qty' => 2]);

    $option1 = ProductOption::create(['product_option_group_id' => $group->id, 'name' => 'Molho 1', 'additional_price' => 1.00]);
    $option2 = ProductOption::create(['product_option_group_id' => $group->id, 'name' => 'Molho 2', 'additional_price' => 1.00]);

    // Soma das quantidades (2 + 1 = 3) excede total_qty do grupo (2)
    $item = ['options' => [$group->id => ['selections' => [
        $option1->id => ['qty' => 2],
        $option2->id => ['qty' => 1],
    ]]]];

    expect(fn () => app(CartOptionPricing::class)->resolve($product, $item))->toThrow(RuntimeException::class);
});

test('resolve retorna extra zero quando o item não tem opções', function () {
    $company = Company::create(['name' => 'G', 'slug' => 'g-'.uniqid(), 'order_prefix' => 'G', 'active' => true]);
    $product = cartOptionPricingProduct($company);

    $result = app(CartOptionPricing::class)->resolve($product, []);

    expect($result)->toBe(['extra' => 0.0, 'options' => []]);
});
