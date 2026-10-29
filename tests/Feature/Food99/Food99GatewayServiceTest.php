<?php

use App\Contracts\Food99GatewayContract;
use App\Exceptions\Food99ApiException;
use App\Exceptions\Food99RateLimitedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('envia o auth_token da loja e o order_id de 19 dígitos como número', function () {
    $ctx = food99Context();
    Http::fake(['*/v1/order/order/confirm' => Http::response(food99Ok(), 200)]);

    app(Food99GatewayContract::class)->confirmOrder($ctx['integration'], '5764607523034934815');

    Http::assertSent(function (Request $request) {
        return str_ends_with($request->url(), '/v1/order/order/confirm')
            && $request['auth_token'] === 'token-99-A'
            && str_contains($request->body(), '"order_id":5764607523034934815');
    });
});

it('decodifica ids int64 da resposta sem virar float', function () {
    $ctx = food99Context();
    Http::fake(['*/v1/order/order/detail*' => Http::response(
        '{"errno":0,"errmsg":"ok","requestId":"r1","data":{"order_id":5764607523034934815,"shop_id":5763000000000012345,"status":100}}',
        200,
    )]);

    $order = app(Food99GatewayContract::class)->getOrderDetails($ctx['integration'], '5764607523034934815');

    // Cabe no int do PHP 64-bit; o DTO converte pra string na borda (nunca chega como número ao JS).
    expect($order['order_id'])->toBe(5764607523034934815)
        ->and((string) $order['shop_id'])->toBe('5763000000000012345')
        ->and($order['status'])->toBe(100)
        ->and($ctx['integration']->fresh()->last_request_id)->toBe('r1');
});

it('trata errno diferente de zero como falha mesmo com HTTP 200', function () {
    $ctx = food99Context();
    Http::fake(['*' => Http::response(food99Error(14001, 'order status invalid', 'req-x'), 200)]);

    try {
        app(Food99GatewayContract::class)->markOrderReady($ctx['integration'], '123');
        $this->fail('esperava Food99ApiException');
    } catch (Food99ApiException $e) {
        expect($e->errno)->toBe(14001)
            ->and($e->requestId)->toBe('req-x')
            ->and($e->userMessage())->toContain('order status invalid');
    }
});

it('lança limite de frequência nos errnos 10005 e 12021', function (int $errno) {
    $ctx = food99Context();
    Http::fake(['*' => Http::response(food99Error($errno), 200)]);

    expect(fn () => app(Food99GatewayContract::class)->markOrderReady($ctx['integration'], '123'))
        ->toThrow(Food99RateLimitedException::class);
})->with([10005, 12021]);

it('segura chamada acima do limite documentado antes de gastar a cota da 99', function () {
    $ctx = food99Context();
    Http::fake(['*' => Http::response(food99Ok(), 200)]);
    $gateway = app(Food99GatewayContract::class);

    $gateway->setStoreStatus($ctx['integration'], ['store_status' => 1]);

    expect(fn () => $gateway->setStoreStatus($ctx['integration'], ['store_status' => 3]))
        ->toThrow(Food99RateLimitedException::class);
    Http::assertSentCount(1);
});

it('renova o token e repete uma vez quando a 99 responde token expirado', function () {
    $ctx = food99Context();
    Http::fake([
        '*/v1/auth/authtoken/refresh*' => Http::response(food99Ok(true), 200),
        '*/v1/auth/authtoken/get*' => Http::response(food99Ok(['auth_token' => 'token-novo', 'token_expiration_time' => now()->addHours(4)->getTimestamp()]), 200),
        '*/v1/order/order/ready' => Http::sequence()
            ->push(food99Error(10102, 'token expired'), 200)
            ->push(food99Ok(), 200),
    ]);

    app(Food99GatewayContract::class)->markOrderReady($ctx['integration'], '123');

    $readyCalls = collect(Http::recorded())->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/v1/order/order/ready'))->values();
    expect($readyCalls)->toHaveCount(2)
        ->and($readyCalls[0][0]['auth_token'])->toBe('token-99-A')
        ->and($readyCalls[1][0]['auth_token'])->toBe('token-novo')
        ->and($ctx['integration']->fresh()->auth_token)->toBe('token-novo');
});

it('trata resposta sem envelope como falha de transporte', function () {
    $ctx = food99Context();
    Http::fake(['*' => Http::response('<html>bad gateway</html>', 502)]);

    try {
        app(Food99GatewayContract::class)->getShopDetail($ctx['integration']);
        $this->fail('esperava Food99ApiException');
    } catch (Food99ApiException $e) {
        expect($e->errno)->toBe(Food99ApiException::TRANSPORT_ERRNO)
            ->and($e->userMessage())->toContain('instável');
    }
});

it('manda items do cancelamento parcial como string JSON', function () {
    $ctx = food99Context();
    Http::fake(['*' => Http::response(food99Ok(), 200)]);

    app(Food99GatewayContract::class)->partialCancel($ctx['integration'], '42', [
        ['app_item_id' => 'veddi-p1', 'mdu_id' => '777', 'cancel_amount' => 1],
    ], ['veddi-p1'], 1);

    Http::assertSent(fn (Request $request) => is_string($request['items'])
        && json_decode($request['items'], true)[0]['mdu_id'] === '777'
        && $request['is_sold_out'] === 1
        && $request['app_item_ids'] === ['veddi-p1']);
});

it('assina as chamadas de app com app_id, timestamp e sign', function () {
    food99Config();
    Http::fake(['*' => Http::response(food99Ok(['list' => []]), 200)]);

    app(Food99GatewayContract::class)->getAuthorizedShops();

    Http::assertSent(function (Request $request) {
        $data = $request->data();
        $expected = app(\App\Services\Food99\Food99Signer::class)->appSign($data);

        return str_ends_with($request->url(), '/v3/auth/authorization/getAuthorizedShops')
            && str_contains($request->body(), '"app_id":3458764573578035295')
            && $data['sign'] === $expected
            && ! array_key_exists('app_secret', $data);
    });
});
