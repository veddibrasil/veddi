<?php

use App\Contracts\IfoodGatewayContract;
use App\Contracts\OrderServiceInterface;
use App\Exceptions\IfoodMerchantAlreadyLinkedException;
use App\Jobs\DisableIfoodItemJob;
use App\Jobs\ProcessIfoodOrderJob;
use App\Jobs\SyncIfoodCatalogJob;
use App\Livewire\Admin\Branches\Pauses;
use App\Livewire\Admin\Orders\Index as OrdersIndex;
use App\Livewire\Admin\Settings\IfoodIntegrationSettings;
use App\Mail\IfoodConnectionAlert;
use App\Models\BranchPause;
use App\Models\CompanyNotification;
use App\Models\IfoodOrderEvent;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Ifood\IfoodAuthService;
use App\Services\Ifood\IfoodCatalogSyncService;
use App\Services\Ifood\IfoodConnectionMonitor;
use App\Services\Order\StockService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function ifoodOperationAdmin(array $ctx, string $role = 'company_admin'): User
{
    $user = User::factory()->create();
    $user->companies()->attach($ctx['company']->id, ['role' => $role]);
    app()->instance('current.company', $ctx['company']);

    return $user;
}

// ─── Uma loja, uma integração ───────────────────────────────────────────────

test('loja ligada a integração suspensa de outra empresa não pode ser conectada de novo', function () {
    $owner = ifoodContext('dup-owner');
    $other = ifoodContext('dup-other');
    $owner['integration']->update(['status' => 'paused']);
    $other['integration']->update([
        'merchant_id' => null,
        'status' => 'disconnected',
        'available_merchants' => [['id' => $owner['integration']->merchant_id, 'name' => 'Loja A'], ['id' => 'outra', 'name' => 'Outra']],
    ]);

    expect(fn () => app(IfoodAuthService::class)->selectMerchant($other['integration']->fresh(), $owner['integration']->merchant_id))
        ->toThrow(IfoodMerchantAlreadyLinkedException::class);
    expect($other['integration']->fresh()->merchant_id)->toBeNull();
});

test('o banco não aceita a mesma loja do iFood em duas integrações', function () {
    $a = ifoodContext('uniq-a');
    $b = ifoodContext('uniq-b');

    expect(fn () => $b['integration']->update(['merchant_id' => $a['integration']->merchant_id]))
        ->toThrow(UniqueConstraintViolationException::class);
});

// ─── Empresa bloqueada ──────────────────────────────────────────────────────

test('webhook de empresa bloqueada não vira pedido e o KEEPALIVE tira a loja da presença', function () {
    config(['ifood.partner_client_secret' => 'segredo-teste']);
    Bus::fake();
    ['integration' => $blocked, 'company' => $company] = ifoodContext('wh-blocked');
    ['integration' => $active] = ifoodContext('wh-active');
    $company->update(['status' => 'BLOCKED']);
    $sign = fn (array $payload) => ['X-IFood-Signature' => hash_hmac('sha256', json_encode($payload), 'segredo-teste')];

    $event = ['id' => 'evt-bloq', 'code' => 'PLC', 'merchantId' => $blocked->merchant_id, 'orderId' => 'o-1', 'createdAt' => now()->toIso8601String()];
    $this->postJson('/webhooks/ifood', $event, $sign($event))->assertStatus(404);
    expect(IfoodOrderEvent::count())->toBe(0);

    $keepalive = ['id' => 'hb', 'code' => 'KEEPALIVE', 'merchantIds' => [$blocked->merchant_id, $active->merchant_id]];
    $this->postJson('/webhooks/ifood', $keepalive, $sign($keepalive))
        ->assertStatus(202)->assertExactJson(['merchantIds' => [$active->merchant_id]]);

    // KEEPALIVE também é sinal de vida do webhook.
    expect($active->fresh()->last_webhook_received_at)->not->toBeNull()
        ->and($active->fresh()->webhook_status)->toBe('healthy')
        ->and($blocked->fresh()->last_webhook_received_at)->toBeNull();
});

// ─── Pedido que não entra no painel ─────────────────────────────────────────

test('pedido do iFood que não entra no painel avisa no sino e na lista de pedidos', function () {
    $ctx = ifoodContext('nao-entrou');
    fakeIfoodApi(ifoodOrderDetailsPayload('ifood-order-nao-entrou', $ctx['integration']->merchant_id, 'ifood-item-coxinha-nao-entrou'));
    // Última tentativa: a falha não volta pra fila e o restaurante é avisado.
    $event = IfoodOrderEvent::create([
        'event_id' => 'evt-nao-entrou', 'event_type' => 'PLC', 'source' => 'polling',
        'ifood_integration_id' => $ctx['integration']->id,
        'payload' => ['orderId' => 'ifood-order-nao-entrou', 'merchantId' => $ctx['integration']->merchant_id],
        'status' => 'pending',
        'attempts' => ProcessIfoodOrderJob::MAX_ATTEMPTS - 1,
    ]);
    $this->mock(OrderServiceInterface::class, fn ($mock) => $mock->shouldReceive('createOrder')->andThrow(new RuntimeException('falha')));

    expect(fn () => runIfoodOrderJob($event->id))->toThrow(RuntimeException::class);

    $notification = CompanyNotification::where('company_id', $ctx['company']->id)->where('type', 'ifood_alert')->first();
    expect($notification?->title)->toBe('Pedido #1234 não entrou no painel')
        ->and($notification->subtitle)->toContain('Gestor de Pedidos do iFood')
        ->and($event->fresh()->error)->toContain('Pedido #1234')->toContain('Não conseguimos registrar o pedido');

    $admin = ifoodOperationAdmin($ctx);
    Livewire::actingAs($admin)->test(OrdersIndex::class)
        ->assertSee('Resolva pelo Gestor de Pedidos do iFood')
        ->assertSee('Não conseguimos registrar o pedido')
        ->call('dismissIfoodNotImported', $event->id)
        ->assertDontSee('Resolva pelo Gestor de Pedidos do iFood');
    expect($event->fresh()->status)->toBe('dismissed');
});

test('aviso de pedido não importado de outra empresa não aparece nem pode ser dispensado', function () {
    $mine = ifoodContext('nao-entrou-minha');
    $other = ifoodContext('nao-entrou-outra');
    $event = IfoodOrderEvent::create([
        'event_id' => 'evt-outra', 'event_type' => 'PLC', 'source' => 'polling',
        'ifood_integration_id' => $other['integration']->id,
        'payload' => ['orderId' => 'o-outra'], 'status' => 'failed', 'error' => 'Falha da outra empresa',
    ]);

    Livewire::actingAs(ifoodOperationAdmin($mine))->test(OrdersIndex::class)
        ->assertDontSee('Falha da outra empresa')
        ->call('dismissIfoodNotImported', $event->id);
    expect($event->fresh()->status)->toBe('failed');
});

test('negociação de pedido fora do painel avisa o restaurante', function () {
    $ctx = ifoodContext('disputa-orfa');
    $event = IfoodOrderEvent::create([
        'event_id' => 'evt-hsd-orfa', 'event_type' => 'HSD', 'source' => 'polling',
        'ifood_integration_id' => $ctx['integration']->id,
        'payload' => ['orderId' => 'ifood-order-orfa', 'metadata' => [
            'disputeId' => 'disputa-orfa-1', 'action' => 'CANCELLATION', 'expiresAt' => now()->addMinutes(5)->toIso8601String(),
        ]],
        'status' => 'pending',
    ]);

    runIfoodOrderJob($event->id);

    expect(CompanyNotification::where('company_id', $ctx['company']->id)->where('type', 'ifood_alert')
        ->where('title', 'Negociação iFood em pedido fora do painel')->exists())->toBeTrue();
    Livewire::actingAs(ifoodOperationAdmin($ctx))->test(OrdersIndex::class)
        ->assertSee('Negociação iFood em pedido fora do painel');
});

test('item pausado ou sem estoque aqui não impede o pedido que já foi feito no iFood', function () {
    $ctx = ifoodContext('sem-estoque');
    DB::table('branch_product')->where('branch_id', $ctx['branch']->id)->where('product_id', $ctx['product']->id)
        ->update(['available' => false, 'track_stock' => true, 'quantity' => 1]);
    fakeIfoodApi(ifoodOrderDetailsPayload('ifood-order-sem-estoque', $ctx['integration']->merchant_id, 'ifood-item-coxinha-sem-estoque', 2));
    $event = IfoodOrderEvent::create([
        'event_id' => 'evt-sem-estoque', 'event_type' => 'PLC', 'source' => 'polling',
        'ifood_integration_id' => $ctx['integration']->id,
        'payload' => ['orderId' => 'ifood-order-sem-estoque', 'merchantId' => $ctx['integration']->merchant_id],
        'status' => 'pending',
    ]);

    runIfoodOrderJob($event->id);

    expect($event->fresh()->status)->toBe('processed')
        ->and(Order::withoutGlobalScopes()->where('external_order_id', 'ifood-order-sem-estoque')->exists())->toBeTrue()
        ->and(DB::table('branch_product')->where('branch_id', $ctx['branch']->id)->where('product_id', $ctx['product']->id)->value('quantity'))->toBe(0);
});

// ─── Disponibilidade no iFood ───────────────────────────────────────────────

test('estoque zerado e reposto mudam a disponibilidade do item no iFood', function () {
    Bus::fake([SyncIfoodCatalogJob::class]);
    $ctx = ifoodContext('estoque-zera');
    DB::table('branch_product')->where('branch_id', $ctx['branch']->id)->update(['track_stock' => true, 'quantity' => 3, 'available' => true]);
    $stock = app(StockService::class);

    $stock->setQuantity($ctx['branch'], $ctx['product'], 0);
    $stock->adjust($ctx['branch'], $ctx['product'], 5);
    $stock->adjust($ctx['branch'], $ctx['product'], 2);

    // Só as viradas (disponível → indisponível → disponível) avisam o iFood.
    Bus::assertDispatchedTimes(SyncIfoodCatalogJob::class, 2);
    Bus::assertDispatched(SyncIfoodCatalogJob::class, fn ($job) => $job->branchId === $ctx['branch']->id
        && $job->productId === $ctx['product']->id && ! $job->syncDetails);
});

test('excluir o produto de vez tira o item de venda no iFood', function () {
    Bus::fake([DisableIfoodItemJob::class, SyncIfoodCatalogJob::class]);
    $ctx = ifoodContext('exclui');

    $ctx['product']->forceDelete();

    Bus::assertDispatched(DisableIfoodItemJob::class, fn ($job) => $job->branchId === $ctx['branch']->id
        && $job->itemId === 'ifood-item-coxinha-exclui');
});

test('job de item desativado envia UNAVAILABLE pelo id guardado', function () {
    $ctx = ifoodContext('desativa');
    $service = Mockery::mock(IfoodCatalogSyncService::class);
    $service->shouldReceive('disableItem')->once()->withArgs(fn ($integration, $itemId) => $integration->id === $ctx['integration']->id && $itemId === 'item-antigo');

    (new DisableIfoodItemJob($ctx['branch']->id, 'item-antigo'))->handle($service);
});

// ─── Cardápio ───────────────────────────────────────────────────────────────

test('cardápio só vai pro iFood depois que o restaurante publica', function () {
    $ctx = ifoodContext('opt-in');
    $ctx['integration']->update(['catalog_published_at' => null]);

    $untouched = Mockery::mock(IfoodCatalogSyncService::class);
    $untouched->shouldNotReceive('syncFullCatalog');
    $untouched->shouldNotReceive('syncAvailability');
    (new SyncIfoodCatalogJob($ctx['branch']->id))->handle($untouched);
    (new SyncIfoodCatalogJob($ctx['branch']->id, $ctx['product']->id))->handle($untouched);

    $publisher = Mockery::mock(IfoodCatalogSyncService::class);
    $publisher->shouldReceive('syncFullCatalog')->once()->andReturn(['synced' => 1, 'failed' => []]);
    (new SyncIfoodCatalogJob($ctx['branch']->id, publish: true))->handle($publisher);

    expect($ctx['integration']->fresh()->catalog_published_at)->not->toBeNull()
        ->and($ctx['integration']->fresh()->catalog_sync_status)->toBe('done');
});

test('produto com problema não impede o envio dos demais e o motivo fica no cadastro', function () {
    Bus::fake();
    Storage::fake('s3');
    $ctx = ifoodContext('por-item');
    Storage::disk('s3')->put('foto.webp', 'conteúdo que não é JPG nem PNG');
    $second = Product::withoutGlobalScopes()->create([
        'company_id' => $ctx['company']->id, 'product_category_id' => $ctx['category']->id, 'name' => 'Pastel',
        'price' => 9, 'active' => true, 'available_in_ifood' => true, 'image_path' => 'foto.webp',
    ]);
    $second->branches()->attach($ctx['branch']->id, ['available' => true]);

    $gateway = Mockery::mock(IfoodGatewayContract::class);
    $gateway->shouldReceive('getCatalogItem')->andReturnNull();
    $gateway->shouldReceive('createCategory')->andReturn('categoria-ifood');
    $gateway->shouldReceive('syncCatalog')->once();

    $result = (new IfoodCatalogSyncService($gateway))->syncFullCatalog($ctx['integration']);

    $pivot = fn (int $productId) => DB::table('branch_product')->where('branch_id', $ctx['branch']->id)->where('product_id', $productId)->first();
    expect($result['synced'])->toBe(1)
        ->and($result['failed'])->toHaveKey($second->id)
        ->and($pivot($second->id)->ifood_sync_error)->toContain('JPG ou PNG')
        ->and($pivot($ctx['product']->id)->ifood_sync_error)->toBeNull()
        ->and($pivot($ctx['product']->id)->ifood_synced_at)->not->toBeNull()
        ->and($ctx['integration']->fresh()->last_synced_at)->not->toBeNull();
});

test('prévia do cardápio aponta os produtos que o iFood não aceita', function () {
    $ctx = ifoodContext('previa');
    $long = Product::withoutGlobalScopes()->create([
        'company_id' => $ctx['company']->id, 'product_category_id' => $ctx['category']->id, 'name' => str_repeat('a', 101),
        'price' => 9, 'active' => true, 'available_in_ifood' => true,
    ]);
    $long->branches()->attach($ctx['branch']->id, ['available' => true]);

    $preview = Livewire::actingAs(ifoodOperationAdmin($ctx))->test(IfoodIntegrationSettings::class)
        ->set('tab', 'cardapio')
        ->assertSee('Produtos que precisam de ajuste (1)')
        ->get('catalogPreview');

    expect($preview['total'])->toBe(2)
        ->and($preview['categories'])->toBe(1)
        ->and($preview['issues'][0]['problem'])->toBe('Nome com mais de 100 caracteres.');
});

test('prévia lista o erro de produto desligado que já está no iFood e só conta o que o iFood aceitou', function () {
    $ctx = ifoodContext('previa-inativo');
    $off = Product::withoutGlobalScopes()->create([
        'company_id' => $ctx['company']->id, 'product_category_id' => $ctx['category']->id, 'name' => 'Pastel antigo',
        'price' => 9, 'active' => false, 'available_in_ifood' => true,
    ]);
    // ifood_item_id nasce antes do envio; sem ifood_synced_at o iFood nunca aceitou o item.
    $off->branches()->attach($ctx['branch']->id, [
        'available' => true, 'ifood_item_id' => 'item-recusado', 'ifood_sync_error' => 'O iFood não aceitou o envio deste item.',
    ]);
    DB::table('branch_product')->where('branch_id', $ctx['branch']->id)->where('product_id', $ctx['product']->id)
        ->update(['ifood_item_id' => 'item-aceito', 'ifood_synced_at' => now()]);

    $preview = Livewire::actingAs(ifoodOperationAdmin($ctx))->test(IfoodIntegrationSettings::class)
        ->set('tab', 'cardapio')
        ->assertSee('Pastel antigo')
        ->get('catalogPreview');

    expect($preview['total'])->toBe(1)
        ->and($preview['published'])->toBe(1)
        ->and($preview['issues'])->toHaveCount(1)
        ->and($preview['issues'][0]['id'])->toBe($off->id);
});

test('aba Loja grava o nome da loja que veio do iFood', function () {
    $ctx = ifoodContext('nome-loja');
    $ctx['integration']->update(['merchant_name' => null]);
    $gateway = Mockery::mock(IfoodGatewayContract::class);
    $gateway->shouldReceive('getMerchantDetails')->andReturn(['id' => $ctx['integration']->merchant_id, 'name' => 'Coxinha da Vila']);
    $gateway->shouldReceive('getMerchantStatus')->andReturn([]);
    app()->instance(IfoodGatewayContract::class, $gateway);

    Livewire::actingAs(ifoodOperationAdmin($ctx))->test(IfoodIntegrationSettings::class)
        ->call('loadStoreOverview')
        ->assertSet('merchantName', 'Coxinha da Vila');

    expect($ctx['integration']->fresh()->merchant_name)->toBe('Coxinha da Vila');
});

// ─── Tela da integração ─────────────────────────────────────────────────────

test('ações da integração exigem administrador da empresa', function () {
    $ctx = ifoodContext('perm');
    $manager = ifoodOperationAdmin($ctx, 'branch_manager');

    Livewire::actingAs($manager)->test(IfoodIntegrationSettings::class)
        ->call('disconnect')->assertForbidden();

    expect($ctx['integration']->fresh()->merchant_id)->not->toBeNull();
});

test('sem o modo de homologação a tela esconde as ferramentas do roteiro', function () {
    config(['ifood.homologation_mode' => false, 'ifood.partner_client_id' => 'client-id-visivel']);
    $ctx = ifoodContext('homolog-off');

    Livewire::actingAs(ifoodOperationAdmin($ctx))->test(IfoodIntegrationSettings::class)
        ->assertDontSee('client-id-visivel')
        ->assertDontSee('Listar todas as lojas')
        ->call('consultMerchantStores')->assertForbidden();
});

test('horários do iFood podem ser preenchidos com os da filial', function () {
    $ctx = ifoodContext('horario-filial');
    $ctx['branch']->update([
        'available_days' => [0, 6],
        'business_hours' => [0 => ['opens_at' => '11:00', 'closes_at' => '15:00'], 6 => ['opens_at' => '18:00', 'closes_at' => '02:00']],
    ]);
    $sent = null;
    Http::fake(function ($request) use (&$sent) {
        if ($request->method() === 'PUT') {
            $sent = $request['shifts'];
        }

        return Http::response(['shifts' => []]);
    });

    Livewire::actingAs(ifoodOperationAdmin($ctx))->test(IfoodIntegrationSettings::class)
        ->call('consultOpeningHours')
        ->call('fillHoursFromBranch')
        ->assertSet('openingShifts', [
            ['dayOfWeek' => 'SUNDAY', 'start' => '11:00', 'end' => '15:00'],
            ['dayOfWeek' => 'SATURDAY', 'start' => '18:00', 'end' => '02:00'],
        ])
        ->call('saveOpeningHours')->assertHasNoErrors();

    // Fechamento antes da abertura passa da meia-noite: 18:00 às 02:00 = 480 minutos.
    expect($sent)->toBe([
        ['dayOfWeek' => 'SUNDAY', 'start' => '11:00:00', 'duration' => 240],
        ['dayOfWeek' => 'SATURDAY', 'start' => '18:00:00', 'duration' => 480],
    ]);
});

test('suspender pede confirmação e pedidos já recebidos continuam com as ações do iFood', function () {
    $ctx = ifoodContext('suspende');
    $order = ifoodKanbanOrder($ctx, 'preparing', 'ifood-suspende-1');

    Livewire::actingAs(ifoodOperationAdmin($ctx))->test(IfoodIntegrationSettings::class)
        ->call('confirmSuspend')->assertSet('showSuspendModal', true)
        ->call('pause')->assertSet('status', 'paused')->assertSee('Integração suspensa');

    Http::fake(['*' => Http::response(null, 202)]);
    app(\App\Services\Ifood\IfoodOrderActionService::class)->markReady($order->fresh(), null);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'ifood-suspende-1'));
});

// ─── Monitor de conexão ─────────────────────────────────────────────────────

test('autorização revogada avisa no sino e por e-mail uma vez, com lembrete depois de um dia', function () {
    Mail::fake();
    $ctx = ifoodContext('monitor-reauth');
    ifoodOperationAdmin($ctx);
    $ctx['integration']->update(['status' => 'reauthorization_required']);
    $monitor = app(IfoodConnectionMonitor::class);

    expect($monitor->process($ctx['integration']->fresh()))->toBe(1)
        ->and($monitor->process($ctx['integration']->fresh()))->toBe(0);
    Mail::assertQueued(IfoodConnectionAlert::class, 1);
    expect(CompanyNotification::where('company_id', $ctx['company']->id)->where('type', 'ifood_alert')->count())->toBe(1);

    $this->travel(25)->hours();
    expect($monitor->process($ctx['integration']->fresh()))->toBe(1);
});

test('refresh recusado pelo iFood avisa o restaurante na hora', function () {
    Mail::fake();
    $ctx = ifoodContext('monitor-agora');
    ifoodOperationAdmin($ctx);
    $ctx['integration']->update(['token_expires_at' => now()->subMinute()]);
    Http::fake(['*/oauth/token' => Http::response(['error' => ['code' => 'invalid_grant', 'message' => 'invalid_grant']], 401)]);

    expect(fn () => app(IfoodAuthService::class)->getAccessToken($ctx['integration']->fresh()))->toThrow(RuntimeException::class);

    expect($ctx['integration']->fresh()->status)->toBe('reauthorization_required');
    Mail::assertQueued(IfoodConnectionAlert::class);
});

test('loja sem consultar o iFood há mais de 10 minutos avisa só no sino e o aviso some ao normalizar', function () {
    Mail::fake();
    $ctx = ifoodContext('monitor-poll');
    ifoodOperationAdmin($ctx);
    $ctx['integration']->update(['last_polled_at' => now()->subMinutes(15)]);

    app(IfoodConnectionMonitor::class)->run();

    Mail::assertNothingQueued();
    expect(CompanyNotification::where('company_id', $ctx['company']->id)->where('type', 'ifood_alert')->value('title'))
        ->toContain('podem não estar chegando');

    $ctx['integration']->update(['last_polled_at' => now()]);
    app(IfoodConnectionMonitor::class)->run();
    expect($ctx['integration']->fresh()->alerts_sent)->toBeNull();
});

// ─── Pausas da filial ───────────────────────────────────────────────────────

test('pausa da filial pode fechar a loja no iFood junto e é removida junto', function () {
    $ctx = ifoodContext('pausa-filial');
    Http::fake(fn ($request) => $request->method() === 'POST'
        ? Http::response(['id' => 'interrupcao-1'])
        : Http::response(null, 204));
    $start = now()->addHour();
    $end = $start->copy()->addHours(3);

    $screen = Livewire::actingAs(ifoodOperationAdmin($ctx))->test(Pauses::class, ['branch' => $ctx['branch']])
        ->assertSee('Fechar também a loja no iFood')
        ->set('starts_date', $start->format('Y-m-d'))->set('starts_time', $start->format('H:i'))
        ->set('ends_date', $end->format('Y-m-d'))->set('ends_time', $end->format('H:i'))
        ->set('reason', 'Reforma')->set('close_in_ifood', true)
        ->call('create')->assertHasNoErrors();

    $pause = BranchPause::first();
    expect($pause->ifood_interruption_id)->toBe('interrupcao-1');
    Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/interruptions')
        && $request['description'] === 'Reforma');

    $screen->call('confirmDelete', $pause->id)->call('delete');
    Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/interruptions/interrupcao-1'));
    expect(BranchPause::count())->toBe(0);
});

test('pausa recorrente ou longa demais não vai pro iFood', function () {
    $ctx = ifoodContext('pausa-longa');
    Http::fake();
    $start = now()->addHour();

    Livewire::actingAs(ifoodOperationAdmin($ctx))->test(Pauses::class, ['branch' => $ctx['branch']])
        ->set('starts_date', $start->format('Y-m-d'))
        ->set('ends_date', $start->copy()->addDays(10)->format('Y-m-d'))
        ->set('close_in_ifood', true)
        ->call('create')->assertHasErrors('close_in_ifood');

    Http::assertNothingSent();
    expect(BranchPause::count())->toBe(0);
});

// ─── Cadastro de produto ────────────────────────────────────────────────────

test('canal iFood no produto só aparece (e só limita nome e foto) com o módulo de integrações', function () {
    Bus::fake();
    $ctx = ifoodContext('produto-modulo');
    $admin = ifoodOperationAdmin($ctx);
    DB::table('branch_product')->where('branch_id', $ctx['branch']->id)
        ->update(['ifood_sync_error' => 'O iFood aceita fotos JPG ou PNG. Troque a foto deste produto.']);

    Livewire::actingAs($admin)->test(\App\Livewire\Admin\Products\Form::class, ['product' => $ctx['product']->fresh()])
        ->assertSee('Disponível no iFood')
        ->assertSee('O iFood aceita fotos JPG ou PNG. Troque a foto deste produto.');

    $ctx['company']->update(['plan' => \App\Enums\Plan::Free]);
    app()->instance('current.company', $ctx['company']->fresh());

    Livewire::actingAs($admin)->test(\App\Livewire\Admin\Products\Form::class, ['product' => $ctx['product']->fresh()])
        ->assertDontSee('Disponível no iFood')
        ->assertDontSee('Troque a foto deste produto')
        ->set('name', str_repeat('b', 120))
        ->set('price', '10.00')
        ->call('save')
        ->assertHasNoErrors('name');
});

test('falha no envio do cardápio aparece na aba Cardápio sem derrubar a tela', function () {
    $ctx = ifoodContext('publica-falha');
    $ctx['integration']->update(['catalog_published_at' => null]);
    $this->mock(IfoodCatalogSyncService::class)->shouldReceive('syncFullCatalog')
        ->andThrow(new RuntimeException('Nenhum item do cardápio foi aceito pelo iFood.'));

    Livewire::actingAs(ifoodOperationAdmin($ctx))->test(IfoodIntegrationSettings::class)
        ->call('syncCatalogNow')
        ->assertOk()
        ->assertSet('tab', 'cardapio')
        ->assertSet('catalogSyncStatus', 'failed')
        ->assertSee('A sincronização não foi concluída');
});

test('a tela abre na filial já conectada e mostra a etapa de cada filial no seletor', function () {
    $ctx = ifoodContext('filial-padrao');
    // Primeira integração (id menor) ainda esperando a escolha da loja.
    $ctx['integration']->update(['merchant_id' => null, 'status' => 'disconnected', 'available_merchants' => [['id' => 'm-1', 'name' => 'Loja 1'], ['id' => 'm-2', 'name' => 'Loja 2']]]);
    $connected = \App\Models\Branch::withoutGlobalScopes()->create([
        'company_id' => $ctx['company']->id, 'name' => 'Zona Sul', 'address' => 'Rua Y', 'city' => 'SP', 'active' => true,
        'opens_at' => '00:00:00', 'closes_at' => '23:59:59',
    ]);
    \App\Models\IfoodIntegration::create([
        'company_id' => $ctx['company']->id, 'branch_id' => $connected->id, 'merchant_id' => 'merchant-zona-sul',
        'access_token' => 'a', 'refresh_token' => 'r', 'token_expires_at' => now()->addHour(), 'status' => 'active',
    ]);

    Livewire::actingAs(ifoodOperationAdmin($ctx))->test(IfoodIntegrationSettings::class)
        ->assertSet('branchId', $connected->id)
        ->assertSet('connectionState', 'connected')
        ->assertSee('Filial filial-padrao · escolha a loja')
        ->assertSee('Zona Sul · conectada');
});
