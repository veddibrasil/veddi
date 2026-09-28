<?php

use App\Livewire\Chat\OrderChat;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductOption;
use App\Models\ProductOptionGroup;
use App\Services\Order\MenuCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * O payload do Livewire é forjável e o cardápio vem de cache: o servidor precisa recusar, no
 * momento de adicionar, item sem preço, sem escolha obrigatória ou de produto indisponível.
 */
function cartGuardContext(array $productOverrides = [], array $pivot = []): array
{
    $company = Company::create([
        'name' => 'Empresa Carrinho',
        'slug' => 'empresa-carrinho-'.uniqid(),
        'order_prefix' => 'CRT',
        'active' => true,
        'plan' => 'pro',
    ]);
    app()->instance('current.company', $company);

    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    $category = ProductCategory::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Bebidas',
        'active' => true,
        'sort_order' => 1,
    ]);

    $product = Product::withoutGlobalScopes()->create(array_merge([
        'company_id' => $company->id,
        'product_category_id' => $category->id,
        'name' => 'Bebida 2 litros',
        'price' => 0,
        'is_variant' => true,
        'active' => true,
        'available_in_delivery' => true,
        'sort_order' => 1,
    ], $productOverrides));

    DB::table('branch_product')->insert(array_merge([
        'branch_id' => $branch->id,
        'product_id' => $product->id,
        'available' => 1,
    ], $pivot));

    $component = new OrderChat;
    $component->companyId = $company->id;
    $component->selectedBranchId = $branch->id;

    return compact('company', 'branch', 'category', 'product', 'component');
}

function cartGuardGroup(Product $product, Company $company, array $overrides = []): array
{
    $group = ProductOptionGroup::create(array_merge([
        'company_id' => $company->id,
        'name' => 'Refrigerante',
        'total_qty' => 5,
        'min_qty' => 0,
        'fixed' => false,
    ], $overrides));
    $product->optionGroups()->attach($group->id, ['sort_order' => 0]);

    $option = ProductOption::create([
        'product_option_group_id' => $group->id,
        'name' => 'Coca-Cola',
        'additional_price' => 15.00,
    ]);

    return [$group, $option];
}

function cartGuardSelection(ProductOptionGroup $group, ?ProductOption $option, int $qty = 1): array
{
    return [$group->id => [
        'group_name' => $group->name,
        'selections' => $option ? [$option->id => ['name' => $option->name, 'qty' => $qty, 'additional_price' => 15.0]] : [],
    ]];
}

test('item de produto com variações sem nenhuma escolha (R$ 0,00) não entra no carrinho', function () {
    ['company' => $company, 'product' => $product, 'component' => $chat] = cartGuardContext();
    [$group] = cartGuardGroup($product, $company);

    $chat->addToCartWithOptions($product->id, cartGuardSelection($group, null));

    expect($chat->cart)->toBeEmpty();
    expect($chat->cartError)->toContain('Escolha as opções');
});

test('o mesmo produto entra no carrinho quando uma opção paga é escolhida', function () {
    ['company' => $company, 'product' => $product, 'component' => $chat] = cartGuardContext();
    [$group, $option] = cartGuardGroup($product, $company);

    $chat->addToCartWithOptions($product->id, cartGuardSelection($group, $option, 2));

    expect($chat->cart)->toHaveCount(1);
    expect($chat->cartError)->toBeNull();
});

test('payload forjado sem o grupo obrigatório é recusado no servidor mesmo com preço base', function () {
    ['company' => $company, 'product' => $product, 'component' => $chat] = cartGuardContext(['price' => 30.0, 'is_variant' => false]);
    [$group] = cartGuardGroup($product, $company, ['min_qty' => 1]);

    // Cliente omite `options` por completo — antes o mínimo do grupo nem era avaliado.
    $chat->addToCartWithOptions($product->id, []);

    expect($chat->cart)->toBeEmpty();
    expect($chat->cartError)->toContain('Escolha ao menos 1');
});

test('grupo com "Não quero" permite seguir sem escolha mesmo com mínimo maior que zero', function () {
    ['company' => $company, 'product' => $product, 'component' => $chat] = cartGuardContext(['price' => 30.0, 'is_variant' => false]);
    [$group] = cartGuardGroup($product, $company, ['min_qty' => 1, 'allow_skip' => true]);

    $chat->addToCartWithOptions($product->id, cartGuardSelection($group, null));

    expect($chat->cart)->toHaveCount(1);
    expect($chat->cartError)->toBeNull();
});

test('produto simples sem preço não é adicionado', function () {
    ['product' => $product, 'component' => $chat] = cartGuardContext(['is_variant' => false, 'price' => 0]);

    $chat->addToCart($product->id);

    expect($chat->cart)->toBeEmpty();
    expect($chat->cartError)->toContain('sem preço');
});

test('produto marcado como indisponível na filial é recusado e o cache do cardápio é limpo', function () {
    ['company' => $company, 'branch' => $branch, 'product' => $product, 'component' => $chat] = cartGuardContext(
        ['is_variant' => false, 'price' => 10.0],
        ['available' => 0],
    );

    $key = MenuCache::menuKey($branch->id, $company->id);
    Cache::put($key, 'cardapio-velho', 300);

    $chat->addToCart($product->id);

    expect($chat->cart)->toBeEmpty();
    expect($chat->cartError)->toContain('não está mais disponível');
    expect(Cache::has($key))->toBeFalse();
});

test('estoque rastreado: não deixa passar da quantidade disponível somando o que já está no carrinho', function () {
    ['product' => $product, 'component' => $chat] = cartGuardContext(
        ['is_variant' => false, 'price' => 10.0],
        ['track_stock' => 1, 'quantity' => 2, 'min_quantity' => 0],
    );

    $chat->addToCart($product->id);
    $chat->addToCart($product->id);
    expect($chat->cart[(string) $product->id]['qty'])->toBe(2);

    $chat->addToCart($product->id);

    expect($chat->cart[(string) $product->id]['qty'])->toBe(2);
    expect($chat->cartError)->not->toBeNull();
});
