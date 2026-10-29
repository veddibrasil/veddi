<?php

/*
 * Regras de SalesInsightsService::generate(). "Hoje" é quarta, 28/10/2026 12:00; o período padrão é
 * 01/10 a 28/10 (28 dias, sem extensão) e o anterior 03/09 a 30/09. Cada regra tem um cenário que
 * dispara e um logo abaixo do limiar que não dispara. Asserções em tipo, prioridade e atalhos
 * (actions), não no texto das mensagens.
 */

use App\DTOs\SalesInsight;
use App\Models\Product;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

require_once __DIR__.'/SoldItemsHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-10-28 12:00:00');
    Cache::flush();
});

afterEach(fn () => Carbon::setTestNow());

const SIR_FRIDAYS = ['2026-10-02', '2026-10-09', '2026-10-16', '2026-10-23'];

/** $units pedidos de 1 un. nas 4 sextas do período, ~60% às 19h e ~40% às 20h. */
function sirFridayPeak(array $ctx, Product|string $product, int $units, array $overrides = []): void
{
    for ($i = 0; $i < $units; $i++) {
        $time = $i % 5 < 3 ? '19:10:00' : '20:10:00';
        sirOrder($ctx, SIR_FRIDAYS[$i % 4].' '.$time, [[$product, 1]], $overrides);
    }
}

/**
 * Movimento típico de 2 pedidos/h às 19h e 20h todos os dias de 30/09 a 27/10 (28 dias) e
 * segunda 15h–17h com 1 pedido/h em $weakMondays das 4 segundas.
 */
function sirWeakHourScenario(array $ctx, Product $product, int $weakMondays, array $overrides = []): void
{
    for ($d = 0; $d < 28; $d++) {
        $day = CarbonImmutable::parse('2026-09-30')->addDays($d)->format('Y-m-d');
        foreach (['19:05:00', '19:35:00', '20:05:00', '20:35:00'] as $time) {
            sirOrder($ctx, "{$day} {$time}", [[$product, 1]], $overrides);
        }
    }

    foreach (array_slice(['2026-10-05', '2026-10-12', '2026-10-19', '2026-10-26'], 0, $weakMondays) as $day) {
        sirOrder($ctx, "{$day} 15:10:00", [[$product, 1]], $overrides);
        sirOrder($ctx, "{$day} 16:10:00", [[$product, 1]], $overrides);
    }
}

function sirCoupon(string $label, array $params): array
{
    return ['label' => $label, 'route' => 'admin.coupons.index', 'params' => $params];
}

// ─── Volume mínimo e janela ─────────────────────────────────────────────────

test('com menos de 20 pedidos não há sugestão (enough_data false)', function (int $orders, bool $enough) {
    $ctx = sirContext();
    $product = sirProduct($ctx['company'], $ctx['category'], 'Coxinha', 10.0, ['active' => false]);
    sirStock($ctx['branch'], sirProduct($ctx['company'], $ctx['category'], 'Parado', 10.0));
    for ($i = 0; $i < $orders; $i++) {
        sirOrder($ctx, sirSpreadAt($i), [[$product, 1]]);
    }

    $result = sirGenerate($ctx['company']);

    expect($result['enough_data'])->toBe($enough)
        ->and($result['orders'])->toBe($orders);

    if ($enough) {
        expect(sirTypes($result))->toContain('unavailable_champion', 'stale');
    } else {
        expect($result['insights'])->toBe([]);
    }
})->with([
    '19 pedidos' => [19, false],
    '20 pedidos' => [20, true],
]);

test('período curto é estendido para 28 dias até a mesma data final', function (string $start, string $end, bool $extended, string $windowStart) {
    $ctx = sirContext();

    $result = sirGenerate($ctx['company'], $start, $end);

    expect($result['extended'])->toBe($extended)
        ->and($result['window']->start->format('Y-m-d'))->toBe($windowStart)
        ->and($result['window']->end->format('Y-m-d H:i:s'))->toBe($end.' 23:59:59')
        ->and($result['window']->days())->toBeGreaterThanOrEqual(28);
})->with([
    '7 dias' => ['2026-10-22', '2026-10-28', true, '2026-10-01'],
    '13 dias' => ['2026-10-16', '2026-10-28', true, '2026-10-01'],
    '27 dias' => ['2026-10-02', '2026-10-28', true, '2026-10-01'],
    '28 dias' => ['2026-10-01', '2026-10-28', false, '2026-10-01'],
    '89 dias' => ['2026-08-01', '2026-10-28', false, '2026-08-01'],
]);

test('com período curto as regras olham a janela estendida', function () {
    $ctx = sirContext();
    $product = sirProduct($ctx['company'], $ctx['category'], 'Coxinha', 10.0, ['active' => false]);
    sirOrders($ctx, 25, fn ($i) => '2026-10-05 '.sprintf('%02d', 8 + $i % 12).':00:00', [[$product, 1]]);

    $result = sirGenerate($ctx['company'], '2026-10-22', '2026-10-28');

    expect($result['extended'])->toBeTrue()
        ->and($result['orders'])->toBe(25)
        ->and($result['enough_data'])->toBeTrue()
        ->and(sirFirst($result, 'unavailable_champion', $product))->not->toBeNull();
});

// ─── 1. champion ────────────────────────────────────────────────────────────

test('champion: um produto com 80%+ do faturamento e 30+ pedidos', function (int $orders, bool $fires) {
    $ctx = sirContext();
    $champion = sirProduct($ctx['company'], $ctx['category'], 'Coxinha Campeã', 40.0);
    $others = array_map(fn ($n) => sirProduct($ctx['company'], $ctx['category'], "Outro {$n}", 10.0), range(1, 4));

    for ($i = 0; $i < $orders; $i++) {
        $items = [[$champion, 1]];
        if ($i < 3) {
            foreach ($others as $other) {
                $items[] = [$other, 1];
            }
        }
        sirOrder($ctx, sirSpreadAt($i), $items);
    }

    $result = sirGenerate($ctx['company']);
    $insight = sirFirst($result, 'champion');

    expect($result['enough_data'])->toBeTrue();

    if (! $fires) {
        expect($insight)->toBeNull();

        return;
    }

    expect($insight)->not->toBeNull()
        ->and($insight->scope)->toBe('store')
        ->and($insight->priority)->toBe(SalesInsight::PRIORITY_OPPORTUNITY)
        ->and($insight->productKey)->toBeNull()
        ->and($insight->relatedKeys)->toBe([sirKey($champion)])
        ->and($insight->weight)->toEqual(40.0 * $orders)
        ->and($insight->actions)->toBe([['label' => 'Ver estoque', 'route' => 'admin.stock.index', 'params' => ['search' => 'Coxinha Campeã']]]);
})->with([
    '30 pedidos' => [30, true],
    '29 pedidos' => [29, false],
]);

test('champion: mix equilibrado não tem campeão', function () {
    $ctx = sirContext();
    $a = sirProduct($ctx['company'], $ctx['category'], 'Coxinha', 20.0);
    $b = sirProduct($ctx['company'], $ctx['category'], 'Kibe', 20.0);
    sirOrders($ctx, 30, fn ($i) => sirSpreadAt($i), [[$a, 1], [$b, 1]]);

    expect(sirFirst(sirGenerate($ctx['company']), 'champion'))->toBeNull();
});

// ─── 2. unavailable_champion ────────────────────────────────────────────────

test('unavailable_champion: campeão inativo, indisponível na filial ou sem estoque', function (string $reason) {
    $ctx = sirContext();
    sirBase($ctx);
    $top = sirProduct($ctx['company'], $ctx['category'], 'Coxinha Top', 50.0);
    sirOrders($ctx, 4, fn ($i) => '2026-10-2'.$i.' 12:00:00', [[$top, 1]]);

    match ($reason) {
        'inativo' => $top->update(['active' => false]),
        'indisponível na filial' => sirStock($ctx['branch'], $top, 10, trackStock: false, available: false),
        'estoque zerado' => sirStock($ctx['branch'], $top, 0, trackStock: true),
    };

    $insight = sirFirst(sirGenerate($ctx['company']), 'unavailable_champion', $top);

    expect($insight)->not->toBeNull()
        ->and($insight->scope)->toBe('product')
        ->and($insight->priority)->toBe(SalesInsight::PRIORITY_NOW)
        ->and($insight->productId)->toBe($top->id)
        ->and($insight->actions[0])->toBe(['label' => 'Editar produto', 'route' => 'admin.products.edit', 'params' => ['product' => $top->id]])
        ->and($insight->actions[1]['route'])->toBe('admin.stock.index')
        ->and($insight->actions[1]['params']['search'])->toBe('Coxinha Top');
})->with(['inativo', 'indisponível na filial', 'estoque zerado']);

test('unavailable_champion: campeão vendável não dispara', function (int $quantity, bool $trackStock) {
    $ctx = sirContext();
    sirBase($ctx);
    $top = sirProduct($ctx['company'], $ctx['category'], 'Coxinha Top', 50.0);
    sirOrders($ctx, 4, fn ($i) => '2026-10-2'.$i.' 12:00:00', [[$top, 1]]);
    sirStock($ctx['branch'], $top, $quantity, $trackStock);

    expect(sirFirst(sirGenerate($ctx['company']), 'unavailable_champion'))->toBeNull();
})->with([
    'com estoque' => [5, true],
    'sem controle de estoque' => [0, false],
]);

test('unavailable_champion: canal desligado com 30%+ das vendas nele', function (int $ifoodUnits, bool $fires) {
    $ctx = sirContext();
    sirBase($ctx);
    $top = sirProduct($ctx['company'], $ctx['category'], 'Coxinha Top', 50.0, ['available_in_ifood' => false]);
    for ($i = 0; $i < 10; $i++) {
        sirOrder($ctx, sirSpreadAt($i, '2026-10-15', 10), [[$top, 1]], ['channel' => $i < $ifoodUnits ? 'ifood' : 'chat']);
    }

    $insight = sirFirst(sirGenerate($ctx['company']), 'unavailable_champion', $top);

    if ($fires) {
        expect($insight)->not->toBeNull()
            ->and($insight->priority)->toBe(SalesInsight::PRIORITY_NOW);
    } else {
        expect($insight)->toBeNull();
    }
})->with([
    '3 de 10 pelo iFood (30%)' => [3, true],
    '2 de 10 pelo iFood (20%)' => [2, false],
]);

test('unavailable_champion: só olha os 5 de maior faturamento', function (int $units, bool $fires) {
    $ctx = sirContext();
    sirBase($ctx); // 3 produtos com R$ 70
    foreach (['G1', 'G2'] as $name) {
        $g = sirProduct($ctx['company'], $ctx['category'], $name, 10.0);
        sirOrders($ctx, 6, fn ($i) => sirSpreadAt($i, '2026-10-10', 10), [[$g, 1]]);
    }
    $inactive = sirProduct($ctx['company'], $ctx['category'], 'Inativo', 10.0, ['active' => false]);
    sirOrders($ctx, $units, fn ($i) => sirSpreadAt($i, '2026-10-12', 10), [[$inactive, 1]]);

    $insight = sirFirst(sirGenerate($ctx['company']), 'unavailable_champion', $inactive);

    expect($insight !== null)->toBe($fires);
})->with([
    '6º em faturamento (R$ 50)' => [5, false],
    '1º em faturamento (R$ 80)' => [8, true],
]);

// ─── 3. stock_running_out ───────────────────────────────────────────────────

test('stock_running_out: estoque cobre menos de 3 dias da média recente', function (int $units, int $stock, bool $fires) {
    $ctx = sirContext();
    sirBase($ctx);
    $product = sirProduct($ctx['company'], $ctx['category'], 'Coxinha Estoque', 10.0);
    sirStock($ctx['branch'], $product, $stock, trackStock: true);
    for ($i = 0; $i < $units; $i++) {
        sirOrder($ctx, CarbonImmutable::parse('2026-10-15 11:00:00')->addDays($i % 14)->format('Y-m-d H:i:s'), [[$product, 1]]);
    }

    $insight = sirFirst(sirGenerate($ctx['company']), 'stock_running_out', $product);

    if (! $fires) {
        expect($insight)->toBeNull();

        return;
    }

    expect($insight)->not->toBeNull()
        ->and($insight->priority)->toBe(SalesInsight::PRIORITY_NOW)
        ->and($insight->productId)->toBe($product->id)
        ->and($insight->actions)->toBe([[
            'label' => 'Repor estoque',
            'route' => 'admin.stock.index',
            'params' => ['search' => 'Coxinha Estoque', 'filial' => $ctx['branch']->id],
        ]]);
})->with([
    '1 un./dia, 2 em estoque' => [14, 2, true],
    '1 un./dia, 3 em estoque' => [14, 3, false],
    '0,5 un./dia, 1 em estoque' => [7, 1, true],
    '0,43 un./dia, 1 em estoque' => [6, 1, false],
]);

test('stock_running_out: período antigo não compara com o estoque de agora', function () {
    $ctx = sirContext();
    sirBase($ctx);
    $product = sirProduct($ctx['company'], $ctx['category'], 'Coxinha Estoque', 10.0);
    sirStock($ctx['branch'], $product, 1, trackStock: true);
    sirOrders($ctx, 14, fn ($i) => CarbonImmutable::parse('2026-10-07 11:00:00')->addDays($i)->format('Y-m-d H:i:s'), [[$product, 1]]);

    $recent = sirGenerate($ctx['company'], '2026-09-23', '2026-10-20');
    expect($recent['enough_data'])->toBeTrue()
        ->and(sirFirst($recent, 'stock_running_out'))->toBeNull();

    // Controle: terminando ontem, a regra roda.
    Cache::flush();
    Carbon::setTestNow('2026-10-21 12:00:00');
    expect(sirFirst(sirGenerate($ctx['company'], '2026-09-23', '2026-10-20'), 'stock_running_out', $product))->not->toBeNull();
});

test('stock_running_out: com filtro de filial só olha o estoque dela', function () {
    $ctx = sirContext();
    $other = sirBranch($ctx['company'], 'Filial Norte');
    sirBase($ctx);
    $product = sirProduct($ctx['company'], $ctx['category'], 'Coxinha Estoque', 10.0);
    sirStock($ctx['branch'], $product, 2, trackStock: true);
    sirStock($other, $product, 1, trackStock: true);
    sirOrders($ctx, 14, fn ($i) => CarbonImmutable::parse('2026-10-15 11:00:00')->addDays($i)->format('Y-m-d H:i:s'), [[$product, 1]]);

    $all = sirFind(sirGenerate($ctx['company']), 'stock_running_out', $product);
    // A outra filial não vendeu nada recentemente: média 0, sem alerta.
    expect($all)->toHaveCount(1)
        ->and($all[0]->actions[0]['params']['filial'])->toBe($ctx['branch']->id);

    Cache::flush();
    expect(sirFind(sirGenerate($ctx['company'], branchId: $other->id), 'stock_running_out'))->toBe([]);
});

test('stock_running_out: com filtro de canal a velocidade de venda considera todos os canais', function () {
    $ctx = sirContext();
    // 21 pedidos de base pelo iFood: com filtro "ifood" ainda há volume para sugerir.
    $filler = sirProduct($ctx['company'], $ctx['category'], 'Base iFood', 10.0);
    sirOrders($ctx, 21, fn ($i) => sirSpreadAt($i), [[$filler, 1]], ['channel' => 'ifood']);
    $product = sirProduct($ctx['company'], $ctx['category'], 'Coxinha Estoque', 10.0);
    sirStock($ctx['branch'], $product, 2, trackStock: true);
    sirOrders($ctx, 14, fn ($i) => CarbonImmutable::parse('2026-10-15 11:00:00')->addDays($i)->format('Y-m-d H:i:s'), [[$product, 1]]); // só chat

    $result = sirGenerate($ctx['company'], channel: 'ifood');

    expect($result['enough_data'])->toBeTrue()
        ->and(sirFirst($result, 'stock_running_out', $product))->not->toBeNull();
});

// ─── 4. peak_time ───────────────────────────────────────────────────────────

test('peak_time: produto com 20+ un. concentradas na sexta à noite', function (int $units, bool $fires) {
    $ctx = sirContext();
    $pizza = sirProduct($ctx['company'], $ctx['category'], 'Pizza de Sexta', 40.0);
    sirFridayPeak($ctx, $pizza, $units);
    sirOrder($ctx, '2026-10-14 10:00:00', [[sirProduct($ctx['company'], $ctx['category'], 'Água', 4.0), 1]]);

    $result = sirGenerate($ctx['company']);
    $insight = sirFirst($result, 'peak_time', $pizza);

    expect($result['enough_data'])->toBeTrue();

    if (! $fires) {
        expect($insight)->toBeNull();

        return;
    }

    // Bloco 19h–21h; divulgar 2h antes (17h) na próxima sexta (30/10).
    expect($insight)->not->toBeNull()
        ->and($insight->priority)->toBe(SalesInsight::PRIORITY_OPPORTUNITY)
        ->and($insight->productId)->toBe($pizza->id)
        ->and($insight->actions)->toHaveCount(1)
        ->and($insight->actions[0]['route'])->toBe('admin.coupons.index')
        ->and($insight->actions[0]['params'])->toEqual([
            'sugestao' => 1,
            'escopo' => 'product',
            'produtos' => (string) $pizza->id,
            'valor' => 10,
            'inicio' => '2026-10-30T17:00',
            'fim' => '2026-10-30T21:00',
            'nome' => 'Pico Pizza de Sexta',
            'codigo' => 'PICO'.$pizza->id,
        ]);
})->with([
    '20 un.' => [20, true],
    '19 un.' => [19, false],
]);

test('peak_time: janela de hoje que ainda não acabou começa agora; a que já acabou vai para a próxima semana', function (array $hours, string $inicio, string $fim) {
    $ctx = sirContext();
    $product = sirProduct($ctx['company'], $ctx['category'], 'Marmita', 20.0);
    $wednesdays = ['2026-10-07', '2026-10-14', '2026-10-21', '2026-10-28'];
    for ($i = 0; $i < 20; $i++) {
        // Hoje (28/10, 12h) só a primeira hora, para não gravar venda no futuro.
        $time = $i % 2 === 0 || $wednesdays[$i % 4] === '2026-10-28' ? $hours[0] : $hours[1];
        sirOrder($ctx, $wednesdays[$i % 4].' '.$time, [[$product, 1]]);
    }

    $insight = sirFirst(sirGenerate($ctx['company']), 'peak_time', $product);

    expect($insight)->not->toBeNull()
        ->and($insight->actions[0]['params']['inicio'])->toBe($inicio)
        ->and($insight->actions[0]['params']['fim'])->toBe($fim);
})->with([
    // Bloco 11h–13h, divulgar às 9h: hoje ainda vale até 13h, começa agora.
    'pico 11h–13h' => [['11:10:00', '12:10:00'], '2026-10-28T12:00', '2026-10-28T13:00'],
    // Bloco 9h–11h, divulgar às 7h: hoje já acabou, próxima quarta.
    'pico 9h–11h' => [['09:10:00', '10:10:00'], '2026-11-04T07:00', '2026-11-04T11:00'],
]);

test('peak_time: item avulso com pico vira sugestão sem atalho de cupom', function () {
    $ctx = sirContext();
    sirFridayPeak($ctx, 'Combo do Dia', 20, ['channel' => 'ifood']);

    $result = sirGenerate($ctx['company']);
    $peak = array_values(array_filter($result['insights'], fn (SalesInsight $i) => $i->type === 'peak_time'));

    expect($peak)->toHaveCount(1)
        ->and($peak[0]->productId)->toBeNull()
        ->and($peak[0]->productKey)->toBe('n:'.md5('combo do dia'))
        ->and($peak[0]->actions)->toBe([]);
});

// ─── 5. trending_up ─────────────────────────────────────────────────────────

test('trending_up: 15+ un. e alta de 30%+ sobre o período anterior', function (int $previous, int $current, bool $fires) {
    $ctx = sirContext();
    sirBase($ctx);
    $product = sirProduct($ctx['company'], $ctx['category'], 'Kibe da Casa', 9.0);
    sirOrder($ctx, '2026-09-15 12:00:00', [[$product, $previous]]);
    sirOrders($ctx, $current, fn ($i) => sirSpreadAt($i, '2026-10-10', 14), [[$product, 1]]);

    $insight = sirFirst(sirGenerate($ctx['company']), 'trending_up', $product);

    if (! $fires) {
        expect($insight)->toBeNull();

        return;
    }

    expect($insight)->not->toBeNull()
        ->and($insight->priority)->toBe(SalesInsight::PRIORITY_OPPORTUNITY)
        ->and($insight->productId)->toBe($product->id)
        ->and($insight->actions)->toBe([['label' => 'Ver estoque', 'route' => 'admin.stock.index', 'params' => ['search' => 'Kibe da Casa']]]);
})->with([
    '10 → 15 (+50%)' => [10, 15, true],
    '11 → 15 (+36%)' => [11, 15, true],
    '12 → 15 (+25%)' => [12, 15, false],
    '5 → 15' => [5, 15, true],
    '4 → 15 (anterior pequeno demais)' => [4, 15, false],
    '5 → 14 (atual pequeno demais)' => [5, 14, false],
]);

test('trending_up: lançamento dentro do período com 15+ un.', function (string $createdAt, bool $storeHadPreviousSales, bool $fires) {
    $ctx = sirContext();
    sirBase($ctx);
    if ($storeHadPreviousSales) {
        sirOrder($ctx, '2026-09-15 12:00:00', [[sirProduct($ctx['company'], $ctx['category'], 'Antigo', 10.0), 1]]);
    }
    $product = sirProduct($ctx['company'], $ctx['category'], 'Novidade', 9.0, [], $createdAt);
    sirOrders($ctx, 15, fn ($i) => sirSpreadAt($i, '2026-10-12', 14), [[$product, 1]]);

    $insight = sirFirst(sirGenerate($ctx['company']), 'trending_up', $product);

    expect($insight !== null)->toBe($fires);
})->with([
    'criado em 05/10' => ['2026-10-05 09:00:00', true, true],
    'criado em 01/10 00:00' => ['2026-10-01 00:00:00', true, true],
    'criado em 30/09 (antes do período, sem venda anterior)' => ['2026-09-30 23:00:00', true, false],
    // Loja nova (sem venda no período anterior): tudo seria "lançamento".
    'loja sem vendas no período anterior' => ['2026-10-05 09:00:00', false, false],
]);

// ─── 6. trending_down ───────────────────────────────────────────────────────

test('trending_down: queda de 30%+ sobre 15+ un. no período anterior', function (int $previous, int $current, bool $fires) {
    $ctx = sirContext();
    sirBase($ctx);
    $product = sirProduct($ctx['company'], $ctx['category'], 'Pastel de Palmito', 10.0);
    sirOrder($ctx, '2026-09-15 12:00:00', [[$product, $previous]]);
    if ($current > 0) {
        sirOrder($ctx, '2026-10-20 12:00:00', [[$product, $current]]);
    }

    $insight = sirFirst(sirGenerate($ctx['company']), 'trending_down', $product);

    if (! $fires) {
        expect($insight)->toBeNull();

        return;
    }

    expect($insight)->not->toBeNull()
        ->and($insight->priority)->toBe(SalesInsight::PRIORITY_NOW) // entre os 10 maiores do período anterior
        ->and($insight->productId)->toBe($product->id)
        ->and($insight->weight)->toEqual(10.0 * ($previous - $current))
        ->and($insight->actions)->toHaveCount(2)
        ->and($insight->actions[0]['label'])->toBe('Criar cupom de 10%')
        ->and($insight->actions[0]['route'])->toBe('admin.coupons.index')
        ->and($insight->actions[0]['params'])->toEqual([
            'sugestao' => 1,
            'escopo' => 'product',
            'produtos' => (string) $product->id,
            'valor' => 10,
            'inicio' => '2026-10-28T12:00',
            'fim' => '2026-11-04T23:59',
            'nome' => 'Volta Pastel de Palmito',
            'codigo' => 'VOLTA'.$product->id,
        ])
        ->and($insight->actions[1])->toBe(['label' => 'Editar produto', 'route' => 'admin.products.edit', 'params' => ['product' => $product->id]]);
})->with([
    '20 → 14 (-30%)' => [20, 14, true],
    '20 → 15 (-25%)' => [20, 15, false],
    '15 → 0' => [15, 0, true],
    '14 → 0 (anterior pequeno demais)' => [14, 0, false],
]);

test('trending_down: fora dos 10 maiores do período anterior é oportunidade, não urgência', function () {
    $ctx = sirContext();
    sirBase($ctx);
    foreach (range(1, 10) as $n) {
        $big = sirProduct($ctx['company'], $ctx['category'], "Grande {$n}", 10.0);
        sirOrder($ctx, '2026-09-10 12:00:00', [[$big, 100]]);
    }
    $product = sirProduct($ctx['company'], $ctx['category'], 'Pastel de Palmito', 10.0);
    sirOrder($ctx, '2026-09-15 12:00:00', [[$product, 20]]);
    sirOrder($ctx, '2026-10-20 12:00:00', [[$product, 14]]);

    $result = sirGenerate($ctx['company']);

    expect(sirFirst($result, 'trending_down', $product)?->priority)->toBe(SalesInsight::PRIORITY_OPPORTUNITY)
        ->and(sirFind($result, 'trending_down'))->toHaveCount(11);
});

test('trending_down: campeão fora de venda já tem a sugestão urgente e não repete a queda', function () {
    $ctx = sirContext();
    sirBase($ctx);
    $product = sirProduct($ctx['company'], $ctx['category'], 'Pastel de Palmito', 10.0);
    sirOrder($ctx, '2026-09-15 12:00:00', [[$product, 20]]);
    sirOrder($ctx, '2026-10-20 12:00:00', [[$product, 14]]);
    $product->update(['active' => false]);

    $result = sirGenerate($ctx['company']);

    expect(sirFirst($result, 'unavailable_champion', $product))->not->toBeNull()
        ->and(sirFirst($result, 'trending_down', $product))->toBeNull();
});

// ─── 7. stale ───────────────────────────────────────────────────────────────

test('stale: produto à venda sem vendas há 14 dias ou mais', function () {
    $ctx = sirContext();
    sirBase($ctx);
    $make = function (string $name, array $attributes = [], string $createdAt = '2026-01-01 00:00:00', bool $available = true) use ($ctx) {
        $product = sirProduct($ctx['company'], $ctx['category'], $name, 12.0, $attributes, $createdAt);
        sirStock($ctx['branch'], $product, 10, trackStock: false, available: $available);

        return $product;
    };

    $lastSaleBefore = $make('Vendeu 13/10 23:59');
    $lastSaleOnLimit = $make('Vendeu 14/10 00:00');
    $never = $make('Nunca vendeu');
    $promo = $make('Em promoção', ['promo_price_enabled' => true, 'promo_price_type' => 'fixed', 'promo_price_value' => 9.0]);
    $make('Lançado 20/10', [], '2026-10-20 00:00:00');
    $make('Indisponível na filial', [], '2026-01-01 00:00:00', false);
    $make('Inativo', ['active' => false]);
    $make('Fora de todos os canais', ['available_in_pdv' => false, 'available_in_delivery' => false, 'available_in_ifood' => false]);
    sirProduct($ctx['company'], $ctx['category'], 'Sem filial', 12.0);
    // Sem estoque é falta de reposição, não produto parado.
    $soldOut = sirProduct($ctx['company'], $ctx['category'], 'Esgotado', 12.0);
    sirStock($ctx['branch'], $soldOut, 0, trackStock: true);
    // Com estoque em outra filial ativa, continua parado.
    $otherBranch = sirBranch($ctx['company'], 'Filial Norte');
    $soldOutHere = sirProduct($ctx['company'], $ctx['category'], 'Esgotado só no Centro', 12.0);
    sirStock($ctx['branch'], $soldOutHere, 0, trackStock: true);
    sirStock($otherBranch, $soldOutHere, 4, trackStock: true);

    sirOrder($ctx, '2026-10-13 23:59:00', [[$lastSaleBefore, 1]]);
    sirOrder($ctx, '2026-10-14 00:00:00', [[$lastSaleOnLimit, 1]]);

    $result = sirGenerate($ctx['company']);
    $stale = sirFind($result, 'stale');

    expect(array_map(fn (SalesInsight $i) => $i->productId, $stale))->toEqualCanonicalizing([$lastSaleBefore->id, $never->id, $promo->id, $soldOutHere->id]);

    $insight = sirFirst($result, 'stale', $lastSaleBefore);
    expect($insight->scope)->toBe('product')
        ->and($insight->priority)->toBe(SalesInsight::PRIORITY_OPPORTUNITY)
        ->and($insight->actions)->toBe([['label' => 'Testar 15% de desconto', 'route' => 'admin.products.edit', 'params' => ['product' => $lastSaleBefore->id, 'promo' => 15]]]);

    // Já em promoção: o atalho só abre o produto.
    expect(sirFirst($result, 'stale', $promo)->actions)->toBe([['label' => 'Editar produto', 'route' => 'admin.products.edit', 'params' => ['product' => $promo->id]]]);
});

test('stale: período que terminou antes de ontem não acusa produto parado', function (string $end, bool $runs) {
    $ctx = sirContext();
    sirOrders($ctx, 25, fn ($i) => sirSpreadAt($i, '2026-09-25', 25), [[sirProduct($ctx['company'], $ctx['category'], 'Base', 10.0), 1]]);
    $product = sirProduct($ctx['company'], $ctx['category'], 'Parado', 12.0);
    sirStock($ctx['branch'], $product, 10, trackStock: false);

    $start = CarbonImmutable::parse($end)->subDays(27)->format('Y-m-d');
    $result = sirGenerate($ctx['company'], $start, $end);

    expect($result['enough_data'])->toBeTrue()
        ->and(sirFirst($result, 'stale', $product) !== null)->toBe($runs);
})->with([
    'até ontem' => ['2026-10-27', true],
    'até anteontem' => ['2026-10-26', false],
]);

test('stale: mais de 5 produtos parados viram uma sugestão só da loja', function (int $count, bool $grouped) {
    $ctx = sirContext();
    sirBase($ctx);
    $keys = [];
    for ($n = 1; $n <= $count; $n++) {
        $product = sirProduct($ctx['company'], $ctx['category'], "Parado {$n}", 12.0);
        sirStock($ctx['branch'], $product, 10, trackStock: false);
        $keys[] = sirKey($product);
    }

    $stale = sirFind(sirGenerate($ctx['company']), 'stale');

    if ($grouped) {
        expect($stale)->toHaveCount(1)
            ->and($stale[0]->scope)->toBe('store')
            ->and($stale[0]->productKey)->toBeNull()
            ->and($stale[0]->relatedKeys)->toEqualCanonicalizing($keys)
            ->and($stale[0]->actions)->toBe([['label' => 'Ver produtos', 'route' => 'admin.products.index', 'params' => []]]);
    } else {
        expect($stale)->toHaveCount($count)
            ->and(array_unique(array_map(fn (SalesInsight $i) => $i->scope, $stale)))->toBe(['product']);
    }
})->with([
    '6 parados' => [6, true],
    '5 parados' => [5, false],
]);

// ─── 8. slow_mover ──────────────────────────────────────────────────────────

test('slow_mover: entre os 20% que menos giram e abaixo de 0,3 un./dia', function (bool $tenProducts) {
    $ctx = sirContext();
    foreach (range(1, $tenProducts ? 8 : 7) as $n) {
        $product = sirProduct($ctx['company'], $ctx['category'], "Giro {$n}", 10.0);
        sirOrders($ctx, 3, fn ($i) => sirSpreadAt($n * 3 + $i), [[$product, 4]]);
    }
    $nine = sirProduct($ctx['company'], $ctx['category'], 'Pouco giro 9 un.', 10.0);
    sirOrder($ctx, '2026-10-12 12:00:00', [[$nine, 9]]);
    $eight = sirProduct($ctx['company'], $ctx['category'], 'Pouco giro 8 un.', 10.0);
    sirOrder($ctx, '2026-10-13 12:00:00', [[$eight, 8]]);

    $result = sirGenerate($ctx['company']);

    expect($result['enough_data'])->toBeTrue()
        // 9 un. em 28 dias = 0,32/dia: fica abaixo dos 20% mas acima de 0,3.
        ->and(sirFirst($result, 'slow_mover', $nine))->toBeNull();

    if (! $tenProducts) {
        expect(sirFind($result, 'slow_mover'))->toBe([]);

        return;
    }

    $insight = sirFirst($result, 'slow_mover', $eight);
    expect($insight)->not->toBeNull()
        ->and($insight->priority)->toBe(SalesInsight::PRIORITY_IDEA)
        ->and($insight->productId)->toBe($eight->id)
        ->and($insight->actions)->toHaveCount(1)
        ->and($insight->actions[0]['label'])->toBe('Criar cupom de 15%')
        ->and($insight->actions[0]['route'])->toBe('admin.coupons.index')
        ->and($insight->actions[0]['params'])->toEqual([
            'sugestao' => 1,
            'escopo' => 'product',
            'produtos' => (string) $eight->id,
            'valor' => 15,
            'inicio' => '2026-10-28T12:00',
            'fim' => '2026-11-04T23:59',
            'nome' => 'Experimente Pouco giro 8 un.',
            'codigo' => 'PROVA'.$eight->id,
        ])
        ->and(sirFind($result, 'slow_mover'))->toHaveCount(1);
})->with([
    '10 produtos vendidos' => [true],
    '9 produtos vendidos (mix pequeno demais)' => [false],
]);

// ─── 9. combo ───────────────────────────────────────────────────────────────

test('combo: par comprado junto em 8+ pedidos, bem acima do acaso', function (int $together, bool $fires) {
    $ctx = sirContext();
    sirBase($ctx);
    $burger = sirProduct($ctx['company'], $ctx['category'], 'X-Burguer', 25.0);
    $fries = sirProduct($ctx['company'], $ctx['category'], 'Batata Frita', 12.0);
    for ($i = 0; $i < 10; $i++) {
        $items = [[$burger, 1]];
        if ($i < $together) {
            $items[] = [$fries, 1];
        }
        sirOrder($ctx, sirSpreadAt($i, '2026-10-10', 18), $items);
    }

    $result = sirGenerate($ctx['company']);
    $combos = sirFind($result, 'combo');

    if (! $fires) {
        expect($combos)->toBe([]);

        return;
    }

    // Âncora = Batata (quem pede batata leva burger em 100%); par = X-Burguer.
    expect($combos)->toHaveCount(1);
    $combo = $combos[0];
    expect($combo->priority)->toBe(SalesInsight::PRIORITY_OPPORTUNITY)
        ->and($combo->productKey)->toBe(sirKey($fries))
        ->and($combo->relatedKeys)->toBe([sirKey($burger)])
        ->and($combo->concerns(sirKey($burger)))->toBeTrue()
        ->and($combo->actions)->toHaveCount(2)
        ->and($combo->actions[0]['route'])->toBe('admin.coupons.index')
        ->and($combo->actions[0]['params'])->toEqual([
            'sugestao' => 1,
            'escopo' => 'product',
            'produtos' => $fries->id.','.$burger->id,
            'valor' => 10,
            'inicio' => '2026-10-28T12:00',
            'fim' => '2026-11-11T23:59',
            'nome' => 'Combo Batata Frita + X-Burguer',
            'codigo' => 'COMBO'.$fries->id.'X'.$burger->id,
        ])
        ->and($combo->actions[1])->toBe(['label' => 'Criar produto combo', 'route' => 'admin.products.create', 'params' => []]);
})->with([
    '8 pedidos juntos' => [8, true],
    '7 pedidos juntos' => [7, false],
]);

test('combo: par presente em quase todo pedido não é combo (lift baixo)', function () {
    $ctx = sirContext();
    $burger = sirProduct($ctx['company'], $ctx['category'], 'X-Burguer', 25.0);
    $fries = sirProduct($ctx['company'], $ctx['category'], 'Batata Frita', 12.0);
    sirOrders($ctx, 20, fn ($i) => sirSpreadAt($i), [[$burger, 1], [$fries, 1]]);

    $result = sirGenerate($ctx['company']);

    expect($result['enough_data'])->toBeTrue()
        ->and(sirFind($result, 'combo'))->toBe([]);
});

// ─── 10. weak_hour ──────────────────────────────────────────────────────────

test('weak_hour: janela de 2h com movimento até 40% do típico', function (int $weakMondays, bool $fires) {
    $ctx = sirContext();
    $product = sirProduct($ctx['company'], $ctx['category'], 'Marmita', 10.0);
    sirWeakHourScenario($ctx, $product, $weakMondays);

    $result = sirGenerate($ctx['company'], '2026-09-30', '2026-10-27');
    $weak = sirFind($result, 'weak_hour');

    if (! $fires) {
        expect($weak)->toBe([]);

        return;
    }

    expect($weak)->toHaveCount(1)
        ->and($weak[0]->scope)->toBe('store')
        ->and($weak[0]->priority)->toBe(SalesInsight::PRIORITY_OPPORTUNITY)
        ->and($weak[0]->productKey)->toBeNull()
        ->and($weak[0]->actions)->toHaveCount(1)
        ->and($weak[0]->actions[0]['label'])->toBe('Criar promoção relâmpago')
        ->and($weak[0]->actions[0]['route'])->toBe('admin.coupons.index')
        // Próxima segunda (02/11) das 15h às 17h, cupom no pedido todo.
        ->and($weak[0]->actions[0]['params'])->toEqual([
            'sugestao' => 1,
            'escopo' => 'order',
            'valor' => 15,
            'inicio' => '2026-11-02T15:00',
            'fim' => '2026-11-02T17:00',
            'nome' => 'Relâmpago seg 15h',
            'codigo' => 'RELAMPAGOSEG15',
            'max_usos' => 50,
        ]);
})->with([
    '3 de 4 segundas (37,5% do típico)' => [3, true],
    '2 de 4 segundas (25% do típico)' => [2, true],
    '4 de 4 segundas (50% do típico)' => [4, false],
    '1 de 4 segundas (não é horário de funcionamento)' => [1, false],
]);

// ─── 11. promo_no_effect ────────────────────────────────────────────────────

test('promo_no_effect: preço promocional sem alta de mais de 5%', function (bool $promo, int $previous, int $current, bool $fires) {
    $ctx = sirContext();
    sirBase($ctx);
    $attributes = $promo ? ['promo_price_enabled' => true, 'promo_price_type' => 'fixed', 'promo_price_value' => 8.0] : [];
    $product = sirProduct($ctx['company'], $ctx['category'], 'Esfiha Promo', 10.0, $attributes);
    sirOrder($ctx, '2026-09-15 12:00:00', [[$product, $previous]]);
    sirOrder($ctx, '2026-10-20 12:00:00', [[$product, $current]]);

    $insight = sirFirst(sirGenerate($ctx['company']), 'promo_no_effect', $product);

    if (! $fires) {
        expect($insight)->toBeNull();

        return;
    }

    expect($insight)->not->toBeNull()
        ->and($insight->priority)->toBe(SalesInsight::PRIORITY_OPPORTUNITY)
        ->and($insight->productId)->toBe($product->id)
        ->and($insight->actions)->toBe([['label' => 'Editar produto', 'route' => 'admin.products.edit', 'params' => ['product' => $product->id]]]);
})->with([
    'promo, 10 → 10' => [true, 10, 10, true],
    'promo, 20 → 21 (+5%)' => [true, 20, 21, true],
    'promo, 10 → 11 (+10%)' => [true, 10, 11, false],
    'promo, 9 → 5 (anterior pequeno demais)' => [true, 9, 5, false],
    'sem promo, 10 → 10' => [false, 10, 10, false],
]);

// ─── 12. ifood_dependency ───────────────────────────────────────────────────

test('ifood_dependency (loja): 30+ pedidos e metade ou mais do faturamento pelo iFood', function (int $ifoodOrders, int $chatOrders, bool $fires) {
    $ctx = sirContext();
    $ifoodProduct = sirProduct($ctx['company'], $ctx['category'], 'Marmita iFood', 10.0);
    $chatProduct = sirProduct($ctx['company'], $ctx['category'], 'Marmita Chat', 10.0);
    sirOrders($ctx, $ifoodOrders, fn ($i) => sirSpreadAt($i), [[$ifoodProduct, 1]], ['channel' => 'ifood']);
    sirOrders($ctx, $chatOrders, fn ($i) => sirSpreadAt($i), [[$chatProduct, 1]]);

    $result = sirGenerate($ctx['company']);
    $store = sirFirst($result, 'ifood_dependency', scope: 'store');

    if (! $fires) {
        expect($store)->toBeNull();

        return;
    }

    expect($store)->not->toBeNull()
        ->and($store->priority)->toBe(SalesInsight::PRIORITY_OPPORTUNITY)
        ->and($store->weight)->toEqual(10.0 * $ifoodOrders)
        ->and($store->message)->toContain(route('chat.company', ['company' => $ctx['company']->slug]))
        ->and($store->actions)->toHaveCount(1)
        ->and($store->actions[0]['route'])->toBe('admin.coupons.index')
        ->and($store->actions[0]['params'])->toEqual([
            'sugestao' => 1,
            'escopo' => 'order',
            'valor' => 10,
            'inicio' => '2026-10-28T12:00',
            'fim' => '2026-11-27T23:59',
            'nome' => 'Primeiro pedido direto',
            'codigo' => 'PEDEDIRETO',
        ]);
})->with([
    '30 iFood + 10 chat (75%)' => [30, 10, true],
    '30 iFood + 30 chat (50%)' => [30, 30, true],
    '30 iFood + 31 chat (49%)' => [30, 31, false],
    '29 iFood + 10 chat (poucos pedidos)' => [29, 10, false],
]);

test('ifood_dependency (produto): 20+ un. com 70%+ pelo iFood', function (int $ifoodUnits, bool $fires) {
    $ctx = sirContext();
    sirBase($ctx);
    $product = sirProduct($ctx['company'], $ctx['category'], 'Açaí 500ml', 18.0);
    for ($i = 0; $i < 20; $i++) {
        sirOrder($ctx, sirSpreadAt($i, '2026-10-05', 20), [[$product, 1]], ['channel' => $i < $ifoodUnits ? 'ifood' : 'chat']);
    }

    $result = sirGenerate($ctx['company']);
    $insight = sirFirst($result, 'ifood_dependency', $product, 'product');

    expect(sirFirst($result, 'ifood_dependency', scope: 'store'))->toBeNull();

    if (! $fires) {
        expect($insight)->toBeNull();

        return;
    }

    expect($insight)->not->toBeNull()
        ->and($insight->priority)->toBe(SalesInsight::PRIORITY_IDEA)
        ->and($insight->productId)->toBe($product->id)
        ->and($insight->weight)->toEqual(360.0)
        ->and($insight->actions)->toBe([]);
})->with([
    '14 de 20 pelo iFood (70%)' => [14, true],
    '13 de 20 pelo iFood (65%)' => [13, false],
]);

test('ifood_dependency: com filtro de canal a regra não roda', function () {
    $ctx = sirContext();
    $product = sirProduct($ctx['company'], $ctx['category'], 'Marmita iFood', 10.0);
    sirOrders($ctx, 30, fn ($i) => sirSpreadAt($i), [[$product, 1]], ['channel' => 'ifood']);

    $unfiltered = sirGenerate($ctx['company']);
    $filtered = sirGenerate($ctx['company'], channel: 'ifood');

    expect(sirFind($unfiltered, 'ifood_dependency'))->toHaveCount(2)
        ->and($filtered['enough_data'])->toBeTrue()
        ->and(sirFind($filtered, 'ifood_dependency'))->toBe([]);
});

// ─── Filtro de categoria, ordenação e limites ───────────────────────────────

test('filtro de categoria desliga as regras da loja toda (campeões, horário fraco, iFood da loja)', function () {
    $ctx = sirContext();
    $product = sirProduct($ctx['company'], $ctx['category'], 'Marmita', 10.0);
    sirWeakHourScenario($ctx, $product, 3, ['channel' => 'ifood']);

    $store = sirGenerate($ctx['company'], '2026-09-30', '2026-10-27');
    expect(sirFirst($store, 'champion'))->not->toBeNull()
        ->and(sirFirst($store, 'weak_hour'))->not->toBeNull()
        ->and(sirFirst($store, 'ifood_dependency', scope: 'store'))->not->toBeNull();

    $byCategory = sirGenerate($ctx['company'], '2026-09-30', '2026-10-27', categoryId: $ctx['category']->id);
    expect($byCategory['enough_data'])->toBeTrue()
        ->and($byCategory['window']->categoryId)->toBe($ctx['category']->id)
        ->and(sirFirst($byCategory, 'champion'))->toBeNull()
        ->and(sirFirst($byCategory, 'weak_hour'))->toBeNull()
        ->and(sirFirst($byCategory, 'ifood_dependency', scope: 'store'))->toBeNull()
        // Regras de produto continuam valendo dentro da categoria.
        ->and(sirFirst($byCategory, 'ifood_dependency', $product, 'product'))->not->toBeNull();
});

test('sugestões vêm ordenadas por prioridade e, dentro dela, por peso', function () {
    $ctx = sirContext();
    sirBase($ctx);

    $inactive = sirProduct($ctx['company'], $ctx['category'], 'Campeão Inativo', 50.0);
    sirOrders($ctx, 4, fn ($i) => '2026-10-2'.$i.' 12:00:00', [[$inactive, 1]]);
    $inactive->update(['active' => false]);

    $falling = sirProduct($ctx['company'], $ctx['category'], 'Em Queda', 10.0);
    sirOrder($ctx, '2026-09-15 12:00:00', [[$falling, 20]]);
    sirOrder($ctx, '2026-10-20 12:00:00', [[$falling, 14]]);

    $rising = sirProduct($ctx['company'], $ctx['category'], 'Em Alta', 10.0);
    sirOrder($ctx, '2026-09-16 12:00:00', [[$rising, 10]]);
    sirOrders($ctx, 15, fn ($i) => sirSpreadAt($i, '2026-10-10', 14), [[$rising, 1]]);

    $ifood = sirProduct($ctx['company'], $ctx['category'], 'Só iFood', 10.0);
    for ($i = 0; $i < 20; $i++) {
        sirOrder($ctx, sirSpreadAt($i, '2026-10-05', 20), [[$ifood, 1]], ['channel' => $i < 14 ? 'ifood' : 'chat']);
    }

    $insights = sirGenerate($ctx['company'])['insights'];
    $index = fn (string $type, Product $product) => collect($insights)->search(fn (SalesInsight $i) => $i->type === $type && $i->productKey === sirKey($product));

    foreach (['unavailable_champion' => $inactive, 'trending_down' => $falling, 'trending_up' => $rising, 'ifood_dependency' => $ifood] as $type => $product) {
        expect($index($type, $product))->not->toBeFalse("{$type} não foi gerada");
    }

    expect($index('unavailable_champion', $inactive))->toBeLessThan($index('trending_down', $falling)) // NOW, peso 200 > 60
        ->and($index('trending_down', $falling))->toBeLessThan($index('trending_up', $rising))      // NOW antes de OPPORTUNITY
        ->and($index('trending_up', $rising))->toBeLessThan($index('ifood_dependency', $ifood))     // OPPORTUNITY antes de IDEA
        ->and($insights[0]->priority)->toBe(SalesInsight::PRIORITY_NOW);

    for ($i = 1; $i < count($insights); $i++) {
        [$previous, $current] = [$insights[$i - 1], $insights[$i]];
        expect($previous->priority < $current->priority || ($previous->priority === $current->priority && $previous->weight >= $current->weight))
            ->toBeTrue("posição {$i}: {$previous->type} ({$previous->priority}/{$previous->weight}) antes de {$current->type} ({$current->priority}/{$current->weight})");
    }
});

test('no máximo 2 sugestões por produto, ficando as mais prioritárias', function () {
    $ctx = sirContext();
    // Pizza vendida só pelo iFood às sextas à noite, dobrou sobre o período anterior e foi desligada no iFood.
    $pizza = sirProduct($ctx['company'], $ctx['category'], 'Pizza Só iFood', 40.0, ['available_in_ifood' => false]);
    sirFridayPeak($ctx, $pizza, 20, ['channel' => 'ifood']);
    sirOrder($ctx, '2026-09-18 19:30:00', [[$pizza, 10]], ['channel' => 'ifood']);

    config(['sales_insights.max_per_product' => 10]);
    $unlimited = array_map(fn (SalesInsight $i) => $i->type, array_filter(sirGenerate($ctx['company'])['insights'], fn (SalesInsight $i) => $i->productKey === sirKey($pizza)));
    expect($unlimited)->toEqualCanonicalizing(['unavailable_champion', 'peak_time', 'trending_up', 'ifood_dependency', 'catalog_presentation']);

    Cache::flush(); // generate() guarda o resultado por janela
    config(['sales_insights.max_per_product' => 2]);
    $limited = array_values(array_filter(sirGenerate($ctx['company'])['insights'], fn (SalesInsight $i) => $i->productKey === sirKey($pizza)));

    expect($limited)->toHaveCount(2)
        ->and($limited[0]->type)->toBe('unavailable_champion')
        ->and($limited[1]->priority)->toBe(SalesInsight::PRIORITY_OPPORTUNITY)
        ->and(array_map(fn (SalesInsight $i) => $i->type, $limited))->not->toContain('ifood_dependency');
});

test('no máximo 20 sugestões no total', function () {
    $ctx = sirContext();
    $filler = sirProduct($ctx['company'], $ctx['category'], 'Base', 10.0);
    sirOrders($ctx, 20, fn ($i) => sirSpreadAt($i), [[$filler, 1]]);
    foreach (range(1, 25) as $n) {
        $product = sirProduct($ctx['company'], $ctx['category'], "Sumiu {$n}", 10.0);
        sirOrder($ctx, sirSpreadAt($n, '2026-09-03', 28), [[$product, 15]]);
    }

    config(['sales_insights.max_total' => 100]);
    expect(sirFind(sirGenerate($ctx['company']), 'trending_down'))->toHaveCount(25);

    Cache::flush();
    config(['sales_insights.max_total' => 20]);
    $result = sirGenerate($ctx['company']);
    expect($result['insights'])->toHaveCount(20)
        // As 10 maiores do período anterior (urgentes) entram primeiro.
        ->and(count(array_filter($result['insights'], fn (SalesInsight $i) => $i->priority === SalesInsight::PRIORITY_NOW)))->toBe(10);
});

// ─── Cache das sugestões ────────────────────────────────────────────────────

test('sugestões ficam em cache por janela e forget() descarta', function () {
    $ctx = sirContext();
    $product = sirProduct($ctx['company'], $ctx['category'], 'Coxinha', 10.0, ['active' => false]);
    sirOrders($ctx, 20, fn ($i) => sirSpreadAt($i), [[$product, 1]]);

    $service = app(\App\Services\Sales\SalesInsightsService::class);
    $first = sirGenerate($ctx['company']);

    // Novos pedidos não aparecem enquanto o cache vale.
    sirOrders($ctx, 5, fn ($i) => sirSpreadAt($i, '2026-10-20', 5), [[$product, 1]]);
    app(\App\Services\Sales\SoldItemsReportService::class)->forget($ctx['company']->id, sirFilters($ctx['company']));
    expect(sirGenerate($ctx['company'])['orders'])->toBe(20);

    $service->forget($ctx['company']->id, sirFilters($ctx['company']));
    expect(sirGenerate($ctx['company'])['orders'])->toBe(25)
        ->and($first['orders'])->toBe(20);
});

test('filtros diferentes na mesma janela dividem o cache, mas "extended" é de cada filtro', function () {
    $ctx = sirContext();
    $product = sirProduct($ctx['company'], $ctx['category'], 'Coxinha', 10.0, ['active' => false]);
    sirOrders($ctx, 20, fn ($i) => sirSpreadAt($i), [[$product, 1]]);

    $short = sirGenerate($ctx['company'], '2026-10-22', '2026-10-28');
    $full = sirGenerate($ctx['company'], '2026-10-01', '2026-10-28');

    expect($short['extended'])->toBeTrue()
        ->and($full['extended'])->toBeFalse()
        ->and($short['insights'])->toEqual($full['insights'])
        ->and($short['window']->toArray())->toBe($full['window']->toArray());
});

test('cache das sugestões é separado por empresa', function () {
    $a = sirContext('A');
    $productA = sirProduct($a['company'], $a['category'], 'Coxinha A', 10.0, ['active' => false]);
    sirOrders($a, 20, fn ($i) => sirSpreadAt($i), [[$productA, 1]]);

    $b = sirContext('B');
    $productB = sirProduct($b['company'], $b['category'], 'Coxinha B', 10.0, ['active' => false]);
    sirOrders($b, 22, fn ($i) => sirSpreadAt($i), [[$productB, 1]]);

    $resultA = sirGenerate($a['company']);
    $resultB = sirGenerate($b['company']);

    expect($resultA['orders'])->toBe(20)
        ->and($resultB['orders'])->toBe(22)
        ->and(sirFirst($resultA, 'unavailable_champion', $productA))->not->toBeNull()
        ->and(sirFind($resultB, 'unavailable_champion', $productA))->toBe([])
        ->and(sirFirst($resultB, 'unavailable_champion', $productB))->not->toBeNull();
});
