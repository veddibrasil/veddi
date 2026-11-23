<?php

use App\Contracts\KeetaGatewayContract;
use App\Exceptions\KeetaApiException;
use App\Exceptions\KeetaRateLimitedException;
use App\Services\Keeta\KeetaClient;
use App\Services\Keeta\KeetaSigner;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    keetaConfig();
    Cache::forget('keeta:app_token');
    Http::preventStrayRequests();
});

it('gera o link assinado sem token antes da primeira autorização', function () {
    Http::fake([
        '*/oauth/authorization/url*' => Http::response(['merchantAuthorizationUrl' => 'https://merchant.mykeeta.com/authorize'], 200),
    ]);

    expect(app(KeetaGatewayContract::class)->authorizationUrl('https://veddi.test/callback'))
        ->toBe('https://merchant.mykeeta.com/authorize');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => ! $request->hasHeader('Authorization')
        && $request->hasHeader('X-App-Signature', app(KeetaSigner::class)->sign(
            app(KeetaClient::class)->url('/oauth/authorization/url'),
            ['clientId' => KEETA_TEST_CLIENT_ID, 'redirectUri' => 'https://veddi.test/callback'],
            '',
        )));
});

it('guarda o token criptografado e reutiliza o cache', function () {
    Http::fake(['*/oauth/token' => Http::response(['access_token' => 'primeiro-token', 'expires_in' => 7200], 200)]);

    $client = app(KeetaClient::class);
    expect($client->accessToken())->toBe('primeiro-token')
        ->and($client->accessToken())->toBe('primeiro-token')
        ->and(Cache::get('keeta:app_token'))->not->toBe('primeiro-token')
        ->and(Crypt::decryptString(Cache::get('keeta:app_token')))->toBe('primeiro-token');
    Http::assertSentCount(1);
});

it('renova o token e repete a operação uma vez após 401', function () {
    Cache::put('keeta:app_token', Crypt::encryptString('antigo'), 3600);
    Http::fake([
        '*/oauth/token' => Http::response(['access_token' => 'novo', 'expires_in' => 7200], 200),
        '*/v1/orders/123' => Http::sequence()->push(['title' => 'expired'], 401)->push(['id' => '123'], 200),
    ]);

    expect(app(KeetaClient::class)->request('GET', '/v1/orders/123', 'detalhe'))->toBe(['id' => '123']);

    $requests = Http::recorded()->map(fn ($pair) => $pair[0])->values();
    expect($requests[0]->header('Authorization'))->toBe(['Bearer antigo'])
        ->and($requests[2]->header('Authorization'))->toBe(['Bearer novo']);
    Http::assertSentCount(3);
});

it('não entra em ciclo de renovação quando o novo token também é recusado', function () {
    Cache::put('keeta:app_token', Crypt::encryptString('antigo'), 3600);
    Http::fake([
        '*/oauth/token' => Http::response(['access_token' => 'novo', 'expires_in' => 7200], 200),
        '*/v1/orders/123' => Http::response(['title' => 'unauthorized'], 401),
    ]);

    expect(fn () => app(KeetaClient::class)->request('GET', '/v1/orders/123', 'detalhe'))->toThrow(KeetaApiException::class);
    Http::assertSentCount(3);
});

it('renova antecipadamente o token pela margem de expiração', function () {
    Http::fake(['*/oauth/token' => Http::sequence()
        ->push(['access_token' => 'primeiro', 'expires_in' => 600], 200)
        ->push(['access_token' => 'segundo', 'expires_in' => 600], 200)]);

    expect(app(KeetaClient::class)->accessToken())->toBe('primeiro');
    $this->travel(301)->seconds();
    expect(app(KeetaClient::class)->accessToken())->toBe('segundo');
    Http::assertSentCount(2);
});

it('respeita Retry-After sem repetir uma operação limitada', function () {
    Cache::put('keeta:app_token', Crypt::encryptString('token'), 3600);
    Http::fake(['*' => Http::response([], 429, ['Retry-After' => '42'])]);

    try {
        app(KeetaClient::class)->request('POST', '/v1/orders/123/confirm', 'confirmar pedido');
        test()->fail('A operação deveria ter sido limitada.');
    } catch (KeetaRateLimitedException $e) {
        expect($e->retryAfter)->toBe(42);
    }
    Http::assertSentCount(1);
});

it('rejeita resposta de token sem access_token', function () {
    Http::fake(['*' => Http::response(['expires_in' => 7200], 200)]);

    expect(fn () => app(KeetaClient::class)->accessToken())->toThrow(KeetaApiException::class)
        ->and(Cache::get('keeta:app_token'))->toBeNull();
});
