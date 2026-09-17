<?php

use App\Services\Html\DescriptionSanitizer;

it('preserva tags e texto permitidos', function () {
    $clean = DescriptionSanitizer::sanitize('<p>Frango <strong>bem temperado</strong> com <em>molho</em> especial.</p>');

    expect($clean)->toContain('<p>')
        ->toContain('<strong>bem temperado</strong>')
        ->toContain('<em>molho</em>');
});

it('remove tag não permitida mas preserva o conteúdo', function () {
    $clean = DescriptionSanitizer::sanitize('<script>alert(1)</script><p>Coxinha</p>');

    expect($clean)->not->toContain('<script')
        ->toContain('Coxinha');
});

it('remove atributo de evento sem aspas — bypass do sanitizador antigo', function () {
    $clean = DescriptionSanitizer::sanitize('<a onmouseover=alert(document.cookie)>oi</a>');

    expect($clean)->not->toContain('onmouseover')
        ->not->toContain('alert(');
});

it('remove atributo de evento com aspas simples ou duplas', function () {
    $clean = DescriptionSanitizer::sanitize('<a href="#" onclick="alert(1)">oi</a>');
    expect($clean)->not->toContain('onclick');

    $clean2 = DescriptionSanitizer::sanitize("<a href='#' onclick='alert(1)'>oi</a>");
    expect($clean2)->not->toContain('onclick');
});

it('remove href com scheme javascript:, inclusive com espaço antes do scheme — bypass do sanitizador antigo', function () {
    $clean = DescriptionSanitizer::sanitize('<a href="javascript:alert(1)">oi</a>');
    expect($clean)->not->toContain('javascript:');

    $clean2 = DescriptionSanitizer::sanitize('<a href=" javascript:alert(1)">oi</a>');
    expect($clean2)->not->toContain('javascript:');
});

it('mantém href com scheme seguro (http/https) e link relativo', function () {
    $clean = DescriptionSanitizer::sanitize('<a href="https://exemplo.com">site</a>');
    expect($clean)->toContain('href="https://exemplo.com"');

    $clean2 = DescriptionSanitizer::sanitize('<a href="/promocoes">promoções</a>');
    expect($clean2)->toContain('href="/promocoes"');
});

it('remove qualquer atributo fora da allowlist, mesmo em tag permitida', function () {
    $clean = DescriptionSanitizer::sanitize('<span style="position:fixed" class="x" data-foo="bar">texto</span>');

    expect($clean)->not->toContain('style=')
        ->not->toContain('class=')
        ->not->toContain('data-foo')
        ->toContain('texto');
});

it('converte quebra de linha simples em br', function () {
    $clean = DescriptionSanitizer::sanitize("Linha 1\nLinha 2");

    expect($clean)->toContain('Linha 1')
        ->toContain('Linha 2')
        ->toContain('<br');
});

it('retorna string vazia para entrada nula ou vazia', function () {
    expect(DescriptionSanitizer::sanitize(null))->toBe('');
    expect(DescriptionSanitizer::sanitize(''))->toBe('');
});
