<?php

/*
 * Exportação CSV da tela de itens vendidos (SoldItems::exportCsv): BOM UTF-8, separador ";",
 * números no formato brasileiro, mesma busca/período da tela e proteção contra fórmula no Excel.
 */

use App\Livewire\Admin\Reports\SoldItems;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

require_once __DIR__.'/SoldItemsHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-10-28 12:00:00');
    Cache::flush();
});

afterEach(fn () => Carbon::setTestNow());

const SIR_CSV_HEADER = [
    'Produto', 'Categoria', 'Quantidade', 'Faturamento (R$)', '% do faturamento', 'Pedidos',
    'Preço médio (R$)', 'Qtd. período anterior', 'Variação (%)', 'Última venda',
];

/** Conteúdo bruto do download capturado pelo Livewire. */
function sirDownloadedContent(Testable $component): string
{
    return base64_decode((string) data_get($component->effects, 'download.content'));
}

/** @return list<list<string>> linhas do CSV (sem o BOM), cabeçalho incluso */
function sirCsvRows(string $content): array
{
    $content = str_starts_with($content, "\xEF\xBB\xBF") ? substr($content, 3) : $content;
    $lines = array_values(array_filter(explode("\n", $content), fn ($line) => $line !== ''));

    return array_map(fn ($line) => str_getcsv($line, ';', '"', ''), $lines);
}

/** Empresa com vendas de 20 e 21/10 e uma venda só no período anterior. */
function sirExportScenario(): array
{
    $ctx = sirContext();
    $doces = sirCategory($ctx['company'], 'Doces');
    $festa = sirProduct($ctx['company'], $ctx['category'], 'Festa 100 coxinhas', 1234.56);
    $acai = sirProduct($ctx['company'], $doces, 'Açaí 500ml', 20.0);
    $pastel = sirProduct($ctx['company'], $ctx['category'], 'Pastel', 6.0);

    sirOrder($ctx, '2026-10-20 10:00:00', [[$festa, 1]]);
    sirOrder($ctx, '2026-10-21 18:45:00', [[$acai, 3]]);
    sirOrder($ctx, '2026-09-15 12:00:00', [[$acai, 2], [$pastel, 4]]); // período anterior (30/08 a 28/09)

    // Outra empresa no mesmo período: nunca entra no arquivo.
    $other = sirContext('B');
    sirOrder($other, '2026-10-20 10:00:00', [[sirProduct($other['company'], $other['category'], 'Produto Secreto B', 99.0), 7]]);
    app()->instance('current.company', $ctx['company']);

    return $ctx + ['admin' => sirUser($ctx['company'])];
}

test('exporta CSV com BOM, ";" e números no formato brasileiro', function () {
    $ctx = sirExportScenario();

    $component = Livewire::actingAs($ctx['admin'])
        ->test(SoldItems::class)
        ->call('exportCsv')
        ->assertFileDownloaded('itens-vendidos-2026-09-29-a-2026-10-28.csv', contentType: 'text/csv; charset=UTF-8');

    $content = sirDownloadedContent($component);

    expect(substr($content, 0, 3))->toBe("\xEF\xBB\xBF")
        // fputcsv só põe aspas em campo com espaço/separador.
        ->and(strtok(substr($content, 3), "\n"))->toBe('Produto;Categoria;Quantidade;"Faturamento (R$)";"% do faturamento";Pedidos;"Preço médio (R$)";"Qtd. período anterior";"Variação (%)";"Última venda"')
        ->and($content)->not->toContain('Produto Secreto B');

    expect(sirCsvRows($content))->toBe([
        SIR_CSV_HEADER,
        ['Açaí 500ml', 'Doces', '3', '60,00', '4,6', '1', '20,00', '2', '50,0', '21/10/2026 18:45'],
        ['Festa 100 coxinhas', 'Salgados', '1', '1.234,56', '95,4', '1', '1.234,56', '0', '—', '20/10/2026 10:00'],
        ['Pastel', 'Salgados', '0', '0,00', '0,0', '0', '0,00', '4', '-100,0', '—'],
    ]);
});

test('exportação respeita a busca (sem acento e sem caixa)', function (string $search) {
    $ctx = sirExportScenario();

    $component = Livewire::actingAs($ctx['admin'])
        ->test(SoldItems::class)
        ->set('search', $search)
        ->call('exportCsv')
        ->assertFileDownloaded();

    $rows = sirCsvRows(sirDownloadedContent($component));

    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toBe(SIR_CSV_HEADER)
        ->and($rows[1][0])->toBe('Açaí 500ml');
})->with(['acai', 'AÇAÍ', '  açaí 500 ']);

test('exportação usa o período escolhido na tela', function () {
    $ctx = sirExportScenario();

    $component = Livewire::actingAs($ctx['admin'])
        ->test(SoldItems::class)
        ->set('dateStart', '2026-10-21')
        ->set('dateEnd', '2026-10-21')
        ->assertSet('period', 'personalizado')
        ->call('exportCsv')
        ->assertFileDownloaded('itens-vendidos-2026-10-21-a-2026-10-21.csv');

    $rows = sirCsvRows(sirDownloadedContent($component));

    // Festa vendeu só em 20/10 (o "período anterior" de um dia): aparece com 0 un. e -100%.
    expect(array_slice($rows, 1))->toBe([
        ['Açaí 500ml', 'Doces', '3', '60,00', '100,0', '1', '20,00', '0', '—', '21/10/2026 18:45'],
        ['Festa 100 coxinhas', 'Salgados', '0', '0,00', '0,0', '0', '0,00', '1', '-100,0', '—'],
    ]);
});

test('exportação respeita a ordenação escolhida', function () {
    $ctx = sirExportScenario();

    $component = Livewire::actingAs($ctx['admin'])
        ->test(SoldItems::class)
        ->set('sort', 'revenue')
        ->call('exportCsv');

    expect(array_column(array_slice(sirCsvRows(sirDownloadedContent($component)), 1), 0))
        ->toBe(['Festa 100 coxinhas', 'Açaí 500ml', 'Pastel']);
});

test('nome ou categoria que começa com = + - @ vira texto no Excel', function () {
    $ctx = sirContext();
    $formulaCategory = sirCategory($ctx['company'], '=Bebidas');
    $product = sirProduct($ctx['company'], $formulaCategory, 'Suco', 7.0);

    sirOrder($ctx, '2026-10-20 10:00:00', [
        ['=HYPERLINK("http://exemplo.test","clique")', 5, 1.0],
        ['+5511999990000', 4, 1.0],
        ['-Refri', 3, 1.0],
        ['@SUM(A1:A2)', 2, 1.0],
        [$product, 1],
    ], ['channel' => 'ifood']);

    $component = Livewire::actingAs(sirUser($ctx['company']))
        ->test(SoldItems::class)
        ->call('exportCsv');

    $rows = array_slice(sirCsvRows(sirDownloadedContent($component)), 1);

    expect(array_map(fn ($row) => [$row[0], $row[1]], $rows))->toBe([
        ["'=HYPERLINK(\"http://exemplo.test\",\"clique\")", 'Avulso iFood'],
        ["'+5511999990000", 'Avulso iFood'],
        ["'-Refri", 'Avulso iFood'],
        ["'@SUM(A1:A2)", 'Avulso iFood'],
        ['Suco', "'=Bebidas"],
    ]);
});

test('nome com ";" e aspas sai escapado e volta igual', function () {
    $ctx = sirContext();
    $product = sirProduct($ctx['company'], $ctx['category'], 'Pastel "especial"; queijo', 9.5);
    sirOrder($ctx, '2026-10-20 10:00:00', [[$product, 2]]);

    $component = Livewire::actingAs(sirUser($ctx['company']))
        ->test(SoldItems::class)
        ->call('exportCsv');

    $rows = sirCsvRows(sirDownloadedContent($component));

    expect($rows[1])->toHaveCount(10)
        ->and($rows[1][0])->toBe('Pastel "especial"; queijo')
        ->and($rows[1][3])->toBe('19,00');
});

test('exportar sem vendas gera só o cabeçalho', function () {
    $ctx = sirContext();

    $component = Livewire::actingAs(sirUser($ctx['company']))
        ->test(SoldItems::class)
        ->call('exportCsv')
        ->assertFileDownloaded();

    expect(sirCsvRows(sirDownloadedContent($component)))->toBe([SIR_CSV_HEADER]);
});

test('exportar não dispara aviso de depreciação do fputcsv (PHP 8.4+)', function () {
    $ctx = sirExportScenario();
    $deprecations = [];
    set_error_handler(function (int $errno, string $message) use (&$deprecations) {
        $deprecations[] = $message;

        return true;
    }, E_DEPRECATED | E_USER_DEPRECATED);

    try {
        Livewire::actingAs($ctx['admin'])->test(SoldItems::class)->call('exportCsv')->assertFileDownloaded();
    } finally {
        restore_error_handler();
    }

    expect(array_values(array_filter($deprecations, fn ($message) => str_contains($message, 'fputcsv'))))->toBe([]);
});
