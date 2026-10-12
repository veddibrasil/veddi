<?php

/**
 * O Embedded Signup carrega o SDK da Meta e abre iframes/popup em facebook.com: sem estas origens
 * na CSP o botão "Conectar WhatsApp" quebra em silêncio no navegador.
 */
test('a CSP libera o SDK e os frames do Embedded Signup da Meta', function () {
    $csp = (string) $this->get('/cadastro')->headers->get('Content-Security-Policy');

    $directive = fn (string $name) => collect(explode(';', $csp))
        ->map(fn ($part) => trim($part))
        ->first(fn ($part) => str_starts_with($part, $name.' '));

    expect($directive('script-src'))->toContain('https://connect.facebook.net')
        ->and($directive('frame-src'))->toContain('https://www.facebook.com')
        ->and($directive('frame-src'))->toContain('https://web.facebook.com')
        ->and($directive('child-src'))->toContain('https://www.facebook.com')
        ->and($directive('child-src'))->toContain('https://web.facebook.com')
        ->and($directive('connect-src'))->toContain('https://graph.facebook.com');
});

test('a CSP continua sem liberar origens além das necessárias para framing', function () {
    $csp = (string) $this->get('/cadastro')->headers->get('Content-Security-Policy');

    expect($csp)->toContain("frame-ancestors 'self'")
        ->and($csp)->toContain("object-src 'none'")
        ->and($csp)->not->toContain('frame-src *');
});
