<?php

use App\Contracts\WhatsAppManagementInterface;
use App\Exceptions\WhatsAppAuthException;
use App\Exceptions\WhatsAppPermanentException;
use App\Exceptions\WhatsAppRetryableException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function whatsappManagement(): WhatsAppManagementInterface
{
    return app(WhatsAppManagementInterface::class);
}

// ── exchangeCode ──────────────────────────────────────────────────────────────

test('exchangeCode troca o code pelo token sem Bearer e sem appsecret_proof', function () {
    whatsappFakeMeta();

    $token = whatsappManagement()->exchangeCode('CODE-123');

    expect($token)->toBe('EAAB-token-do-restaurante');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && str_starts_with($request->url(), 'https://graph.facebook.com/v25.0/oauth/access_token')
        && str_contains($request->url(), 'client_id=111222333')
        && str_contains($request->url(), 'client_secret=app-secret-teste')
        && str_contains($request->url(), 'code=CODE-123')
        && ! str_contains($request->url(), 'appsecret_proof')
        && ! $request->hasHeader('Authorization'));
});

test('exchangeCode sem credenciais do app falha sem chamar a Meta', function () {
    whatsappFakeMeta();
    config(['services.meta.app_secret' => null]);

    expect(fn () => whatsappManagement()->exchangeCode('CODE'))->toThrow(WhatsAppPermanentException::class, 'META_APP_ID');

    Http::assertNothingSent();
});

test('exchangeCode com resposta sem access_token é falha permanente', function () {
    whatsappFakeMeta(['GET oauth/access_token' => ['foo' => 'bar']]);

    whatsappManagement()->exchangeCode('CODE');
})->throws(WhatsAppPermanentException::class);

test('exchangeCode com code inválido vira exceção permanente com o código da Meta', function () {
    whatsappFakeMeta(['GET oauth/access_token' => whatsappGraphError(100, 400, 'Invalid verification code format.')]);

    try {
        whatsappManagement()->exchangeCode('CODE');
        $this->fail('Deveria lançar exceção');
    } catch (WhatsAppPermanentException $e) {
        expect($e->getCode())->toBe(100)->and($e->getMessage())->toContain('Invalid verification code');
    }
});

// ── debugToken ────────────────────────────────────────────────────────────────

test('debugToken autentica com o token do app, sem appsecret_proof, e extrai WABAs dos escopos', function () {
    whatsappFakeMeta(['GET debug_token' => ['data' => [
        'app_id' => '111222333',
        'is_valid' => true,
        'expires_at' => 0,
        'scopes' => ['whatsapp_business_management', 'whatsapp_business_messaging', 'business_management'],
        'granular_scopes' => [
            ['scope' => 'whatsapp_business_management', 'target_ids' => ['WABA-1']],
            ['scope' => 'whatsapp_business_messaging', 'target_ids' => ['WABA-1']],
            ['scope' => 'business_management', 'target_ids' => ['PORTFOLIO-9']],
        ],
    ]]]);

    $info = whatsappManagement()->debugToken('EAAB-token-do-restaurante');

    expect($info['is_valid'])->toBeTrue()
        ->and($info['app_id'])->toBe('111222333')
        ->and($info['waba_ids'])->toBe(['WABA-1'])
        ->and($info['granular_scopes'])->toBe([
            ['scope' => 'whatsapp_business_management', 'target_ids' => ['WABA-1']],
            ['scope' => 'whatsapp_business_messaging', 'target_ids' => ['WABA-1']],
            ['scope' => 'business_management', 'target_ids' => ['PORTFOLIO-9']],
        ])
        ->and($info['scopes'])->toContain('whatsapp_business_messaging');

    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && str_starts_with($request->url(), 'https://graph.facebook.com/v25.0/debug_token')
        && str_contains($request->url(), 'input_token=EAAB-token-do-restaurante')
        && $request->hasHeader('Authorization', 'Bearer 111222333|app-secret-teste')
        && ! str_contains($request->url(), 'appsecret_proof'));
});

test('debugToken junta várias WABAs sem duplicar', function () {
    whatsappFakeMeta(['GET debug_token' => ['data' => [
        'is_valid' => true,
        'granular_scopes' => [
            ['scope' => 'whatsapp_business_management', 'target_ids' => ['A', 'B']],
            ['scope' => 'whatsapp_business_messaging', 'target_ids' => ['B']],
        ],
    ]]]);

    expect(whatsappManagement()->debugToken('t')['waba_ids'])->toBe(['A', 'B']);
});

test('debugToken de token inválido devolve is_valid false', function () {
    whatsappFakeMeta(['GET debug_token' => ['data' => ['is_valid' => false, 'error' => ['message' => 'Expired']]]]);

    $info = whatsappManagement()->debugToken('t');

    expect($info['is_valid'])->toBeFalse()->and($info['waba_ids'])->toBe([]);
});

// ── phone numbers ─────────────────────────────────────────────────────────────

test('listPhoneNumbers lista os números da WABA com appsecret_proof', function () {
    whatsappFakeMeta();

    $numbers = whatsappManagement()->listPhoneNumbers('1000000000001', 'tok');

    expect($numbers)->toBe([[
        'id' => '2000000000001',
        'display_phone_number' => '+55 11 99999-0001',
        'verified_name' => 'Restaurante Teste',
        'quality_rating' => 'GREEN',
    ]]);

    $proof = hash_hmac('sha256', 'tok', 'app-secret-teste');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/1000000000001/phone_numbers')
        && str_contains($request->url(), 'appsecret_proof='.$proof)
        && $request->hasHeader('Authorization', 'Bearer tok'));
});

test('getPhoneNumber devolve nome verificado, número, qualidade e limite', function () {
    whatsappFakeMeta();

    expect(whatsappManagement()->getPhoneNumber('2000000000001', 'tok'))->toBe([
        'verified_name' => 'Restaurante Teste',
        'display_phone_number' => '+55 11 99999-0001',
        'quality_rating' => 'GREEN',
        'messaging_limit_tier' => 'TIER_250',
    ]);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/2000000000001?')
        && str_contains($request->url(), 'fields=verified_name'));
});

test('registerPhone envia messaging_product e o PIN no corpo', function () {
    whatsappFakeMeta();

    whatsappManagement()->registerPhone('2000000000001', 'tok', '482913');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_contains($request->url(), '/2000000000001/register')
        && $request->data() === ['messaging_product' => 'whatsapp', 'pin' => '482913']);
});

test('registerPhone com success diferente de true é falha permanente', function () {
    whatsappFakeMeta(['POST register' => ['success' => false]]);

    whatsappManagement()->registerPhone('2000000000001', 'tok', '482913');
})->throws(WhatsAppPermanentException::class);

test('registerPhone com 133016 devolve exceção permanente com o código', function () {
    whatsappFakeMeta(['POST register' => whatsappGraphError(133016, 400, 'Account register limit reached')]);

    try {
        whatsappManagement()->registerPhone('2000000000001', 'tok', '482913');
        $this->fail('Deveria lançar exceção');
    } catch (WhatsAppPermanentException $e) {
        expect($e->getCode())->toBe(133016);
    }
});

// ── subscribed_apps ───────────────────────────────────────────────────────────

test('subscribeApp e unsubscribeApp chamam POST e DELETE em subscribed_apps', function () {
    whatsappFakeMeta();

    whatsappManagement()->subscribeApp('1000000000001', 'tok');
    whatsappManagement()->unsubscribeApp('1000000000001', 'tok');

    expect(whatsappMetaCalls())->toBe(['POST subscribed_apps', 'DELETE subscribed_apps']);

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && str_contains($request->url(), '/1000000000001/subscribed_apps')
        && str_contains($request->url(), 'appsecret_proof=')
        && $request->hasHeader('Authorization', 'Bearer tok'));
});

test('subscribeApp sem success true é falha permanente', function () {
    whatsappFakeMeta(['POST subscribed_apps' => ['success' => false]]);

    whatsappManagement()->subscribeApp('1000000000001', 'tok');
})->throws(WhatsAppPermanentException::class);

test('token recusado em qualquer operação de gestão vira WhatsAppAuthException', function () {
    whatsappFakeMeta(['POST subscribed_apps' => whatsappGraphError(190, 401, 'Error validating access token')]);

    whatsappManagement()->subscribeApp('1000000000001', 'tok');
})->throws(WhatsAppAuthException::class);

// ── templates ─────────────────────────────────────────────────────────────────

test('createTemplate envia o corpo com example.body_text e devolve id e status', function () {
    whatsappFakeMeta(['POST message_templates' => ['id' => '5550001', 'status' => 'PENDING', 'category' => 'UTILITY']]);

    $result = whatsappManagement()->createTemplate('1000000000001', 'tok', [
        'name' => 'pedido_recebido',
        'language' => 'pt_BR',
        'category' => 'UTILITY',
        'body' => 'Olá, {{1}}! Recebemos seu pedido #{{2}} no valor de {{3}}.',
        'example' => ['Maria', '1042', 'R$ 45,90'],
    ]);

    expect($result)->toBe(['id' => '5550001', 'status' => 'PENDING', 'category' => 'UTILITY']);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_contains($request->url(), '/1000000000001/message_templates')
        && $request->data() === [
            'name' => 'pedido_recebido',
            'language' => 'pt_BR',
            'category' => 'UTILITY',
            'components' => [[
                'type' => 'BODY',
                'text' => 'Olá, {{1}}! Recebemos seu pedido #{{2}} no valor de {{3}}.',
                'example' => ['body_text' => [['Maria', '1042', 'R$ 45,90']]],
            ]],
        ]);
});

test('createTemplate cobre todos os templates do config com exemplos do tamanho certo', function () {
    whatsappFakeMeta();

    foreach (config('whatsapp_templates.templates') as $definition) {
        whatsappManagement()->createTemplate('1000000000001', 'tok', [
            'name' => $definition['name'],
            'language' => 'pt_BR',
            'category' => 'UTILITY',
            'body' => $definition['body'],
            'example' => $definition['example'],
        ]);
    }

    $sent = whatsappMetaRequests('POST message_templates');

    expect($sent)->toHaveCount(10);

    foreach ($sent as $request) {
        $component = $request->data()['components'][0];
        preg_match_all('/\{\{\d+\}\}/', $component['text'], $matches);

        expect($component['example']['body_text'][0])->toHaveCount(count($matches[0]));
    }
});

test('listTemplates segue a paginação da Meta', function () {
    $calls = 0;

    whatsappFakeMeta(['GET message_templates' => function (Request $request) use (&$calls) {
        $calls++;

        if (! str_contains($request->url(), 'after=')) {
            return [
                'data' => [['id' => '1', 'name' => 'pedido_recebido', 'language' => 'pt_BR', 'status' => 'approved', 'category' => 'UTILITY']],
                'paging' => ['cursors' => ['after' => 'CURSOR-2'], 'next' => 'https://graph.facebook.com/next'],
            ];
        }

        return [
            'data' => [['id' => '2', 'name' => 'pedido_cancelado', 'language' => 'pt_BR', 'status' => 'REJECTED', 'rejected_reason' => 'INCORRECT_CATEGORY']],
            'paging' => ['cursors' => ['after' => 'CURSOR-3']],
        ];
    }]);

    $templates = whatsappManagement()->listTemplates('1000000000001', 'tok');

    expect($calls)->toBe(2)
        ->and($templates)->toHaveCount(2)
        ->and($templates[0])->toMatchArray(['id' => '1', 'name' => 'pedido_recebido', 'status' => 'APPROVED', 'category' => 'UTILITY'])
        ->and($templates[1])->toMatchArray(['name' => 'pedido_cancelado', 'status' => 'REJECTED', 'rejected_reason' => 'INCORRECT_CATEGORY']);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'after=CURSOR-2') && str_contains($request->url(), 'limit=100'));
});

test('listTemplates ignora itens sem nome e para no limite de páginas', function () {
    $calls = 0;

    whatsappFakeMeta(['GET message_templates' => function () use (&$calls) {
        $calls++;

        return [
            'data' => [['id' => '9'], ['id' => '1', 'name' => 'pedido_recebido', 'language' => 'pt_BR', 'status' => 'APPROVED']],
            'paging' => ['cursors' => ['after' => 'SEMPRE'], 'next' => 'https://graph.facebook.com/next'],
        ];
    }]);

    $templates = whatsappManagement()->listTemplates('1000000000001', 'tok');

    expect($calls)->toBe(20)->and($templates)->toHaveCount(20);
});

test('falha temporária na listagem de templates vira exceção retentável', function () {
    whatsappFakeMeta(['GET message_templates' => whatsappGraphError(2, 503, 'Service unavailable')]);

    whatsappManagement()->listTemplates('1000000000001', 'tok');
})->throws(WhatsAppRetryableException::class);
