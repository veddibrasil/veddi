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

it('sanitiza os filhos promovidos ao desembrulhar tag não permitida — bypass com <font><img onerror>', function (string $payload) {
    $clean = strtolower(DescriptionSanitizer::sanitize($payload));

    expect($clean)->not->toContain('onerror')
        ->not->toContain('onload')
        ->not->toContain('javascript:')
        ->not->toContain('<img')
        ->not->toContain('<svg');
})->with([
    'img dentro de font' => '<font><img src=x onerror=alert(document.domain)></font>',
    'svg dentro de tag desconhecida' => '<x><svg onload=alert(1)></svg></x>',
    'link javascript dentro de div' => '<div><a href="javascript:alert(1)">promo</a></div>',
    'aninhamento profundo de tags proibidas' => '<font><center><x><y><img src=x onerror=alert(1)></y></x></center></font>',
    'tag proibida dentro de tag permitida' => '<p><font><img src=x onerror=alert(1)></font></p>',
    'svg com filho que carrega handler' => '<svg><g><image href=x onerror=alert(1)></image></g></svg>',
]);

it('preserva o texto e as tags permitidas que estavam dentro da tag desembrulhada', function () {
    $clean = DescriptionSanitizer::sanitize('<font color="red">Promo <strong>hoje</strong> <a href="https://exemplo.com" onclick="x()">site</a></font>');

    expect($clean)->toContain('Promo')
        ->toContain('<strong>hoje</strong>')
        ->toContain('href="https://exemplo.com"')
        ->not->toContain('<font')
        ->not->toContain('color=')
        ->not->toContain('onclick');
});

it('descarta o conteúdo de script, style e afins em vez de promovê-lo a texto', function () {
    $clean = DescriptionSanitizer::sanitize('<p>Coxinha</p><script>alert(1)</script><style>body{display:none}</style><template><img src=x onerror=alert(2)></template><noscript><img src=x onerror=alert(3)></noscript>');

    expect($clean)->toContain('Coxinha')
        ->not->toContain('alert(')
        ->not->toContain('display:none')
        ->not->toContain('onerror');
});

it('remove comentários, inclusive os que ficam dentro de tag desembrulhada', function () {
    $clean = DescriptionSanitizer::sanitize('<font><!-- <img src=x onerror=alert(1)> -->texto</font>');

    expect($clean)->toContain('texto')
        ->not->toContain('<!--')
        ->not->toContain('onerror');
});

it('escapa markup que vira texto após o desembrulhar', function () {
    $clean = DescriptionSanitizer::sanitize('<font>&lt;img src=x onerror=alert(1)&gt;</font>');

    expect($clean)->not->toContain('<img')
        ->toContain('&lt;img');
});
