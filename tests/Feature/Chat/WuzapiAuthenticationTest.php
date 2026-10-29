<?php

use App\Contracts\ChatCodeSender;
use App\Livewire\Chat\OrderChat;
use App\Models\Company;
use App\Services\Chat\PhoneVerification;
use App\Services\Chat\UnavailableCodeSender;
use App\Services\Chat\WhatsAppCodeSender;
use App\Services\Chat\WuzapiCodeSender;
use App\Services\Messaging\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'chat_security.code_channel' => 'whatsapp',
        'chat_security.whatsapp_auth_enabled' => true,
        'chat_security.whatsapp_meta_enabled' => false,
        'chat_security.whatsapp_wuzapi_fallback_enabled' => true,
        'services.wuzapi.base_url' => 'https://wuzapi.test',
        'services.wuzapi.session_token' => 'dedicated-veddi-session-token',
        'services.whatsapp.fallback_to_platform' => false,
    ]);
    Http::preventStrayRequests();
});

function wuzapiAuthCompany(): Company
{
    return Company::create([
        'name' => 'Loja da Veddi', 'slug' => 'veddi-auth-'.uniqid(),
        'order_prefix' => 'WUZ', 'active' => true, 'status' => 'ACTIVE',
    ]);
}

function fakeChatWuzapi(array $status = ['connected' => true, 'loggedIn' => true]): void
{
    Http::fake([
        'wuzapi.test/session/status' => Http::response(['success' => true, 'data' => $status]),
        'wuzapi.test/chat/send/text' => Http::response(['success' => true, 'data' => ['Id' => 'WU.TEST']]),
    ]);
}

function wuzapiAuthRequests(): \Illuminate\Support\Collection
{
    return Http::recorded()->map(fn ($pair) => $pair[0])
        ->filter(fn ($request) => parse_url($request->url(), PHP_URL_HOST) === 'wuzapi.test')->values();
}

test('fallback habilita perfil salvo sem Meta e envia apenas código pelo protocolo WuzAPI', function (string $phone) {
    $company = wuzapiAuthCompany();
    fakeChatWuzapi();
    $logs = whatsappCaptureLogs();

    expect(app(ChatCodeSender::class))->toBeInstanceOf(WhatsAppCodeSender::class)
        ->and((new OrderChat)->savedProfileAvailable())->toBeTrue();
    app(ChatCodeSender::class)->send($company->id, $phone, '076259', 'challenge-1');
    Http::assertSentCount(2);
    $request = wuzapiAuthRequests()->last();
    expect($request->method())->toBe('POST')
        ->and($request->url())->toBe('https://wuzapi.test/chat/send/text')
        ->and($request->header('token'))->toBe(['dedicated-veddi-session-token'])
        ->and($request->hasHeader('Authorization'))->toBeFalse()
        ->and($request['Phone'])->toBe('55'.$phone)
        ->and($request['Body'])->toContain('076259', 'Loja da Veddi', '5 minutos')
        ->and($request['Id'])->toMatch('/^[A-F0-9]{32}$/')
        ->and($request['Id'])->not->toContain($phone, '076259')
        ->and(json_encode($logs->getRecords()))->not->toContain($phone, '076259', 'dedicated-veddi-session-token');
})->with(['11977770001', '1133334444']);

test('desabilitar autenticação bloqueia fallback mesmo com sessão configurada', function () {
    config(['chat_security.whatsapp_auth_enabled' => false]);
    expect(app(ChatCodeSender::class))->toBeInstanceOf(UnavailableCodeSender::class)
        ->and((new OrderChat)->savedProfileAvailable())->toBeFalse();
    expect(fn () => app(WhatsAppCodeSender::class)->send(1, '11977770001', '076259'))->toThrow(RuntimeException::class);
    Http::assertNothingSent();
});

test('fallback indisponível não libera perfil nem faz requisições', function (string $key, mixed $value) {
    config([$key => $value]);
    expect(WuzapiCodeSender::isConfigured())->toBeFalse()
        ->and(app(ChatCodeSender::class))->toBeInstanceOf(UnavailableCodeSender::class)
        ->and((new OrderChat)->savedProfileAvailable())->toBeFalse();
    Http::assertNothingSent();
})->with([
    ['chat_security.whatsapp_wuzapi_fallback_enabled', false],
    ['services.wuzapi.session_token', ''],
    ['services.wuzapi.base_url', ''],
    ['services.wuzapi.base_url', 'http://wuzapi.test'],
    ['services.wuzapi.base_url', 'https://secret@wuzapi.test'],
    ['services.wuzapi.base_url', 'https://wuzapi.test?token=secret'],
    ['services.wuzapi.base_url', 'https://wuzapi.test#secret'],
]);

test('telefone ou código inválido não aciona nenhum provedor', function (string $phone, string $code) {
    $company = wuzapiAuthCompany();
    expect(fn () => app(ChatCodeSender::class)->send($company->id, $phone, $code))->toThrow(RuntimeException::class);
    Http::assertNothingSent();
})->with([['5511977770001', '076259'], ['11977770001', '76259'], ['1197777000x', '076259'], ['11977770001', 'abcdef']]);

test('API oficial pronta tem prioridade sobre WuzAPI', function () {
    config(['chat_security.whatsapp_meta_enabled' => true]);
    $company = whatsappOrderContext()['company'];
    whatsappFakeMeta(['GET message_templates' => ['data' => [[
        'name' => config('chat_security.whatsapp_auth_template'), 'language' => 'pt_BR',
        'status' => 'APPROVED', 'category' => 'AUTHENTICATION',
    ]]]]);
    app(ChatCodeSender::class)->send($company->id, '11977770001', '076259');
    expect(whatsappMetaRequests('POST messages'))->toHaveCount(1)
        ->and(wuzapiAuthRequests())->toHaveCount(0);
});

test('empresa sem remetente oficial usa a sessão dedicada de fallback', function () {
    config(['chat_security.whatsapp_meta_enabled' => true]);
    $company = wuzapiAuthCompany();
    fakeChatWuzapi();
    app(ChatCodeSender::class)->send($company->id, '11977770001', '076259');
    expect(wuzapiAuthRequests())->toHaveCount(2);
});

test('WABA ausente usa fallback sem consultar Meta', function () {
    config([
        'chat_security.whatsapp_meta_enabled' => true,
        'services.whatsapp.fallback_to_platform' => true,
        'services.whatsapp.platform.phone_number_id' => 'platform-phone',
        'services.whatsapp.platform.token' => 'platform-token',
        'services.whatsapp.platform.waba_id' => '',
    ]);
    $company = wuzapiAuthCompany();
    fakeChatWuzapi();
    app(ChatCodeSender::class)->send($company->id, '11977770001', '076259');
    expect(wuzapiAuthRequests())->toHaveCount(2);
});

test('template oficial ausente pendente ou de categoria errada seleciona fallback antes de enviar', function (array $templates) {
    config(['chat_security.whatsapp_meta_enabled' => true]);
    $company = whatsappOrderContext()['company'];
    Http::fake([
        'graph.facebook.com/*/message_templates*' => Http::response(['data' => $templates]),
        'wuzapi.test/session/status' => Http::response(['success' => true, 'data' => ['connected' => true, 'loggedIn' => true]]),
        'wuzapi.test/chat/send/text' => Http::response(['success' => true, 'data' => ['Id' => 'WU.TEST']]),
    ]);
    app(ChatCodeSender::class)->send($company->id, '11977770001', '076259');
    expect(whatsappMetaRequests('POST messages'))->toHaveCount(0)
        ->and(wuzapiAuthRequests())->toHaveCount(2);
})->with([
    [[]],
    [[['name' => 'veddi_confirmar_telefone', 'language' => 'pt_BR', 'status' => 'PENDING', 'category' => 'AUTHENTICATION']]],
    [[['name' => 'veddi_confirmar_telefone', 'language' => 'pt_BR', 'status' => 'APPROVED', 'category' => 'UTILITY']]],
]);

test('falha ou timeout da consulta Meta não é tratado como configuração pendente', function (int $status) {
    config(['chat_security.whatsapp_meta_enabled' => true]);
    $company = whatsappOrderContext()['company'];
    whatsappFakeMeta(['GET message_templates' => $status === 0
        ? fn () => throw new ConnectionException('Timeout')
        : Http::response(['error' => ['code' => 1, 'message' => 'Indisponível']], $status)]);
    expect(fn () => app(ChatCodeSender::class)->send($company->id, '11977770001', '076259'))->toThrow(RuntimeException::class);
    expect(wuzapiAuthRequests())->toHaveCount(0)
        ->and(whatsappMetaRequests('POST messages'))->toHaveCount(0);
})->with([0, 401, 500]);

test('falha timeout ou resposta ambígua no envio Meta nunca reenvia pelo fallback', function (string $scenario) {
    config(['chat_security.whatsapp_meta_enabled' => true]);
    $company = whatsappOrderContext()['company'];
    whatsappFakeMeta([
        'GET message_templates' => ['data' => [[
            'name' => config('chat_security.whatsapp_auth_template'), 'language' => 'pt_BR',
            'status' => 'APPROVED', 'category' => 'AUTHENTICATION',
        ]]],
        'POST messages' => match ($scenario) {
            'timeout' => fn () => throw new ConnectionException('Timeout'),
            'missing_id' => ['messages' => []],
            default => Http::response(['error' => ['code' => 132000, 'message' => 'Recusado']], 400),
        },
    ]);
    expect(fn () => app(ChatCodeSender::class)->send($company->id, '11977770001', '076259'))->toThrow(RuntimeException::class);
    expect(wuzapiAuthRequests())->toHaveCount(0);
})->with(['timeout', 'missing_id', 'rejected']);

test('sessão WuzAPI precisa estar conectada e autenticada', function (array $status) {
    $company = wuzapiAuthCompany();
    fakeChatWuzapi($status);
    expect(fn () => app(ChatCodeSender::class)->send($company->id, '11977770001', '076259'))
        ->toThrow(RuntimeException::class, 'Sessão de autenticação desconectada.');
    Http::assertSentCount(1);
})->with([[['connected' => false, 'loggedIn' => false]], [['connected' => true, 'loggedIn' => false]], [['connected' => false, 'loggedIn' => true]], [[]]]);

test('resposta WuzAPI falha inválida ou sem id não confirma envio nem vaza corpo', function (int $status, mixed $body) {
    $company = wuzapiAuthCompany();
    Http::fake([
        'wuzapi.test/session/status' => Http::response(['success' => true, 'data' => ['connected' => true, 'loggedIn' => true]]),
        'wuzapi.test/chat/send/text' => Http::response($body, $status),
    ]);
    $logs = whatsappCaptureLogs();
    try {
        app(ChatCodeSender::class)->send($company->id, '11977770001', '076259');
        $this->fail('Envio inválido não deve ser confirmado.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain('076259', '11977770001', 'dedicated-veddi-session-token')
            ->and($e->getPrevious())->toBeNull();
    }
    Http::assertSentCount(2);
    expect(json_encode($logs->getRecords()))->not->toContain('076259', '11977770001', 'dedicated-veddi-session-token');
})->with([
    [401, ['error' => '076259 11977770001 dedicated-veddi-session-token']],
    [429, ['error' => 'rate_limited']],
    [500, ['error' => '076259']],
    [200, ['success' => false, 'error' => '076259']],
    [200, '<html>076259</html>'],
    [200, ['success' => true, 'data' => []]],
]);

test('timeout WuzAPI não retenta envio e descarta exceção original sensível', function () {
    $company = wuzapiAuthCompany();
    $attempts = 0;
    Http::fake(function ($request) use (&$attempts) {
        if ($request->method() === 'GET') {
            return Http::response(['success' => true, 'data' => ['connected' => true, 'loggedIn' => true]]);
        }
        $attempts++;
        throw new ConnectionException('076259 11977770001 dedicated-veddi-session-token');
    });
    try {
        app(ChatCodeSender::class)->send($company->id, '11977770001', '076259');
        $this->fail('Timeout deve falhar.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('Falha de conexão com o serviço de autenticação.')
            ->and($e->getPrevious())->toBeNull();
    }
    expect($attempts)->toBe(1);
});

test('fluxo completo envia desafio com id estável preserva isolamento e consome uma vez', function () {
    $company = wuzapiAuthCompany();
    fakeChatWuzapi();
    $verification = app(PhoneVerification::class);
    $id = $verification->send($company->id, '11977770001');
    $request = wuzapiAuthRequests()->last();
    preg_match('/é (\d{6})\./u', $request['Body'], $matches);
    $code = $matches[1];
    expect($request['Id'])->toBe(strtoupper(substr(hash_hmac('sha256', $company->id.'|'.$id, config('app.key')), 0, 32)))
        ->and(DB::table('chat_phone_challenges')->find($id)->code_hash)->not->toBe($code);
    expect($verification->consume($id, $company->id + 1, '11977770001', $code))->toBeFalse()
        ->and($verification->consume($id, $company->id, '11977770002', $code))->toBeFalse();
    $nonce = session('chat_challenge_nonce');
    session()->forget('chat_challenge_nonce');
    expect($verification->consume($id, $company->id, '11977770001', $code))->toBeFalse();
    session(['chat_challenge_nonce' => $nonce]);
    expect($verification->consume($id, $company->id, '11977770001', $code))->toBeTrue()
        ->and($verification->consume($id, $company->id, '11977770001', $code))->toBeFalse();
});

test('solicitação repetida é limitada antes de enviar outro código', function () {
    $company = wuzapiAuthCompany();
    fakeChatWuzapi();
    $verification = app(PhoneVerification::class);
    $verification->send($company->id, '11977770001');
    expect(fn () => $verification->send($company->id, '11977770001'))->toThrow(RuntimeException::class, 'Aguarde antes de solicitar outro código.');
    Http::assertSentCount(2);
});

test('falha WuzAPI invalida o desafio e preserva erro genérico do chat', function () {
    $company = wuzapiAuthCompany();
    fakeChatWuzapi(['connected' => false]);
    expect(fn () => app(PhoneVerification::class)->send($company->id, '11977770001'))
        ->toThrow(RuntimeException::class, 'Não foi possível enviar o código. Você pode continuar preenchendo seus dados.');
    expect(DB::table('chat_phone_challenges')->sole()->consumed_at)->not->toBeNull();
});

test('WuzAPI não torna notificações de pedido disponíveis quando Meta está pendente', function () {
    $ctx = whatsappOrderContext(withConnection: false);
    expect(app(WhatsAppService::class)->prepareNotification($ctx['order'], 'preparing'))->toBeNull();
    Http::assertNothingSent();
});
