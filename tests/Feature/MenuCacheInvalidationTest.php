<?php

use App\Livewire\Admin\Categories\Index as CategoriesIndex;
use App\Livewire\Admin\Products\Index as ProductsIndex;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\Order\MenuCache;
use App\Services\Order\OrderService;
use App\Services\Order\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * O chat guarda o cardápio por filial (com estoque e disponibilidade). Toda escrita que muda esses
 * dados precisa invalidar o cache — senão produto esgotado continua "comprável" por até 5 minutos.
 */
function menuCacheContext(): array
{
    $company = Company::create([
        'name' => 'Empresa Cache',
        'slug' => 'empresa-cache-'.uniqid(),
        'order_prefix' => 'CCH',
        'active' => true,
        'plan' => 'pro',
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

    $category = ProductCategory::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Salgados',
        'active' => true,
        'sort_order' => 1,
    ]);

    $product = Product::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'product_category_id' => $category->id,
        'name' => 'Coxinha',
        'price' => 8.0,
        'active' => true,
        'available_in_delivery' => true,
        'sort_order' => 1,
    ]);

    DB::table('branch_product')->insert([
        'branch_id' => $branch->id,
        'product_id' => $product->id,
        'available' => 1,
        'track_stock' => 1,
        'quantity' => 5,
        'min_quantity' => 0,
    ]);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    $key = MenuCache::menuKey($branch->id, $company->id);
    Cache::put($key, 'cardapio-velho', 300);

    return compact('company', 'branch', 'category', 'product', 'admin', 'key');
}

test('ajuste manual de estoque limpa o cardápio da filial', function () {
    ['branch' => $branch, 'product' => $product, 'key' => $key] = menuCacheContext();

    app(StockService::class)->adjust($branch, $product, -5, 'acabou');

    expect(Cache::has($key))->toBeFalse();
});

test('definir quantidade e ligar/desligar rastreio limpam o cardápio da filial', function () {
    ['branch' => $branch, 'product' => $product, 'key' => $key] = menuCacheContext();

    app(StockService::class)->setQuantity($branch, $product, 0);
    expect(Cache::has($key))->toBeFalse();

    Cache::put($key, 'cardapio-velho', 300);
    app(StockService::class)->toggleTracking($branch, $product, false);
    expect(Cache::has($key))->toBeFalse();
});

test('venda que baixa o estoque limpa o cardápio: produto esgotado deixa de aparecer como disponível', function () {
    ['company' => $company, 'branch' => $branch, 'product' => $product, 'key' => $key] = menuCacheContext();

    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'name' => 'Ana', 'phone' => '11999990003', 'email' => 'ana@teste.com',
    ]);

    // createOrder já baixa o estoque dentro da própria transação.
    $order = app(OrderService::class)->createOrder(
        customerId: $customer->id,
        branchId: $branch->id,
        cart: ['k' => ['product_id' => $product->id, 'qty' => 5]],
        notes: '',
        paymentMethod: 'CASH',
        orderType: 'delivery',
    );

    expect(Cache::has($key))->toBeFalse();
    expect(DB::table('branch_product')->where('product_id', $product->id)->value('available'))->toBe(0);

    // Cancelar devolve o estoque — e o cardápio precisa refletir isso também.
    Cache::put($key, 'cardapio-velho', 300);
    app(StockService::class)->restoreForOrder($order);
    expect(Cache::has($key))->toBeFalse();
});

test('a invalidação espera o commit quando a escrita está dentro de uma transação', function () {
    ['branch' => $branch, 'product' => $product, 'key' => $key] = menuCacheContext();

    DB::transaction(function () use ($branch, $product, $key) {
        app(StockService::class)->adjust($branch, $product, -1);

        // Ainda dentro da transação: limpar agora deixaria outro request repopular o cache com dado antigo.
        expect(Cache::has($key))->toBeTrue();
    });

    expect(Cache::has($key))->toBeFalse();
});

test('criar, renomear, desativar e excluir categoria limpam o cardápio', function () {
    ['admin' => $admin, 'key' => $key] = menuCacheContext();

    $component = Livewire::actingAs($admin)->test(CategoriesIndex::class);

    $component->set('name', 'Bebidas')->call('save');
    expect(Cache::has($key))->toBeFalse();

    $category = ProductCategory::where('name', 'Bebidas')->firstOrFail();

    Cache::put($key, 'cardapio-velho', 300);
    $component->call('edit', $category->id)->set('name', 'Bebidas geladas')->set('active', false)->call('save');
    expect(Cache::has($key))->toBeFalse();
    expect($category->refresh()->active)->toBeFalse();

    Cache::put($key, 'cardapio-velho', 300);
    $component->call('confirmDelete', $category->id)->call('delete');
    expect(Cache::has($key))->toBeFalse();
});

test('reordenar produtos pelos botões sobe/desce limpa o cardápio', function () {
    ['admin' => $admin, 'category' => $category, 'company' => $company, 'product' => $first, 'key' => $key] = menuCacheContext();

    Product::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'product_category_id' => $category->id,
        'name' => 'Risole', 'price' => 7.0, 'active' => true, 'sort_order' => 2,
    ]);

    Livewire::actingAs($admin)->test(ProductsIndex::class)->call('moveProduct', $first->id, 'down');

    expect(Cache::has($key))->toBeFalse();
});
