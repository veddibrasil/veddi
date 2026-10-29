<?php

/*
 * Agregado de itens vendidos (SoldItemsReportService::build): o que conta como venda, limites do
 * período, isolamento entre empresas, canais, filtros, avulsos, brindes, período anterior, mapa de
 * calor, pares, limites e cache.
 */

use App\DTOs\SoldItemsFilters;
use App\Livewire\Admin\Reports\SoldItems;
use App\Models\Order;
use App\Services\Sales\SoldItemsReportService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

require_once __DIR__.'/SoldItemsHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-10-28 12:00:00');
    Cache::flush();
});

afterEach(fn () => Carbon::setTestNow());

// ─── O que conta como venda ─────────────────────────────────────────────────

test('só pedidos com status confirmado contam', function () {
    $ctx = sirContext();
    $product = sirProduct($ctx['company'], $ctx['category'], 'Coxinha', 10.0);

    foreach (Order::CONFIRMED_STATUSES as $status) {
        sirOrder($ctx, '2026-10-20 12:00:00', [[$product, 1]], ['status' => $status]);
    }
    foreach (['pending', 'awaiting_payment', 'cancelled', 'refunded'] as $status) {
        sirOrder($ctx, '2026-10-20 12:00:00', [[$product, 100]], ['status' => $status]);
    }

    $report = sirReport($ctx['company']);

    expect($report['kpis']['orders'])->toBe(count(Order::CONFIRMED_STATUSES))
        ->and($report['kpis']['items'])->toBe(count(Order::CONFIRMED_STATUSES))
        ->and($report['kpis']['revenue'])->toEqual(60.0)
        ->and($report['products'][sirKey($product)]['qty'])->toBe(6);
});

test('comanda aberta não conta como venda', function () {
    $ctx = sirContext();
    $product = sirProduct($ctx['company'], $ctx['category'], 'Chopp', 12.0);

    sirOrder($ctx, '2026-10-20 20:00:00', [[$product, 2]], ['order_type' => 'pdv', 'is_open_tab' => false]);
    sirOrder($ctx, '2026-10-20 21:00:00', [[$product, 7]], ['order_type' => 'pdv', 'is_open_tab' => true]);

    $report = sirReport($ctx['company']);

    expect($report['kpis']['orders'])->toBe(1)
        ->and($report['products'][sirKey($product)]['qty'])->toBe(2);
});

test('limites do período: 00:00:00 do início e 23:59:59 do fim entram, um segundo fora não', function () {
    $ctx = sirContext();
    $product = sirProduct($ctx['company'], $ctx['category'], 'Coxinha', 10.0);

    sirOrder($ctx, '2026-10-10 00:00:00', [[$product, 1]]);
    sirOrder($ctx, '2026-10-20 23:59:59', [[$product, 2]]);
    sirOrder($ctx, '2026-10-09 23:59:59', [[$product, 4]]);
    sirOrder($ctx, '2026-10-21 00:00:00', [[$product, 8]]);

    $report = sirReport($ctx['company'], '2026-10-10', '2026-10-20');

    expect($report['kpis']['orders'])->toBe(2)
        ->and($report['kpis']['items'])->toBe(3)
        ->and($report['meta']['days'])->toBe(11)
        ->and(array_key_first($report['daily']))->toBe('2026-10-10')
        ->and(array_key_last($report['daily']))->toBe('2026-10-20')
        ->and($report['daily']['2026-10-10']['items'])->toBe(1)
        ->and($report['daily']['2026-10-20']['items'])->toBe(2)
        // O segundo antes do início é o último segundo do período anterior.
        ->and($report['products'][sirKey($product)]['prev_qty'])->toBe(4);
});

test('datas invertidas são trocadas', function () {
    $ctx = sirContext();

    $filters = SoldItemsFilters::fromInput($ctx['company']->id, '2026-10-20', '2026-10-10');

    expect($filters->start->format('Y-m-d H:i:s'))->toBe('2026-10-10 00:00:00')
        ->and($filters->end->format('Y-m-d H:i:s'))->toBe('2026-10-20 23:59:59')
        ->and($filters->days())->toBe(11);
});

test('período maior que 92 dias é limitado pela data final', function () {
    $ctx = sirContext();
    $product = sirProduct($ctx['company'], $ctx['category'], 'Coxinha', 10.0);

    $filters = SoldItemsFilters::fromInput($ctx['company']->id, '2026-01-01', '2026-10-28');

    expect($filters->days())->toBe(92)
        ->and($filters->start->format('Y-m-d'))->toBe('2026-07-29')
        ->and($filters->end->format('Y-m-d'))->toBe('2026-10-28');

    sirOrder($ctx, '2026-07-28 23:59:59', [[$product, 5]]);
    sirOrder($ctx, '2026-07-29 00:00:00', [[$product, 1]]);

    $report = sirReport($ctx['company'], '2026-01-01', '2026-10-28');

    expect($report['meta']['days'])->toBe(92)
        ->and($report['daily'])->toHaveCount(92)
        ->and($report['kpis']['items'])->toBe(1);
});

test('período de exatamente 92 dias não é cortado', function () {
    $ctx = sirContext();

    $filters = SoldItemsFilters::fromInput($ctx['company']->id, '2026-07-29', '2026-10-28');

    expect($filters->days())->toBe(92)
        ->and($filters->start->format('Y-m-d'))->toBe('2026-07-29');
});

test('datas inválidas caem no padrão de 30 dias até hoje', function () {
    $ctx = sirContext();

    $filters = SoldItemsFilters::fromInput($ctx['company']->id, 'ontem', '28/10/2026');

    expect($filters->start->format('Y-m-d'))->toBe('2026-09-29')
        ->and($filters->end->format('Y-m-d'))->toBe('2026-10-28')
        ->and($filters->includesToday())->toBeTrue();
});

// ─── Multiempresa ───────────────────────────────────────────────────────────

test('vendas de outra empresa nunca entram, nem com nomes e avulsos iguais', function () {
    $b = sirContext('B');
    $foreign = sirProduct($b['company'], $b['category'], 'Coxinha', 50.0);
    sirOrder($b, '2026-10-20 12:00:00', [[$foreign, 9], ['Refri Lata', 9, 6.0]]);
    sirOrder($b, '2026-10-21 12:00:00', [[$foreign, 9]]);
    sirOrder($b, '2026-09-20 12:00:00', [[$foreign, 9]]); // período anterior de B

    $a = sirContext('A');
    $own = sirProduct($a['company'], $a['category'], 'Coxinha', 10.0);
    sirOrder($a, '2026-10-20 12:00:00', [[$own, 1], ['Refri Lata', 1, 6.0]]);

    $report = sirReport($a['company']);

    expect($report['kpis']['orders'])->toBe(1)
        ->and($report['kpis']['items'])->toBe(2)
        ->and($report['kpis']['revenue'])->toEqual(16.0)
        ->and($report['previous_kpis']['orders'])->toBe(0)
        ->and(array_keys($report['products']))->toEqualCanonicalizing([sirKey($own), SoldItemsReportService::productKey(null, 'Refri Lata')])
        ->and($report['products'][SoldItemsReportService::productKey(null, 'Refri Lata')]['qty'])->toBe(1)
        ->and(array_keys($report['branches']))->toBe([$a['branch']->id]);

    $reportB = sirReport($b['company']);

    expect($reportB['kpis']['orders'])->toBe(2)
        ->and($reportB['products'][sirKey($foreign)]['qty'])->toBe(18);
});

test('item que aponta para produto de outra empresa não herda nome nem categoria dele', function () {
    $b = sirContext('B');
    $foreign = sirProduct($b['company'], $b['category'], 'Nome Secreto B', 50.0);

    $a = sirContext('A');
    // Linha corrompida/forjada: product_id de B num pedido de A.
    $orderId = sirOrder($a, '2026-10-20 12:00:00', []);
    DB::table('order_items')->insert([
        'order_id' => $orderId,
        'product_id' => $foreign->id,
        'product_name' => 'Nome no pedido',
        'unit_price' => 5,
        'quantity' => 1,
        'subtotal' => 5,
    ]);

    $report = sirReport($a['company']);
    $item = $report['products'][sirKey($foreign)];

    expect($item['name'])->toBe('Nome no pedido')
        ->and($item['category_id'])->toBeNull();

    expect(sirReport($a['company'], categoryId: $b['category']->id)['kpis']['orders'])->toBe(1); // filtro de B é ignorado
});

// ─── Canais e filtros ───────────────────────────────────────────────────────

test('canal: chat (entrega e retirada), PDV e iFood', function () {
    $ctx = sirContext();
    $product = sirProduct($ctx['company'], $ctx['category'], 'Coxinha', 10.0);

    sirOrder($ctx, '2026-10-20 12:00:00', [[$product, 1]], ['channel' => 'chat', 'order_type' => 'delivery']);
    sirOrder($ctx, '2026-10-20 12:10:00', [[$product, 2]], ['channel' => 'chat', 'order_type' => 'pickup']);
    sirOrder($ctx, '2026-10-20 12:20:00', [[$product, 3]], ['channel' => 'chat', 'order_type' => 'pdv']);
    sirOrder($ctx, '2026-10-20 12:30:00', [[$product, 4]], ['channel' => 'ifood', 'order_type' => 'delivery']);

    $report = sirReport($ctx['company']);

    expect($report['channels']['chat']['orders'])->toBe(2)
        ->and($report['channels']['chat']['items'])->toBe(3)
        ->and($report['channels']['pdv']['orders'])->toBe(1)
        ->and($report['channels']['pdv']['items'])->toBe(3)
        ->and($report['channels']['ifood']['orders'])->toBe(1)
        ->and($report['channels']['ifood']['items'])->toBe(4)
        ->and($report['products'][sirKey($product)]['channels'])->toBe(['chat' => 3, 'pdv' => 3, 'ifood' => 4]);

    expect(sirReport($ctx['company'], channel: 'chat')['kpis'])->toMatchArray(['orders' => 2, 'items' => 3])
        ->and(sirReport($ctx['company'], channel: 'pdv')['kpis'])->toMatchArray(['orders' => 1, 'items' => 3])
        ->and(sirReport($ctx['company'], channel: 'ifood')['kpis'])->toMatchArray(['orders' => 1, 'items' => 4])
        ->and(sirReport($ctx['company'], channel: 'whatsapp')['kpis'])->toMatchArray(['orders' => 4, 'items' => 10]);
});

test('channelOf: iFood tem precedência sobre PDV, o resto é chat', function () {
    expect(SoldItemsReportService::channelOf('ifood', 'pdv'))->toBe('ifood')
        ->and(SoldItemsReportService::channelOf('chat', 'pdv'))->toBe('pdv')
        ->and(SoldItemsReportService::channelOf(null, 'pdv'))->toBe('pdv')
        ->and(SoldItemsReportService::channelOf('chat', 'pickup'))->toBe('chat')
        ->and(SoldItemsReportService::channelOf(null, null))->toBe('chat');
});

test('filtro de filial', function () {
    $ctx = sirContext();
    $other = sirBranch($ctx['company'], 'Filial Zona 7');
    $product = sirProduct($ctx['company'], $ctx['category'], 'Coxinha', 10.0);

    sirOrder($ctx, '2026-10-20 12:00:00', [[$product, 1]]);
    sirOrder($ctx, '2026-10-20 13:00:00', [[$product, 5]], ['branch_id' => $other->id]);

    $all = sirReport($ctx['company']);
    $filtered = sirReport($ctx['company'], branchId: $other->id);

    expect($all['kpis']['items'])->toBe(6)
        ->and($all['branches'][$ctx['branch']->id])->toMatchArray(['name' => 'Filial Centro', 'orders' => 1, 'items' => 1])
        ->and($all['branches'][$other->id])->toMatchArray(['name' => 'Filial Zona 7', 'orders' => 1, 'items' => 5])
        ->and($filtered['kpis']['items'])->toBe(5)
        ->and(array_keys($filtered['branches']))->toBe([$other->id]);
});

test('filtro de categoria deixa de fora os itens avulsos', function () {
    $ctx = sirContext();
    $drinks = sirCategory($ctx['company'], 'Bebidas');
    $coxinha = sirProduct($ctx['company'], $ctx['category'], 'Coxinha', 10.0);
    $suco = sirProduct($ctx['company'], $drinks, 'Suco', 7.0);

    sirOrder($ctx, '2026-10-20 12:00:00', [[$coxinha, 2], [$suco, 1], ['Item avulso iFood', 3, 5.0]]);

    $all = sirReport($ctx['company']);
    $filtered = sirReport($ctx['company'], categoryId: $ctx['category']->id);

    expect($all['kpis']['items'])->toBe(6)
        ->and($all['categories'][$ctx['category']->id])->toMatchArray(['name' => 'Salgados', 'items' => 2])
        ->and($all['categories'][$drinks->id])->toMatchArray(['name' => 'Bebidas', 'items' => 1])
        ->and($all['categories'][0])->toMatchArray(['name' => 'Sem categoria', 'items' => 3])
        ->and($filtered['kpis']['items'])->toBe(2)
        ->and(array_keys($filtered['products']))->toBe([sirKey($coxinha)]);
});

// ─── Avulsos, brindes e excluídos ───────────────────────────────────────────

test('avulsos com o nome em caixa e espaços diferentes viram um item só', function () {
    $ctx = sirContext();

    sirOrder($ctx, '2026-10-20 12:00:00', [['Açaí 500ml', 1, 20.0]], ['channel' => 'ifood']);
    sirOrder($ctx, '2026-10-21 12:00:00', [['  AÇAÍ 500ML ', 2, 20.0]], ['channel' => 'ifood']);
    sirOrder($ctx, '2026-10-22 12:00:00', [['açaí 500ml', 3, 20.0]], ['channel' => 'ifood']);
    sirOrder($ctx, '2026-10-22 13:00:00', [['Açaí 300ml', 1, 15.0]], ['channel' => 'ifood']);

    $report = sirReport($ctx['company']);
    $key = 'n:'.md5('açaí 500ml');

    expect(SoldItemsReportService::productKey(null, '  AÇAÍ 500ML '))->toBe($key)
        ->and(SoldItemsReportService::productKey(7, 'qualquer'))->toBe('p:7')
        ->and($report['products'])->toHaveCount(2)
        ->and($report['products'][$key]['qty'])->toBe(6)
        ->and($report['products'][$key]['orders'])->toBe(3)
        ->and($report['products'][$key]['product_id'])->toBeNull()
        ->and($report['products'][$key]['revenue'])->toEqual(120.0);
});

test('brinde (subtotal zero) conta em free_items e não em quantidade nem faturamento', function () {
    $ctx = sirContext();
    $coxinha = sirProduct($ctx['company'], $ctx['category'], 'Coxinha', 10.0);
    $refri = sirProduct($ctx['company'], $ctx['category'], 'Refri brinde', 5.0);
    $bala = sirProduct($ctx['company'], $ctx['category'], 'Bala', 1.0);

    sirOrder($ctx, '2026-10-20 12:00:00', [[$coxinha, 2], [$refri, 1, 0.0]]);
    sirOrder($ctx, '2026-10-20 13:00:00', [[$refri, 1, 0.0]]);        // pedido só com brinde
    sirOrder($ctx, '2026-10-21 13:00:00', [[$coxinha, 1, 0.0], [$coxinha, 1]]);
    sirOrder($ctx, '2026-10-21 14:00:00', [[$bala, 4, 0.0]]);          // só brinde no período

    $report = sirReport($ctx['company']);

    expect($report['kpis'])->toMatchArray(['orders' => 2, 'items' => 3, 'free_items' => 7, 'revenue' => 30.0, 'products' => 1])
        ->and($report['products'][sirKey($coxinha)])->toMatchArray(['qty' => 3, 'free_qty' => 1, 'orders' => 2, 'revenue' => 30.0])
        ->and($report['products'])->not->toHaveKey(sirKey($refri))
        ->and($report['products'])->not->toHaveKey(sirKey($bala))
        ->and($report['pairs'])->toBe([]);
});

test('brinde no período de produto que só vendeu no período anterior mantém free_qty', function () {
    $ctx = sirContext();
    $refri = sirProduct($ctx['company'], $ctx['category'], 'Refri', 5.0);

    sirOrder($ctx, '2026-09-20 12:00:00', [[$refri, 3]]);          // venda no período anterior
    sirOrder($ctx, '2026-10-20 12:00:00', [[$refri, 2, 0.0]]);     // só brinde no período

    $report = sirReport($ctx['company']);

    expect($report['kpis']['free_items'])->toBe(2)
        ->and($report['products'][sirKey($refri)])->toMatchArray(['qty' => 0, 'prev_qty' => 3])
        // O total de brindes do KPI tem que bater com a soma por produto.
        ->and($report['products'][sirKey($refri)]['free_qty'])->toBe(2);
});

test('produto excluído (soft delete) continua no relatório marcado como excluído', function () {
    $ctx = sirContext();
    $product = sirProduct($ctx['company'], $ctx['category'], 'Coxinha antiga', 10.0);
    sirOrder($ctx, '2026-10-20 12:00:00', [[$product, 2]]);
    sirOrder($ctx, '2026-09-20 12:00:00', [[$product, 1]]);
    $product->delete();

    $report = sirReport($ctx['company']);
    $item = $report['products'][sirKey($product)];

    expect($item)->toMatchArray(['name' => 'Coxinha antiga', 'deleted' => true, 'qty' => 2, 'prev_qty' => 1, 'category_id' => $ctx['category']->id]);

    $catalog = app(SoldItemsReportService::class)->catalog($ctx['company']->id, [$product->id]);
    expect($catalog->get($product->id)?->trashed())->toBeTrue();

    $catalogWithoutSales = app(SoldItemsReportService::class)->catalog($ctx['company']->id, []);
    expect($catalogWithoutSales->has($product->id))->toBeFalse();

    // onlySold: só os produtos vendidos (excluídos inclusive), sem o resto do catálogo.
    $unsold = sirProduct($ctx['company'], $ctx['category'], 'Nunca vendido', 10.0);
    $onlySold = app(SoldItemsReportService::class)->catalog($ctx['company']->id, [$product->id], true);
    expect($onlySold->keys()->all())->toBe([$product->id])
        ->and(app(SoldItemsReportService::class)->catalog($ctx['company']->id, [], true))->toHaveCount(0)
        ->and(app(SoldItemsReportService::class)->catalog($ctx['company']->id, [$product->id])->has($unsold->id))->toBeTrue();
});

// ─── Período anterior ───────────────────────────────────────────────────────

test('período anterior: prev_qty por produto e previous_kpis', function () {
    $ctx = sirContext();
    $coxinha = sirProduct($ctx['company'], $ctx['category'], 'Coxinha', 10.0);
    $kibe = sirProduct($ctx['company'], $ctx['category'], 'Kibe', 8.0);

    // Período fechado: 01/10 a 14/10 (14 dias) → anterior: 17/09 00:00 a 30/09 23:59:59.
    sirOrder($ctx, '2026-10-01 00:00:00', [[$coxinha, 5]]);
    sirOrder($ctx, '2026-09-17 00:00:00', [[$coxinha, 3], [$kibe, 1, 0.0]]);
    sirOrder($ctx, '2026-09-30 23:59:59', [[$kibe, 2]]);
    sirOrder($ctx, '2026-09-16 23:59:59', [[$kibe, 50]]); // antes do período anterior
    sirOrder($ctx, '2026-09-20 12:00:00', [[$kibe, 50]], ['status' => 'cancelled']);

    $filters = sirFilters($ctx['company'], '2026-10-01', '2026-10-14');
    expect($filters->previous()->start->format('Y-m-d H:i:s'))->toBe('2026-09-17 00:00:00')
        ->and($filters->previous()->end->format('Y-m-d H:i:s'))->toBe('2026-09-30 23:59:59');

    $report = sirReport($ctx['company'], '2026-10-01', '2026-10-14');

    expect($report['products'][sirKey($coxinha)])->toMatchArray(['qty' => 5, 'prev_qty' => 3, 'prev_revenue' => 30.0])
        ->and($report['products'][sirKey($kibe)])->toMatchArray(['qty' => 0, 'prev_qty' => 2, 'prev_revenue' => 16.0, 'name' => 'Kibe'])
        ->and($report['kpis'])->toMatchArray(['orders' => 1, 'items' => 5, 'products' => 1])
        ->and($report['previous_kpis'])->toMatchArray(['orders' => 2, 'items' => 5, 'revenue' => 46.0, 'products' => 2])
        ->and($report['previous_kpis']['ticket'])->toEqual(23.0);
});

test('período que inclui hoje compara com o anterior até o mesmo horário', function () {
    $ctx = sirContext();
    $coxinha = sirProduct($ctx['company'], $ctx['category'], 'Coxinha', 10.0);

    // Hoje = 28/10 12:00; período 15/10 a 28/10 → anterior: 01/10 00:00 a 14/10 12:00.
    sirOrder($ctx, '2026-10-20 12:00:00', [[$coxinha, 1]]);
    sirOrder($ctx, '2026-10-01 00:00:00', [[$coxinha, 1]]);
    sirOrder($ctx, '2026-10-14 11:59:59', [[$coxinha, 2]]);
    sirOrder($ctx, '2026-10-14 12:00:01', [[$coxinha, 40]]);

    $previous = sirFilters($ctx['company'], '2026-10-15', '2026-10-28')->previous();
    expect($previous->start->format('Y-m-d H:i:s'))->toBe('2026-10-01 00:00:00')
        ->and($previous->end->format('Y-m-d H:i:s'))->toBe('2026-10-14 12:00:00');

    $report = sirReport($ctx['company'], '2026-10-15', '2026-10-28');

    expect($report['products'][sirKey($coxinha)]['prev_qty'])->toBe(3)
        ->and($report['previous_kpis']['orders'])->toBe(2);
});

// ─── Hora, dia da semana e pares ────────────────────────────────────────────

test('pedido de sexta às 19:30 cai em heatmap[5][19] e hourly[19]', function () {
    $ctx = sirContext();
    $product = sirProduct($ctx['company'], $ctx['category'], 'Pizza', 40.0);

    expect(CarbonImmutable::parse('2026-10-23')->dayOfWeekIso)->toBe(5);

    sirOrder($ctx, '2026-10-23 19:30:00', [[$product, 2]]);

    $report = sirReport($ctx['company']);
    $item = $report['products'][sirKey($product)];

    expect($report['heatmap'][5][19])->toBe(1)
        ->and($report['heatmap_days'][5][19])->toBe(1)
        ->and(array_sum(array_map('array_sum', $report['heatmap'])))->toBe(1)
        ->and($report['hourly'][19])->toMatchArray(['orders' => 1, 'items' => 2, 'revenue' => 80.0])
        ->and($report['hourly'][18]['orders'])->toBe(0)
        ->and($item['hours'][19])->toBe(2)
        ->and($item['weekdays'][5])->toBe(2)
        ->and($item['daily'])->toBe(['2026-10-23' => 2])
        ->and($report['meta']['weekday_occurrences'][5])->toBe(4);
});

test('heatmap_days conta datas distintas, heatmap conta pedidos', function () {
    $ctx = sirContext();
    $product = sirProduct($ctx['company'], $ctx['category'], 'Pizza', 40.0);

    sirOrder($ctx, '2026-10-23 19:05:00', [[$product, 1]]);
    sirOrder($ctx, '2026-10-23 19:45:00', [[$product, 1]]);
    sirOrder($ctx, '2026-10-16 19:10:00', [[$product, 1]]);

    $report = sirReport($ctx['company']);

    expect($report['heatmap'][5][19])->toBe(3)
        ->and($report['heatmap_days'][5][19])->toBe(2);
});

test('par conta uma vez por pedido, mesmo com quantidade > 1 ou linha repetida', function () {
    $ctx = sirContext();
    $a = sirProduct($ctx['company'], $ctx['category'], 'Coxinha', 10.0);
    $b = sirProduct($ctx['company'], $ctx['category'], 'Refri', 6.0);

    sirOrder($ctx, '2026-10-20 12:00:00', [[$a, 3], [$b, 2], [$a, 1]]);
    sirOrder($ctx, '2026-10-21 12:00:00', [[$a, 1], [$b, 1]]);

    $report = sirReport($ctx['company']);
    $keys = [sirKey($a), sirKey($b)];
    sort($keys);

    expect($report['pairs'])->toBe([['a' => $keys[0], 'b' => $keys[1], 'count' => 2]])
        ->and($report['products'][sirKey($a)])->toMatchArray(['qty' => 5, 'orders' => 2]);
});

test('par que aparece em um pedido só fica abaixo do suporte mínimo', function () {
    $ctx = sirContext();
    $a = sirProduct($ctx['company'], $ctx['category'], 'Coxinha', 10.0);
    $b = sirProduct($ctx['company'], $ctx['category'], 'Refri', 6.0);

    sirOrder($ctx, '2026-10-20 12:00:00', [[$a, 1], [$b, 1]]);

    expect(sirReport($ctx['company'])['pairs'])->toBe([]);
});

test('pedido com mais de 12 produtos distintos não gera pares; com 12 gera', function (int $distinct, int $expectedPairs) {
    $ctx = sirContext();
    $products = [];
    for ($i = 1; $i <= $distinct; $i++) {
        $products[] = [sirProduct($ctx['company'], $ctx['category'], "Item {$i}", 5.0), 1];
    }

    sirOrder($ctx, '2026-10-20 12:00:00', $products);
    sirOrder($ctx, '2026-10-21 12:00:00', $products);

    $report = sirReport($ctx['company']);

    expect($report['pairs'])->toHaveCount($expectedPairs);
    if ($expectedPairs > 0) {
        expect(array_unique(array_column($report['pairs'], 'count')))->toBe([2]);
    }
})->with([
    '13 produtos' => [13, 0],
    '12 produtos' => [12, 66],
]);

// ─── Cache ──────────────────────────────────────────────────────────────────

test('segunda chamada de build() vem do cache, sem consultar o banco', function () {
    $ctx = sirContext();
    $product = sirProduct($ctx['company'], $ctx['category'], 'Coxinha', 10.0);
    sirOrder($ctx, '2026-10-20 12:00:00', [[$product, 2]]);

    $service = app(SoldItemsReportService::class);
    $filters = sirFilters($ctx['company']);

    DB::enableQueryLog();
    $first = $service->build($ctx['company']->id, $filters);
    $firstQueries = count(DB::getQueryLog());

    DB::flushQueryLog();
    sirOrder($ctx, '2026-10-21 12:00:00', [[$product, 5]]);
    DB::flushQueryLog();

    $second = $service->build($ctx['company']->id, $filters);

    expect($firstQueries)->toBeGreaterThan(0)
        ->and(DB::getQueryLog())->toHaveCount(0)
        ->and($second)->toBe($first)
        ->and($second['kpis']['items'])->toBe(2);

    // forget() descarta e a próxima chamada recalcula.
    $service->forget($ctx['company']->id, $filters);
    expect($service->build($ctx['company']->id, $filters)['kpis']['items'])->toBe(7);
    DB::disableQueryLog();
});

test('botão atualizar da tela descarta o cache do relatório e das sugestões', function () {
    $ctx = sirContext();
    $product = sirProduct($ctx['company'], $ctx['category'], 'Coxinha', 10.0, ['active' => false]);
    sirOrders($ctx, 20, fn ($i) => sirSpreadAt($i, '2026-09-29', 30), [[$product, 1]]);

    $component = Livewire::actingAs(sirUser($ctx['company']))->test(SoldItems::class);
    expect($component->instance()->report['kpis']['orders'])->toBe(20)
        ->and($component->instance()->insights['orders'])->toBe(20);

    sirOrders($ctx, 3, fn ($i) => '2026-10-27 1'.$i.':00:00', [[$product, 1]]);

    // Ainda em cache numa nova requisição.
    $component->set('search', 'cox');
    expect($component->instance()->report['kpis']['orders'])->toBe(20);

    $component->call('refresh');
    expect($component->instance()->report['kpis']['orders'])->toBe(23)
        ->and($component->instance()->insights['orders'])->toBe(23);
});

test('chave de cache muda por empresa e por filtro', function () {
    $a = sirContext('A');
    $productA = sirProduct($a['company'], $a['category'], 'Coxinha', 10.0);
    sirOrder($a, '2026-10-20 12:00:00', [[$productA, 2]]);

    $b = sirContext('B');
    $productB = sirProduct($b['company'], $b['category'], 'Coxinha', 10.0);
    sirOrder($b, '2026-10-20 12:00:00', [[$productB, 9]]);

    $filters = SoldItemsFilters::fromInput($a['company']->id, '2026-10-01', '2026-10-28');

    expect($filters->cacheKey($a['company']->id))->not->toBe($filters->cacheKey($b['company']->id))
        ->and($filters->cacheKey($a['company']->id))->not->toBe(SoldItemsFilters::fromInput($a['company']->id, '2026-10-01', '2026-10-28', null, 'pdv')->cacheKey($a['company']->id));

    $service = app(SoldItemsReportService::class);
    expect($service->build($a['company']->id, $filters)['kpis']['items'])->toBe(2)
        ->and($service->build($b['company']->id, $filters)['kpis']['items'])->toBe(9);
});

// ─── Última venda (regra de produto parado) ─────────────────────────────────

test('lastSaleDates traz a última venda confirmada de cada produto dentro da janela', function () {
    $ctx = sirContext();
    $other = sirBranch($ctx['company'], 'Filial 2');
    $a = sirProduct($ctx['company'], $ctx['category'], 'A', 10.0);
    $b = sirProduct($ctx['company'], $ctx['category'], 'B (só brinde)', 10.0);
    $c = sirProduct($ctx['company'], $ctx['category'], 'C (cancelado)', 10.0);
    $d = sirProduct($ctx['company'], $ctx['category'], 'D (antigo)', 10.0);
    $e = sirProduct($ctx['company'], $ctx['category'], 'E (outra filial)', 10.0);
    $f = sirProduct($ctx['company'], $ctx['category'], 'F (comanda aberta)', 10.0);

    sirOrder($ctx, '2026-10-01 10:00:00', [[$a, 1]]);
    sirOrder($ctx, '2026-10-20 18:30:00', [[$a, 1]]);
    sirOrder($ctx, '2026-10-27 09:00:00', [[$a, 1]]);   // depois do "até"
    sirOrder($ctx, '2026-10-20 10:00:00', [[$b, 1, 0.0]]);
    sirOrder($ctx, '2026-10-20 10:00:00', [[$c, 1]], ['status' => 'cancelled']);
    sirOrder($ctx, '2026-07-01 10:00:00', [[$d, 1]]);   // fora dos 90 dias
    sirOrder($ctx, '2026-10-15 10:00:00', [[$e, 1]], ['branch_id' => $other->id]);
    sirOrder($ctx, '2026-10-15 10:00:00', [[$f, 1]], ['is_open_tab' => true]);
    sirOrder($ctx, '2026-10-15 10:00:00', [['Avulso', 1, 5.0]]);

    $foreign = sirContext('B');
    $foreignProduct = sirProduct($foreign['company'], $foreign['category'], 'X', 10.0);
    sirOrder($foreign, '2026-10-20 10:00:00', [[$foreignProduct, 1]]);

    $service = app(SoldItemsReportService::class);
    $until = CarbonImmutable::parse('2026-10-25 23:59:59');

    $all = $service->lastSaleDates($ctx['company']->id, null, $until, 90);
    $branchOnly = $service->lastSaleDates($ctx['company']->id, $other->id, $until, 90);

    ksort($all);
    $expected = [$a->id => '2026-10-20 18:30:00', $e->id => '2026-10-15 10:00:00'];
    ksort($expected);

    expect($all)->toBe($expected)
        ->and($branchOnly)->toBe([$e->id => '2026-10-15 10:00:00']);
});
