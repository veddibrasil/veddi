<?php

use App\Livewire\Admin\Integrations\Index as IntegrationsIndex;
use App\Livewire\Admin\Orders\Index as OrdersIndex;
use App\Livewire\Admin\Settings\Food99IntegrationSettings;
use App\Models\Company;
use App\Models\Food99Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function food99Admin(Company $company): User
{
    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    return $admin;
}

function food99FreshBranch(array $ctx): array
{
    $ctx['integration']->delete();

    return $ctx;
}

it('fica fora do ar com a 99Food desligada na plataforma', function () {
    $ctx = food99Context();
    config(['food99.enabled' => false]);

    $this->actingAs(food99Admin($ctx['company']))->get(route('admin.settings.99food'))->assertNotFound();
});

it('não abre para quem não é administrador da empresa', function () {
    $ctx = food99Context();
    $manager = User::factory()->create();
    $manager->companies()->attach($ctx['company']->id, ['role' => 'branch_manager', 'branch_id' => $ctx['branch']->id]);

    $this->actingAs($manager)->get(route('admin.settings.99food'))->assertForbidden();
});

it('conecta pela URL de autorização e pelo ID da loja', function () {
    $ctx = food99FreshBranch(food99Context());
    Http::fake([
        '*/v1/auth/authorizationpage/getUrl' => Http::response(food99Ok(['url' => 'https://99food.test/autorizar']), 200),
        '*/v3/auth/authorization/getAuthorizedShops' => Http::response(food99Ok(['list' => [
            ['shop_id' => '5763000000000077001', 'shop_name' => 'Coxinha 99', 'app_shop_id' => 'uuid-x', 'bound_flag' => 1],
        ]]), 200),
        '*/v1/auth/authtoken/get*' => Http::response(food99Ok(['auth_token' => 'tok', 'token_expiration_time' => now()->addHours(3)->getTimestamp()]), 200),
        '*' => Http::response(food99Ok(['shop_name' => 'Coxinha 99', 'sub_biz_status' => 1]), 200),
    ]);
    $this->actingAs(food99Admin($ctx['company']));

    Livewire::test(Food99IntegrationSettings::class)
        ->assertSet('connectionState', 'not_connected')
        ->call('connect')
        ->assertSet('connectionState', 'pending_authorization')
        ->assertSee('https://99food.test/autorizar')
        ->set('shopIdInput', '5763000000000077001')
        ->call('linkShop')
        ->assertHasNoErrors()
        ->assertSet('connectionState', 'connected')
        ->assertSee('Coxinha 99');

    $integration = Food99Integration::withoutGlobalScopes()->where('branch_id', $ctx['branch']->id)->sole();
    expect($integration->status)->toBe('active')
        ->and($integration->shop_id)->toBe('5763000000000077001')
        ->and($integration->app_shop_id)->toBe('uuid-x');
});

it('suspende fechando a loja na 99 e retoma revogando o fechamento', function () {
    $ctx = food99Context();
    Http::fake([
        // Retomar reenvia o cardápio publicado.
        '*/v3/item/item/upload' => Http::response(food99Ok(['taskID' => 5]), 200),
        '*' => Http::response(food99Ok(), 200),
    ]);
    $this->actingAs(food99Admin($ctx['company']));

    $component = Livewire::test(Food99IntegrationSettings::class)->call('suspend');
    expect($ctx['integration']->fresh()->status)->toBe('paused');

    $this->travel(61)->seconds();
    $component->call('resume');
    expect($ctx['integration']->fresh()->status)->toBe('active');

    $statuses = collect(Http::recorded())->filter(fn ($p) => str_ends_with($p[0]->url(), '/setStatus'))->map(fn ($p) => $p[0]['store_status'])->values()->all();
    expect($statuses)->toBe([2, 4]);
});

it('publica o cardápio pela aba Cardápio', function () {
    $ctx = food99Context(integration: ['catalog_published_at' => null]);
    Http::fake(['*/v3/item/item/upload' => Http::response(food99Ok(['taskID' => 77]), 200)]);
    $this->actingAs(food99Admin($ctx['company']));

    Livewire::test(Food99IntegrationSettings::class)
        ->set('tab', 'cardapio')
        ->assertSee('Publicar cardápio na 99Food')
        ->call('publishCatalog');

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v3/item/item/upload'));
    expect($ctx['integration']->fresh()->catalog_published_at)->not->toBeNull()
        ->and($ctx['integration']->fresh()->catalog_task_id)->toBe('77');
});

it('desconecta a loja', function () {
    $ctx = food99Context();
    Http::fake(['*' => Http::response(food99Ok(), 200)]);
    $this->actingAs(food99Admin($ctx['company']));

    Livewire::test(Food99IntegrationSettings::class)->call('confirmDisconnect')->call('disconnect')->assertSet('connectionState', 'not_connected');

    expect($ctx['integration']->fresh()->status)->toBe('disconnected');
});

it('mostra a integração só da empresa do usuário', function () {
    $a = food99Context('A');
    $b = food99Context('B');
    app()->instance('current.company', $b['company']);
    $this->actingAs(food99Admin($b['company']));

    Livewire::test(Food99IntegrationSettings::class)
        ->assertSet('branchId', $b['branch']->id)
        ->assertSee('Loja 99 B')
        ->assertDontSee('Loja 99 A');

    Livewire::test(Food99IntegrationSettings::class)
        ->set('branchId', $a['branch']->id)
        ->assertSet('branchId', $b['branch']->id);
});

it('mostra o card da 99Food nas integrações', function () {
    $ctx = food99Context();
    $this->actingAs(food99Admin($ctx['company']));

    Livewire::test(IntegrationsIndex::class)->assertSee('99Food')->assertSee('1 de 1 filial conectada');

    config(['food99.enabled' => false]);
    Livewire::test(IntegrationsIndex::class)->assertDontSee('99Food');
});

it('alerta no topo dos pedidos o pedido 99Food aguardando aceite', function () {
    $ctx = food99Context();
    receiveFood99Order($this, $ctx, food99OrderInfo($ctx));
    $this->actingAs(food99Admin($ctx['company']));

    Livewire::test(OrdersIndex::class)
        ->assertSee('1 pedido 99Food aguardando aceite')
        ->assertSee('99Food #12');
});
