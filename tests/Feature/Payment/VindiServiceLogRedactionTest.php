<?php

use App\Services\Payment\VindiService;

/**
 * `redactCardPayload()` é privado — chamamos via Reflection porque o que
 * importa aqui é garantir que NENHUM dado pessoal do titular (CPF/CNPJ,
 * e-mail, telefone, endereço) sobrevive antes de ir pro Log::channel('payments'),
 * que replica pro Nightwatch (SaaS terceiro). Testar via HTTP real exigiria
 * mockar toda a integração Vindi só pra validar uma função de mascaramento.
 */
function redactVindiPayload(array $payload): array
{
    $service = new VindiService;
    $method = new ReflectionMethod(VindiService::class, 'redactCardPayload');

    return $method->invoke($service, $payload);
}

it('mascara CPF, nome, e-mail, telefone e endereço do titular no payload de cartão', function () {
    $payload = [
        'payment' => [
            'card_number' => '4111111111111111',
            'card_cvv' => '123',
        ],
        'customer' => [
            'name' => 'Fulano da Silva',
            'email' => 'fulano@exemplo.com',
            'cpf' => '12345678900',
            'cnpj' => null,
            'contacts' => [
                ['type_contact' => 'M', 'number_contact' => '11999998888'],
            ],
            'addresses' => [
                [
                    'street' => 'Rua das Flores',
                    'number' => '123',
                    'completion' => 'Apto 45',
                    'neighborhood' => 'Centro',
                    'city' => 'São Paulo',
                    'state' => 'São Paulo',
                    'postal_code' => '01000-000',
                ],
            ],
        ],
    ];

    $redacted = redactVindiPayload($payload);

    $serialized = json_encode($redacted);

    expect($serialized)->not->toContain('Fulano da Silva')
        ->not->toContain('fulano@exemplo.com')
        ->not->toContain('12345678900')
        ->not->toContain('11999998888')
        ->not->toContain('Rua das Flores')
        ->not->toContain('Apto 45')
        ->not->toContain('Centro');

    // Cidade/estado/CEP não são PII sensível o bastante pra impedir debug — mantidos.
    expect($redacted['customer']['addresses'][0]['city'])->toBe('São Paulo');
});

it('continua mascarando PAN e CVV do cartão (comportamento pré-existente)', function () {
    $payload = [
        'payment' => [
            'card_number' => '4111111111111111',
            'card_cvv' => '123',
            'card_token' => 'tok_abcdefghijklmnop',
        ],
    ];

    $redacted = redactVindiPayload($payload);

    expect($redacted['payment']['card_number'])->toBe('************1111');
    expect($redacted['payment']['card_cvv'])->toBe('***');
    expect($redacted['payment']['card_token'])->toBe('tok_abcd...');
});
