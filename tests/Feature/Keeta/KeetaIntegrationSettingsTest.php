<?php

use App\Livewire\Admin\Settings\KeetaIntegrationSettings;
use App\Models\KeetaIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function keetaAdmin(array $ctx): User
{
    $user = User::factory()->create();
    $ctx['company']->users()->attach($user->id, ['role' => 'company_admin']);

    return $user;
}

it('esconde a tela com a Keeta desligada e mostra o card ligada', function () {
    $ctx = keetaContext();
    $admin = keetaAdmin($ctx);

    config(['keeta.enabled' => false]);
    $this->actingAs($admin)->get(route('admin.settings.keeta'))->assertNotFound();

    config(['keeta.enabled' => true]);
    $this->actingAs($admin)->get(route('admin.integrations.index'))->assertOk()->assertSee('Keeta');

    config(['keeta.company_ids' => [999999]]);
    expect($ctx['company']->fresh()->canUseKeetaIntegration())->toBeFalse();
});

it('conecta: gera o link com state, recebe o retorno, escolhe a loja e faz o onboarding', function () {
    $ctx = keetaContext(integration: ['keeta_merchant_id' => null, 'status' => 'disconnected', 'catalog_published_at' => null]);
    $admin = keetaAdmin($ctx);
    fakeKeetaApi([], [
        '*/oauth/authorization/url*' => Http::response(['merchantAuthorizationUrl' => 'https://merchant.mykeeta.com/m/web/openapi/authorize?appId=1&redirectUri=&state=&scope=all/#/app-activation'], 200),
        '*/merchantInfo*' => Http::response([
            'userId' => 1, 'brandId' => 2, 'brandName' => 'Marca',
            'authorizedShops' => [['id' => 478268, 'name' => 'Loja Centro', 'address' => 'Rua A', 'longitude' => 0, 'latitude' => 0, 'timeZone' => 'America/Sao_Paulo']],
            'page' => ['pageNum' => 1, 'pageSize' => 50, 'totalPage' => 1, 'totalCount' => 1],
        ], 200),
        '*/v1/merchantOnboarding*' => Http::response(['keetaMerchantId' => 478268], 201),
    ]);

    $this->actingAs($admin);
    $component = Livewire::test(KeetaIntegrationSettings::class)->call('connect');

    $integration = KeetaIntegration::where('branch_id', $ctx['branch']->id)->first();
    $state = $integration->authorization_state;
    expect($integration->status)->toBe('pending_authorization')->and($state)->not->toBeEmpty();
    $component->assertRedirectContains('state='.$state);

    $this->get(route('admin.settings.keeta.callback', ['authId' => '41008', 'state' => 'state-errado']))->assertRedirect()->assertSessionHas('error');
    $this->get(route('admin.settings.keeta.callback', ['authId' => '41008', 'state' => $state]))->assertRedirect();

    expect($integration->fresh()->status)->toBe('pending_mapping')
        ->and($integration->fresh()->available_shops[0]['id'])->toBe('478268');

    Livewire::test(KeetaIntegrationSettings::class)
        ->assertSet('connectionState', 'pending_mapping')
        ->set('selectedShopId', '478268')
        ->call('linkShop')
        ->assertSet('connectionState', 'connected');

    $integration = $integration->fresh();
    expect($integration->status)->toBe('active')
        ->and($integration->keeta_merchant_id)->toBe('478268')
        ->and($integration->menu_api_key)->not->toBeEmpty();

    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/v1/merchantOnboarding?merchantId='.$integration->merchant_ref)
        && $r['keetaMerchantId'] === 478268
        && $r['ordersWebhookURL'] === route('webhook.keeta.orders')
        && $r['getMerchantURL']['baseURL'] === $integration->menuUrl()
        && $r['getMerchantURL']['apiKey'] === $integration->menu_api_key);
});

it('loja fora da autorização não pode ser escolhida', function () {
    $ctx = keetaContext(integration: ['keeta_merchant_id' => null, 'status' => 'pending_mapping', 'available_shops' => [['id' => '1', 'name' => 'A', 'address' => null]]]);
    $this->actingAs(keetaAdmin($ctx));
    fakeKeetaApi();

    Livewire::test(KeetaIntegrationSettings::class)->set('selectedShopId', '2')->call('linkShop');

    expect($ctx['integration']->fresh()->keeta_merchant_id)->toBeNull();
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'merchantOnboarding'));
});

it('onboarding recusado deixa a integração em erro', function () {
    $ctx = keetaContext(integration: ['keeta_merchant_id' => null, 'status' => 'pending_mapping', 'available_shops' => [['id' => '7', 'name' => 'A', 'address' => null]]]);
    $this->actingAs(keetaAdmin($ctx));
    fakeKeetaApi([], ['*/v1/merchantOnboarding*' => Http::response(['title' => 'forbidden', 'status' => 403], 403)]);

    Livewire::test(KeetaIntegrationSettings::class)->set('selectedShopId', '7')->call('linkShop')->assertSet('connectionState', 'error');

    expect($ctx['integration']->fresh()->status)->toBe('error');
});

it('desconecta fechando a loja e trocando o merchant_ref', function () {
    $ctx = keetaContext();
    $this->actingAs(keetaAdmin($ctx));
    fakeKeetaApi();
    $oldRef = $ctx['integration']->merchant_ref;

    Livewire::test(KeetaIntegrationSettings::class)->call('disconnect')->assertSet('connectionState', 'not_connected');

    $integration = $ctx['integration']->fresh();
    expect($integration->keeta_merchant_id)->toBeNull()
        ->and($integration->merchant_ref)->not->toBe($oldRef)
        ->and($integration->status)->toBe('disconnected');
    Http::assertSent(fn (Request $r) => $r->body() === '{"merchantStatus":"UNAVAILABLE"}');
});

it('liga e desliga o aceite automático e publica o cardápio', function () {
    $ctx = keetaContext(integration: ['catalog_published_at' => null]);
    $this->actingAs(keetaAdmin($ctx));
    fakeKeetaApi();

    Livewire::test(KeetaIntegrationSettings::class)
        ->call('toggleAutoAccept')
        ->set('tab', 'cardapio')
        ->call('publishCatalog');

    expect($ctx['integration']->fresh()->auto_accept)->toBeTrue()
        ->and($ctx['integration']->fresh()->catalog_published_at)->not->toBeNull();
    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/v1/merchantUpdate/'));
});

it('quem não é administrador da empresa não opera a tela', function () {
    $ctx = keetaContext();
    $user = User::factory()->create();
    $ctx['company']->users()->attach($user->id, ['role' => 'branch_manager']);

    $this->actingAs($user)->get(route('admin.settings.keeta'))->assertForbidden();
});

it('renderiza as abas loja, cardápio e diagnóstico', function () {
    $ctx = keetaContext();
    $this->actingAs(keetaAdmin($ctx));
    fakeKeetaApi();

    Livewire::test(KeetaIntegrationSettings::class)
        ->assertSee('Loja na Keeta')
        ->assertSee('Aceite automático')
        ->set('tab', 'cardapio')
        ->assertSee('Cardápio na Keeta')
        ->assertSee('Vai para a Keeta')
        ->set('tab', 'diagnostico')
        ->assertSee($ctx['integration']->keeta_merchant_id)
        ->assertSee('Nenhum evento recebido');
});

it('mostra "Disponível na Keeta" no produto e "Fechar também na Keeta" na pausa', function () {
    $ctx = keetaContext();
    $admin = keetaAdmin($ctx);
    fakeKeetaApi();

    $this->actingAs($admin)->get(route('admin.products.edit', $ctx['product']))->assertOk()->assertSee('Disponível na Keeta');
    $this->actingAs($admin)->get(route('admin.branches.pauses', $ctx['branch']))->assertOk()->assertSee('Fechar também a loja na Keeta');
});

it('pausa marcada fecha a loja na Keeta e reabre ao remover', function () {
    $ctx = keetaContext();
    $this->actingAs(keetaAdmin($ctx));
    fakeKeetaApi();

    Livewire::test(\App\Livewire\Admin\Branches\Pauses::class, ['branch' => $ctx['branch']])
        ->set('reason', 'Reforma')
        ->set('starts_date', now()->subHour()->format('Y-m-d'))
        ->set('starts_time', now()->subHour()->format('H:i'))
        ->set('ends_date', now()->addDay()->format('Y-m-d'))
        ->set('ends_time', '23:00')
        ->set('close_in_keeta', true)
        ->call('create')
        ->assertHasNoErrors();

    $pause = \App\Models\BranchPause::first();
    expect($pause->keeta_sync)->toBeTrue()->and($pause->keeta_sync_state)->toBe('applied')
        ->and($ctx['integration']->fresh()->store_status)->toBe('UNAVAILABLE');

    Livewire::test(\App\Livewire\Admin\Branches\Pauses::class, ['branch' => $ctx['branch']])
        ->call('confirmDelete', $pause->id)
        ->call('delete');

    expect($ctx['integration']->fresh()->store_status)->toBe('AVAILABLE');
});
