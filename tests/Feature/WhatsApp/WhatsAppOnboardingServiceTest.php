<?php

use App\Exceptions\WhatsAppOnboardingException;
use App\Exceptions\WhatsAppRetryableException;
use App\Jobs\CompleteWhatsAppOnboarding;
use App\Jobs\ProvisionWhatsAppTemplates;
use App\Models\Company;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppTemplate;
use App\Services\Messaging\WhatsAppOnboardingService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

const WABA = '1000000000001';
const PHONE = '2000000000001';

function onboardingCompany(string $name = 'Restaurante Novo'): Company
{
    return Company::create([
        'name' => $name,
        'slug' => 'novo-'.uniqid(),
        'order_prefix' => 'NOV',
        'active' => true,
        'status' => 'ACTIVE',
    ]);
}

function onboarding(): WhatsAppOnboardingService
{
    return app(WhatsAppOnboardingService::class);
}

/** Conexão pending como o start() deixa, pronta para o complete(). */
function pendingConnection(?Company $company = null, array $attributes = []): WhatsAppConnection
{
    return WhatsAppConnection::factory()->forCompany($company ?? onboardingCompany())->create(array_merge([
        'waba_id' => WABA,
        'phone_number_id' => PHONE,
        'access_token' => null,
        'token_scopes' => null,
        'registration_pin' => null,
        'status' => WhatsAppConnection::STATUS_PENDING,
        'onboarding_type' => WhatsAppConnection::TYPE_CLOUD_API,
        'quality_rating' => null,
        'messaging_limit_tier' => null,
        'verified_name' => null,
        'display_phone_number' => null,
        'connected_at' => null,
    ], $attributes));
}

beforeEach(function () {
    Queue::fake();
});

// ── start() ───────────────────────────────────────────────────────────────────

test('start cria a conexão pending sem token e despacha a conclusão com o code', function () {
    $company = onboardingCompany();

    $connection = onboarding()->start($company, 'CODE-ABC', WABA, PHONE, 'cloud_api');

    expect($connection->company_id)->toBe($company->id)
        ->and($connection->status)->toBe('pending')
        ->and($connection->onboarding_type)->toBe('cloud_api')
        ->and($connection->waba_id)->toBe(WABA)
        ->and($connection->phone_number_id)->toBe(PHONE)
        ->and($connection->fresh()->access_token)->toBeNull();

    Queue::assertPushed(CompleteWhatsAppOnboarding::class, 1);
    Queue::assertPushedOn('critical', CompleteWhatsAppOnboarding::class, fn (CompleteWhatsAppOnboarding $job) => $job->connectionId === $connection->id && $job->code === 'CODE-ABC');
});

test('start aceita coexistência sem waba nem número (o complete descobre)', function () {
    $connection = onboarding()->start(onboardingCompany(), 'CODE', null, null, 'coexistence');

    expect($connection->onboarding_type)->toBe('coexistence')
        ->and($connection->waba_id)->toBeNull()
        ->and($connection->phone_number_id)->toBeNull();
});

test('start reinicia uma conexão anterior: limpa token e templates e mantém o PIN', function () {
    $company = onboardingCompany();
    $old = WhatsAppConnection::factory()->forCompany($company)->create([
        'access_token' => 'token-antigo', 'registration_pin' => '123456', 'last_error' => 'erro antigo',
        'status' => WhatsAppConnection::STATUS_ERROR, 'waba_id' => WABA, 'phone_number_id' => PHONE,
    ]);
    WhatsAppTemplate::factory()->forEvent('preparing')->create(['whatsapp_connection_id' => $old->id]);

    $connection = onboarding()->start($company, 'CODE', WABA, PHONE, 'cloud_api');

    $connection->refresh();
    expect($connection->id)->toBe($old->id)
        ->and($connection->status)->toBe('pending')
        ->and($connection->access_token)->toBeNull()
        ->and($connection->last_error)->toBeNull()
        ->and($connection->registration_pin)->toBe('123456')
        ->and($connection->templates()->count())->toBe(0)
        ->and(WhatsAppConnection::withoutGlobalScopes()->where('company_id', $company->id)->count())->toBe(1);
});

test('start valida os dados de entrada', function (string $code, ?string $waba, ?string $phone, string $type) {
    expect(fn () => onboarding()->start(onboardingCompany(), $code, $waba, $phone, $type))
        ->toThrow(WhatsAppOnboardingException::class);

    Queue::assertNothingPushed();
})->with([
    'code vazio' => ['   ', WABA, PHONE, 'cloud_api'],
    'tipo inválido' => ['CODE', WABA, PHONE, 'tanto-faz'],
    'waba não numérica' => ['CODE', 'abc; DROP TABLE', PHONE, 'cloud_api'],
    'número não numérico' => ['CODE', WABA, '123abc', 'cloud_api'],
    'waba curta demais' => ['CODE', '12', PHONE, 'cloud_api'],
]);

test('start recusa WABA ou número que já estão com outra empresa e não mexe em nada', function (string $status) {
    $other = WhatsAppConnection::factory()->create(['waba_id' => WABA, 'phone_number_id' => PHONE, 'status' => $status]);
    $company = onboardingCompany();

    expect(fn () => onboarding()->start($company, 'CODE', WABA, '9990000000009', 'cloud_api'))
        ->toThrow(WhatsAppOnboardingException::class, 'já está conectado a outra empresa');

    expect(fn () => onboarding()->start($company, 'CODE', '9990000000009', PHONE, 'cloud_api'))
        ->toThrow(WhatsAppOnboardingException::class, 'já está conectado a outra empresa');

    expect(WhatsAppConnection::withoutGlobalScopes()->where('company_id', $company->id)->exists())->toBeFalse()
        ->and($other->fresh()->waba_id)->toBe(WABA);

    Queue::assertNothingPushed();
})->with(['active', 'pending', 'provisioning', 'templates_pending', 'error depois de ter conectado' => 'error']);

test('start libera o vínculo velho de outra empresa que já se desconectou', function () {
    $velha = WhatsAppConnection::factory()->create(['waba_id' => WABA, 'phone_number_id' => PHONE, 'status' => 'disconnected']);
    $company = onboardingCompany();

    $connection = onboarding()->start($company, 'CODE', WABA, PHONE, 'cloud_api');

    expect($connection->phone_number_id)->toBe(PHONE)
        ->and($velha->fresh()->waba_id)->toBeNull()
        ->and($velha->fresh()->phone_number_id)->toBeNull()
        ->and($velha->fresh()->status)->toBe('disconnected');
});

test('start libera os ids de outra empresa com erro que nunca conectou (ids não comprovados)', function () {
    $tentativa = WhatsAppConnection::factory()->create([
        'waba_id' => WABA, 'phone_number_id' => PHONE, 'status' => 'error', 'connected_at' => null, 'access_token' => null,
    ]);
    $company = onboardingCompany();

    $connection = onboarding()->start($company, 'CODE', WABA, PHONE, 'cloud_api');

    expect($connection->status)->toBe('pending')
        ->and($connection->phone_number_id)->toBe(PHONE)
        ->and($tentativa->fresh()->waba_id)->toBeNull()
        ->and($tentativa->fresh()->phone_number_id)->toBeNull()
        ->and($tentativa->fresh()->status)->toBe('error');
});

test('ids de outro restaurante enviados com um code inválido não ficam presos a quem tentou', function () {
    whatsappFakeMeta(['GET oauth/access_token' => whatsappGraphError(100, 400, 'Invalid verification code format.')]);
    $invasora = onboardingCompany('Invasora');
    $dona = onboardingCompany('Dona do número');

    $tentativa = onboarding()->start($invasora, 'CODE-LIXO', WABA, PHONE, 'cloud_api');
    onboarding()->complete($tentativa, 'CODE-LIXO');

    expect($tentativa->fresh()->status)->toBe('error')
        ->and($tentativa->fresh()->waba_id)->toBeNull()
        ->and($tentativa->fresh()->phone_number_id)->toBeNull();

    $connection = onboarding()->start($dona, 'CODE-DONA', WABA, PHONE, 'cloud_api');

    expect($connection->status)->toBe('pending')
        ->and($connection->waba_id)->toBe(WABA)
        ->and($connection->phone_number_id)->toBe(PHONE);
});

test('a mesma empresa pode reconectar o próprio número', function () {
    $company = onboardingCompany();
    WhatsAppConnection::factory()->forCompany($company)->create(['waba_id' => WABA, 'phone_number_id' => PHONE, 'status' => 'disconnected']);

    $connection = onboarding()->start($company, 'CODE', WABA, PHONE, 'cloud_api');

    expect($connection->status)->toBe('pending');
});

test('start não cria conexão em duplicidade para a mesma empresa', function () {
    $company = onboardingCompany();

    onboarding()->start($company, 'CODE-1', WABA, PHONE, 'cloud_api');
    onboarding()->start($company, 'CODE-2', WABA, PHONE, 'cloud_api');

    expect(WhatsAppConnection::withoutGlobalScopes()->where('company_id', $company->id)->count())->toBe(1);
});

// ── complete(): fluxo cloud_api ───────────────────────────────────────────────

test('cloud_api: troca o code, confere a WABA, inscreve o app, registra o número e vai para provisioning', function () {
    whatsappFakeMeta();
    $connection = pendingConnection();

    onboarding()->complete($connection, 'CODE-123');

    $connection->refresh();

    expect($connection->status)->toBe('provisioning')
        ->and($connection->last_error)->toBeNull()
        ->and($connection->access_token)->toBe('EAAB-token-do-restaurante')
        ->and($connection->waba_id)->toBe(WABA)
        ->and($connection->phone_number_id)->toBe(PHONE)
        ->and($connection->registration_pin)->toMatch('/^\d{6}$/')
        ->and($connection->verified_name)->toBe('Restaurante Teste')
        ->and($connection->display_phone_number)->toBe('+55 11 99999-0001')
        ->and($connection->quality_rating)->toBe('GREEN')
        ->and($connection->messaging_limit_tier)->toBe('TIER_250')
        ->and($connection->token_scopes)->toBe([
            ['scope' => 'whatsapp_business_management', 'target_ids' => [WABA]],
            ['scope' => 'whatsapp_business_messaging', 'target_ids' => [WABA]],
        ]);

    expect(whatsappMetaCalls())->toBe([
        'GET oauth/access_token',
        'GET debug_token',
        'GET phone_numbers',
        'POST subscribed_apps',
        'GET phone',
        'POST register',
        'GET phone',
    ]);

    Queue::assertPushedOn('whatsapp', ProvisionWhatsAppTemplates::class, fn (ProvisionWhatsAppTemplates $job) => $job->connectionId === $connection->id);
});

test('cloud_api com número que está no app WhatsApp Business vira coexistência e NÃO registra', function () {
    whatsappFakeMeta(['GET phone' => [
        'id' => PHONE, 'verified_name' => 'Restaurante Teste', 'display_phone_number' => '+55 11 99999-0001',
        'quality_rating' => 'GREEN', 'is_on_biz_app' => true,
    ]]);
    $connection = pendingConnection();

    onboarding()->complete($connection, 'CODE');

    $connection->refresh();

    expect($connection->onboarding_type)->toBe(WhatsAppConnection::TYPE_COEXISTENCE)
        ->and($connection->status)->toBe('provisioning')
        ->and(whatsappMetaCalls())->not->toContain('POST register');
});

test('cloud_api: o registro usa o PIN gravado e as chamadas com token levam Bearer e appsecret_proof', function () {
    whatsappFakeMeta();
    $connection = pendingConnection();

    onboarding()->complete($connection, 'CODE-123');

    $pin = $connection->fresh()->registration_pin;
    $proof = hash_hmac('sha256', 'EAAB-token-do-restaurante', 'app-secret-teste');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_contains($request->url(), '/'.PHONE.'/register')
        && $request->data() === ['messaging_product' => 'whatsapp', 'pin' => $pin]
        && $request->hasHeader('Authorization', 'Bearer EAAB-token-do-restaurante')
        && str_contains($request->url(), 'appsecret_proof='.$proof));

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/'.WABA.'/subscribed_apps')
        && $request->hasHeader('Authorization', 'Bearer EAAB-token-do-restaurante'));
});

test('o token fica criptografado no banco e nada sensível aparece nos logs', function () {
    whatsappFakeMeta();
    $handler = whatsappCaptureLogs();
    $connection = pendingConnection();

    onboarding()->complete($connection, 'CODE-SECRETO-XYZ');

    $raw = DB::table('whatsapp_connections')->where('id', $connection->id)->first();
    $logged = json_encode($handler->getRecords());

    expect($raw->access_token)->not->toContain('EAAB-token-do-restaurante')
        ->and($raw->registration_pin)->not->toBe($connection->fresh()->registration_pin)
        ->and($logged)->not->toContain('EAAB-token-do-restaurante')
        ->and($logged)->not->toContain('CODE-SECRETO-XYZ')
        ->and($logged)->not->toContain('app-secret-teste')
        ->and($logged)->not->toContain($connection->fresh()->registration_pin);
});

test('cloud_api sem número informado descobre o número na WABA e registra', function () {
    whatsappFakeMeta();
    $connection = pendingConnection(attributes: ['phone_number_id' => null]);

    onboarding()->complete($connection, 'CODE');

    expect($connection->fresh()->phone_number_id)->toBe(PHONE)
        ->and(whatsappMetaCalls())->toContain('GET phone_numbers')
        ->and(whatsappMetaCalls())->toContain('POST register');
});

test('sem WABA informada usa a única WABA dos escopos', function () {
    whatsappFakeMeta();
    $connection = pendingConnection(attributes: ['waba_id' => null]);

    onboarding()->complete($connection, 'CODE');

    expect($connection->fresh()->waba_id)->toBe(WABA)
        ->and($connection->fresh()->status)->toBe('provisioning');
});

// ── complete(): coexistência ──────────────────────────────────────────────────

test('coexistência sem phone_number_id descobre o número pela API e NÃO registra', function () {
    whatsappFakeMeta();
    $connection = pendingConnection(attributes: [
        'onboarding_type' => WhatsAppConnection::TYPE_COEXISTENCE,
        'waba_id' => null,
        'phone_number_id' => null,
    ]);

    onboarding()->complete($connection, 'CODE');

    $connection->refresh();

    expect($connection->status)->toBe('provisioning')
        ->and($connection->waba_id)->toBe(WABA)
        ->and($connection->phone_number_id)->toBe(PHONE)
        ->and($connection->registration_pin)->toBeNull()
        ->and($connection->verified_name)->toBe('Restaurante Teste')
        ->and(whatsappMetaCalls())->toBe([
            'GET oauth/access_token',
            'GET debug_token',
            'GET phone_numbers',
            'POST subscribed_apps',
            'GET phone',
        ]);

    Http::assertNotSent(fn (Request $request) => str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/register'));

    Queue::assertPushed(ProvisionWhatsAppTemplates::class);
});

test('coexistência com o número informado também não registra, só confirma o número', function () {
    whatsappFakeMeta();
    $connection = pendingConnection(attributes: ['onboarding_type' => WhatsAppConnection::TYPE_COEXISTENCE]);

    onboarding()->complete($connection, 'CODE');

    expect(whatsappMetaCalls())->not->toContain('POST register')
        ->and(whatsappMetaCalls())->toContain('GET phone_numbers')
        ->and(whatsappMetaCalls())->toContain('GET phone')
        ->and($connection->fresh()->status)->toBe('provisioning');
});

// ── complete(): falhas ────────────────────────────────────────────────────────

test('falha na troca do code: erro amigável, uma única tentativa, nada além disso', function (mixed $response) {
    whatsappFakeMeta(['GET oauth/access_token' => $response]);
    $connection = pendingConnection();

    onboarding()->complete($connection, 'CODE-VENCIDO');

    $connection->refresh();

    expect($connection->status)->toBe('error')
        ->and($connection->last_error)->toBe(WhatsAppOnboardingService::MSG_CODE_EXCHANGE)
        ->and($connection->access_token)->toBeNull()
        ->and(whatsappMetaCalls())->toBe(['GET oauth/access_token']);

    Queue::assertNotPushed(ProvisionWhatsAppTemplates::class);
})->with([
    'code inválido (400)' => fn () => whatsappGraphError(100, 400, 'Invalid verification code format.'),
    'code expirado (400)' => fn () => whatsappGraphError(36007, 400, 'Code expired'),
    'erro da Meta (500) — code de uso único não é retentado' => fn () => whatsappGraphError(1, 500, 'Internal error'),
    'sem token na resposta' => fn () => Http::response(['nada' => true], 200),
]);

test('falha de conexão na troca do code também não é retentada', function () {
    whatsappFakeMeta(['GET oauth/access_token' => fn () => throw new ConnectionException('cURL error 28: timeout')]);
    $connection = pendingConnection();

    onboarding()->complete($connection, 'CODE');

    expect($connection->fresh()->status)->toBe('error')
        ->and($connection->fresh()->last_error)->toBe(WhatsAppOnboardingService::MSG_CODE_EXCHANGE);
});

test('token inválido ou de outro app é recusado', function (array $data) {
    whatsappFakeMeta(['GET debug_token' => ['data' => $data]]);
    $connection = pendingConnection();

    onboarding()->complete($connection, 'CODE');

    expect($connection->fresh()->status)->toBe('error')
        ->and($connection->fresh()->last_error)->toBe(WhatsAppOnboardingService::MSG_TOKEN_INVALID)
        ->and(whatsappMetaCalls())->not->toContain('POST subscribed_apps');
})->with([
    'inválido' => [['is_valid' => false, 'app_id' => '111222333']],
    'de outro app' => [['is_valid' => true, 'app_id' => '999888777', 'granular_scopes' => [['scope' => 'whatsapp_business_management', 'target_ids' => [WABA]]]]],
]);

test('WABA informada que não está nos escopos do token é recusada', function () {
    whatsappFakeMeta();
    $connection = pendingConnection(attributes: ['waba_id' => '7770000000007']);

    onboarding()->complete($connection, 'CODE');

    expect($connection->fresh()->status)->toBe('error')
        ->and($connection->fresh()->last_error)->toBe(WhatsAppOnboardingService::MSG_WABA_MISMATCH)
        ->and($connection->fresh()->access_token)->toBeNull()
        ->and(whatsappMetaCalls())->not->toContain('POST subscribed_apps')
        ->and(whatsappMetaCalls())->not->toContain('POST register');
});

test('token sem nenhuma WABA e sem WABA informada dá erro claro', function () {
    whatsappFakeMeta(['GET debug_token' => ['data' => ['is_valid' => true, 'app_id' => '111222333', 'granular_scopes' => []]]]);
    $connection = pendingConnection(attributes: ['waba_id' => null]);

    onboarding()->complete($connection, 'CODE');

    expect($connection->fresh()->last_error)->toBe(WhatsAppOnboardingService::MSG_NO_WABA);
});

test('várias WABAs autorizadas sem WABA informada: não adivinha', function () {
    whatsappFakeMeta(['GET debug_token' => ['data' => [
        'is_valid' => true, 'app_id' => '111222333',
        'granular_scopes' => [['scope' => 'whatsapp_business_management', 'target_ids' => ['1111111111', '2222222222']]],
    ]]]);
    $connection = pendingConnection(attributes: ['waba_id' => null]);

    onboarding()->complete($connection, 'CODE');

    expect($connection->fresh()->last_error)->toBe(WhatsAppOnboardingService::MSG_MULTIPLE_WABA)
        ->and(whatsappMetaCalls())->not->toContain('POST subscribed_apps');
});

test('WABA sem número ou com vários números sem número informado: erro claro', function (array $numbers, string $message) {
    whatsappFakeMeta(['GET phone_numbers' => ['data' => $numbers]]);
    $connection = pendingConnection(attributes: ['phone_number_id' => null, 'onboarding_type' => WhatsAppConnection::TYPE_COEXISTENCE]);

    onboarding()->complete($connection, 'CODE');

    expect($connection->fresh()->status)->toBe('error')
        ->and($connection->fresh()->last_error)->toBe($message)
        ->and(whatsappMetaCalls())->not->toContain('POST subscribed_apps');
})->with([
    'nenhum número' => [[], WhatsAppOnboardingService::MSG_NO_NUMBER],
    'dois números' => [[['id' => '111111111'], ['id' => '222222222']], WhatsAppOnboardingService::MSG_MULTIPLE_NUMBERS],
]);

test('coexistência numa WABA com vários números conecta o único que está no app WhatsApp Business', function () {
    whatsappFakeMeta(['GET phone_numbers' => ['data' => [
        ['id' => '3000000000003', 'is_on_biz_app' => false, 'platform_type' => 'CLOUD_API'],
        ['id' => PHONE, 'is_on_biz_app' => true, 'platform_type' => 'CLOUD_API'],
    ]]]);
    $connection = pendingConnection(attributes: ['phone_number_id' => null, 'onboarding_type' => WhatsAppConnection::TYPE_COEXISTENCE]);

    onboarding()->complete($connection, 'CODE');

    expect($connection->fresh()->status)->toBe('provisioning')
        ->and($connection->fresh()->phone_number_id)->toBe(PHONE)
        ->and(whatsappMetaCalls())->not->toContain('POST register');
});

test('vários números sem um único candidato claro: não adivinha', function (array $numbers, string $type) {
    whatsappFakeMeta(['GET phone_numbers' => ['data' => $numbers]]);
    $connection = pendingConnection(attributes: ['phone_number_id' => null, 'onboarding_type' => $type]);

    onboarding()->complete($connection, 'CODE');

    expect($connection->fresh()->status)->toBe('error')
        ->and($connection->fresh()->last_error)->toBe(WhatsAppOnboardingService::MSG_MULTIPLE_NUMBERS)
        ->and(whatsappMetaCalls())->not->toContain('POST subscribed_apps');
})->with([
    'coexistência com dois números no app' => [[['id' => '111111111', 'is_on_biz_app' => true], ['id' => '222222222', 'is_on_biz_app' => true]], 'coexistence'],
    'coexistência sem número no app' => [[['id' => '111111111', 'is_on_biz_app' => false], ['id' => '222222222']], 'coexistence'],
    'cloud_api (o app não decide o número)' => [[['id' => '111111111', 'is_on_biz_app' => true], ['id' => '222222222', 'is_on_biz_app' => false]], 'cloud_api'],
]);

test('número informado que não pertence à WABA autorizada é recusado antes de qualquer efeito na Meta', function () {
    whatsappFakeMeta();
    // Ex.: id do número de outro restaurante enviado junto com um code válido da própria WABA.
    $connection = pendingConnection(attributes: ['phone_number_id' => '8880000000008']);

    onboarding()->complete($connection, 'CODE');

    $connection->refresh();

    expect($connection->status)->toBe('error')
        ->and($connection->last_error)->toBe(WhatsAppOnboardingService::MSG_NUMBER_MISMATCH)
        ->and($connection->access_token)->toBeNull()
        ->and($connection->waba_id)->toBeNull()
        ->and($connection->phone_number_id)->toBeNull()
        ->and(whatsappMetaCalls())->not->toContain('POST subscribed_apps')
        ->and(whatsappMetaCalls())->not->toContain('POST register');

    Queue::assertNotPushed(ProvisionWhatsAppTemplates::class);
});

test('token que expira gera aviso no log (só conexão e expires_at) sem impedir a conexão', function () {
    $expiresAt = now()->addDays(60)->timestamp;
    whatsappFakeMeta(['GET debug_token' => ['data' => [
        'app_id' => '111222333', 'is_valid' => true, 'expires_at' => $expiresAt,
        'granular_scopes' => [['scope' => 'whatsapp_business_management', 'target_ids' => [WABA]]],
    ]]]);
    $handler = whatsappCaptureLogs();
    $connection = pendingConnection();

    onboarding()->complete($connection, 'CODE');

    $warnings = collect($handler->getRecords())->filter(fn ($record) => str_contains($record->message, 'expira'));

    expect($connection->fresh()->status)->toBe('provisioning')
        ->and($warnings)->toHaveCount(1)
        ->and($warnings->first()->level)->toBe(Monolog\Level::Warning)
        ->and($warnings->first()->context)->toBe(['connection_id' => $connection->id, 'expires_at' => $expiresAt]);
});

test('token que não expira (expires_at 0) não gera aviso', function () {
    whatsappFakeMeta();
    $handler = whatsappCaptureLogs();

    onboarding()->complete(pendingConnection(), 'CODE');

    expect(collect($handler->getRecords())->filter(fn ($record) => str_contains($record->message, 'expira')))->toBeEmpty();
});

test('WABA já vinculada a outra empresa: erro amigável e nenhuma chamada com efeito na Meta', function () {
    WhatsAppConnection::factory()->create(['waba_id' => WABA, 'phone_number_id' => '9990000000009', 'status' => 'active']);
    whatsappFakeMeta();
    $connection = pendingConnection(attributes: ['phone_number_id' => PHONE]);

    onboarding()->complete($connection, 'CODE');

    $connection->refresh();

    expect($connection->status)->toBe('error')
        ->and($connection->last_error)->toBe(WhatsAppOnboardingService::MSG_ALREADY_LINKED)
        ->and($connection->access_token)->toBeNull()
        ->and(whatsappMetaCalls())->not->toContain('POST subscribed_apps')
        ->and(whatsappMetaCalls())->not->toContain('POST register');

    Queue::assertNotPushed(ProvisionWhatsAppTemplates::class);
});

test('número descoberto na WABA que já é de outra empresa: erro amigável', function () {
    WhatsAppConnection::factory()->create(['waba_id' => '5550000000005', 'phone_number_id' => PHONE, 'status' => 'active']);
    whatsappFakeMeta();
    $connection = pendingConnection(attributes: ['phone_number_id' => null, 'onboarding_type' => WhatsAppConnection::TYPE_COEXISTENCE]);

    onboarding()->complete($connection, 'CODE');

    expect($connection->fresh()->last_error)->toBe(WhatsAppOnboardingService::MSG_ALREADY_LINKED)
        ->and(whatsappMetaCalls())->not->toContain('POST subscribed_apps');
});

test('vínculo velho (desconectado) de outra empresa é liberado no complete', function () {
    $velha = WhatsAppConnection::factory()->create(['waba_id' => WABA, 'phone_number_id' => PHONE, 'status' => 'disconnected']);
    whatsappFakeMeta();
    $connection = pendingConnection(attributes: ['phone_number_id' => null, 'onboarding_type' => WhatsAppConnection::TYPE_COEXISTENCE]);

    onboarding()->complete($connection, 'CODE');

    expect($connection->fresh()->status)->toBe('provisioning')
        ->and($connection->fresh()->phone_number_id)->toBe(PHONE)
        ->and($velha->fresh()->phone_number_id)->toBeNull();
});

test('registro com 133016 (limite de registros) não é retentado e explica o motivo', function () {
    whatsappFakeMeta(['POST register' => whatsappGraphError(133016, 400, 'Account register limit')]);
    $connection = pendingConnection();

    onboarding()->complete($connection, 'CODE');

    expect($connection->fresh()->status)->toBe('error')
        ->and($connection->fresh()->last_error)->toBe(WhatsAppOnboardingService::MSG_REGISTER_LIMIT)
        ->and(collect(whatsappMetaCalls())->filter(fn ($call) => $call === 'POST register'))->toHaveCount(1);

    Queue::assertNotPushed(ProvisionWhatsAppTemplates::class);
});

test('registro com PIN divergente (133005) orienta o restaurante', function () {
    whatsappFakeMeta(['POST register' => whatsappGraphError(133005, 400, 'Two step verification PIN mismatch')]);
    $connection = pendingConnection();

    onboarding()->complete($connection, 'CODE');

    expect($connection->fresh()->last_error)->toBe(WhatsAppOnboardingService::MSG_PIN_MISMATCH);
});

test('outro erro definitivo no registro mostra o código da Meta', function () {
    whatsappFakeMeta(['POST register' => whatsappGraphError(131037, 400, 'display name approval needed')]);
    $connection = pendingConnection();

    onboarding()->complete($connection, 'CODE');

    expect($connection->fresh()->status)->toBe('error')
        ->and($connection->fresh()->last_error)->toContain('131037');
});

test('token recusado depois da troca (auth) marca erro com orientação para reconectar', function () {
    whatsappFakeMeta(['POST subscribed_apps' => whatsappGraphError(190, 401, 'Error validating access token')]);
    $connection = pendingConnection();

    onboarding()->complete($connection, 'CODE');

    expect($connection->fresh()->status)->toBe('error')
        ->and($connection->fresh()->last_error)->toBe(WhatsAppOnboardingService::MSG_TOKEN_REVOKED)
        ->and($connection->fresh()->access_token)->toBeNull();
});

test('número do front que o token não alcança falha na confirmação do número', function () {
    whatsappFakeMeta(['GET phone' => whatsappGraphError(100, 400, 'Unsupported get request')]);
    $connection = pendingConnection();

    onboarding()->complete($connection, 'CODE');

    expect($connection->fresh()->status)->toBe('error')
        ->and($connection->fresh()->last_error)->toContain('100');
});

// ── complete(): retomada depois de falha temporária ───────────────────────────

test('falha temporária depois da troca do code é relançada e a retomada NÃO troca o code de novo', function () {
    $attempts = 0;
    whatsappFakeMeta(['POST subscribed_apps' => function () use (&$attempts) {
        return ++$attempts === 1 ? whatsappGraphError(2, 503, 'Service unavailable') : ['success' => true];
    }]);

    $connection = pendingConnection();

    expect(fn () => onboarding()->complete($connection, 'CODE-UNICO'))->toThrow(WhatsAppRetryableException::class);

    $connection->refresh();
    expect($connection->status)->toBe('pending')
        ->and($connection->access_token)->toBe('EAAB-token-do-restaurante');

    // Segunda tentativa (retry do job) com o mesmo code, que a Meta já consumiu.
    onboarding()->complete($connection, 'CODE-UNICO');

    expect($connection->fresh()->status)->toBe('provisioning')
        ->and(collect(whatsappMetaCalls())->filter(fn ($call) => $call === 'GET oauth/access_token'))->toHaveCount(1);
});

test('o retry do registro reaproveita o mesmo PIN', function () {
    $attempts = 0;
    whatsappFakeMeta(['POST register' => function () use (&$attempts) {
        return ++$attempts === 1 ? whatsappGraphError(2, 503, 'Service unavailable') : ['success' => true];
    }]);

    $connection = pendingConnection();

    expect(fn () => onboarding()->complete($connection, 'CODE'))->toThrow(WhatsAppRetryableException::class);
    onboarding()->complete($connection, 'CODE');

    $pins = whatsappMetaRequests('POST register')->map(fn (Request $request) => $request->data()['pin']);

    expect($pins)->toHaveCount(2)->and($pins->unique())->toHaveCount(1);
});

test('reconectar o mesmo número reaproveita o PIN de registro existente', function () {
    whatsappFakeMeta();
    $company = onboardingCompany();
    WhatsAppConnection::factory()->forCompany($company)->create([
        'waba_id' => WABA, 'phone_number_id' => PHONE, 'registration_pin' => '654321', 'status' => 'disconnected', 'access_token' => null,
    ]);

    $connection = onboarding()->start($company, 'CODE', WABA, PHONE, 'cloud_api');
    onboarding()->complete($connection, 'CODE');

    Http::assertSent(fn (Request $request) => str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/register')
        && $request->data()['pin'] === '654321');
});

test('complete ignora conexão que não está mais pending', function (string $status) {
    whatsappFakeMeta();
    $connection = pendingConnection(attributes: ['status' => $status]);

    onboarding()->complete($connection, 'CODE');

    Http::assertNothingSent();
    expect($connection->fresh()->status)->toBe($status);
})->with(['disconnected', 'active', 'provisioning', 'error']);

// ── complete(): "Cancelar conexão" com o job rodando ──────────────────────────

/** O restaurante clica em "Cancelar conexão" enquanto o job espera uma resposta da Meta. */
function onboardingCancelMidway(WhatsAppConnection $connection): void
{
    WhatsAppConnection::withoutGlobalScopes()->whereKey($connection->id)->update([
        'status' => WhatsAppConnection::STATUS_DISCONNECTED,
        'access_token' => null,
        'token_scopes' => null,
        'disconnected_at' => now(),
    ]);
}

test('cancelada durante a troca do code: o token não é guardado e nada mais acontece', function () {
    $connection = pendingConnection();
    whatsappFakeMeta(['GET oauth/access_token' => function () use ($connection) {
        onboardingCancelMidway($connection);

        return ['access_token' => 'EAAB-token-do-restaurante'];
    }]);

    onboarding()->complete($connection, 'CODE');

    $connection->refresh();

    expect($connection->status)->toBe('disconnected')
        ->and($connection->access_token)->toBeNull()
        ->and($connection->last_error)->toBeNull()
        ->and(whatsappMetaCalls())->toBe(['GET oauth/access_token']);

    Queue::assertNotPushed(ProvisionWhatsAppTemplates::class);
});

test('cancelada depois de inscrever o app: não volta para provisioning e remove a inscrição', function () {
    $connection = pendingConnection();
    whatsappFakeMeta(['POST register' => function () use ($connection) {
        onboardingCancelMidway($connection);

        return ['success' => true];
    }]);

    onboarding()->complete($connection, 'CODE');

    $connection->refresh();

    expect($connection->status)->toBe('disconnected')
        ->and($connection->access_token)->toBeNull()
        ->and($connection->last_error)->toBeNull();

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && str_contains($request->url(), '/'.WABA.'/subscribed_apps')
        && $request->hasHeader('Authorization', 'Bearer EAAB-token-do-restaurante'));

    Queue::assertNotPushed(ProvisionWhatsAppTemplates::class);
});

test('cancelada e a Meta recusa remover a inscrição: segue desconectada, sem erro', function () {
    $connection = pendingConnection();
    whatsappFakeMeta([
        'POST register' => function () use ($connection) {
            onboardingCancelMidway($connection);

            return ['success' => true];
        },
        'DELETE subscribed_apps' => whatsappGraphError(1, 500, 'Internal error'),
    ]);

    onboarding()->complete($connection, 'CODE');

    expect($connection->fresh()->status)->toBe('disconnected')
        ->and($connection->fresh()->last_error)->toBeNull();
});

test('falha da Meta depois do cancelamento não devolve a conexão para erro', function () {
    $connection = pendingConnection();
    whatsappFakeMeta(['POST register' => function () use ($connection) {
        onboardingCancelMidway($connection);

        return whatsappGraphError(131037, 400, 'display name approval needed');
    }]);

    onboarding()->complete($connection, 'CODE');

    expect($connection->fresh()->status)->toBe('disconnected')
        ->and($connection->fresh()->last_error)->toBeNull();
});

test('fail() não transforma em erro uma conexão já desconectada', function () {
    $connection = pendingConnection(attributes: ['status' => 'disconnected']);

    onboarding()->fail($connection, 'Falha qualquer');

    expect($connection->fresh()->status)->toBe('disconnected')
        ->and($connection->fresh()->last_error)->toBeNull()
        ->and($connection->fresh()->waba_id)->toBe(WABA);
});

test('fail() de uma conexão que já tinha conectado mantém os ids', function () {
    $connection = pendingConnection(attributes: ['connected_at' => now()->subMonth()]);

    onboarding()->fail($connection, 'Falha qualquer');

    expect($connection->fresh()->status)->toBe('error')
        ->and($connection->fresh()->waba_id)->toBe(WABA)
        ->and($connection->fresh()->phone_number_id)->toBe(PHONE);
});

test('signup novo no meio do job: o job antigo não avança a conexão nova nem remove a inscrição dela', function () {
    $company = onboardingCompany();
    $connection = pendingConnection($company);
    whatsappFakeMeta(['POST register' => function () use ($company) {
        // O restaurante cancelou e conectou de novo enquanto este job ainda rodava.
        onboarding()->start($company, 'CODE-NOVO', WABA, PHONE, WhatsAppConnection::TYPE_CLOUD_API);

        return ['success' => true];
    }]);

    onboarding()->complete($connection, 'CODE-ANTIGO');

    $connection->refresh();

    expect($connection->status)->toBe('pending')
        ->and($connection->access_token)->toBeNull()
        ->and(whatsappMetaCalls())->not->toContain('DELETE subscribed_apps');

    Queue::assertNotPushed(ProvisionWhatsAppTemplates::class);
    Queue::assertPushed(CompleteWhatsAppOnboarding::class, fn (CompleteWhatsAppOnboarding $job) => $job->code === 'CODE-NOVO');
});

// ── job ───────────────────────────────────────────────────────────────────────

test('o job usa payload criptografado, é único por signup e roda na fila critical', function () {
    $job = new CompleteWhatsAppOnboarding(7, 'CODE');

    expect($job)->toBeInstanceOf(ShouldBeEncrypted::class)
        ->and($job->uniqueId())->toStartWith('7:')
        ->and($job->uniqueId())->toBe((new CompleteWhatsAppOnboarding(7, 'CODE'))->uniqueId())
        ->and($job->uniqueId())->not->toBe((new CompleteWhatsAppOnboarding(7, 'OUTRO-CODE'))->uniqueId())
        ->and($job->uniqueId())->not->toContain('CODE')
        ->and($job->queue)->toBe('critical')
        ->and($job->afterCommit)->toBeTrue()
        ->and($job->tries)->toBe(3)
        // Abaixo do retry_after do Redis: o job não pode ser pego de novo enquanto ainda roda.
        ->and($job->timeout)->toBe(80)
        ->and($job->timeout)->toBeLessThan((int) config('queue.connections.redis.retry_after'));
});

test('job de uma tentativa antiga não age depois que o restaurante reconectou', function () {
    whatsappFakeMeta();
    $company = onboardingCompany();

    $connection = onboarding()->start($company, 'CODE-ANTIGO', WABA, PHONE, WhatsAppConnection::TYPE_CLOUD_API);
    onboarding()->disconnect($connection->fresh());
    onboarding()->start($company, 'CODE-NOVO', WABA, PHONE, WhatsAppConnection::TYPE_CLOUD_API);

    // Retry do job antigo chegando depois da reconexão: não troca o code velho nem marca erro.
    onboarding()->complete($connection->fresh(), 'CODE-ANTIGO');
    (new CompleteWhatsAppOnboarding($connection->id, 'CODE-ANTIGO'))->failed(new RuntimeException('boom'));

    Http::assertNothingSent();
    expect($connection->fresh()->status)->toBe('pending');

    onboarding()->complete($connection->fresh(), 'CODE-NOVO');

    expect($connection->fresh()->status)->toBe('provisioning');
});

test('failed() do job deixa a conexão pendente em erro com instrução para tentar de novo', function () {
    $connection = pendingConnection(attributes: ['access_token' => 'tok']);

    (new CompleteWhatsAppOnboarding($connection->id, 'CODE'))->failed(new RuntimeException('boom'));

    expect($connection->fresh()->status)->toBe('error')
        ->and($connection->fresh()->last_error)->toBe(WhatsAppOnboardingService::MSG_TEMPORARY)
        ->and($connection->fresh()->access_token)->toBeNull();
});

test('failed() do job não mexe em conexão que já avançou', function () {
    $connection = pendingConnection(attributes: ['status' => 'provisioning']);

    (new CompleteWhatsAppOnboarding($connection->id, 'CODE'))->failed(new RuntimeException('boom'));

    expect($connection->fresh()->status)->toBe('provisioning');
});

test('o job com conexão inexistente termina sem erro', function () {
    whatsappFakeMeta();

    (new CompleteWhatsAppOnboarding(999999, 'CODE'))->handle(onboarding());

    Http::assertNothingSent();
});

// ── ponta a ponta (fila sync + payload criptografado) ─────────────────────────

test('ponta a ponta: start → complete → templates até templates_pending, com a fila real', function () {
    // Descarta o Queue::fake() do beforeEach: aqui roda a fila real (driver sync).
    app()->forgetInstance('queue');
    Queue::clearResolvedInstance('queue');

    whatsappFakeMeta();
    $company = onboardingCompany();

    $connection = onboarding()->start($company, 'CODE-E2E', WABA, PHONE, 'cloud_api');

    $connection->refresh();

    expect($connection->status)->toBe('templates_pending')
        ->and($connection->access_token)->toBe('EAAB-token-do-restaurante')
        ->and($connection->templates()->count())->toBe(10)
        ->and($connection->templates()->where('status', 'PENDING')->count())->toBe(10)
        ->and(whatsappMetaCalls()[0])->toBe('GET oauth/access_token');
});

// ── disconnect() ──────────────────────────────────────────────────────────────

test('disconnect remove a inscrição do app, descarta o token e marca desconectada', function () {
    whatsappFakeMeta();
    $connection = WhatsAppConnection::factory()->create(['waba_id' => WABA, 'access_token' => 'tok-restaurante', 'registration_pin' => '123456']);

    onboarding()->disconnect($connection);

    $connection->refresh();

    expect($connection->status)->toBe('disconnected')
        ->and($connection->access_token)->toBeNull()
        ->and($connection->token_scopes)->toBeNull()
        ->and($connection->disconnected_at)->not->toBeNull()
        ->and($connection->last_error)->toBeNull()
        ->and($connection->registration_pin)->toBe('123456');

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && str_contains($request->url(), '/'.WABA.'/subscribed_apps')
        && $request->hasHeader('Authorization', 'Bearer tok-restaurante'));
});

test('disconnect ignora erro de token na Meta e desconecta mesmo assim', function (mixed $response) {
    whatsappFakeMeta(['DELETE subscribed_apps' => $response]);
    $connection = WhatsAppConnection::factory()->create(['waba_id' => WABA, 'access_token' => 'tok-revogado']);

    onboarding()->disconnect($connection);

    expect($connection->fresh()->status)->toBe('disconnected')
        ->and($connection->fresh()->access_token)->toBeNull();
})->with([
    'token revogado (190)' => fn () => whatsappGraphError(190, 401, 'Error validating access token'),
    'erro da Meta (500)' => fn () => whatsappGraphError(1, 500, 'Internal error'),
    'sem confirmação' => fn () => ['success' => false],
]);

test('disconnect não quebra se a Meta estiver fora do ar', function () {
    whatsappFakeMeta(['DELETE subscribed_apps' => fn () => throw new ConnectionException('cURL error 6')]);
    $connection = WhatsAppConnection::factory()->create(['waba_id' => WABA, 'access_token' => 'tok']);

    onboarding()->disconnect($connection);

    expect($connection->fresh()->status)->toBe('disconnected');
});

test('disconnect sem token não chama a Meta', function () {
    whatsappFakeMeta();
    $connection = WhatsAppConnection::factory()->create(['waba_id' => WABA, 'access_token' => null, 'status' => 'error']);

    onboarding()->disconnect($connection);

    Http::assertNothingSent();
    expect($connection->fresh()->status)->toBe('disconnected');
});

test('disconnect não afeta a conexão de outra empresa', function () {
    whatsappFakeMeta();
    $a = WhatsAppConnection::factory()->create(['waba_id' => WABA, 'access_token' => 'tok-a']);
    $b = WhatsAppConnection::factory()->create(['access_token' => 'tok-b']);

    onboarding()->disconnect($a);

    expect($b->fresh()->status)->toBe('active')
        ->and($b->fresh()->access_token)->toBe('tok-b');
});

test('depois de desconectar, a conexão deixa de enviar mensagens', function () {
    whatsappFakeMeta();
    ['company' => $company, 'connection' => $connection] = whatsappOrderContext();

    expect(app(App\Services\Messaging\WhatsAppService::class)->resolveSender($company))->not->toBeNull();

    onboarding()->disconnect($connection);

    expect(app(App\Services\Messaging\WhatsAppService::class)->resolveSender($company))->toBeNull();
});

// ── refreshPhoneInfo ──────────────────────────────────────────────────────────

test('refreshPhoneInfo atualiza qualidade e limite e não apaga campos que a Meta não devolveu', function () {
    whatsappFakeMeta(['GET phone' => ['id' => PHONE, 'quality_rating' => 'YELLOW', 'messaging_limit_tier' => 'TIER_1K']]);
    $connection = WhatsAppConnection::factory()->create([
        'phone_number_id' => PHONE, 'access_token' => 'tok', 'verified_name' => 'Nome Antigo', 'quality_rating' => 'GREEN',
    ]);

    onboarding()->refreshPhoneInfo($connection);

    $connection->refresh();
    expect($connection->quality_rating)->toBe('YELLOW')
        ->and($connection->messaging_limit_tier)->toBe('TIER_1K')
        ->and($connection->verified_name)->toBe('Nome Antigo');
});
