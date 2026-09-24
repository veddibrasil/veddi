<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductOption;
use App\Models\ProductOptionGroup;
use App\Services\Order\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Pedido do cliente (chat público) não pode fechar com item sem preço ou sem a escolha obrigatória,
 * mesmo que o carrinho venha adulterado. PDV e iFood têm fluxo próprio e não entram nessa regra.
 */
function selfServiceContext(array $productOverrides = []): array
{
    $company = Company::create([
        'name' => 'Empresa Regras',
        'slug' => 'empresa-regras-'.uniqid(),
        'order_prefix' => 'RGR',
        'active' => true,
    ]);
    app()->instance('current.company', $company);

    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial',
        'address' => 'Rua A, 1',
        'city' => 'SP',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Maria',
        'phone' => '11999990002',
        'email' => 'maria@teste.com',
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
        'available_in_pdv' => true,
        'sort_order' => 1,
    ], $productOverrides));

    DB::table('branch_product')->insert([
        'branch_id' => $branch->id,
        'product_id' => $product->id,
        'available' => 1,
    ]);

    $group = ProductOptionGroup::create([
        'company_id' => $company->id,
        'name' => 'Refrigerante',
        'total_qty' => 5,
        'min_qty' => 0,
        'fixed' => false,
    ]);
    $product->optionGroups()->attach($group->id, ['sort_order' => 0]);

    $option = ProductOption::create([
        'product_option_group_id' => $group->id,
        'name' => 'Coca-Cola',
        'additional_price' => 15.00,
    ]);

    return compact('company', 'branch', 'customer', 'product', 'group', 'option');
}

function selfServiceCreate(array $ctx, array $cart, string $orderType = 'delivery', string $channel = 'chat')
{
    return app(OrderService::class)->createOrder(
        customerId: $ctx['customer']->id,
        branchId: $ctx['branch']->id,
        cart: $cart,
        notes: '',
        paymentMethod: 'CASH',
        orderType: $orderType,
        channel: $channel,
    );
}

test('chat recusa item de R$ 0,00 (sem opção escolhida em produto de preço 0)', function () {
    $ctx = selfServiceContext();

    $cart = ['k' => ['product_id' => $ctx['product']->id, 'qty' => 1]];

    expect(fn () => selfServiceCreate($ctx, $cart))->toThrow(RuntimeException::class, 'sem preço');
    expect(DB::table('orders')->count())->toBe(0);
});

test('chat aceita o mesmo produto quando a opção paga é escolhida, cobrando o preço do banco', function () {
    $ctx = selfServiceContext();

    $cart = ['k' => [
        'product_id' => $ctx['product']->id,
        'qty' => 2,
        'options' => [$ctx['group']->id => ['selections' => [$ctx['option']->id => ['qty' => 1, 'additional_price' => 0]]]],
    ]];

    $order = selfServiceCreate($ctx, $cart);

    expect((float) $order->total)->toBe(30.0); // 2 × R$ 15,00 do banco, não o 0 enviado
});

test('chat recusa quantidade zero ou negativa', function () {
    $ctx = selfServiceContext(['price' => 10.0, 'is_variant' => false]);

    expect(fn () => selfServiceCreate($ctx, ['k' => ['product_id' => $ctx['product']->id, 'qty' => 0]]))
        ->toThrow(RuntimeException::class, 'Quantidade inválida');
});

test('chat recusa item sem o grupo obrigatório mesmo enviando o carrinho sem options', function () {
    $ctx = selfServiceContext(['price' => 30.0, 'is_variant' => false]);
    $ctx['group']->update(['min_qty' => 1]);

    $cart = ['k' => ['product_id' => $ctx['product']->id, 'qty' => 1]];

    expect(fn () => selfServiceCreate($ctx, $cart))->toThrow(RuntimeException::class, 'Escolha ao menos 1');
});

test('chat aceita pular grupo com "Não quero" mesmo com mínimo > 0', function () {
    $ctx = selfServiceContext(['price' => 30.0, 'is_variant' => false]);
    $ctx['group']->update(['min_qty' => 1, 'allow_skip' => true]);

    $cart = ['k' => [
        'product_id' => $ctx['product']->id,
        'qty' => 1,
        'options' => [$ctx['group']->id => ['selections' => []]],
    ]];

    $order = selfServiceCreate($ctx, $cart);

    expect((float) $order->total)->toBe(30.0);
});

test('PDV continua podendo lançar item de preço zero (brinde/cortesia do atendente)', function () {
    $ctx = selfServiceContext(['price' => 0, 'is_variant' => false]);

    $cart = ['k' => ['product_id' => $ctx['product']->id, 'qty' => 1]];

    $order = selfServiceCreate($ctx, $cart, orderType: 'pdv');

    expect((float) $order->total)->toBe(0.0);
});
