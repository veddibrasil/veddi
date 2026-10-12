<?php

use App\Support\Printing\ThermalReceiptPaper;

// Fixture com N linhas de altura conhecida — o cupom real varia (itens, movimentações, produtos).
beforeEach(function () {
    view()->addNamespace('fixtures', dirname(__DIR__, 2).'/Fixtures/views');
});

/** @return array{width: float, height: float, pages: int} em pontos, lidos do PDF gerado. */
function thermalPdfInfo(int $lines, int $widthMm = 80): array
{
    $output = ThermalReceiptPaper::pdf('fixtures::thermal-probe', ['lines' => $lines], $widthMm)->output();

    // O DomPDF escreve "/MediaBox [0.000 0.000 226.772 74.000]"; "/Count N" aparece também no
    // outline (sempre 0), então o número de páginas é o maior /Count do arquivo.
    preg_match('#/MediaBox\s*\[\s*[\d.]+\s+[\d.]+\s+([\d.]+)\s+([\d.]+)\s*\]#', $output, $box);
    preg_match_all('#/Count\s+(\d+)#', $output, $counts);

    return ['width' => (float) $box[1], 'height' => (float) $box[2], 'pages' => (int) max($counts[1])];
}

const THERMAL_MAX_PT = 900 * 72 / 25.4; // 2551.18

test('a altura da página acompanha o conteúdo em vez de ficar fixa em 900 mm', function () {
    $short = thermalPdfInfo(5);

    // Antes: sempre 900 mm (2551 pt) — um metro de papel em branco depois do fim do cupom.
    expect($short['height'])->toBeLessThan(150.0);
    expect($short['height'])->toBeGreaterThan(40.0);
    expect($short['pages'])->toBe(1);
});

test('cupom maior gera página maior, sempre em uma página só', function () {
    $short = thermalPdfInfo(5);
    $medium = thermalPdfInfo(60);
    $long = thermalPdfInfo(200);

    expect($medium['height'])->toBeGreaterThan($short['height']);
    expect($long['height'])->toBeGreaterThan($medium['height']);
    expect($long['height'])->toBeLessThan(THERMAL_MAX_PT);

    foreach ([$short, $medium, $long] as $info) {
        expect($info['pages'])->toBe(1);
    }
});

test('a página termina logo depois do último elemento, com folga pequena', function () {
    $info = thermalPdfInfo(40);

    // 40 linhas de 9px (~10.4 pt cada) + padding do body: o excesso sobre o conteúdo é só a folga.
    $contentApprox = 40 * 10.4 + 2 * 7.5;
    expect($info['height'] - $contentApprox)->toBeLessThan(30.0);
});

test('respeita a largura do papel configurada na impressora', function () {
    expect(thermalPdfInfo(5, 80)['width'])->toEqualWithDelta(80 * 72 / 25.4, 0.5);
    expect(thermalPdfInfo(5, 58)['width'])->toEqualWithDelta(58 * 72 / 25.4, 0.5);
});

test('conteúdo maior que a página máxima mantém a altura máxima e pagina, sem cortar', function () {
    $info = thermalPdfInfo(1500);

    expect($info['height'])->toEqualWithDelta(THERMAL_MAX_PT, 0.5);
    expect($info['pages'])->toBeGreaterThan(1);
});
