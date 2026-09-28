<?php

use App\Models\WhatsAppConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();

    config([
        'services.meta.app_secret' => 'app-secret-teste',
        'services.whatsapp.fallback_to_platform' => false,
        'services.whatsapp.platform.phone_number_id' => '777000111',
        'services.whatsapp.platform.token' => 'token-plataforma',
    ]);
});

test('envia pedido_em_preparo pelo número da plataforma por padrão', function () {
    Http::fake(['graph.facebook.com/*' => whatsappSendSuccess('wamid.TESTE')]);

    $this->artisan('whatsapp:send-test', ['phone' => '(11) 99999-0001'])
        ->expectsOutputToContain('wamid.TESTE')
        ->assertSuccessful();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/777000111/messages')
        && $request->hasHeader('Authorization', 'Bearer token-plataforma')
        && $request->data()['to'] === '5511999990001'
        && $request->data()['template']['name'] === 'pedido_em_preparo'
        && $request->data()['template']['language'] === ['code' => 'pt_BR']
        && $request->data()['template']['components'][0]['parameters'] === [['type' => 'text', 'text' => '1042']]);
});

test('--company usa a conexão ativa da empresa (por id ou slug)', function () {
    $connection = WhatsAppConnection::factory()->create();
    $company = $connection->company;

    Http::fake(['graph.facebook.com/*' => whatsappSendSuccess('wamid.EMPRESA')]);

    $this->artisan('whatsapp:send-test', ['phone' => '11999990001', '--company' => (string) $company->id])
        ->expectsOutputToContain('wamid.EMPRESA')
        ->assertSuccessful();

    $this->artisan('whatsapp:send-test', ['phone' => '11999990001', '--company' => $company->slug])
        ->assertSuccessful();

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), "/{$connection->phone_number_id}/messages")
        && $request->hasHeader('Authorization', 'Bearer '.$connection->access_token));
});

test('--company sem conexão ativa nem fallback falha sem chamar a Meta', function () {
    $connection = WhatsAppConnection::factory()->status('error')->create();

    Http::fake();

    $this->artisan('whatsapp:send-test', ['phone' => '11999990001', '--company' => (string) $connection->company_id])
        ->expectsOutputToContain('não tem conexão ativa')
        ->assertFailed();

    Http::assertNothingSent();
});

test('--company inexistente falha', function () {
    Http::fake();

    $this->artisan('whatsapp:send-test', ['phone' => '11999990001', '--company' => 'nao-existe'])
        ->expectsOutputToContain('não encontrada')
        ->assertFailed();

    Http::assertNothingSent();
});

test('slug numérico no começo não é confundido com id', function () {
    $connection = WhatsAppConnection::factory()->create();

    Http::fake();

    // Sem o ctype_digit, o MySQL casaria '1-pizzaria' com o id 1.
    $this->artisan('whatsapp:send-test', ['phone' => '11999990001', '--company' => $connection->company_id.'-pizzaria'])
        ->expectsOutputToContain('não encontrada')
        ->assertFailed();

    Http::assertNothingSent();
});

test('telefone inválido falha sem chamar a Meta', function () {
    Http::fake();

    $this->artisan('whatsapp:send-test', ['phone' => '1133334444'])
        ->expectsOutputToContain('Telefone inválido')
        ->assertFailed();

    Http::assertNothingSent();
});

test('sem número da plataforma configurado, falha com mensagem clara', function () {
    config(['services.whatsapp.platform.token' => null]);

    Http::fake();

    $this->artisan('whatsapp:send-test', ['phone' => '11999990001'])
        ->expectsOutputToContain('Número da plataforma não configurado')
        ->assertFailed();

    Http::assertNothingSent();
});

test('erro da Meta é exibido com a classe e o código', function () {
    Http::fake(['graph.facebook.com/*' => whatsappGraphError(132001, 404, 'Template name does not exist')]);

    $this->artisan('whatsapp:send-test', ['phone' => '11999990001'])
        ->expectsOutputToContain('WhatsAppPermanentException, código 132001')
        ->assertFailed();
});
