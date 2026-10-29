<?php

use App\Exceptions\Food99ShopAlreadyLinkedException;
use App\Models\Food99Integration;
use App\Services\Food99\Food99AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function food99TokenResponse(string $token, int $hours = 4): array
{
    return food99Ok(['auth_token' => $token, 'token_expiration_time' => now()->addHours($hours)->getTimestamp()]);
}

function food99DisconnectedIntegration(array $ctx): Food99Integration
{
    $ctx['integration']->forceFill([
        'shop_id' => null, 'shop_name' => null, 'auth_token' => null, 'token_expires_at' => null,
        'status' => 'disconnected', 'catalog_published_at' => null,
    ])->save();

    return $ctx['integration']->fresh();
}

it('usa o token gravado enquanto estiver fora da margem de renovação', function () {
    $ctx = food99Context();
    Http::fake();

    expect(app(Food99AuthService::class)->getToken($ctx['integration']))->toBe('token-99-A');
    Http::assertNothingSent();
});

it('busca token pelo app_shop_id quando a loja ainda não tem', function () {
    $ctx = food99Context();
    $ctx['integration']->forceFill(['auth_token' => null, 'token_expires_at' => null])->save();
    Http::fake(['*/v1/auth/authtoken/get*' => Http::response(food99TokenResponse('tok-1'), 200)]);

    expect(app(Food99AuthService::class)->getToken($ctx['integration']->fresh()))->toBe('tok-1');

    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/v1/auth/authtoken/get')
        && $r['app_shop_id'] === $ctx['integration']->app_shop_id
        && $r['app_id'] === FOOD99_TEST_APP_ID);
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'authtoken/refresh'));
    expect($ctx['integration']->fresh()->token_expires_at->isFuture())->toBeTrue();
});

it('pede refresh antes do get quando o token está vencendo', function () {
    $ctx = food99Context();
    $ctx['integration']->update(['token_expires_at' => now()->addMinute()]);
    Http::fake([
        '*/v1/auth/authtoken/refresh*' => Http::response(food99Ok(true), 200),
        '*/v1/auth/authtoken/get*' => Http::response(food99TokenResponse('tok-renovado'), 200),
    ]);

    expect(app(Food99AuthService::class)->getToken($ctx['integration']->fresh()))->toBe('tok-renovado');

    $paths = collect(Http::recorded())->map(fn ($pair) => parse_url($pair[0]->url(), PHP_URL_PATH))->all();
    expect($paths)->toBe(['/v1/auth/authtoken/refresh', '/v1/auth/authtoken/get']);
});

it('respeita o cooldown de 2 minutos entre refreshes', function () {
    $ctx = food99Context();
    Http::fake([
        '*/v1/auth/authtoken/refresh*' => Http::response(food99Ok(true), 200),
        '*/v1/auth/authtoken/get*' => Http::response(food99TokenResponse('tok-x'), 200),
    ]);
    $auth = app(Food99AuthService::class);

    $auth->renewExpiredToken($ctx['integration'], 'token-99-A');
    $this->travel(40)->seconds();
    $ctx['integration']->forceFill(['auth_token' => 'tok-velho'])->save();
    $auth->renewExpiredToken($ctx['integration']->fresh(), 'tok-velho');

    $refreshes = collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'authtoken/refresh'));
    expect($refreshes)->toHaveCount(1);
});

it('liga a loja informada pelo restaurante e aplica a configuração', function () {
    $ctx = food99Context();
    $integration = food99DisconnectedIntegration($ctx);
    Http::fake([
        '*/v3/auth/authorization/getAuthorizedShops' => Http::response(food99Ok(['total' => 1, 'list' => [
            ['shop_id' => 5763000000000099001, 'shop_name' => 'Coxinha da Vila', 'bound_flag' => 1, 'app_shop_id' => 'uuid-99-gerado'],
        ]]), 200),
        '*/v1/auth/authtoken/get*' => Http::response(food99TokenResponse('tok-loja'), 200),
        '*/v1/shop/shop/setconfirmmethod' => Http::response(food99Ok(), 200),
        '*/v1/shop/apply/set' => Http::response(food99Ok(), 200),
        '*/v1/shop/shop/detail*' => Http::response(food99Ok(['shop_name' => 'Coxinha da Vila', 'biz_status' => 1, 'sub_biz_status' => 1]), 200),
    ]);

    $warnings = app(Food99AuthService::class)->selectShop($integration, '5763000000000099001');

    $integration->refresh();
    expect($warnings)->toBe([])
        ->and($integration->status)->toBe('active')
        ->and($integration->shop_id)->toBe('5763000000000099001')
        ->and($integration->app_shop_id)->toBe('uuid-99-gerado')
        ->and($integration->auth_token)->toBe('tok-loja')
        ->and($integration->sub_biz_status)->toBe(1)
        ->and($integration->order_confirm_method)->toBe(2);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/setconfirmmethod') && $r['order_confirm_method'] === 2);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/apply/set') && $r['receive_cancel_apply'] === 1);
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'shopBind'));
});

it('vincula com o app_shop_id do VEDDI quando a loja autorizou mas não está vinculada', function () {
    $ctx = food99Context();
    $integration = food99DisconnectedIntegration($ctx);
    Http::fake([
        '*/v3/auth/authorization/getAuthorizedShops' => Http::response(food99Ok(['list' => [
            ['shop_id' => '5763000000000099002', 'shop_name' => 'Loja', 'bound_flag' => 0],
        ]]), 200),
        '*/v3/auth/authorization/shopBind' => Http::response(food99Ok(), 200),
        '*/v1/auth/authtoken/get*' => Http::response(food99TokenResponse('tok'), 200),
        '*' => Http::response(food99Ok([]), 200),
    ]);

    app(Food99AuthService::class)->selectShop($integration, '5763000000000099002');

    $expected = Food99Integration::appShopIdFor($ctx['company']->id, $ctx['branch']->id);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/shopBind')
        && $r['shop_infos'][0]['app_shop_id'] === $expected
        && $r['shop_infos'][0]['shop_id'] === 5763000000000099002);
    expect($integration->fresh()->app_shop_id)->toBe($expected);
});

it('recusa loja que não autorizou o app', function () {
    $ctx = food99Context();
    $integration = food99DisconnectedIntegration($ctx);
    Http::fake(['*' => Http::response(food99Ok(['list' => []]), 200)]);

    expect(fn () => app(Food99AuthService::class)->selectShop($integration, '5763000000000099003'))
        ->toThrow(RuntimeException::class, 'ainda não autorizou');
    expect($integration->fresh()->status)->toBe('disconnected');
});

it('impede ligar a loja que já está em outra empresa', function () {
    $a = food99Context('A');
    $b = food99Context('B');
    $integration = food99DisconnectedIntegration($b);
    Http::fake(['*' => Http::response(food99Ok(['list' => []]), 200)]);

    expect(fn () => app(Food99AuthService::class)->selectShop($integration, $a['integration']->shop_id))
        ->toThrow(Food99ShopAlreadyLinkedException::class);
});

it('nunca devolve lojas já ligadas e cai na lista de vinculadas sem acesso ao getAuthorizedShops', function () {
    $a = food99Context('A');
    Http::fake([
        '*/v3/auth/authorization/getAuthorizedShops' => Http::response(food99Error(10006, 'no permission'), 200),
        '*/v1/shop/shop/list' => Http::response(food99Ok(['list' => [
            ['shop_id' => $a['integration']->shop_id, 'shop_name' => 'Já ligada', 'app_shop_id' => $a['integration']->app_shop_id],
            ['shop_id' => '5763000000000099004', 'shop_name' => 'Livre', 'app_shop_id' => 'x-1'],
        ]]), 200),
    ]);

    $shops = app(Food99AuthService::class)->authorizedShops();

    expect(array_column($shops, 'shop_id'))->toBe(['5763000000000099004']);
});

it('guarda a URL de autorização e reaproveita enquanto válida', function () {
    $ctx = food99Context();
    $integration = food99DisconnectedIntegration($ctx);
    Http::fake(['*/v1/auth/authorizationpage/getUrl' => Http::response(food99Ok(['url' => 'https://99food.test/auth?x=1']), 200)]);
    $auth = app(Food99AuthService::class);

    expect($auth->authorizationUrl($integration))->toBe('https://99food.test/auth?x=1')
        ->and($auth->authorizationUrl($integration->fresh()))->toBe('https://99food.test/auth?x=1');

    Http::assertSentCount(1);
    expect($integration->fresh()->status)->toBe('pending_authorization')
        ->and($integration->fresh()->authorization_url_expires_at->greaterThan(now()->addDays(6)))->toBeTrue();
});

it('desconecta desfazendo o vínculo e limpando token e cardápio', function () {
    $ctx = food99Context();
    Http::fake(['*/v1/shop/shop/unbind' => Http::response(food99Ok(), 200)]);

    app(Food99AuthService::class)->disconnect($ctx['integration']);

    $integration = $ctx['integration']->fresh();
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/unbind') && $r['auth_token'] === 'token-99-A');
    expect($integration->status)->toBe('disconnected')
        ->and($integration->shop_id)->toBeNull()
        ->and($integration->auth_token)->toBeNull()
        ->and($integration->catalog_published_at)->toBeNull()
        ->and($integration->app_shop_id)->toBe(Food99Integration::appShopIdFor($ctx['company']->id, $ctx['branch']->id));
});

it('desconecta mesmo se o unbind falhar', function () {
    $ctx = food99Context();
    Http::fake(['*' => Http::response(food99Error(10101), 200)]);

    app(Food99AuthService::class)->disconnect($ctx['integration']);

    expect($ctx['integration']->fresh()->status)->toBe('disconnected');
});
