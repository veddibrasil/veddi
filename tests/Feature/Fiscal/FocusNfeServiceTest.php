<?php

use App\DTOs\FiscalNoteDTO;
use App\Exceptions\FocusNfeCompanyRegistrationException;
use App\Services\Fiscal\FocusNfeService;
use Illuminate\Support\Facades\Http;

function fiscalNoteDtoForTest(): FiscalNoteDTO
{
    return new FiscalNoteDTO(
        emitenteCnpj: '12345678000100',
        emitenteNome: 'Empresa Teste',
        emitenteLogradouro: 'Rua A',
        emitenteNumero: '100',
        emitenteBairro: 'Centro',
        emitenteMunicipio: 'São Paulo',
        emitenteUf: 'SP',
        emitenteCep: '01310000',
        emitenteCrt: 1,
        emitenteInscricaoEstadual: 'ISENTO',
        nfceSerie: '1',
        orderId: 1,
        total: 30.00,
        paymentMethod: 'PIX',
        items: [],
    );
}

test('query() usa a referência (ref) no path, não a chave de acesso', function () {
    Http::fake([
        '*/v2/nfce/ref_test_001' => Http::response(['status' => 'autorizado', 'chave_nfe' => str_repeat('1', 44)], 200),
    ]);

    $service = new FocusNfeService('tok', 'https://homologacao.focusnfe.com.br');
    $result = $service->query('ref_test_001');

    expect($result->status)->toBe('authorized');
    expect($result->providerReference)->toBe('ref_test_001');

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        return str_contains($request->url(), '/v2/nfce/ref_test_001')
            && ! str_contains($request->url(), str_repeat('1', 44));
    });
});

test('issue() marca status error quando Focus rejeita com erro de validação de schema (422 sem status assíncrono)', function () {
    Http::fake([
        '*/v2/nfce*' => Http::response([
            'codigo' => 'erro_validacao_schema',
            'mensagem' => "41:0: ERROR: Element '{http://www.portalfiscal.inf.br/nfe}IE': [facet 'pattern'] The value '' is not accepted by the pattern '[0-9]{2,14}|ISENTO'.",
        ], 422),
    ]);

    $service = new FocusNfeService('tok', 'https://homologacao.focusnfe.com.br');
    $result = $service->issue(fiscalNoteDtoForTest());

    expect($result->status)->toBe('error');
    expect($result->errorMessage)->toContain('IE');
});

test('issue() mantém status pending quando 422 vem com status assíncrono da SEFAZ', function () {
    Http::fake([
        '*/v2/nfce*' => Http::response(['status' => 'processando_autorizacao'], 422),
    ]);

    $service = new FocusNfeService('tok', 'https://homologacao.focusnfe.com.br');
    $result = $service->issue(fiscalNoteDtoForTest());

    expect($result->status)->toBe('pending');
});

test('createCompany() faz POST em /v2/empresas e retorna o corpo decodificado', function () {
    Http::fake([
        '*/v2/empresas' => Http::response([
            'id' => 'focus-empresa-1',
            'token_producao' => 'tok-prod',
            'token_homologacao' => 'tok-homolog',
        ], 201),
    ]);

    $service = new FocusNfeService('platform-token', 'https://homologacao.focusnfe.com.br');
    $response = $service->createCompany(['nome' => 'Empresa Teste', 'cnpj' => '12345678000199']);

    expect($response['id'])->toBe('focus-empresa-1');
    expect($response['token_producao'])->toBe('tok-prod');
    expect($response['token_homologacao'])->toBe('tok-homolog');

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        return $request->method() === 'POST'
            && str_contains($request->url(), '/v2/empresas')
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('platform-token:'));
    });
});

test('createCompany() lança FocusNfeCompanyRegistrationException em resposta de erro', function () {
    Http::fake([
        '*/v2/empresas' => Http::response(['mensagem' => 'Certificado inválido ou senha incorreta'], 422),
    ]);

    $service = new FocusNfeService('platform-token', 'https://homologacao.focusnfe.com.br');

    expect(fn () => $service->createCompany(['nome' => 'Empresa Teste', 'cnpj' => '12345678000199']))
        ->toThrow(FocusNfeCompanyRegistrationException::class, 'Certificado inválido ou senha incorreta');
});

test('updateCompany() faz PUT em /v2/empresas/{id}', function () {
    Http::fake([
        '*/v2/empresas/focus-empresa-1' => Http::response([
            'id' => 'focus-empresa-1',
            'token_producao' => 'tok-prod-2',
            'token_homologacao' => 'tok-homolog-2',
        ], 200),
    ]);

    $service = new FocusNfeService('platform-token', 'https://homologacao.focusnfe.com.br');
    $response = $service->updateCompany('focus-empresa-1', ['nome' => 'Empresa Teste Atualizada']);

    expect($response['token_producao'])->toBe('tok-prod-2');

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        return $request->method() === 'PUT' && str_contains($request->url(), '/v2/empresas/focus-empresa-1');
    });
});

test('updateCompany() lança FocusNfeCompanyRegistrationException em resposta de erro', function () {
    Http::fake([
        '*/v2/empresas/focus-empresa-1' => Http::response(['mensagem' => 'Empresa não encontrada'], 404),
    ]);

    $service = new FocusNfeService('platform-token', 'https://homologacao.focusnfe.com.br');

    expect(fn () => $service->updateCompany('focus-empresa-1', ['nome' => 'Empresa Teste']))
        ->toThrow(FocusNfeCompanyRegistrationException::class, 'Empresa não encontrada');
});

test('listWebhooks() faz GET em /v2/hooks e retorna o array decodificado', function () {
    Http::fake([
        '*/v2/hooks' => Http::response([
            ['id' => 'hook-1', 'cnpj' => '12345678000199', 'event' => 'nfce'],
        ], 200),
    ]);

    $service = new FocusNfeService('platform-token', 'https://api.focusnfe.com.br');
    $hooks = $service->listWebhooks();

    expect($hooks)->toHaveCount(1);
    expect($hooks[0]['id'])->toBe('hook-1');

    Http::assertSent(fn (\Illuminate\Http\Client\Request $request) => $request->method() === 'GET'
        && str_contains($request->url(), '/v2/hooks'));
});

test('listWebhooks() lança exceção quando Focus responde erro', function () {
    Http::fake([
        '*/v2/hooks' => Http::response(['mensagem' => 'Token inválido'], 401),
    ]);

    $service = new FocusNfeService('platform-token', 'https://api.focusnfe.com.br');

    expect(fn () => $service->listWebhooks())
        ->toThrow(FocusNfeCompanyRegistrationException::class, 'Token inválido');
});

test('createWebhook() faz POST em /v2/hooks com o payload informado', function () {
    Http::fake([
        '*/v2/hooks' => Http::response(['id' => 'hook-1'], 201),
    ]);

    $service = new FocusNfeService('platform-token', 'https://api.focusnfe.com.br');
    $response = $service->createWebhook([
        'event' => 'nfce',
        'url' => 'https://exemplo.com/webhooks/fiscal',
        'cnpj' => '12345678000199',
    ]);

    expect($response['id'])->toBe('hook-1');

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        return $request->method() === 'POST'
            && str_contains($request->url(), '/v2/hooks')
            && ($request['event'] ?? null) === 'nfce'
            && ($request['cnpj'] ?? null) === '12345678000199';
    });
});

test('createWebhook() lança FocusNfeCompanyRegistrationException em resposta de erro', function () {
    Http::fake([
        '*/v2/hooks' => Http::response(['mensagem' => 'Evento inválido'], 400),
    ]);

    $service = new FocusNfeService('platform-token', 'https://api.focusnfe.com.br');

    expect(fn () => $service->createWebhook(['event' => 'invalido', 'url' => 'https://exemplo.com']))
        ->toThrow(FocusNfeCompanyRegistrationException::class, 'Evento inválido');
});

test('deleteWebhook() faz DELETE em /v2/hooks/{id}', function () {
    Http::fake([
        '*/v2/hooks/hook-1' => Http::response([], 200),
    ]);

    $service = new FocusNfeService('platform-token', 'https://api.focusnfe.com.br');
    $service->deleteWebhook('hook-1');

    Http::assertSent(fn (\Illuminate\Http\Client\Request $request) => $request->method() === 'DELETE'
        && str_contains($request->url(), '/v2/hooks/hook-1'));
});

test('deleteWebhook() lança exceção quando Focus responde erro', function () {
    Http::fake([
        '*/v2/hooks/hook-1' => Http::response(['mensagem' => 'Webhook não encontrado'], 404),
    ]);

    $service = new FocusNfeService('platform-token', 'https://api.focusnfe.com.br');

    expect(fn () => $service->deleteWebhook('hook-1'))
        ->toThrow(FocusNfeCompanyRegistrationException::class, 'Webhook não encontrado');
});
