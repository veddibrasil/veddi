<?php

use App\Exceptions\FocusNfeCompanyRegistrationException;
use App\Services\Fiscal\FocusNfeService;
use Illuminate\Support\Facades\Http;

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
