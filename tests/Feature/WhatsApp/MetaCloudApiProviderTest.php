<?php

use App\Contracts\WhatsAppProviderInterface;
use App\DTOs\WhatsAppSender;
use App\Exceptions\WhatsAppAuthException;
use App\Exceptions\WhatsAppBillingException;
use App\Exceptions\WhatsAppPermanentException;
use App\Exceptions\WhatsAppRetryableException;
use App\Services\Messaging\MetaGraphClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;

beforeEach(function () {
    Http::preventStrayRequests();

    config([
        'services.meta.app_id' => '111222333',
        'services.meta.app_secret' => 'app-secret-teste',
        'services.meta.graph_version' => 'v25.0',
    ]);
});

function whatsappProvider(): WhatsAppProviderInterface
{
    return app(WhatsAppProviderInterface::class);
}

function whatsappTestSender(): WhatsAppSender
{
    return new WhatsAppSender('105551234', 'token-restaurante');
}

test('monta o payload de template e retorna o wamid', function () {
    Http::fake(['graph.facebook.com/*' => whatsappSendSuccess('wamid.ABC123')]);

    $wamid = whatsappProvider()->sendTemplate(
        whatsappTestSender(),
        '5511999990001',
        'pedido_recebido',
        ['Maria', '1042', 'R$ 45,90'],
    );

    expect($wamid)->toBe('wamid.ABC123');

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) {
        return $request->method() === 'POST'
            && str_starts_with($request->url(), 'https://graph.facebook.com/v25.0/105551234/messages')
            && $request->hasHeader('Authorization', 'Bearer token-restaurante')
            && $request->data() === [
                'messaging_product' => 'whatsapp',
                'to' => '5511999990001',
                'type' => 'template',
                'template' => [
                    'name' => 'pedido_recebido',
                    'language' => ['code' => 'pt_BR'],
                    'components' => [[
                        'type' => 'body',
                        'parameters' => [
                            ['type' => 'text', 'text' => 'Maria'],
                            ['type' => 'text', 'text' => '1042'],
                            ['type' => 'text', 'text' => 'R$ 45,90'],
                        ],
                    ]],
                ],
            ];
    });
});

test('usa o idioma informado', function () {
    Http::fake(['graph.facebook.com/*' => whatsappSendSuccess()]);

    whatsappProvider()->sendTemplate(whatsappTestSender(), '5511999990001', 'pedido_em_preparo', ['1'], 'en_US');

    Http::assertSent(fn (Request $request) => $request->data()['template']['language'] === ['code' => 'en_US']);
});

test('envia appsecret_proof calculado com o token do restaurante', function () {
    Http::fake(['graph.facebook.com/*' => whatsappSendSuccess()]);

    whatsappProvider()->sendTemplate(whatsappTestSender(), '5511999990001', 'pedido_em_preparo', ['1']);

    $proof = hash_hmac('sha256', 'token-restaurante', 'app-secret-teste');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'appsecret_proof='.$proof));
});

test('não envia appsecret_proof sem app secret configurado', function () {
    config(['services.meta.app_secret' => null]);
    Http::fake(['graph.facebook.com/*' => whatsappSendSuccess()]);

    whatsappProvider()->sendTemplate(whatsappTestSender(), '5511999990001', 'pedido_em_preparo', ['1']);

    Http::assertSent(fn (Request $request) => ! str_contains($request->url(), 'appsecret_proof'));
});

test('appsecret_proof em GET vai na query junto com os parâmetros', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['data' => []])]);

    app(MetaGraphClient::class)->request('get', '999/phone_numbers', ['fields' => 'display_phone_number'], 'token-restaurante');

    $proof = hash_hmac('sha256', 'token-restaurante', 'app-secret-teste');

    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && str_contains($request->url(), 'fields=display_phone_number')
        && str_contains($request->url(), 'appsecret_proof='.$proof));
});

test('a versão da Graph API vem do config', function () {
    config(['services.meta.graph_version' => 'v99.0']);
    Http::fake(['graph.facebook.com/*' => whatsappSendSuccess()]);

    whatsappProvider()->sendTemplate(whatsappTestSender(), '5511999990001', 'pedido_em_preparo', ['1']);

    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://graph.facebook.com/v99.0/105551234/messages'));
});

test('sanitiza parâmetros que a Meta rejeita', function () {
    Http::fake(['graph.facebook.com/*' => whatsappSendSuccess()]);

    whatsappProvider()->sendTemplate(
        whatsappTestSender(),
        '5511999990001',
        'pedido_recebido',
        ["Maria\nSilva\t  Souza", '', 'R$ 10,00'],
    );

    Http::assertSent(function (Request $request) {
        $texts = array_column($request->data()['template']['components'][0]['parameters'], 'text');

        return $texts === ['Maria Silva Souza', '-', 'R$ 10,00'];
    });
});

test('resposta 2xx sem wamid é falha permanente (não retenta para não duplicar)', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => []], 200)]);

    whatsappProvider()->sendTemplate(whatsappTestSender(), '5511999990001', 'pedido_em_preparo', ['1']);
})->throws(WhatsAppPermanentException::class);

dataset('graph error mapping', [
    'HTTP 500 sem código' => [500, 0, WhatsAppRetryableException::class],
    'HTTP 503' => [503, 0, WhatsAppRetryableException::class],
    'HTTP 429' => [429, 0, WhatsAppRetryableException::class],
    '130429 throughput' => [400, 130429, WhatsAppRetryableException::class],
    '131000 erro genérico' => [400, 131000, WhatsAppRetryableException::class],
    '80007 rate limit da WABA' => [400, 80007, WhatsAppRetryableException::class],
    '4 rate limit do app' => [400, 4, WhatsAppRetryableException::class],
    '190 token inválido' => [401, 190, WhatsAppAuthException::class],
    '190 com HTTP 400' => [400, 190, WhatsAppAuthException::class],
    '200 permissão' => [403, 200, WhatsAppAuthException::class],
    '10 permissão negada' => [403, 10, WhatsAppAuthException::class],
    'HTTP 401 sem código' => [401, 0, WhatsAppAuthException::class],
    '131042 pagamento' => [400, 131042, WhatsAppBillingException::class],
    '131026 não entregável' => [400, 131026, WhatsAppPermanentException::class],
    '131047 fora da janela' => [400, 131047, WhatsAppPermanentException::class],
    '132000 parâmetros' => [400, 132000, WhatsAppPermanentException::class],
    '132001 template inexistente' => [400, 132001, WhatsAppPermanentException::class],
    '132012 formato do parâmetro' => [400, 132012, WhatsAppPermanentException::class],
    '100 parâmetro inválido' => [400, 100, WhatsAppPermanentException::class],
]);

test('mapeia erros da Graph API para a exceção certa', function (int $status, int $code, string $exception) {
    Http::fake(['graph.facebook.com/*' => whatsappGraphError($code, $status, 'Mensagem da Meta')]);

    try {
        whatsappProvider()->sendTemplate(whatsappTestSender(), '5511999990001', 'pedido_em_preparo', ['1']);
        $this->fail('Deveria lançar exceção');
    } catch (Throwable $e) {
        expect($e)->toBeInstanceOf($exception)
            ->and($e->getCode())->toBe($code)
            ->and($e->getMessage())->toContain('Mensagem da Meta')
            ->and($e->httpStatus)->toBe($status);
    }
})->with('graph error mapping');

test('inclui error_data.details na mensagem da exceção', function () {
    Http::fake(['graph.facebook.com/*' => Http::response([
        'error' => [
            'message' => '(#132000) Number of parameters does not match',
            'code' => 132000,
            'error_data' => ['messaging_product' => 'whatsapp', 'details' => 'body: number of localizable_params (1) does not match'],
        ],
    ], 400)]);

    whatsappProvider()->sendTemplate(whatsappTestSender(), '5511999990001', 'pedido_em_preparo', ['1']);
})->throws(WhatsAppPermanentException::class, 'localizable_params');

test('falha de conexão vira exceção retentável', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

    whatsappProvider()->sendTemplate(whatsappTestSender(), '5511999990001', 'pedido_em_preparo', ['1']);
})->throws(WhatsAppRetryableException::class);

test('mensagem de conexão não vaza segredos da query string nem encadeia a exceção original', function () {
    config(['logging.channels.whatsapp' => ['driver' => 'monolog', 'handler' => TestHandler::class]]);
    Log::forgetChannel('whatsapp');

    $leaky = 'cURL error 6: Could not resolve host for https://graph.facebook.com/v25.0/oauth/access_token'
        .'?client_id=111222333&client_secret=SEGREDO-DO-APP&code=CODE-DE-USO-UNICO&appsecret_proof=deadbeef01';

    Http::fake(fn () => throw new ConnectionException($leaky));

    try {
        app(MetaGraphClient::class)->request('get', 'oauth/access_token', [
            'client_id' => '111222333',
            'client_secret' => 'SEGREDO-DO-APP',
            'code' => 'CODE-DE-USO-UNICO',
        ]);
        $this->fail('Deveria lançar exceção');
    } catch (WhatsAppRetryableException $e) {
        expect($e->getMessage())->not->toContain('SEGREDO-DO-APP')
            ->and($e->getMessage())->not->toContain('CODE-DE-USO-UNICO')
            ->and($e->getMessage())->not->toContain('deadbeef01')
            ->and($e->getPrevious())->toBeNull();
    }

    /** @var TestHandler $handler */
    $handler = Log::channel('whatsapp')->getLogger()->getHandlers()[0];
    $logged = json_encode($handler->getRecords());

    expect($handler->getRecords())->not->toBeEmpty()
        ->and($logged)->not->toContain('SEGREDO-DO-APP')
        ->and($logged)->not->toContain('CODE-DE-USO-UNICO')
        ->and($logged)->not->toContain('token-restaurante');
});

test('erros da Graph API não logam token nem app secret', function () {
    config(['logging.channels.whatsapp' => ['driver' => 'monolog', 'handler' => TestHandler::class]]);
    Log::forgetChannel('whatsapp');

    Http::fake(['graph.facebook.com/*' => whatsappGraphError(131026)]);

    try {
        whatsappProvider()->sendTemplate(whatsappTestSender(), '5511999990001', 'pedido_em_preparo', ['1']);
    } catch (WhatsAppPermanentException) {
    }

    /** @var TestHandler $handler */
    $handler = Log::channel('whatsapp')->getLogger()->getHandlers()[0];
    $logged = json_encode($handler->getRecords());

    expect($handler->getRecords())->not->toBeEmpty()
        ->and($logged)->not->toContain('token-restaurante')
        ->and($logged)->not->toContain('app-secret-teste')
        ->and($logged)->not->toContain('5511999990001');
});

test('WhatsAppSender esconde o token em dumps', function () {
    $dump = print_r(whatsappTestSender(), true);

    expect($dump)->not->toContain('token-restaurante');
});
