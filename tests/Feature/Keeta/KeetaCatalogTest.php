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
        ->and($merchant['basicInfo']['contactPhones']['commercialNumber'])->toBe('11988887777')
        ->and($result['issues'])->toHaveKey($semCategoria->id)
        ->and(collect($merchant['items'])->pluck('id'))->not->toContain("veddi-p{$foraDaKeeta->id}");
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
        && $r->body() === '{}' && $r->hasHeader('X-App-Signature'));
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
