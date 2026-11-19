<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('diagnostica o ambiente offline sem fazer chamadas à Keeta', function () {
    keetaConfig(['keeta.client_id' => null]);
    Http::preventStrayRequests();

    $this->artisan('keeta:homologation-check', ['--json' => true])
        ->expectsOutputToContain('Preencha KEETA_CLIENT_ID')
        ->assertFailed();
    Http::assertNothingSent();
});

it('identifica a primeira autorização pendente sem expor as credenciais', function () {
    keetaConfig();
    fakeKeetaApi([], [
        '*/oauth/authorization/url*' => Http::response(['merchantAuthorizationUrl' => 'https://merchant.mykeeta.com/authorize']),
        '*/oauth/token' => Http::response(['title' => 'This client_id has no authorized Merchant. Please contact Merchant authorize.'], 401),
    ]);

    $this->artisan('keeta:homologation-check', ['--live' => true, '--json' => true])
        ->expectsOutputToContain('HTTP 401: o app ainda não possui nenhuma loja autorizada.')
        ->assertFailed();
    Http::assertSentCount(2);
});

it('aprova os pré-requisitos de uma loja vinculada com cardápio e token válido', function () {
    keetaContext();
    config(['app.url' => 'https://veddi.test']);
    fakeKeetaApi([], ['*/oauth/authorization/url*' => Http::response(['merchantAuthorizationUrl' => 'https://merchant.mykeeta.com/authorize'])]);

    $this->artisan('keeta:homologation-check', ['--live' => true, '--json' => true])
        ->expectsOutputToContain('"ready": true')
        ->assertSuccessful();
});
