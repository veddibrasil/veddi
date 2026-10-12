<?php

use App\Support\MoneyInput;

test('converte formatos de valor digitados pelo operador', function (mixed $input, ?float $expected) {
    expect(MoneyInput::parse($input))->toBe($expected);
})->with([
    'ponto decimal' => ['12.50', 12.5],
    'vírgula decimal' => ['12,5', 12.5],
    'inteiro' => ['20', 20.0],
    'milhar com vírgula decimal' => ['1.234,56', 1234.56],
    'milhar com ponto decimal' => ['1,234.56', 1234.56],
    'vários pontos de milhar' => ['1.234.567', 1234567.0],
    'prefixo de moeda e espaço' => ['R$ 12,50', 12.5],
    'negativo' => ['-50', -50.0],
    'float já numérico' => [7.25, 7.25],
    'vazio' => ['', null],
    'só espaços' => ['   ', null],
    'texto' => ['abc', null],
    'só sinal' => ['-', null],
    'nulo' => [null, null],
]);

test('toFloat trata vazio e inválido como zero', function () {
    expect(MoneyInput::toFloat(''))->toBe(0.0);
    expect(MoneyInput::toFloat('abc'))->toBe(0.0);
    expect(MoneyInput::toFloat('3,10'))->toBe(3.1);
});
