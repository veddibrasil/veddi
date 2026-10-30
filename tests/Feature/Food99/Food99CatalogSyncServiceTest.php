<?php

use App\Jobs\SyncFood99CatalogJob;
use App\Models\Food99WebhookEvent;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionGroup;
use App\Services\Food99\Food99CatalogSyncService;
use App\Services\Order\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function food99OptionGroup(Product $product, array $attributes = [], array $options = [['name' => 'Catupiry', 'additional_price' => 2.5, 'max_qty' => 1]]): ProductOptionGroup
{
    $group = ProductOptionGroup::create(array_merge([
        'company_id' => $product->company_id,
        'name' => 'Recheio extra',
        'total_qty' => 2,
        'min_qty' => 1,
        'fixed' => false,
        'allow_skip' => false,
        'sort_order' => 1,
    ], $attributes));
    DB::table('option_group_product')->insert(['product_id' => $product->id, 'product_option_group_id' => $group->id]);

    foreach ($options as $i => $option) {
        ProductOption::create(array_merge(['product_option_group_id' => $group->id, 'active' => true, 'default_qty' => 0, 'sort_order' => $i + 1], $option));
    }

    return $group;
}

function food99Catalog(): Food99CatalogSyncService
{
    return app(Food99CatalogSyncService::class);
}

it('monta o cardápio V3 com ids determinísticos, centavos e complementos como itens', function () {
    $ctx = food99Context();
    $ctx['product']->update(['description' => '<p>Massa <b>crocante</b></p>', 'promo_price_enabled' => true, 'promo_price_type' => 'fixed', 'promo_price_value' => 7.00]);
    $group = food99OptionGroup($ctx['product']);
    $option = $group->options()->first();

    $built = food99Catalog()->buildMenu($ctx['integration']);
    $menu = $built['menu'];
    $items = collect($menu['items'])->keyBy('app_item_id');

    expect($built['issues'])->toBe([])
        ->and($menu['menus'][0]['app_menu_id'])->toBe('veddi-m'.$ctx['branch']->id)
        ->and($menu['categories'][0])->toMatchArray(['app_category_id' => 'veddi-c'.$ctx['category']->id, 'category_name' => 'Salgados', 'app_item_ids' => ['veddi-p'.$ctx['product']->id]])
        ->and($items['veddi-p'.$ctx['product']->id])->toMatchArray([
            'item_name' => 'Coxinha',
            'short_desc' => 'Massa crocante',
            'price' => 800,
            'activity_price' => 700,
            'status' => 1,
            'is_sold_separately' => true,
            'app_modifier_group_ids' => ['veddi-g'.$group->id],
        ])
        ->and($items['veddi-o'.$option->id])->toMatchArray(['item_name' => 'Catupiry', 'price' => 250, 'is_sold_separately' => false])
        ->and($menu['modifier_groups'][0])->toMatchArray([
            'app_modifier_group_id' => 'veddi-g'.$group->id,
            'is_required' => 1,
            'quantity_min_permitted' => 1,
            'quantity_max_permitted' => 2,
            'buy_mode' => 0,
            'app_mg_items' => [['app_item_id' => 'veddi-o'.$option->id, 'price' => 250, 'purchase_limit' => 1]],
        ]);
});

it('deixa fora o produto com pendência e marca indisponível o sem estoque', function () {
    $ctx = food99Context();
    $nomeLongo = Product::withoutGlobalScopes()->create([
        'company_id' => $ctx['company']->id, 'product_category_id' => $ctx['category']->id, 'name' => str_repeat('a', 181), 'price' => 5, 'active' => true, 'available_in_99food' => true,
    ]);
    DB::table('branch_product')->insert(['branch_id' => $ctx['branch']->id, 'product_id' => $nomeLongo->id, 'available' => 1]);
    DB::table('branch_product')->where('product_id', $ctx['product']->id)->update(['track_stock' => true, 'quantity' => 0]);
    $foraDoCanal = Product::withoutGlobalScopes()->create([
        'company_id' => $ctx['company']->id, 'product_category_id' => $ctx['category']->id, 'name' => 'Só no chat', 'price' => 5, 'active' => true, 'available_in_99food' => false,
    ]);
    DB::table('branch_product')->insert(['branch_id' => $ctx['branch']->id, 'product_id' => $foraDoCanal->id, 'available' => 1]);

    $built = food99Catalog()->buildMenu($ctx['integration']);

    expect($built['issues'][$nomeLongo->id])->toContain('180 caracteres')
        ->and($built['products'])->toBe([$ctx['product']->id])
        ->and(collect($built['menu']['items'])->firstWhere('app_item_id', 'veddi-p'.$ctx['product']->id)['status'])->toBe(2);
});

it('envia o cardápio e aplica o resultado da task recebido no callback', function () {
    $ctx = food99Context();
    $outro = Product::withoutGlobalScopes()->create([
        'company_id' => $ctx['company']->id, 'product_category_id' => $ctx['category']->id, 'name' => 'Kibe', 'price' => 6, 'active' => true, 'available_in_99food' => true, 'image_path' => 'produtos/kibe.webp',
    ]);
    DB::table('branch_product')->insert(['branch_id' => $ctx['branch']->id, 'product_id' => $outro->id, 'available' => 1]);
    Http::fake(['*/v3/item/item/upload' => Http::response(food99Ok(['taskID' => 9100000000000000001]), 200)]);

    $result = food99Catalog()->upload($ctx['integration']);

    expect($result['task_id'])->toBe('9100000000000000001')
        ->and($ctx['integration']->fresh()->catalog_sync_status)->toBe('running')
        ->and(DB::table('branch_product')->where('product_id', $outro->id)->value('food99_sync_error'))->toContain('JPG ou PNG');

    postFood99Webhook($this, food99Callback($ctx['integration'], 'uploadMenuTaskStatus', [
        'taskID' => 9100000000000000001,
        'status' => 5,
        'operationList' => [
            ['operationType' => 'itemUpdate', 'successList' => [['appItemID' => 'veddi-p'.$ctx['product']->id]], 'failedList' => [['appItemID' => 'veddi-p'.$outro->id, 'reason' => 'price invalid']]],
        ],
    ]))->assertOk();

    $integration = $ctx['integration']->fresh();
    expect($integration->catalog_sync_status)->toBe('partial')
        ->and($integration->catalog_synced_at)->not->toBeNull()
        ->and(DB::table('branch_product')->where('product_id', $ctx['product']->id)->value('food99_sync_status'))->toBe('synced')
        ->and(DB::table('branch_product')->where('product_id', $outro->id)->value('food99_sync_status'))->toBe('failed')
        ->and(DB::table('branch_product')->where('product_id', $outro->id)->value('food99_sync_error'))->toContain('price invalid')
        ->and(Food99WebhookEvent::sole()->status)->toBe('processed');
});

it('ignora resultado de task antiga', function () {
    $ctx = food99Context(integration: ['catalog_task_id' => '2']);

    expect(food99Catalog()->applyTaskResult($ctx['integration'], '1', 1, []))->toBeFalse()
        ->and($ctx['integration']->fresh()->catalog_sync_status)->toBeNull();
});

it('só envia automaticamente depois que o restaurante publica o cardápio', function () {
    $ctx = food99Context(integration: ['catalog_published_at' => null]);
    Http::fake(['*' => Http::response(food99Ok(['taskID' => 1]), 200)]);

    (new SyncFood99CatalogJob($ctx['branch']->id))->handle(food99Catalog());
    Http::assertNothingSent();

    (new SyncFood99CatalogJob($ctx['branch']->id, publish: true))->handle(food99Catalog());
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v3/item/item/upload'));
    expect($ctx['integration']->fresh()->catalog_published_at)->not->toBeNull();
});

it('pausa o item na 99 em tempo real quando o produto é desativado', function () {
    $ctx = food99Context();
    Http::fake(['*' => Http::response(food99Ok(), 200)]);

    $ctx['product']->update(['active' => false]);

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v3/item/item/updateItemStatus')
        && $r['app_item_ids'] === ['veddi-p'.$ctx['product']->id] && $r['status'] === 2);
});

it('tira o item de venda quando o estoque zera pelo pedido', function () {
    $ctx = food99Context();
    DB::table('branch_product')->where('product_id', $ctx['product']->id)->update(['track_stock' => true, 'quantity' => 2]);
    Http::fake(['*' => Http::response(food99Ok(), 200)]);

    app(StockService::class)->setQuantity($ctx['branch'], $ctx['product'], 0, 'Contagem');

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v3/item/item/updateItemStatus') && $r['status'] === 2);
});

it('não mexe na 99 de outra empresa ao pausar produto', function () {
    $a = food99Context('A');
    $b = food99Context('B');
    Http::fake(['*' => Http::response(food99Ok(), 200)]);

    $a['product']->update(['active' => false]);

    Http::assertSent(fn (Request $r) => $r['auth_token'] === 'token-99-A');
    Http::assertNotSent(fn (Request $r) => $r['auth_token'] === 'token-99-B');
});
