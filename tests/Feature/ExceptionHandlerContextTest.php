<?php

use App\Exceptions\Handler;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;

function exceptionLogContext(Request $request): array
{
    app()->instance('request', $request);

    $handler = app(ExceptionHandler::class);
    expect($handler)->toBeInstanceOf(Handler::class);

    return (fn () => $this->context())->call($handler);
}

test('requisição Livewire não registra nada do payload — nem senha de certificado', function () {
    $request = Request::create('/livewire/update', 'POST', [
        'components' => [[
            'snapshot' => json_encode(['data' => ['branchPhone' => '(44) 99999-0000'], 'memo' => ['name' => 'admin.fiscal.config']]),
            'updates' => ['certificatePassword' => 'senha-do-certificado'],
            'calls' => [['method' => 'save', 'params' => []]],
        ]],
    ]);
    $request->headers->set('X-Livewire', '1');

    $context = exceptionLogContext($request);

    expect($context['input'])->toBe(['livewire' => '[redacted]']);
    expect(json_encode($context))->not->toContain('senha-do-certificado')
        ->not->toContain('99999-0000');
});

test('requisição comum mascara chaves sensíveis em qualquer nível e mantém o resto', function () {
    $request = Request::create('/cadastro', 'POST', [
        'name' => 'Lanchonete',
        'password' => 'segredo',
        'owner' => ['cpf_cnpj' => '12345678000199', 'phone' => '44999990000', 'city' => 'Maringá'],
        'access_token' => 'tok',
    ]);

    expect(exceptionLogContext($request)['input'])->toBe([
        'name' => 'Lanchonete',
        'password' => '[redacted]',
        'owner' => ['cpf_cnpj' => '[redacted]', 'phone' => '[redacted]', 'city' => 'Maringá'],
        'access_token' => '[redacted]',
    ]);
});
