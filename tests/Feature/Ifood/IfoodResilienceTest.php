<?php

use App\Contracts\IfoodGatewayContract;
use App\Exceptions\IfoodCatalogItemException;
use App\Jobs\DisableIfoodItemJob;
use App\Jobs\ProcessIfoodOrderJob;
use App\Jobs\SyncIfoodCatalogJob;
use App\Livewire\Admin\Branches\Pauses;
use App\Livewire\Admin\NotificationBell;
use App\Livewire\Admin\Orders\Index as OrdersIndex;
use App\Livewire\Admin\Settings\IfoodIntegrationSettings;
use App\Models\Branch;
use App\Models\BranchPause;
use App\Models\CompanyNotification;
use App\Models\IfoodIntegration;
use App\Models\IfoodOrderEvent;
use App\Models\User;
use App\Services\Ifood\IfoodBranchPauseSync;
use App\Services\Ifood\IfoodCatalogSyncService;
use App\Services\Ifood\IfoodConnectionMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function ifoodResilienceUser(array $ctx, string $role = 'company_admin', ?int $branchId = null): User
{
    $user = User::factory()->create();
    $user->companies()->attach($ctx['company']->id, ['role' => $role, 'branch_id' => $branchId]);
    app()->instance('current.company', $ctx['company']);

    return $user;
}

// ─── Envio do cardápio ──────────────────────────────────────────────────────

test('envio do cardápio na fila é cancelado se a integração foi suspensa antes de rodar', function () {
    $ctx = ifoodContext('fila-suspensa');
    $ctx['integration']->update(['status' => 'paused', 'catalog_sync_status' => 'queued']);

    (new SyncIfoodCatalogJob($ctx['branch']->id, publish: true))->handle(app(IfoodCatalogSyncService::class));

    $integration = $ctx['integration']->fresh();
    expect($integration->catalog_sync_status)->toBe('failed')
        ->and($integration->catalog_sync_error)->toContain('Retome a integração');
});

test('cardápio sem nenhum item aceito falha na hora, sem ficar repetindo na fila', function () {
    $ctx = ifoodContext('nenhum-aceito');
    $service = Mockery::mock(IfoodCatalogSyncService::class);
    $service->shouldReceive('syncFullCatalog')->once()
        ->andThrow(new IfoodCatalogItemException('Nenhum item do cardápio foi aceito pelo iFood. Produto sem categoria.'));

    (new SyncIfoodCatalogJob($ctx['branch']->id))->handle($service);

    $integration = $ctx['integration']->fresh();
    expect($integration->catalog_sync_status)->toBe('failed')
        ->and($integration->catalog_sync_error)->toContain('Nenhum item do cardápio foi aceito');
});

test('retomar a integração reenvia o cardápio e reinicia o prazo do monitor', function () {
    Bus::fake([SyncIfoodCatalogJob::class]);
    $ctx = ifoodContext('retoma');
    $ctx['integration']->update(['status' => 'paused', 'last_polled_at' => now()->subHours(3)]);

    Livewire::actingAs(ifoodResilienceUser($ctx))->test(IfoodIntegrationSettings::class)
        ->call('resume')
        ->assertSet('status', 'active');

    Bus::assertDispatched(SyncIfoodCatalogJob::class, fn (SyncIfoodCatalogJob $job) => $job->branchId === $ctx['branch']->id && $job->productId === null);
    expect($ctx['integration']->fresh()->last_polled_at->gt(now()->subMinute()))->toBeTrue();
});

// ─── Monitor de conexão ─────────────────────────────────────────────────────

test('monitor não usa updated_at como sinal de polling', function () {
    $ctx = ifoodContext('monitor-sem-poll');
    ifoodResilienceUser($ctx);
    $ctx['integration']->update(['last_polled_at' => null]);
    IfoodIntegration::withoutGlobalScopes()->whereKey($ctx['integration']->id)->toBase()->update(['updated_at' => now()->subHour()]);

    expect(app(IfoodConnectionMonitor::class)->process($ctx['integration']->fresh()))->toBe(0);
});

test('empresa bloqueada não recebe e-mail de reconexão do iFood', function () {
    Mail::fake();
    $ctx = ifoodContext('monitor-bloqueada');
    ifoodResilienceUser($ctx);
    $ctx['company']->update(['status' => 'BLOCKED']);
    $ctx['integration']->update(['status' => 'reauthorization_required']);

    expect(app(IfoodConnectionMonitor::class)->process($ctx['integration']->fresh()))->toBe(0);
    Mail::assertNothingQueued();
});

// ─── Eventos de pedido ──────────────────────────────────────────────────────

test('integração suspensa ainda recebe cancelamento de pedido em andamento, mas não pedido novo', function () {
    Bus::fake();
    config(['ifood.partner_client_secret' => 'segredo-webhook']);
    $ctx = ifoodContext('wh-suspensa');
    $ctx['integration']->update(['status' => 'paused']);
    $event = fn (string $id, string $code) => [
        'id' => $id, 'code' => $code, 'merchantId' => $ctx['integration']->merchant_id,
        'orderId' => 'pedido-em-andamento', 'createdAt' => now()->toIso8601String(),
    ];
    $post = fn (array $payload) => $this->postJson('/webhooks/ifood', $payload, [
        'X-IFood-Signature' => hash_hmac('sha256', json_encode($payload), 'segredo-webhook'),
    ]);

    $post($event('evt-can', 'CAN'))->assertStatus(202);
    $post($event('evt-plc', 'PLC'))->assertStatus(404);

    expect(IfoodOrderEvent::pluck('event_type')->all())->toBe(['CAN']);
    Bus::assertDispatchedTimes(ProcessIfoodOrderJob::class, 1);
});

test('pedido novo que a fila desistiu de processar avisa o restaurante e não fica pendente', function () {
    $ctx = ifoodContext('fila-desistiu');
    $event = IfoodOrderEvent::create([
        'event_id' => 'evt-desistiu', 'event_type' => 'PLC', 'source' => 'webhook',
        'ifood_integration_id' => $ctx['integration']->id,
        'payload' => ['orderId' => 'pedido-desistiu'], 'status' => 'pending', 'attempts' => 3,
    ]);

    (new ProcessIfoodOrderJob($event->id))->failed(new RuntimeException('timeout'));
    (new ProcessIfoodOrderJob($event->id))->failed(new RuntimeException('timeout'));

    expect($event->fresh()->status)->toBe('failed')
        ->and(CompanyNotification::where('company_id', $ctx['company']->id)->where('type', 'ifood_alert')->count())->toBe(1);
});

// ─── Itens fora de venda com a integração suspensa ──────────────────────────

test('tirar o produto da filial com a integração suspensa também tira o item de venda no iFood', function () {
    $ctx = ifoodContext('desliga-suspensa');
    $ctx['integration']->update(['status' => 'paused']);
    $gateway = Mockery::mock(IfoodGatewayContract::class);
    $gateway->shouldReceive('updateItemStatuses')->once()
        ->with(Mockery::any(), [['id' => 'item-suspenso', 'status' => 'UNAVAILABLE']]);
    app()->instance(IfoodGatewayContract::class, $gateway);

    (new DisableIfoodItemJob($ctx['branch']->id, 'item-suspenso'))->handle(app(IfoodCatalogSyncService::class));

    Bus::fake([DisableIfoodItemJob::class]);
    DB::table('branch_product')->where('branch_id', $ctx['branch']->id)->where('product_id', $ctx['product']->id)
        ->update(['ifood_item_id' => 'item-excluido']);
    $ctx['product']->forceDelete();
    Bus::assertDispatched(DisableIfoodItemJob::class, fn (DisableIfoodItemJob $job) => $job->itemId === 'item-excluido');
});

// ─── Pausas ─────────────────────────────────────────────────────────────────

test('excluir pausa já removida ou encerrada no iFood não vira erro', function () {
    $ctx = ifoodContext('pausa-404');
    $pause = fn (array $attributes) => BranchPause::create(array_merge([
        'company_id' => $ctx['company']->id, 'branch_id' => $ctx['branch']->id, 'reason' => 'Reforma',
        'recurring_annual' => false, 'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(),
    ], $attributes));

    Http::fake(['*' => Http::response(['error' => ['message' => 'Interruption not found']], 404)]);
    expect(app(IfoodBranchPauseSync::class)->remove($pause(['ifood_interruption_id' => 'ja-removida'])))->toBeTrue();

    Http::fake();
    expect(app(IfoodBranchPauseSync::class)->remove($pause(['ifood_interruption_id' => 'encerrada', 'ends_at' => now()->subMinute()])))->toBeTrue();
    Http::assertNothingSent();
});

test('remover a pausa pela aba do iFood desvincula a pausa da filial', function () {
    $ctx = ifoodContext('pausa-desvincula');
    $pause = BranchPause::create([
        'company_id' => $ctx['company']->id, 'branch_id' => $ctx['branch']->id, 'reason' => 'Reforma',
        'recurring_annual' => false, 'starts_at' => now(), 'ends_at' => now()->addHours(2), 'ifood_interruption_id' => 'int-aba',
    ]);
    $gateway = Mockery::mock(IfoodGatewayContract::class);
    $gateway->shouldReceive('listInterruptions')->andReturn([['id' => 'int-aba', 'description' => 'Reforma']]);
    $gateway->shouldReceive('deleteInterruption')->once()->with(Mockery::any(), 'int-aba');
    app()->instance(IfoodGatewayContract::class, $gateway);

    Livewire::actingAs(ifoodResilienceUser($ctx))->test(IfoodIntegrationSettings::class)
        ->call('removeStoreInterruption', 'int-aba')
        ->assertHasNoErrors();

    expect($pause->fresh()->ifood_interruption_id)->toBeNull();
});

test('marcar a pausa como anual desmarca o fechamento no iFood', function () {
    $ctx = ifoodContext('pausa-anual');

    Livewire::actingAs(ifoodResilienceUser($ctx))->test(Pauses::class, ['branch' => $ctx['branch']])
        ->set('close_in_ifood', true)
        ->set('recurring_annual', true)
        ->assertSet('close_in_ifood', false);
});

// ─── Pedidos e sino: escopo vem do servidor ─────────────────────────────────

test('permissão e escopo da tela de pedidos não podem ser trocados pelo navegador', function () {
    $ctx = ifoodContext('trava-pedidos');
    $screen = Livewire::actingAs(ifoodResilienceUser($ctx, 'branch_manager', $ctx['branch']->id))->test(OrdersIndex::class);

    foreach (['isSuperAdmin' => true, 'canUpdate' => true, 'companyId' => 999, 'userBranchId' => null] as $property => $value) {
        expect(fn () => $screen->set($property, $value))->toThrow(CannotUpdateLockedPropertyException::class);
    }
});

test('gerente de filial não age em pedido nem dispensa aviso de outra filial', function () {
    $ctx = ifoodContext('outra-filial');
    $otherBranch = Branch::withoutGlobalScopes()->create([
        'company_id' => $ctx['company']->id, 'name' => 'Zona Norte', 'address' => 'Rua Z', 'city' => 'SP', 'active' => true,
        'opens_at' => '00:00:00', 'closes_at' => '23:59:59',
    ]);
    $otherIntegration = IfoodIntegration::create([
        'company_id' => $ctx['company']->id, 'branch_id' => $otherBranch->id, 'merchant_id' => 'merchant-zona-norte',
        'access_token' => 'a', 'refresh_token' => 'r', 'token_expires_at' => now()->addHour(), 'status' => 'active',
    ]);
    $event = IfoodOrderEvent::create([
        'event_id' => 'evt-zona-norte', 'event_type' => 'PLC', 'source' => 'polling',
        'ifood_integration_id' => $otherIntegration->id, 'payload' => ['orderId' => 'o-zn'], 'status' => 'failed',
    ]);
    $order = ifoodKanbanOrder(['company' => $ctx['company'], 'branch' => $otherBranch], 'paid', 'ifood-zona-norte');
    $ownEvent = IfoodOrderEvent::create([
        'event_id' => 'evt-propria', 'event_type' => 'PLC', 'source' => 'polling',
        'ifood_integration_id' => $ctx['integration']->id, 'payload' => ['orderId' => 'o-propria'], 'status' => 'failed',
    ]);
    $manager = ifoodResilienceUser($ctx, 'branch_manager', $ctx['branch']->id);

    Livewire::actingAs($manager)->test(OrdersIndex::class)
        ->call('dismissIfoodNotImported', $event->id)
        ->call('dismissIfoodNotImported', $ownEvent->id);
    expect($event->fresh()->status)->toBe('failed')
        ->and($ownEvent->fresh()->status)->toBe('dismissed');

    try {
        Livewire::actingAs($manager)->test(OrdersIndex::class)->call('updateOrderStatus', $order->id, 'cancelled');
    } catch (Throwable) {
        // Pedido fora da filial do usuário não é encontrado.
    }
    expect($order->fresh()->status)->toBe('paid');
});

test('alerta de conexão no sino não leva quem não é administrador a uma tela proibida', function () {
    $ctx = ifoodContext('sino-link');
    CompanyNotification::create([
        'company_id' => $ctx['company']->id, 'type' => 'ifood_alert',
        'title' => 'A conexão com o iFood caiu', 'link' => route('admin.settings.ifood'),
    ]);
    $settingsUrl = route('admin.settings.ifood');

    Livewire::actingAs(ifoodResilienceUser($ctx, 'caixa', $ctx['branch']->id))->test(NotificationBell::class)
        ->assertSee('A conexão com o iFood caiu')
        ->assertDontSee($settingsUrl);

    Livewire::actingAs(ifoodResilienceUser($ctx))->test(NotificationBell::class)
        ->assertSee($settingsUrl);

    expect(fn () => Livewire::actingAs(ifoodResilienceUser($ctx))->test(NotificationBell::class)->set('companyId', 999))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});
