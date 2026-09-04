<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\IfoodIntegration;
use App\Services\Ifood\IfoodAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'ifood.partner_client_id' => 'platform-client-id',
        'ifood.partner_client_secret' => 'platform-client-secret',
    ]);
});

function makeIfoodIntegration(string $suffix, ?string $accessToken = null, ?\Carbon\CarbonInterface $tokenExpiresAt = null): IfoodIntegration
{
    $company = Company::create([
        'name' => "Empresa iFood Auth {$suffix}",
        'slug' => "empresa-ifood-auth-{$suffix}-".uniqid(),
        'order_prefix' => 'IFA',
        'active' => true,
    ]);

    app()->instance('current.company', $company);

    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => "Filial {$suffix}",
        'address' => 'Rua X, 1',
        'city' => 'SP',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    return IfoodIntegration::create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'merchant_id' => "merchant-{$suffix}",
        'refresh_token' => "refresh-{$suffix}",
        'access_token' => $accessToken,
        'token_expires_at' => $tokenExpiresAt,
        'status' => 'active',
    ]);
}

test('getAccessToken gera novo token quando não há token salvo', function () {
    $integration = makeIfoodIntegration('new');

    Http::fake([
        '*/authentication/v1.0/oauth/token' => Http::response([
            'accessToken' => 'token-new-123',
            'expiresIn' => 3600,
        ], 200),
    ]);

    $token = (new IfoodAuthService)->getAccessToken($integration);

    expect($token)->toBe('token-new-123');

    $integration->refresh();
    expect($integration->access_token)->toBe('token-new-123')
        ->and($integration->token_expires_at)->not->toBeNull();

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) use ($integration) {
        return str_contains($request->url(), '/authentication/v1.0/oauth/token')
            && $request['grantType'] === 'refresh_token'
            && $request['clientId'] === config('ifood.partner_client_id')
            && $request['clientSecret'] === config('ifood.partner_client_secret')
            && $request['refreshToken'] === $integration->refresh_token;
    });
});

test('getAccessToken reusa token ainda válido sem chamar a API', function () {
    $integration = makeIfoodIntegration('valid', 'token-still-valid', now()->addMinutes(30));

    Http::fake();

    $token = (new IfoodAuthService)->getAccessToken($integration);

    expect($token)->toBe('token-still-valid');
    Http::assertNothingSent();
});

test('getAccessToken renova automaticamente quando token está expirado', function () {
    $integration = makeIfoodIntegration('expired', 'token-old', now()->subMinutes(5));

    Http::fake([
        '*/authentication/v1.0/oauth/token' => Http::response([
            'accessToken' => 'token-renewed-456',
            'expiresIn' => 3600,
        ], 200),
    ]);

    $token = (new IfoodAuthService)->getAccessToken($integration);

    expect($token)->toBe('token-renewed-456');
    Http::assertSentCount(1);
});

test('getAccessToken renova quando token expira dentro da margem de segurança', function () {
    $integration = makeIfoodIntegration('margin', 'token-about-to-expire', now()->addSeconds(30));

    Http::fake([
        '*/authentication/v1.0/oauth/token' => Http::response([
            'accessToken' => 'token-renewed-margin',
            'expiresIn' => 3600,
        ], 200),
    ]);

    $token = (new IfoodAuthService)->getAccessToken($integration);

    expect($token)->toBe('token-renewed-margin');
});

test('getAccessToken lança exceção quando iFood responde com erro', function () {
    $integration = makeIfoodIntegration('error');

    Http::fake([
        '*/authentication/v1.0/oauth/token' => Http::response(['error' => 'invalid_client'], 401),
    ]);

    expect(fn () => (new IfoodAuthService)->getAccessToken($integration))
        ->toThrow(RuntimeException::class);
});

test('tokens de integrações de empresas diferentes nunca se misturam', function () {
    $integrationA = makeIfoodIntegration('tenantA');
    $integrationB = makeIfoodIntegration('tenantB');

    Http::fake([
        '*/authentication/v1.0/oauth/token' => Http::sequence()
            ->push(['accessToken' => 'token-tenant-A', 'expiresIn' => 3600], 200)
            ->push(['accessToken' => 'token-tenant-B', 'expiresIn' => 3600], 200),
    ]);

    $service = new IfoodAuthService;
    $tokenA = $service->getAccessToken($integrationA);
    $tokenB = $service->getAccessToken($integrationB);

    expect($tokenA)->toBe('token-tenant-A')
        ->and($tokenB)->toBe('token-tenant-B');

    $integrationA->refresh();
    $integrationB->refresh();

    expect($integrationA->access_token)->toBe('token-tenant-A')
        ->and($integrationB->access_token)->toBe('token-tenant-B')
        ->and($integrationA->access_token)->not->toBe($integrationB->access_token);
});

test('requestUserCode grava os dados de autorização pendente', function () {
    $integration = makeIfoodIntegration('usercode');

    Http::fake([
        '*/authentication/v1.0/oauth/userCode' => Http::response([
            'userCode' => 'ABCD-1234',
            'authorizationCodeVerifier' => 'verifier-xyz',
            'verificationUrlComplete' => 'https://portal.ifood.com.br/apps/link?c=ABCD-1234',
            'expiresIn' => 600,
        ], 200),
    ]);

    (new IfoodAuthService)->requestUserCode($integration);

    $integration->refresh();

    expect($integration->user_code)->toBe('ABCD-1234')
        ->and($integration->authorization_code_verifier)->toBe('verifier-xyz')
        ->and($integration->verification_url)->toBe('https://portal.ifood.com.br/apps/link?c=ABCD-1234')
        ->and($integration->user_code_expires_at)->not->toBeNull()
        ->and($integration->merchant_id)->toBeNull()
        ->and($integration->status)->toBe('disconnected');

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        return str_contains($request->url(), '/authentication/v1.0/oauth/userCode')
            && $request['clientId'] === config('ifood.partner_client_id');
    });
});

test('completeAuthorization conclui a conexão quando iFood aprova e retorna um merchant', function () {
    $integration = makeIfoodIntegration('exchange-ok');
    $integration->update([
        'merchant_id' => null,
        'user_code' => 'ABCD-1234',
        'authorization_code_verifier' => 'verifier-xyz',
        'user_code_expires_at' => now()->addMinutes(5),
        'status' => 'disconnected',
    ]);

    Http::fake([
        '*/authentication/v1.0/oauth/token' => Http::response([
            'accessToken' => 'access-final',
            'refreshToken' => 'refresh-final',
            'expiresIn' => 21600,
        ], 200),
        '*/merchant/v1.0/merchants' => Http::response([
            ['id' => 'merchant-discovered', 'name' => 'Loja Teste'],
        ], 200),
    ]);

    // authorizationCode real é DIFERENTE do user_code — iFood mostra outro código
    // na tela depois da aprovação (ver comentário em IfoodAuthService::completeAuthorization).
    (new IfoodAuthService)->completeAuthorization($integration, 'HTLM-KWVR');

    Http::assertSent(fn (\Illuminate\Http\Client\Request $request) => ! str_contains($request->url(), '/oauth/token')
        || $request['authorizationCode'] === 'HTLM-KWVR');

    $integration->refresh();
    expect($integration->merchant_id)->toBe('merchant-discovered')
        ->and($integration->access_token)->toBe('access-final')
        ->and($integration->refresh_token)->toBe('refresh-final')
        ->and($integration->status)->toBe('active')
        ->and($integration->user_code)->toBeNull()
        ->and($integration->authorization_code_verifier)->toBeNull()
        ->and($integration->user_code_expires_at)->toBeNull();
});

test('completeAuthorization lança exceção quando o código de autorização é inválido', function () {
    $integration = makeIfoodIntegration('exchange-invalid');
    $integration->update([
        'merchant_id' => null,
        'user_code' => 'ABCD-1234',
        'authorization_code_verifier' => 'verifier-xyz',
        'user_code_expires_at' => now()->addMinutes(5),
        'status' => 'disconnected',
    ]);

    Http::fake([
        '*/authentication/v1.0/oauth/token' => Http::response(['error' => ['code' => 'Unauthorized', 'message' => 'Invalid authorization code XXXX-YYYY']], 401),
    ]);

    expect(fn () => (new IfoodAuthService)->completeAuthorization($integration, 'XXXX-YYYY'))
        ->toThrow(RuntimeException::class);

    $integration->refresh();
    expect($integration->merchant_id)->toBeNull()
        ->and($integration->user_code)->toBe('ABCD-1234');
});

test('completeAuthorization bloqueia quando o merchant já está ativo em outra empresa', function () {
    $other = makeIfoodIntegration('conflict-existing');
    $other->update(['merchant_id' => 'merchant-shared', 'status' => 'active']);

    $integration = makeIfoodIntegration('conflict-new');
    $integration->update([
        'merchant_id' => null,
        'user_code' => 'ABCD-1234',
        'authorization_code_verifier' => 'verifier-xyz',
        'user_code_expires_at' => now()->addMinutes(5),
        'status' => 'disconnected',
    ]);

    Http::fake([
        '*/authentication/v1.0/oauth/token' => Http::response([
            'accessToken' => 'access-final',
            'refreshToken' => 'refresh-final',
            'expiresIn' => 21600,
        ], 200),
        '*/merchant/v1.0/merchants' => Http::response([
            ['id' => 'merchant-shared', 'name' => 'Loja Compartilhada'],
        ], 200),
    ]);

    expect(fn () => (new IfoodAuthService)->completeAuthorization($integration, 'HTLM-KWVR'))
        ->toThrow(\App\Exceptions\IfoodMerchantAlreadyLinkedException::class);

    $integration->refresh();
    expect($integration->merchant_id)->toBeNull()
        ->and($integration->status)->toBe('disconnected');
});

test('completeAuthorization com mais de um merchant guarda a lista e não ativa sozinho', function () {
    $integration = makeIfoodIntegration('exchange-multi');
    $integration->update([
        'merchant_id' => null,
        'user_code' => 'ABCD-1234',
        'authorization_code_verifier' => 'verifier-xyz',
        'user_code_expires_at' => now()->addMinutes(5),
        'status' => 'disconnected',
    ]);

    Http::fake([
        '*/authentication/v1.0/oauth/token' => Http::response([
            'accessToken' => 'access-final',
            'refreshToken' => 'refresh-final',
            'expiresIn' => 21600,
        ], 200),
        '*/merchant/v1.0/merchants' => Http::response([
            ['id' => 'merchant-a', 'name' => 'Loja A'],
            ['id' => 'merchant-b', 'name' => 'Loja B'],
        ], 200),
    ]);

    (new IfoodAuthService)->completeAuthorization($integration, 'HTLM-KWVR');

    $integration->refresh();
    expect($integration->merchant_id)->toBeNull()
        ->and($integration->status)->toBe('disconnected')
        ->and($integration->access_token)->toBe('access-final')
        ->and($integration->available_merchants)->toBe([
            ['id' => 'merchant-a', 'name' => 'Loja A'],
            ['id' => 'merchant-b', 'name' => 'Loja B'],
        ]);
});

test('selectMerchant confirma um merchant válido da lista e ativa a integração', function () {
    $integration = makeIfoodIntegration('select-ok');
    $integration->update([
        'merchant_id' => null,
        'available_merchants' => [
            ['id' => 'merchant-a', 'name' => 'Loja A'],
            ['id' => 'merchant-b', 'name' => 'Loja B'],
        ],
        'status' => 'disconnected',
    ]);

    (new IfoodAuthService)->selectMerchant($integration, 'merchant-b');

    $integration->refresh();
    expect($integration->merchant_id)->toBe('merchant-b')
        ->and($integration->available_merchants)->toBeNull()
        ->and($integration->status)->toBe('active');
});

test('selectMerchant bloqueia quando o merchant escolhido já está ativo em outra empresa', function () {
    $other = makeIfoodIntegration('select-conflict-existing');
    $other->update(['merchant_id' => 'merchant-b', 'status' => 'active']);

    $integration = makeIfoodIntegration('select-conflict-new');
    $integration->update([
        'merchant_id' => null,
        'available_merchants' => [
            ['id' => 'merchant-a', 'name' => 'Loja A'],
            ['id' => 'merchant-b', 'name' => 'Loja B'],
        ],
        'status' => 'disconnected',
    ]);

    expect(fn () => (new IfoodAuthService)->selectMerchant($integration, 'merchant-b'))
        ->toThrow(\App\Exceptions\IfoodMerchantAlreadyLinkedException::class);

    $integration->refresh();
    expect($integration->merchant_id)->toBeNull()
        ->and($integration->status)->toBe('disconnected');
});

test('selectMerchant rejeita merchant_id que não está entre os autorizados', function () {
    $integration = makeIfoodIntegration('select-invalid');
    $integration->update([
        'merchant_id' => null,
        'available_merchants' => [
            ['id' => 'merchant-a', 'name' => 'Loja A'],
        ],
        'status' => 'disconnected',
    ]);

    expect(fn () => (new IfoodAuthService)->selectMerchant($integration, 'merchant-invasor'))
        ->toThrow(RuntimeException::class);

    $integration->refresh();
    expect($integration->merchant_id)->toBeNull();
});

test('completeAuthorization lança exceção em falha inesperada', function () {
    $integration = makeIfoodIntegration('exchange-fail');
    $integration->update([
        'merchant_id' => null,
        'user_code' => 'ABCD-1234',
        'authorization_code_verifier' => 'verifier-xyz',
        'user_code_expires_at' => now()->addMinutes(5),
        'status' => 'disconnected',
    ]);

    Http::fake([
        '*/authentication/v1.0/oauth/token' => Http::response(['error' => 'invalid_client'], 401),
    ]);

    expect(fn () => (new IfoodAuthService)->completeAuthorization($integration, 'HTLM-KWVR'))
        ->toThrow(RuntimeException::class);
});
