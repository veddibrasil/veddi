<?php

use App\Contracts\IfoodGatewayContract;
use App\Jobs\SyncIfoodCatalogJob;
use App\Services\Ifood\IfoodCatalogSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('syncFullCatalog mapeia produto pro payload e atribui ifood_item_id (UUID) quando ausente', function () {
    ['branch' => $branch, 'product' => $product, 'integration' => $integration] = ifoodContext('cat1');

    // Contexto já grava um ifood_item_id fixo — limpa pra simular produto nunca sincronizado.
    DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $product->id)->update(['ifood_item_id' => null]);

    $gateway = Mockery::mock(IfoodGatewayContract::class);
    $gateway->shouldReceive('createCategory')->once()->andReturn('categoria-ifood-1');

    $capturedPayload = null;
    $gateway->shouldReceive('syncCatalog')->once()->andReturnUsing(function ($int, $payload) use (&$capturedPayload) {
        $capturedPayload = $payload;
    });

    (new IfoodCatalogSyncService($gateway))->syncFullCatalog($integration);

    expect($capturedPayload['products'][0]['name'])->toBe('Coxinha')
        ->and($capturedPayload['item']['status'])->toBe('AVAILABLE')
        ->and($capturedPayload['item']['categoryId'])->toBe('categoria-ifood-1')
        ->and(Str::isUuid($capturedPayload['item']['id']))->toBeTrue()
        ->and($capturedPayload['products'])->not->toBeEmpty();

    $ifoodItemId = DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $product->id)->value('ifood_item_id');
    expect(Str::isUuid($ifoodItemId))->toBeTrue()
        ->and($ifoodItemId)->toBe($capturedPayload['item']['id']);

    $integration->refresh();
    expect($integration->last_synced_at)->not->toBeNull();
});

test('syncFullCatalog reusa ifood_item_id já existente em vez de gerar outro', function () {
    ['branch' => $branch, 'product' => $product, 'integration' => $integration] = ifoodContext('cat2');

    $gateway = Mockery::mock(IfoodGatewayContract::class);
    $gateway->shouldReceive('createCategory')->once()->andReturn('categoria-ifood-2');

    $capturedPayload = null;
    $gateway->shouldReceive('syncCatalog')->once()->andReturnUsing(function ($int, $payload) use (&$capturedPayload) {
        $capturedPayload = $payload;
    });

    (new IfoodCatalogSyncService($gateway))->syncFullCatalog($integration);

    // ifoodContext já grava "ifood-item-coxinha-cat2" no branch_product.
    expect($capturedPayload['item']['id'])->toBe('ifood-item-coxinha-cat2');
});

test('syncFullCatalog reusa ifood_category_id já mapeado sem chamar createCategory de novo', function () {
    ['branch' => $branch, 'product' => $product, 'company' => $company, 'integration' => $integration] = ifoodContext('cat2b');

    App\Models\IfoodCategory::create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'product_category_id' => $product->category->id,
        'ifood_category_id' => 'categoria-ja-mapeada',
    ]);

    $gateway = Mockery::mock(IfoodGatewayContract::class);
    $gateway->shouldNotReceive('createCategory');

    $capturedPayload = null;
    $gateway->shouldReceive('syncCatalog')->once()->andReturnUsing(function ($int, $payload) use (&$capturedPayload) {
        $capturedPayload = $payload;
    });

    (new IfoodCatalogSyncService($gateway))->syncFullCatalog($integration);

    expect($capturedPayload['item']['categoryId'])->toBe('categoria-ja-mapeada');
});

test('syncFullCatalog monta item COMBO_V2 com grupo de complemento (OFFER_UNIT)', function () {
    ['branch' => $branch, 'product' => $product, 'integration' => $integration] = ifoodContext('cat2c');

    $group = App\Models\ProductOptionGroup::create([
        'company_id' => $product->company_id,
        'name' => 'Escolha o sabor',
        'total_qty' => 2,
        'min_qty' => 1,
        'fixed' => false,
        'allow_skip' => false,
        'sort_order' => 1,
    ]);
    DB::table('option_group_product')->insert(['product_id' => $product->id, 'product_option_group_id' => $group->id]);
    $option = App\Models\ProductOption::create([
        'product_option_group_id' => $group->id,
        'name' => 'Frango',
        'active' => true,
        'additional_price' => 2.5,
        'default_qty' => 0,
        'max_qty' => 1,
        'sort_order' => 1,
    ]);

    $gateway = Mockery::mock(IfoodGatewayContract::class);
    $gateway->shouldReceive('createCategory')->once()->andReturn('categoria-ifood-2c');

    $capturedPayload = null;
    $gateway->shouldReceive('syncCatalog')->once()->andReturnUsing(function ($int, $payload) use (&$capturedPayload) {
        $capturedPayload = $payload;
    });

    (new IfoodCatalogSyncService($gateway))->syncFullCatalog($integration);

    expect($capturedPayload['item']['type'])->toBe('COMBO_V2')
        ->and($capturedPayload['optionGroups'])->toHaveCount(1)
        ->and($capturedPayload['optionGroups'][0]['optionGroupType'])->toBe('OFFER_UNIT')
        ->and($capturedPayload['optionGroups'][0]['optionIds'])->toHaveCount(1)
        ->and($capturedPayload['options'][0]['price']['value'])->toBe(2.5)
        ->and($capturedPayload['products'][0]['optionGroups'][0]['associationType'])->toBe('MAIN')
        ->and($capturedPayload['products'])->toHaveCount(2);

    $option->refresh();
    expect(Str::isUuid($option->ifood_option_id))->toBeTrue()
        ->and(Str::isUuid($option->ifood_product_id))->toBeTrue()
        ->and($capturedPayload['options'][0]['id'])->toBe($option->ifood_option_id);
});

test('syncFullCatalog gera novo ifood_item_id quando produto ganha grupo de complemento (DEFAULT -> COMBO_V2)', function () {
    ['branch' => $branch, 'product' => $product, 'integration' => $integration] = ifoodContext('cat2d');

    $gateway = Mockery::mock(IfoodGatewayContract::class);
    $gateway->shouldReceive('createCategory')->once()->andReturn('categoria-ifood-2d');
    $gateway->shouldReceive('syncCatalog')->twice();

    // Primeira sync: sem grupo de complemento, tipo DEFAULT.
    (new IfoodCatalogSyncService($gateway))->syncFullCatalog($integration);

    $original = DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $product->id)->first(['ifood_item_id', 'ifood_item_type']);
    expect($original->ifood_item_type)->toBe('DEFAULT');

    // Produto ganha grupo de complemento -> vira COMBO_V2 na próxima sync.
    $group = App\Models\ProductOptionGroup::create([
        'company_id' => $product->company_id,
        'name' => 'Escolha o sabor',
        'total_qty' => 1,
        'min_qty' => 1,
        'fixed' => false,
        'allow_skip' => false,
        'sort_order' => 1,
    ]);
    DB::table('option_group_product')->insert(['product_id' => $product->id, 'product_option_group_id' => $group->id]);
    App\Models\ProductOption::create([
        'product_option_group_id' => $group->id,
        'name' => 'Frango',
        'active' => true,
        'additional_price' => 2.5,
        'default_qty' => 0,
        'max_qty' => 1,
        'sort_order' => 1,
    ]);

    (new IfoodCatalogSyncService($gateway))->syncFullCatalog($integration->fresh());

    $updated = DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $product->id)->first(['ifood_item_id', 'ifood_item_type']);
    expect($updated->ifood_item_type)->toBe('COMBO_V2')
        ->and($updated->ifood_item_id)->not->toBe($original->ifood_item_id)
        ->and(Str::isUuid($updated->ifood_item_id))->toBeTrue();
});

test('syncFullCatalog reusa ifood_item_id quando ifood_item_type nunca foi registrado (backfill sem regenerar)', function () {
    ['branch' => $branch, 'product' => $product, 'integration' => $integration] = ifoodContext('cat2e');

    // ifoodContext grava um ifood_item_id fixo, mas ifood_item_type começa null
    // (coluna nova) — simula item sincronizado antes deste rastreamento existir.
    $preExisting = DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $product->id)->value('ifood_item_id');

    $gateway = Mockery::mock(IfoodGatewayContract::class);
    $gateway->shouldReceive('createCategory')->once()->andReturn('categoria-ifood-2e');
    $gateway->shouldReceive('syncCatalog')->once();

    (new IfoodCatalogSyncService($gateway))->syncFullCatalog($integration);

    $row = DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $product->id)->first(['ifood_item_id', 'ifood_item_type']);
    expect($row->ifood_item_id)->toBe($preExisting)
        ->and($row->ifood_item_type)->toBe('DEFAULT');
});

test('syncAvailability não faz nada quando produto ainda não tem ifood_item_id', function () {
    ['branch' => $branch, 'product' => $product] = ifoodContext('cat3');
    DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $product->id)->update(['ifood_item_id' => null]);

    $gateway = Mockery::mock(IfoodGatewayContract::class);
    $gateway->shouldNotReceive('syncCatalog');
    $gateway->shouldNotReceive('createCategory');

    (new IfoodCatalogSyncService($gateway))->syncAvailability($branch, $product);
});

test('syncAvailability envia UNAVAILABLE quando produto é pausado (active=false)', function () {
    ['branch' => $branch, 'product' => $product] = ifoodContext('cat4');

    // ProductObserver também reage a esse update despachando SyncIfoodCatalogJob de
    // verdade (fila sync) — Bus::fake() evita que ele rode com o gateway real aqui,
    // já que este teste quer testar syncAvailability isoladamente com o mock.
    Bus::fake();
    $product->update(['active' => false]);

    $gateway = Mockery::mock(IfoodGatewayContract::class);
    $gateway->shouldReceive('createCategory')->once()->andReturn('categoria-ifood-4');

    $capturedPayload = null;
    $gateway->shouldReceive('syncCatalog')->once()->andReturnUsing(function ($int, $payload) use (&$capturedPayload) {
        $capturedPayload = $payload;
    });

    (new IfoodCatalogSyncService($gateway))->syncAvailability($branch, $product);

    expect($capturedPayload['item']['id'])->toBe('ifood-item-coxinha-cat4')
        ->and($capturedPayload['item']['status'])->toBe('UNAVAILABLE');
});

test('ProductObserver dispara SyncIfoodCatalogJob quando active muda e há integração ativa na filial', function () {
    Bus::fake();
    ['branch' => $branch, 'product' => $product] = ifoodContext('cat5');

    $product->update(['active' => false]);

    Bus::assertDispatched(SyncIfoodCatalogJob::class, fn ($job) => $job->branchId === $branch->id && $job->productId === $product->id);
});

test('ProductObserver não dispara nada quando campo alterado não é relevante pro iFood', function () {
    Bus::fake();
    ['product' => $product] = ifoodContext('cat6');

    $product->update(['name' => 'Coxinha Grande']);

    Bus::assertNotDispatched(SyncIfoodCatalogJob::class);
});
