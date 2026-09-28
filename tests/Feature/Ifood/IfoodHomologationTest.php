<?php

use App\Livewire\Admin\Settings\IfoodIntegrationSettings;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionGroup;
use App\Models\User;
use App\Services\Ifood\IfoodCatalogSyncService;
use App\Services\Ifood\IfoodGatewayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function merchantScreen(array $ctx)
{
    $user = User::factory()->create();
    $user->companies()->attach($ctx['company']->id, ['role' => 'company_admin']);
    app()->instance('current.company', $ctx['company']);

    return Livewire::actingAs($user)->test(IfoodIntegrationSettings::class);
}

test('merchant consulta lojas detalhes e disponibilidade e não expõe tokens', function () {
    $ctx = ifoodContext('merchant-info');
    config(['ifood.partner_client_id' => 'test-client']);
    Http::fake([
        '*/merchants?page=*' => Http::response([['id' => $ctx['integration']->merchant_id, 'name' => 'Loja teste']]),
        '*/merchants/*/status' => Http::response([['state' => 'OK', 'available' => true]]),
        '*/merchants/*' => Http::response(['name' => 'Loja teste', 'address' => ['city' => 'São Paulo']]),
    ]);
    merchantScreen($ctx)->call('consultMerchant')->assertHasNoErrors()
        ->assertSee('Loja teste')->assertSee('São Paulo')->assertSee('OK')->assertSee('test-client')
        ->assertDontSee($ctx['integration']->access_token)->assertDontSee($ctx['integration']->refresh_token);
    Http::assertSentCount(3);
});

test('merchant pagina até listar todas as lojas', function () {
    $ctx = ifoodContext('merchant-pages');
    Http::fakeSequence()->push(array_map(fn ($id) => ['id' => (string) $id], range(1, 100)))->push([['id' => '101']]);
    $result = app(IfoodGatewayService::class)->listMerchants($ctx['integration']);
    expect($result)->toHaveCount(101);
    Http::assertSent(fn ($r) => str_contains($r->url(), 'page=2'));
});

test('merchant cria consulta e remove pausa sem pausar a integração', function () {
    $ctx = ifoodContext('merchant-pause');
    $pause = ['id' => 'pause-1', 'description' => 'Homologação', 'start' => now()->toIso8601String(), 'end' => now()->addHour()->toIso8601String()];
    Http::fake(fn ($r) => Http::response($r->method() === 'GET' ? [$pause] : ($r->method() === 'DELETE' ? null : $pause), $r->method() === 'DELETE' ? 204 : 200));
    $start = now('America/Sao_Paulo')->addMinutes(5);
    merchantScreen($ctx)
        ->set('interruptionDescription', 'Homologação')
        ->set('interruptionStart', $start->format('Y-m-d\TH:i'))
        ->set('interruptionEnd', $start->copy()->addHour()->format('Y-m-d\TH:i'))
        ->call('createStoreInterruption')->assertHasNoErrors()
        ->call('consultInterruptions')->assertSee('Homologação')
        ->call('removeStoreInterruption', 'pause-1')->assertHasNoErrors();
    // O iFood usa o fuso da loja e ignora offset: o horário vai como digitado, sem conversão.
    Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/interruptions')
        && $r['start'] === $start->format('Y-m-d\TH:i').':00' && $r['end'] === $start->copy()->addHour()->format('Y-m-d\TH:i').':00');
    Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/interruptions/pause-1'));
    expect($ctx['integration']->fresh()->status)->toBe('active');
});

test('merchant recusa pausa inválida e id de pausa que não pertence à loja', function () {
    $ctx = ifoodContext('merchant-pause-invalid');
    Http::fake(['*' => Http::response([])]);
    merchantScreen($ctx)->set('interruptionDescription', 'Teste')
        ->set('interruptionStart', now()->format('Y-m-d\TH:i'))
        ->set('interruptionEnd', now()->addDays(8)->format('Y-m-d\TH:i'))
        ->call('createStoreInterruption')->assertHasErrors('interruptionEnd');
    Http::assertNothingSent();
    merchantScreen($ctx)->call('removeStoreInterruption', 'other-store-pause')->assertHasErrors('merchantOperation');
    Http::assertNotSent(fn ($r) => $r->method() === 'DELETE');
});

test('horários da homologação preservam os outros dias e são consultáveis depois de salvar', function () {
    $ctx = ifoodContext('merchant-hours');
    $shifts = [['dayOfWeek' => 'MONDAY', 'start' => '08:00:00', 'duration' => 480]];
    Http::fake(function ($r) use (&$shifts) {
        if ($r->method() === 'PUT') {
            $shifts = $r['shifts'];
        }

        return Http::response(['shifts' => $shifts]);
    });
    merchantScreen($ctx)->call('consultOpeningHours')->assertHasNoErrors()
        ->call('fillHomologationHours')->call('saveOpeningHours')->assertHasNoErrors()
        ->call('consultOpeningHours')->assertCount('openingShifts', 5);
    expect($shifts)->toBe([
        ['dayOfWeek' => 'MONDAY', 'start' => '08:00:00', 'duration' => 480],
        ['dayOfWeek' => 'SATURDAY', 'start' => '10:00:00', 'duration' => 540],
        ['dayOfWeek' => 'SUNDAY', 'start' => '09:00:00', 'duration' => 180],
        ['dayOfWeek' => 'SUNDAY', 'start' => '13:00:00', 'duration' => 180],
        ['dayOfWeek' => 'SUNDAY', 'start' => '17:00:00', 'duration' => 360],
    ]);
    Http::assertSent(fn ($r) => $r->method() === 'PUT' && $r['storeId'] === $ctx['integration']->merchant_id);
});

test('horários sobrepostos não chegam à API inclusive na virada da semana', function () {
    $ctx = ifoodContext('merchant-overlap');
    Http::fake(['*' => Http::response(['shifts' => []])]);
    merchantScreen($ctx)->call('consultOpeningHours')->set('openingShifts', [
        ['dayOfWeek' => 'SUNDAY', 'start' => '23:00', 'duration' => 120],
        ['dayOfWeek' => 'MONDAY', 'start' => '00:30', 'duration' => 60],
    ])->call('saveOpeningHours')->assertHasErrors('openingShifts');
    Http::assertNotSent(fn ($r) => $r->method() === 'PUT');
});

test('merchant não consulta integração de outra empresa e limpa dados ao trocar filial', function () {
    $ctx = ifoodContext('merchant-own');
    $other = ifoodContext('merchant-other');
    Http::fake(['*' => Http::response([])]);
    $screen = merchantScreen($ctx)->set('branchId', $other['branch']->id)->assertSet('branchId', $ctx['branch']->id)
        ->assertSet('merchantDetails', [])->assertSet('hoursBranchId', null);
    $screen->call('consultMerchant');
    Http::assertNotSent(fn ($r) => str_contains($r->url(), $other['integration']->merchant_id));
});

test('falha da API aparece na tela e não é anunciada como sucesso', function () {
    $ctx = ifoodContext('merchant-error');
    Http::fake(['*' => Http::response([], 403)]);
    merchantScreen($ctx)->call('consultMerchant')->assertHasErrors('merchantOperation')->assertSet('merchantCheckedAt', null);
});

/** Simula somente a API externa; os serviços e a persistência usados são reais. */
function fakeHomologationCatalog(array &$remote): void
{
    Http::fake(function ($request) use (&$remote) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        if (str_ends_with($path, '/catalogs')) {
            return Http::response([['catalogId' => 'catalog-test']]);
        }
        if (str_ends_with($path, '/categories')) {
            return Http::response(['id' => 'category-test'], 201);
        }
        if (str_ends_with($path, '/image/upload')) {
            return Http::response(['imagePath' => 'ifood/'.hash('sha256', $request['image']).'.png']);
        }
        if (preg_match('~/items/([^/]+)/flat$~', $path, $match)) {
            return Http::response($remote[$match[1]] ?? [], isset($remote[$match[1]]) ? 200 : 404);
        }
        if (str_ends_with($path, '/items') && $request->method() === 'PUT') {
            $remote[$request['item']['id']] = $request->data();

            return Http::response([], 201);
        }
        // A API recusa corpo em lote: um item por PATCH, identificado por itemId.
        if (str_ends_with($path, '/items/price')) {
            if (! isset($request['itemId'], $remote[$request['itemId']])) {
                return Http::response(['error' => ['code' => 'BadRequest']], 400);
            }
            $remote[$request['itemId']]['item']['price'] = $request['price'];

            return Http::response(['batchId' => 'prices-batch'], 202);
        }
        if (str_ends_with($path, '/items/status')) {
            if (! isset($request['itemId'], $remote[$request['itemId']])) {
                return Http::response(['error' => ['code' => 'BadRequest']], 400);
            }
            $remote[$request['itemId']]['item']['status'] = $request['status'];

            return Http::response(['batchId' => 'status-batch'], 202);
        }
        if (str_contains($path, '/batch/')) {
            return Http::response(['batchStatus' => 'COMPLETED', 'results' => [['result' => 'SUCCESS']]]);
        }
        if (str_ends_with($path, '/options/price') || str_ends_with($path, '/options/status')) {
            $field = str_ends_with($path, '/price') ? 'price' : 'status';
            foreach ($remote as &$item) {
                foreach ($item['options'] as &$option) {
                    if ($option['id'] === $request['optionId']) {
                        $option[$field] = $request[$field];
                    }
                }
            }

            return Http::response(null, 204);
        }
        throw new RuntimeException('Unexpected request: '.$request->method().' '.$path);
    });
}

test('catálogo cria fotos e complementos e modifica preços e status com PATCH antes da estrutura', function () {
    Bus::fake();
    $ctx = ifoodContext('catalog-flow');
    Storage::fake('s3');
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII=');
    Storage::disk('s3')->put('product.png', $png);
    $ctx['product']->update(['image_path' => 'product.png']);
    $group = ProductOptionGroup::create(['company_id' => $ctx['company']->id, 'name' => 'Complementos', 'total_qty' => 2, 'min_qty' => 0]);
    $ctx['product']->optionGroups()->attach($group);
    $options = collect(['Molho', 'Queijo'])->map(fn ($name) => ProductOption::create([
        'product_option_group_id' => $group->id, 'name' => $name, 'additional_price' => 2, 'active' => true, 'image_path' => 'product.png',
    ]));
    $remote = [];
    fakeHomologationCatalog($remote);
    $service = app(IfoodCatalogSyncService::class);
    $service->syncFullCatalog($ctx['integration']);
    $id = DB::table('branch_product')->where('product_id', $ctx['product']->id)->value('ifood_item_id');
    expect($remote[$id]['products'])->toHaveCount(3);
    foreach ($remote[$id]['products'] as $product) {
        expect($product['imagePath'])->toStartWith('ifood/');
    }
    $originalImage = $remote[$id]['products'][0]['imagePath'];
    Storage::disk('s3')->put('changed.png', $png."\n");
    $ctx['product']->update(['name' => 'Produto alterado', 'price' => 12, 'active' => false, 'image_path' => 'changed.png']);
    foreach ($options as $option) {
        $option->update(['name' => $option->name.' novo', 'additional_price' => 4, 'active' => false, 'image_path' => 'changed.png']);
    }
    $service->syncFullCatalog($ctx['integration']->fresh());
    expect($remote[$id]['item']['status'])->toBe('UNAVAILABLE')
        ->and($remote[$id]['item']['price']['value'])->toBe(12.0)
        ->and($remote[$id]['products'][0]['name'])->toBe('Produto alterado');
    foreach ($remote[$id]['products'] as $product) {
        expect($product['imagePath'])->not->toBe($originalImage);
    }
    foreach ($remote[$id]['options'] as $option) {
        expect($option['status'])->toBe('UNAVAILABLE')->and($option['price']['value'])->toBe(4.0);
    }
    $calls = Http::recorded()->map(fn ($pair) => $pair[0]->method().' '.parse_url($pair[0]->url(), PHP_URL_PATH))->values();
    foreach (['items/price', 'items/status', 'options/price', 'options/status'] as $endpoint) {
        expect($calls->contains(fn ($call) => str_starts_with($call, 'PATCH ') && str_ends_with($call, '/'.$endpoint)))->toBeTrue();
    }
    $putIndices = $calls->keys()->filter(fn ($index) => str_starts_with($calls[$index], 'PUT '));
    expect($putIndices)->toHaveCount(2);
    expect($calls->search(fn ($call) => str_ends_with($call, '/options/status')))->toBeLessThan($putIndices->last());
});

test('catálogo altera o preço de vários itens por PATCH individual e não usa PUT para alteração somente de preço', function () {
    Bus::fake();
    $ctx = ifoodContext('catalog-batch');
    $second = Product::create(['company_id' => $ctx['company']->id, 'product_category_id' => $ctx['category']->id, 'name' => 'Segundo', 'price' => 9, 'active' => true, 'available_in_ifood' => true]);
    $second->branches()->attach($ctx['branch']->id, ['available' => true]);
    $remote = [];
    fakeHomologationCatalog($remote);
    $service = app(IfoodCatalogSyncService::class);
    $service->syncFullCatalog($ctx['integration']);
    $ctx['product']->update(['price' => 11]);
    $second->update(['price' => 13]);
    $service->syncFullCatalog($ctx['integration']->fresh());
    $patches = Http::recorded()->filter(fn ($pair) => $pair[0]->method() === 'PATCH' && str_ends_with($pair[0]->url(), '/items/price'));
    expect($patches)->toHaveCount(2)
        ->and($patches->map(fn ($pair) => $pair[0]['price']['value'])->sort()->values()->all())->toEqual([11.0, 13.0]);
    $patches->each(fn ($pair) => expect($pair[0]['itemId'])->toBeIn(array_keys($remote))->and($pair[0]->data())->not->toHaveKey('prices'));
    expect(Http::recorded()->filter(fn ($pair) => $pair[0]->method() === 'PUT'))->toHaveCount(2);
});

test('falha parcial de lote interrompe sincronização e não grava sucesso', function () {
    $ctx = ifoodContext('catalog-partial');
    Http::fake([
        '*/items/price' => Http::response(['batchId' => 'batch-1'], 202),
        '*/batch/batch-1' => Http::response(['status' => 'COMPLETED', 'failureCount' => 1, 'results' => [['result' => 'FAILURE']]]),
    ]);
    expect(fn () => app(IfoodGatewayService::class)->updateItemPrices($ctx['integration'], [['itemId' => 'i1', 'price' => 10]]))->toThrow(RuntimeException::class, 'falhas');
    expect($ctx['integration']->fresh()->last_synced_at)->toBeNull();
});

test('upload inválido e resposta de catálogo incompleta interrompem a operação', function () {
    $ctx = ifoodContext('catalog-invalid');
    Http::fake(['*' => Http::response([])]);
    expect(fn () => app(IfoodGatewayService::class)->uploadCatalogImage($ctx['integration'], 'data:image/png;base64,test'))->toThrow(RuntimeException::class, 'imagePath');
    expect(fn () => app(IfoodGatewayService::class)->getCatalogItem($ctx['integration'], 'id'))->toThrow(RuntimeException::class, 'incompleta');
});

test('adicionar complementos mantém o mesmo item DEFAULT à venda', function () {
    Bus::fake();
    $ctx = ifoodContext('catalog-type');
    $remote = [];
    fakeHomologationCatalog($remote);
    $service = app(IfoodCatalogSyncService::class);
    $service->syncFullCatalog($ctx['integration']);
    $oldId = array_key_first($remote);
    $group = ProductOptionGroup::create(['company_id' => $ctx['company']->id, 'name' => 'Extras', 'total_qty' => 1, 'min_qty' => 0]);
    $ctx['product']->optionGroups()->attach($group);
    ProductOption::create(['product_option_group_id' => $group->id, 'name' => 'Extra', 'additional_price' => 1, 'active' => true]);
    $service->syncFullCatalog($ctx['integration']->fresh());
    expect(DB::table('branch_product')->where('product_id', $ctx['product']->id)->value('ifood_item_id'))->toBe($oldId)
        ->and($remote)->toHaveCount(1)
        ->and($remote[$oldId]['item']['type'])->toBe('DEFAULT')
        ->and($remote[$oldId]['item']['status'])->toBe('AVAILABLE')
        ->and($remote[$oldId]['optionGroups'])->toHaveCount(1)
        ->and($remote[$oldId]['products'][0]['optionGroups'][0])->not->toHaveKey('associationType');
});

test('item legado COMBO_V2 volta a DEFAULT pausando o antigo e mantendo somente o novo à venda', function () {
    Bus::fake();
    $ctx = ifoodContext('catalog-legacy-combo');
    $remote = [];
    fakeHomologationCatalog($remote);
    $group = ProductOptionGroup::create(['company_id' => $ctx['company']->id, 'name' => 'Extras', 'total_qty' => 1, 'min_qty' => 0]);
    $ctx['product']->optionGroups()->attach($group);
    ProductOption::create(['product_option_group_id' => $group->id, 'name' => 'Extra', 'additional_price' => 1, 'active' => true]);
    $service = app(IfoodCatalogSyncService::class);
    $service->syncFullCatalog($ctx['integration']);
    $oldId = array_key_first($remote);
    // Simula o item publicado pela versão anterior, que usava COMBO_V2.
    $remote[$oldId]['item']['type'] = 'COMBO_V2';
    DB::table('branch_product')->where('product_id', $ctx['product']->id)->update(['ifood_item_type' => 'COMBO_V2']);
    $service->syncFullCatalog($ctx['integration']->fresh());
    $newId = DB::table('branch_product')->where('product_id', $ctx['product']->id)->value('ifood_item_id');
    expect($newId)->not->toBe($oldId)
        ->and($remote[$oldId]['item']['status'])->toBe('UNAVAILABLE')
        ->and($remote[$newId]['item']['status'])->toBe('AVAILABLE')
        ->and($remote[$newId]['item']['type'])->toBe('DEFAULT');
});

test('salvar produto despacha dados completos somente depois de persistir o formulário', function () {
    Bus::fake();
    $ctx = ifoodContext('catalog-form');
    $user = User::factory()->create();
    $user->companies()->attach($ctx['company']->id, ['role' => 'company_admin']);
    Livewire::actingAs($user)->test(\App\Livewire\Admin\Products\Form::class, ['product' => $ctx['product']->fresh()])
        ->set('name', 'Produto Teste')->set('price', '20.00')->set('available_in_ifood', true)
        ->call('save')->assertHasNoErrors();
    expect($ctx['product']->fresh()->name)->toBe('Produto Teste');
    Bus::assertDispatched(\App\Jobs\SyncIfoodCatalogJob::class, fn ($job) => $job->productId === $ctx['product']->id && $job->branchId === $ctx['branch']->id && $job->syncDetails);
});

test('job de catálogo restaura tenant e ignora produto de outra empresa', function () {
    $ctx = ifoodContext('catalog-job-own');
    $other = ifoodContext('catalog-job-other');
    $service = Mockery::mock(IfoodCatalogSyncService::class);
    $service->shouldNotReceive('syncProduct');
    $service->shouldNotReceive('syncAvailability');
    (new \App\Jobs\SyncIfoodCatalogJob($ctx['branch']->id, $other['product']->id, true))->handle($service);
    expect(app('current.company')->id)->toBe($other['company']->id);
});
