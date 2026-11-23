<?php

use App\Jobs\SyncKeetaCatalogJob;
use App\Models\KeetaIntegration;
use App\Services\Keeta\KeetaCatalogBuilder;
use App\Services\Keeta\KeetaStoreHours;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('converte horários locais para UTC quebrando o dia, como o exemplo da Keeta', function () {
    $hours = app(KeetaStoreHours::class);

    expect($hours->toUtcWeekHours([['day' => 1, 'start' => '08:00', 'end' => '20:00']], 'America/Sao_Paulo'))
        ->toBe([['dayOfWeek' => ['MONDAY'], 'timePeriods' => ['startTime' => '11:00:00.000Z', 'endTime' => '23:00:00.000Z']]]);

    expect($hours->toUtcWeekHours([
        ['day' => 1, 'start' => '10:00', 'end' => '22:00'],
        ['day' => 2, 'start' => '10:00', 'end' => '22:00'],
    ], 'America/Sao_Paulo'))->toBe([
        ['dayOfWeek' => ['TUESDAY', 'WEDNESDAY'], 'timePeriods' => ['startTime' => '00:00:00.000Z', 'endTime' => '01:00:00.000Z']],
        ['dayOfWeek' => ['MONDAY', 'TUESDAY'], 'timePeriods' => ['startTime' => '13:00:00.000Z', 'endTime' => '23:59:00.000Z']],
    ]);

    // Turno até 21h local termina à meia-noite UTC: vira 23:59 sem segundo pedaço.
    expect($hours->toUtcWeekHours([['day' => 7, 'start' => '18:00', 'end' => '21:00']], 'America/Sao_Paulo'))
        ->toBe([['dayOfWeek' => ['SUNDAY'], 'timePeriods' => ['startTime' => '21:00:00.000Z', 'endTime' => '23:59:00.000Z']]]);
});

it('monta o merchant do Open Delivery com ids veddi-* e pendências', function () {
    $ctx = keetaContext();
    $semCategoria = \App\Models\Product::withoutGlobalScopes()->create([
        'company_id' => $ctx['company']->id, 'product_category_id' => $ctx['category']->id, 'name' => 'Sem preço', 'price' => 0, 'active' => true, 'available_in_keeta' => true,
    ]);
    \Illuminate\Support\Facades\DB::table('branch_product')->insert(['branch_id' => $ctx['branch']->id, 'product_id' => $semCategoria->id, 'available' => 1]);
    $foraDaKeeta = \App\Models\Product::withoutGlobalScopes()->create([
        'company_id' => $ctx['company']->id, 'product_category_id' => $ctx['category']->id, 'name' => 'Só no chat', 'price' => 5, 'active' => true, 'available_in_keeta' => false,
    ]);
    \Illuminate\Support\Facades\DB::table('branch_product')->insert(['branch_id' => $ctx['branch']->id, 'product_id' => $foraDaKeeta->id, 'available' => 1]);

    $result = app(KeetaCatalogBuilder::class)->build($ctx['integration']);
    $merchant = $result['merchant'];
    $pid = $ctx['product']->id;

    expect($merchant['id'])->toBe($ctx['integration']->merchant_ref)
        ->and($merchant['status'])->toBe('AVAILABLE')
        ->and($merchant['services'][0]['serviceType'])->toBe('DELIVERY')
        ->and($merchant['services'][0]['serviceHours']['weekHours'])->not->toBeEmpty()
        ->and($merchant['categories'][0]['itemOfferId'])->toBe(["veddi-po{$pid}"])
        ->and($merchant['itemOffers'][0])->toMatchArray(['id' => "veddi-po{$pid}", 'itemId' => "veddi-p{$pid}", 'status' => 'AVAILABLE', 'price' => ['value' => 8.0, 'originalValue' => 8.0]])
        ->and(collect($merchant['items'])->firstWhere('id', "veddi-p{$pid}")['externalCode'])->toBe("veddi-p{$pid}")
        ->and($merchant['basicInfo']['contactPhones']['commercialNumber'])->toBe('55-11988887777')
        ->and($result['issues'])->toHaveKey($semCategoria->id)
        ->and(collect($merchant['items'])->pluck('id'))->not->toContain("veddi-p{$foraDaKeeta->id}");
});

it('deixa de fora, com pendência, categoria ou produto que cita outro app (a Keeta descarta sem erro)', function () {
    $ctx = keetaContext();
    Queue::fake();
    $ctx['category']->update(['name' => 'Homologação iFood — teste']);

    $result = app(KeetaCatalogBuilder::class)->build($ctx['integration']);

    expect($result['merchant']['categories'])->toBe([])
        ->and($result['issues'][$ctx['product']->id])->toContain('iFood', 'Homologação iFood — teste');

    $ctx['category']->update(['name' => 'Salgados fritos']);
    expect(app(KeetaCatalogBuilder::class)->build($ctx['integration'])['merchant']['categories'])->toHaveCount(1);

    $ctx['product']->update(['name' => 'Combo Rappi']);
    $result = app(KeetaCatalogBuilder::class)->build($ctx['integration']);
    expect($result['merchant']['itemOffers'])->toBe([])
        ->and($result['issues'][$ctx['product']->id])->toContain('Rappi');

    // Palavras parecidas não contam como nome de app.
    $ctx['product']->update(['name' => 'Sushi food box']);
    expect(app(KeetaCatalogBuilder::class)->build($ctx['integration'])['merchant']['itemOffers'])->toHaveCount(1);
});

it('serve o cardápio só com X-API-KEY certo e cardápio publicado', function () {
    $ctx = keetaContext();
    $url = route('integrations.keeta.merchant', ['merchantRef' => $ctx['integration']->merchant_ref]);

    $this->getJson($url)->assertNotFound();
    $this->getJson($url, ['X-API-KEY' => 'errada'])->assertNotFound();

    $this->getJson($url, ['X-API-KEY' => 'menu-key-A'])->assertOk()
        ->assertJsonPath('id', $ctx['integration']->merchant_ref)
        ->assertJsonPath('itemOffers.0.id', 'veddi-po'.$ctx['product']->id);

    expect($ctx['integration']->fresh()->last_menu_fetched_at)->not->toBeNull();

    KeetaIntegration::withoutGlobalScopes()->whereKey($ctx['integration']->id)->update(['catalog_published_at' => null]);
    \Illuminate\Support\Facades\Cache::flush();
    $this->getJson($url, ['X-API-KEY' => 'menu-key-A'])->assertNotFound();
});

it('avisa a Keeta (merchantUpdate vazio) quando o cardápio publicado muda', function () {
    $ctx = keetaContext();
    fakeKeetaApi();

    (new SyncKeetaCatalogJob($ctx['branch']->id))->handle(app(\App\Services\Keeta\KeetaCatalogSyncService::class));

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/merchantUpdate/'.$ctx['integration']->merchant_ref)
        && $r->body() === '' && $r->hasHeader('X-App-Signature'));
    expect($ctx['integration']->fresh()->catalog_sync_status)->toBe('synced');
});

it('mudança no produto agenda o aviso só para filial com cardápio publicado', function () {
    $ctx = keetaContext();
    Queue::fake();

    $ctx['product']->update(['price' => 9.5]);
    Queue::assertPushed(SyncKeetaCatalogJob::class, fn ($job) => $job->branchId === $ctx['branch']->id);

    KeetaIntegration::withoutGlobalScopes()->update(['catalog_published_at' => null]);
    Queue::fake();
    $ctx['product']->update(['price' => 10]);
    Queue::assertNotPushed(SyncKeetaCatalogJob::class);
});

it('abre e fecha a loja com merchantStatus sozinho no corpo', function () {
    $ctx = keetaContext();
    fakeKeetaApi();

    app(\App\Services\Keeta\KeetaStoreStatusSync::class)->setOpen($ctx['integration'], false);

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/merchantUpdate/'.$ctx['integration']->merchant_ref)
        && $r->body() === '{"merchantStatus":"UNAVAILABLE"}');
    expect($ctx['integration']->fresh()->store_status)->toBe('UNAVAILABLE');
});

it('preserva os itemOfferId existentes depois de incluir um produto', function () {
    $ctx = keetaContext();
    $before = app(KeetaCatalogBuilder::class)->build($ctx['integration'])['merchant'];
    $new = \App\Models\Product::withoutGlobalScopes()->create([
        'company_id' => $ctx['company']->id, 'product_category_id' => $ctx['category']->id,
        'name' => 'Novo item SIT', 'price' => 6, 'active' => true, 'available_in_keeta' => true,
    ]);
    $new->branches()->attach($ctx['branch']->id, ['available' => true]);
    $after = app(KeetaCatalogBuilder::class)->build($ctx['integration'])['merchant'];

    expect(collect($after['itemOffers'])->firstWhere('itemId', $before['itemOffers'][0]['itemId'])['id'])
        ->toBe($before['itemOffers'][0]['id'])
        ->and($after['itemOffers'])->toHaveCount(2);
});

it('avisa a Keeta quando o estoque zera ou é reposto', function () {
    $ctx = keetaContext();
    \Illuminate\Support\Facades\DB::table('branch_product')->where('product_id', $ctx['product']->id)
        ->update(['track_stock' => true, 'quantity' => 2]);
    Queue::fake();
    $stock = app(\App\Services\Order\StockService::class);
    $stock->setQuantity($ctx['branch'], $ctx['product'], 0, 'SIT esgotado');
    Queue::assertPushed(SyncKeetaCatalogJob::class, fn ($job) => $job->branchId === $ctx['branch']->id && $job->afterCommit);
    expect(app(KeetaCatalogBuilder::class)->build($ctx['integration'])['merchant']['itemOffers'][0]['status'])->toBe('UNAVAILABLE');

    Queue::fake();
    $stock->setQuantity($ctx['branch'], $ctx['product'], 5, 'SIT reposição');
    Queue::assertPushed(SyncKeetaCatalogJob::class);
    expect(app(KeetaCatalogBuilder::class)->build($ctx['integration'])['merchant']['itemOffers'][0]['status'])->toBe('AVAILABLE');
});

it('avisa a Keeta quando ligar ou desligar o rastreio com estoque zerado muda a disponibilidade', function () {
    $ctx = keetaContext();
    \Illuminate\Support\Facades\DB::table('branch_product')->where('product_id', $ctx['product']->id)
        ->update(['track_stock' => false, 'quantity' => 0]);
    $stock = app(\App\Services\Order\StockService::class);

    Queue::fake();
    $stock->toggleTracking($ctx['branch'], $ctx['product'], true);
    Queue::assertPushed(SyncKeetaCatalogJob::class, fn ($job) => $job->branchId === $ctx['branch']->id);
    expect(app(KeetaCatalogBuilder::class)->build($ctx['integration'])['merchant']['itemOffers'][0]['status'])->toBe('UNAVAILABLE');

    Queue::fake();
    $stock->toggleTracking($ctx['branch'], $ctx['product'], false);
    Queue::assertPushed(SyncKeetaCatalogJob::class);
    expect(app(KeetaCatalogBuilder::class)->build($ctx['integration'])['merchant']['itemOffers'][0]['status'])->toBe('AVAILABLE');

    // Com quantidade em estoque, ligar o rastreio não muda a disponibilidade: nada a avisar.
    \Illuminate\Support\Facades\DB::table('branch_product')->where('product_id', $ctx['product']->id)->update(['quantity' => 3]);
    Queue::fake();
    $stock->toggleTracking($ctx['branch'], $ctx['product'], true);
    Queue::assertNotPushed(SyncKeetaCatalogJob::class);
});

it('avisa a Keeta depois de editar somente os complementos no formulário', function () {
    $ctx = keetaContext();
    $admin = \App\Models\User::factory()->create();
    $ctx['company']->users()->attach($admin->id, ['role' => 'company_admin']);
    Queue::fake();

    \Livewire\Livewire::actingAs($admin)->test(\App\Livewire\Admin\Products\Form::class, ['product' => $ctx['product']->fresh()])
        ->set('optionGroups', [[
            'group_id' => null, 'key' => 'g1', 'name' => 'Molho', 'total_qty' => 1, 'min_qty' => '0',
            'fixed' => false, 'allow_skip' => true, 'image_path' => null,
            'options' => [['id' => null, 'key' => 'o1', 'name' => 'Picante', 'additional_price' => '1.00',
                'default_qty' => 0, 'max_qty' => '', 'description' => '', 'active' => false, 'sort_order' => 0, 'image_path' => null]],
        ]])
        ->call('save')->assertHasNoErrors();

    Queue::assertPushed(SyncKeetaCatalogJob::class, fn ($job) => $job->branchId === $ctx['branch']->id);
    $merchant = app(KeetaCatalogBuilder::class)->build($ctx['integration'])['merchant'];
    expect($merchant['optionGroups'][0]['options'][0]['status'])->toBe('UNAVAILABLE');
});

it('avisa a Keeta ao editar horário ou telefone da filial conectada', function () {
    $ctx = keetaContext();
    Queue::fake();
    $ctx['branch']->update(['opens_at' => '09:00:00', 'phone' => '11911112222']);
    Queue::assertPushed(SyncKeetaCatalogJob::class, fn ($job) => $job->branchId === $ctx['branch']->id);

    $merchant = app(KeetaCatalogBuilder::class)->build($ctx['integration'])['merchant'];
    expect($merchant['basicInfo']['contactPhones']['commercialNumber'])->toBe('55-11911112222');

    Queue::fake();
    $ctx['company']->update(['email' => 'sit@example.com']);
    Queue::assertPushed(SyncKeetaCatalogJob::class);
    $merchant = app(KeetaCatalogBuilder::class)->build($ctx['integration'])['merchant'];
    expect($merchant['basicInfo']['contactEmails'])->toBe(['sit@example.com']);
});

it('publica a disponibilidade por produto em UTC com vínculo de id estável', function () {
    $ctx = keetaContext();
    Queue::fake();
    $ctx['product']->update(['keeta_availability' => ['days' => [1], 'start' => '22:00', 'end' => '02:00']]);
    Queue::assertPushed(SyncKeetaCatalogJob::class);

    $merchant = app(KeetaCatalogBuilder::class)->build($ctx['integration'])['merchant'];
    expect($merchant['itemOffers'][0]['availabilityId'])->toBe(['veddi-a'.$ctx['product']->id])
        ->and($merchant['availabilities'])->toBe([[
            'id' => 'veddi-a'.$ctx['product']->id,
            'hours' => [
                ['dayOfWeek' => ['TUESDAY'], 'timePeriods' => ['startTime' => '01:00:00.000Z', 'endTime' => '03:00:00.000Z']],
                ['dayOfWeek' => ['TUESDAY'], 'timePeriods' => ['startTime' => '03:00:00.000Z', 'endTime' => '05:00:00.000Z']],
            ],
        ]]);
});

it('salva, valida e remove a restrição de horário Keeta no cadastro do produto', function () {
    $ctx = keetaContext();
    $admin = \App\Models\User::factory()->create();
    $ctx['company']->users()->attach($admin->id, ['role' => 'company_admin']);
    Queue::fake();
    $component = \Livewire\Livewire::actingAs($admin)->test(\App\Livewire\Admin\Products\Form::class, ['product' => $ctx['product']->fresh()])
        ->set('keeta_schedule_enabled', true)
        ->assertSee('Dias disponíveis na Keeta')
        ->set('keeta_available_days', [])
        ->call('save')->assertHasErrors(['keeta_available_days'])
        ->set('keeta_available_days', [1, 2])
        ->set('keeta_available_start', '11:00')
        ->set('keeta_available_end', '11:00')
        ->call('save')->assertHasErrors(['keeta_available_end'])
        ->set('keeta_available_end', '14:00')
        ->call('save')->assertHasNoErrors();

    expect($ctx['product']->fresh()->keeta_availability)->toBe(['days' => [1, 2], 'start' => '11:00', 'end' => '14:00']);
    $component->set('keeta_schedule_enabled', false)->call('save')->assertHasNoErrors();
    expect($ctx['product']->fresh()->keeta_availability)->toBeNull();
    $merchant = app(KeetaCatalogBuilder::class)->build($ctx['integration'])['merchant'];
    expect($merchant['itemOffers'][0])->not->toHaveKey('availabilityId');
});
