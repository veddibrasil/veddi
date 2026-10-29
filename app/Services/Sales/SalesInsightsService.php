<?php

namespace App\Services\Sales;

use App\DTOs\SalesInsight;
use App\DTOs\SoldItemsFilters;
use App\Models\Company;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Sugestões de venda por regras determinísticas sobre o agregado de SoldItemsReportService.
 * Limiares em config/sales_insights.php. Os atalhos das sugestões só pré-preenchem a tela de
 * destino (cupom, produto, estoque): nada é gravado aqui.
 */
class SalesInsightsService
{
    private const WEEKDAYS = [1 => 'segunda', 2 => 'terça', 3 => 'quarta', 4 => 'quinta', 5 => 'sexta', 6 => 'sábado', 7 => 'domingo'];

    private const WEEKDAYS_SHORT = [1 => 'seg', 2 => 'ter', 3 => 'qua', 4 => 'qui', 5 => 'sex', 6 => 'sab', 7 => 'dom'];

    private const CHANNEL_FLAGS = ['chat' => 'available_in_delivery', 'pdv' => 'available_in_pdv', 'ifood' => 'available_in_ifood'];

    private const CHANNEL_LABELS = ['chat' => 'no chat', 'pdv' => 'no PDV', 'ifood' => 'no iFood'];

    public function __construct(private SoldItemsReportService $reports) {}

    /**
     * @return array{insights: list<SalesInsight>, window: SoldItemsFilters, extended: bool, enough_data: bool, orders: int}
     */
    public function generate(Company $company, SoldItemsFilters $filters, string $context = 'all', array $productIds = []): array
    {
        $window = $filters->withMinimumWindow((int) config('sales_insights.min_window_days', 28));

        // Cache curto: catálogo e estoque mudam rápido, mas busca/ordenação/paginação da tela
        // re-renderizam o componente e não devem refazer catálogo + última venda a cada tecla.
        $result = $this->cached($company, $window);

        // Filtra antes dos limites: um produto pode não aparecer entre as 20 sugestões da loja.
        $result['insights'] = $this->rank(array_values(array_filter(
            $result['insights'],
            function (SalesInsight $insight) use ($context, $productIds) {
                if ($productIds !== [] && ! collect($productIds)->contains(fn ($id) => $insight->concerns('p:'.$id))) {
                    return false;
                }

                return match ($context) {
                    'products' => $insight->scope === 'product' || $insight->relatedKeys !== [],
                    'orders' => $insight->scope === 'store' || in_array($insight->type, ['combo', 'peak_time', 'trending_down'], true),
                    'stock' => in_array($insight->type, ['stock_running_out', 'unavailable_champion', 'trending_up', 'champion'], true),
                    'coupons' => collect($insight->actions)->contains(fn ($action) => $action['route'] === 'admin.coupons.index'),
                    default => true,
                };
            },
        )));

        // Fora do cache: filtros diferentes (7 e 28 dias até hoje) caem na mesma janela.
        return ['window' => $window, 'extended' => $window !== $filters] + $result;
    }

    /**
     * Todas as sugestões "Agir agora", sem os limites do ranking (máx. por produto e total): os
     * avisos do sino não podem perder um alerta urgente por causa do corte da tela.
     *
     * @return list<SalesInsight>
     */
    public function urgent(Company $company, SoldItemsFilters $filters): array
    {
        $window = $filters->withMinimumWindow((int) config('sales_insights.min_window_days', 28));
        $urgent = array_filter(
            $this->cached($company, $window)['insights'],
            fn (SalesInsight $insight) => $insight->priority === SalesInsight::PRIORITY_NOW,
        );
        usort($urgent, fn (SalesInsight $a, SalesInsight $b) => $b->weight <=> $a->weight);

        return $urgent;
    }

    /** Cache curto: catálogo e estoque mudam rápido, mas re-renders da tela não devem recalcular tudo. */
    private function cached(Company $company, SoldItemsFilters $window): array
    {
        return Cache::remember(
            $this->cacheKey($company->id, $window),
            (int) config('sales_insights.cache_ttl_insights', 120),
            fn () => $this->compute($company, $window),
        );
    }

    public function forget(int $companyId, SoldItemsFilters $filters): void
    {
        Cache::forget($this->cacheKey($companyId, $filters->withMinimumWindow((int) config('sales_insights.min_window_days', 28))));
    }

    private function cacheKey(int $companyId, SoldItemsFilters $window): string
    {
        return 'sold-items:insights:v2:'.$companyId.':'.md5(json_encode($window->toArray()));
    }

    private function compute(Company $company, SoldItemsFilters $window): array
    {
        $report = $this->reports->build($company->id, $window);

        $result = [
            'insights' => [],
            'enough_data' => false,
            'orders' => $report['kpis']['orders'],
        ];

        if ($report['kpis']['orders'] < (int) config('sales_insights.min_orders', 20)) {
            return $result;
        }

        $catalog = $this->reports->catalog($company->id, array_values(array_filter(array_column($report['products'], 'product_id'))));
        $storeRules = $window->categoryId === null;

        $unavailable = $this->unavailableChampions($report, $catalog, $window);
        $insights = [
            ...($storeRules ? $this->champions($report) : []),
            ...$unavailable,
            // Estoque é um só para todos os canais: a velocidade de venda ignora o filtro de canal.
            ...$this->stockRunningOut(
                $window->channel ? $this->reports->build($company->id, $window->withChannel(null)) : $report,
                $catalog,
                $window,
            ),
            ...$this->peakTimes($report, $catalog),
            ...$this->trendingUp($report, $catalog, $window),
            ...$this->trendingDown($report, $catalog, array_map(fn (SalesInsight $insight) => $insight->productKey, $unavailable)),
            ...$this->stale($company, $catalog, $window),
            ...$this->slowMovers($report, $catalog),
            ...$this->combos($report, $catalog),
            ...($storeRules ? $this->weakHours($report) : []),
            ...$this->promosWithoutEffect($report, $catalog),
            ...$this->catalogPresentation($report, $catalog),
            ...$this->ifoodDependency($company, $report, $catalog, $window, $storeRules),
        ];

        $result['insights'] = $insights;
        $result['enough_data'] = true;

        return $result;
    }

    /**
     * Menor conjunto de produtos que soma sales_insights.rules.champion.revenue_share do
     * faturamento, desde que seja uma fração pequena do mix (senão não há "campeões").
     *
     * @return list<string>
     */
    public function championKeys(array $report): array
    {
        $cfg = config('sales_insights.rules.champion');
        $total = (float) $report['kpis']['revenue'];
        $sold = collect($report['products'])->filter(fn ($p) => $p['qty'] > 0)->sortByDesc('revenue');

        if ($total <= 0 || $sold->isEmpty()) {
            return [];
        }

        $keys = [];
        $cumulative = 0.0;
        foreach ($sold as $key => $product) {
            $keys[] = (string) $key;
            $cumulative += $product['revenue'];
            if ($cumulative >= $total * $cfg['revenue_share']) {
                break;
            }
        }

        return count($keys) <= max(1, (int) ceil($sold->count() * $cfg['max_products_share'])) ? $keys : [];
    }

    // ─── Regras ──────────────────────────────────────────────────────────────

    private function champions(array $report): array
    {
        $cfg = config('sales_insights.rules.champion');
        if ($report['kpis']['orders'] < $cfg['min_orders'] || ! ($keys = $this->championKeys($report))) {
            return [];
        }

        $top = array_slice($keys, 0, $cfg['top']);
        $names = array_map(fn ($key) => $report['products'][$key]['name'], $top);
        $revenue = array_sum(array_map(fn ($key) => $report['products'][$key]['revenue'], $top));
        $share = $this->percent($revenue / $report['kpis']['revenue']);

        return [new SalesInsight(
            type: 'champion',
            scope: 'store',
            priority: SalesInsight::PRIORITY_OPPORTUNITY,
            title: 'Seus campeões de venda',
            message: 'Seus campeões: '.$this->joinNames($names)." somam {$share}% do faturamento. Mantenha estoque garantido, capriche na foto e deixe-os no topo do cardápio.",
            evidence: [count($keys).' produto(s) fazem '.$this->percent(config('sales_insights.rules.champion.revenue_share')).'% do faturamento'],
            actions: [$this->stockAction($names[0])],
            weight: $revenue,
            relatedKeys: $keys,
        )];
    }

    private function unavailableChampions(array $report, Collection $catalog, SoldItemsFilters $window): array
    {
        $cfg = config('sales_insights.rules.unavailable_champion');
        $top = collect($report['products'])
            ->filter(fn ($p) => $p['qty'] > 0 && $p['product_id'])
            ->sortByDesc('revenue')
            ->take($cfg['top']);

        $insights = [];
        foreach ($top as $key => $sold) {
            $product = $catalog->get($sold['product_id']);
            if (! $product || $product->trashed()) {
                continue;
            }

            $reasons = [];
            if (! $product->active) {
                $reasons[] = 'inativo no cardápio';
            } else {
                $pivots = $this->branchPivots($product, $window->branchId);
                $sellable = $pivots->filter(fn ($branch) => $branch->pivot->available && (! $branch->pivot->track_stock || $branch->pivot->quantity > 0));
                if ($pivots->isNotEmpty() && $sellable->isEmpty()) {
                    $reasons[] = $pivots->count() === 1 ? 'indisponível na filial '.$pivots->first()->name : 'indisponível em todas as filiais';
                }
                foreach (self::CHANNEL_FLAGS as $channel => $flag) {
                    if (! $product->{$flag} && ($sold['channels'][$channel] ?? 0) / $sold['qty'] >= $cfg['channel_share']) {
                        $reasons[] = 'desligado '.self::CHANNEL_LABELS[$channel];
                    }
                }
            }

            if ($reasons === []) {
                continue;
            }

            $insights[] = new SalesInsight(
                type: 'unavailable_champion',
                scope: 'product',
                priority: SalesInsight::PRIORITY_NOW,
                title: 'Campeão fora de venda',
                message: "{$sold['name']} é um dos mais vendidos ({$sold['qty']} un., {$this->money($sold['revenue'])}) e está ".$this->joinNames($reasons).'. Cada dia parado é venda perdida.',
                evidence: $reasons,
                productKey: $key,
                productId: $product->id,
                actions: [$this->editAction($product->id), $this->stockAction($sold['name'], $window->branchId)],
                weight: $sold['revenue'],
            );
        }

        return $insights;
    }

    private function stockRunningOut(array $report, Collection $catalog, SoldItemsFilters $window): array
    {
        // Estoque é o de agora: comparar com vendas de um período antigo não diz nada.
        if ($window->end->lessThan(CarbonImmutable::today()->subDay())) {
            return [];
        }

        $cfg = config('sales_insights.rules.stock_running_out');
        $insights = [];

        foreach ($report['products'] as $key => $sold) {
            $product = $sold['product_id'] ? $catalog->get($sold['product_id']) : null;
            if (! $this->isSellable($product)) {
                continue;
            }

            foreach ($this->branchPivots($product, $window->branchId) as $branch) {
                $pivot = $branch->pivot;
                if (! $pivot->track_stock || ! $pivot->available || $pivot->quantity <= 0) {
                    continue;
                }

                $average = ($sold['recent_qty_by_branch'][$branch->id] ?? 0) / $cfg['recent_days'];
                if ($average < $cfg['min_daily_avg'] || $pivot->quantity / $average >= $cfg['max_cover_days']) {
                    continue;
                }

                $days = max(1, (int) floor($pivot->quantity / $average));
                $peak = $this->bestWeekday($sold, $report['meta']['weekday_occurrences'], $report['meta']['days']);
                $before = $peak && $peak['ratio'] >= config('sales_insights.rules.peak_time.weekday_ratio')
                    ? ' Reponha antes de '.$this->weekdayName($peak['weekday']).', o dia mais forte dele.'
                    : ' Reponha já.';

                $insights[] = new SalesInsight(
                    type: 'stock_running_out',
                    scope: 'product',
                    priority: SalesInsight::PRIORITY_NOW,
                    title: 'Estoque acabando',
                    message: "{$sold['name']} na filial {$branch->name}: restam {$pivot->quantity} un. e sai ~".$this->decimal($average).'/dia, acaba em ~'.$days.' dia(s).'.$before,
                    evidence: ["{$pivot->quantity} un. em estoque", 'média de '.$this->decimal($average).' un./dia nos últimos '.$cfg['recent_days'].' dias'],
                    productKey: $key,
                    productId: $product->id,
                    actions: [$this->stockAction($sold['name'], $branch->id, 'Repor estoque')],
                    weight: $sold['revenue'],
                );
            }
        }

        return $insights;
    }

    private function peakTimes(array $report, Collection $catalog): array
    {
        $cfg = config('sales_insights.rules.peak_time');
        $insights = [];

        // Só os mais relevantes: numa loja que só abre no almoço todo produto "pica" no almoço.
        $candidates = collect($report['products'])
            ->filter(fn ($p) => $p['qty'] >= $cfg['min_units'])
            ->sortByDesc('revenue')
            ->take($cfg['top']);

        foreach ($candidates as $key => $sold) {
            $weekday = $this->bestWeekday($sold, $report['meta']['weekday_occurrences'], $report['meta']['days']);
            $weekday = $weekday && $weekday['ratio'] >= $cfg['weekday_ratio'] ? $weekday : null;
            $block = $this->bestHourBlock($sold);
            $block = $block && $block['share'] >= $cfg['block_share'] ? $block : null;

            if (! $weekday && ! $block) {
                continue;
            }

            $from = $block ? $block['hour'] : null;
            $promoteAt = $block ? max(0, $from - $cfg['lead_hours']) : null;
            $evidence = [];
            if ($weekday) {
                $evidence[] = $this->decimal($weekday['ratio']).'× a média diária '.$this->weekdayPhrase($weekday['weekday']);
            }
            if ($block) {
                $evidence[] = $this->percent($block['share'])."% das unidades entre {$from}h e ".($from + 2).'h';
            }

            $message = match (true) {
                $weekday && $block => "{$sold['name']} vende mais ".$this->weekdayPhrase($weekday['weekday']).", entre {$from}h e ".($from + 2)."h. Divulgue no WhatsApp e no Instagram a partir das {$promoteAt}h desse dia e reforce a produção.",
                (bool) $weekday => "{$sold['name']} vende ".$this->decimal($weekday['ratio']).'× a média '.$this->weekdayPhrase($weekday['weekday']).'. Divulgue na véspera e no próprio dia, e reforce a produção.',
                default => "{$sold['name']} concentra ".$this->percent($block['share'])."% das vendas entre {$from}h e ".($from + 2)."h. Divulgue a partir das {$promoteAt}h e deixe a produção adiantada.",
            };

            $product = $sold['product_id'] ? $catalog->get($sold['product_id']) : null;
            $actions = [];
            if ($this->isSellable($product)) {
                [$start, $end] = $this->nextWindow($weekday['weekday'] ?? null, $promoteAt ?? 0, $block ? $from + 2 : 24);
                $actions[] = $this->couponAction('Criar cupom para o pico', [
                    'produtos' => (string) $product->id,
                    'valor' => $cfg['discount'],
                    'inicio' => $start,
                    'fim' => $end,
                    'nome' => Str::limit('Pico '.$sold['name'], 80, ''),
                    'codigo' => 'PICO'.$product->id,
                ]);
            }

            $insights[] = new SalesInsight(
                type: 'peak_time',
                scope: 'product',
                priority: SalesInsight::PRIORITY_OPPORTUNITY,
                title: 'Melhor momento para vender',
                message: $message,
                evidence: $evidence,
                productKey: $key,
                productId: $product?->id,
                actions: $actions,
                weight: $sold['revenue'],
            );
        }

        return $insights;
    }

    private function trendingUp(array $report, Collection $catalog, SoldItemsFilters $window): array
    {
        $cfg = config('sales_insights.rules.trending_up');
        $insights = [];

        foreach ($report['products'] as $key => $sold) {
            $product = $sold['product_id'] ? $catalog->get($sold['product_id']) : null;
            $previous = $sold['prev_qty'];
            // Loja sem vendas no período anterior (recém-aberta, dados importados) faria tudo virar "lançamento".
            $isLaunch = $previous === 0 && $report['previous_kpis']['orders'] > 0
                && $product && $product->created_at?->greaterThanOrEqualTo($window->start);

            if ($sold['qty'] < $cfg['min_current'] || ! ($isLaunch || ($previous >= $cfg['min_previous'] && $cfg['ratio'] <= $sold['qty'] / $previous))) {
                continue;
            }

            $message = $isLaunch
                ? "{$sold['name']} estreou com {$sold['qty']} un. vendidas. Bom começo: garanta estoque, destaque no cardápio e divulgue."
                : "{$sold['name']} está em alta: {$previous} → {$sold['qty']} un. (+".$this->percent($sold['qty'] / $previous - 1).'%). Garanta estoque e destaque no cardápio; é um bom candidato a combo.';

            $insights[] = new SalesInsight(
                type: 'trending_up',
                scope: 'product',
                priority: SalesInsight::PRIORITY_OPPORTUNITY,
                title: $isLaunch ? 'Lançamento em alta' : 'Em alta',
                message: $message,
                evidence: ["{$previous} un. no período anterior", "{$sold['qty']} un. agora"],
                productKey: $key,
                productId: $product?->id,
                actions: $this->isSellable($product) ? [$this->stockAction($sold['name'])] : [],
                weight: $sold['revenue'],
            );
        }

        return $insights;
    }

    private function trendingDown(array $report, Collection $catalog, array $skipKeys): array
    {
        $cfg = config('sales_insights.rules.trending_down');
        $topPrevious = collect($report['products'])->sortByDesc('prev_revenue')->take($cfg['top'])->keys()->all();
        $insights = [];

        foreach ($report['products'] as $key => $sold) {
            $previous = $sold['prev_qty'];
            if (in_array($key, $skipKeys, true) || $previous < $cfg['min_previous'] || $cfg['ratio'] < $sold['qty'] / $previous) {
                continue;
            }

            $product = $sold['product_id'] ? $catalog->get($sold['product_id']) : null;
            $actions = [];
            if ($this->isSellable($product)) {
                $now = CarbonImmutable::now();
                $actions[] = $this->couponAction('Criar cupom de '.$cfg['discount'].'%', [
                    'produtos' => (string) $product->id,
                    'valor' => $cfg['discount'],
                    'inicio' => $now,
                    'fim' => $now->addDays($cfg['coupon_days'])->setTime(23, 59),
                    'nome' => Str::limit('Volta '.$sold['name'], 80, ''),
                    'codigo' => 'VOLTA'.$product->id,
                ]);
                $actions[] = $this->editAction($product->id);
            }

            $insights[] = new SalesInsight(
                type: 'trending_down',
                scope: 'product',
                priority: in_array($key, $topPrevious, true) ? SalesInsight::PRIORITY_NOW : SalesInsight::PRIORITY_OPPORTUNITY,
                title: 'Em queda',
                message: "{$sold['name']} caiu ".$this->percent(1 - $sold['qty'] / $previous)."% ({$previous} → {$sold['qty']} un.) em relação ao período anterior. Confira disponibilidade, preço e foto; um cupom de {$cfg['discount']}% pode reativar as vendas.",
                evidence: ["{$previous} un. no período anterior", "{$sold['qty']} un. agora"],
                productKey: $key,
                productId: $product?->id,
                actions: $actions,
                weight: $sold['prev_revenue'] - $sold['revenue'],
            );
        }

        return $insights;
    }

    private function stale(Company $company, Collection $catalog, SoldItemsFilters $window): array
    {
        // O catálogo é o de agora: "parado" medido no fim de um período antigo acusaria produto que já voltou a vender.
        if ($window->end->lessThan(CarbonImmutable::today()->subDay())) {
            return [];
        }

        $cfg = config('sales_insights.rules.stale');
        $lastSales = $this->reports->lastSaleDates($company->id, $window->branchId, $window->end, $cfg['lookback_days']);
        $limit = $window->end->startOfDay()->subDays($cfg['days']);
        $stale = [];

        foreach ($catalog as $product) {
            if (! $this->isSellable($product)
                || ($window->categoryId && (int) $product->product_category_id !== $window->categoryId)
                || ! ($product->available_in_delivery || $product->available_in_pdv || $product->available_in_ifood)
                // Sem estoque não é "parado", é falta de reposição (não sugerir desconto).
                || ! $this->branchPivots($product, $window->branchId)->contains(fn ($branch) => $branch->pivot->available && (! $branch->pivot->track_stock || $branch->pivot->quantity > 0))
                || $product->created_at?->greaterThan($limit)) {
                continue;
            }

            $lastSale = isset($lastSales[$product->id]) ? CarbonImmutable::parse($lastSales[$product->id]) : null;
            if ($lastSale && $lastSale->greaterThanOrEqualTo($limit)) {
                continue;
            }

            $stale[] = [
                'product' => $product,
                'days' => $lastSale ? (int) $lastSale->startOfDay()->diffInDays($window->end->startOfDay()) : null,
            ];
        }

        if (count($stale) > $cfg['group_above']) {
            $names = array_map(fn ($item) => $item['product']->name, array_slice($stale, 0, 5));
            $more = count($stale) - count($names);

            return [new SalesInsight(
                type: 'stale',
                scope: 'store',
                priority: SalesInsight::PRIORITY_OPPORTUNITY,
                title: 'Produtos parados',
                message: count($stale)." produtos não vendem há {$cfg['days']} dias ou mais: ".implode(', ', $names).($more > 0 ? " e mais {$more}" : '').'. Teste preço promocional, troque a foto ou retire do cardápio para simplificar a escolha do cliente.',
                evidence: [count($stale).' produtos sem venda'],
                actions: [['label' => 'Ver produtos', 'route' => 'admin.products.index', 'params' => []]],
                relatedKeys: array_map(fn ($item) => SoldItemsReportService::productKey($item['product']->id, null), $stale),
            )];
        }

        return array_map(function ($item) use ($cfg) {
            $product = $item['product'];
            $since = $item['days'] !== null ? "não vende há {$item['days']} dias" : "não vendeu nada nos últimos {$cfg['lookback_days']} dias";
            $canPromo = ! $product->is_variant && ! $product->promo_price_enabled;

            return new SalesInsight(
                type: 'stale',
                scope: 'product',
                priority: SalesInsight::PRIORITY_OPPORTUNITY,
                title: 'Sem vendas',
                message: "{$product->name} {$since}. Teste um preço promocional, troque a foto ou retire do cardápio.",
                evidence: [$item['days'] !== null ? "última venda há {$item['days']} dias" : 'sem venda registrada'],
                productKey: SoldItemsReportService::productKey($product->id, null),
                productId: $product->id,
                actions: [$canPromo ? $this->editAction($product->id, $cfg['promo']) : $this->editAction($product->id)],
                weight: (float) $product->price,
            );
        }, $stale);
    }

    private function slowMovers(array $report, Collection $catalog): array
    {
        $cfg = config('sales_insights.rules.slow_mover');
        $days = $report['meta']['days'];
        $sold = collect($report['products'])->filter(fn ($p) => $p['qty'] > 0);

        if ($days < $cfg['min_window_days'] || $sold->count() < $cfg['min_products']) {
            return [];
        }

        $champion = $sold->sortByDesc('revenue')->first();
        $bottom = $sold->sortBy('qty')->take(max(1, (int) floor($sold->count() * $cfg['bottom_share'])));
        $insights = [];

        foreach ($bottom as $key => $item) {
            $product = $item['product_id'] ? $catalog->get($item['product_id']) : null;
            if (! $this->isSellable($product) || $cfg['max_daily_avg'] <= $item['qty'] / $days) {
                continue;
            }

            $now = CarbonImmutable::now();
            $pairWith = $champion['name'] !== $item['name'] ? " Ofereça junto com {$champion['name']}, o mais vendido," : ' Ofereça como acompanhamento';

            $insights[] = new SalesInsight(
                type: 'slow_mover',
                scope: 'product',
                priority: SalesInsight::PRIORITY_IDEA,
                title: 'Pouco giro',
                message: "{$item['name']} gira pouco ({$item['qty']} un. em {$days} dias).{$pairWith} ou teste {$cfg['discount']}% de desconto; se não reagir, considere retirar do cardápio.",
                evidence: ['média de '.$this->decimal($item['qty'] / $days).' un./dia'],
                productKey: $key,
                productId: $product->id,
                actions: [$this->couponAction('Criar cupom de '.$cfg['discount'].'%', [
                    'produtos' => (string) $product->id,
                    'valor' => $cfg['discount'],
                    'inicio' => $now,
                    'fim' => $now->addDays($cfg['coupon_days'])->setTime(23, 59),
                    'nome' => Str::limit('Experimente '.$item['name'], 80, ''),
                    'codigo' => 'PROVA'.$product->id,
                ])],
                weight: $item['revenue'],
            );
        }

        return $insights;
    }

    private function combos(array $report, Collection $catalog): array
    {
        $cfg = config('sales_insights.rules.combo');
        $orders = $report['kpis']['orders'];
        $candidates = [];

        foreach ($report['pairs'] as $pair) {
            $a = $report['products'][$pair['a']] ?? null;
            $b = $report['products'][$pair['b']] ?? null;
            if (! $a || ! $b || $pair['count'] < $cfg['min_orders'] || ! $a['orders'] || ! $b['orders']) {
                continue;
            }

            // Âncora = o lado em que o par é mais frequente ("quem pede A leva B em X%").
            [$anchorKey, $otherKey] = $pair['count'] / $a['orders'] >= $pair['count'] / $b['orders'] ? [$pair['a'], $pair['b']] : [$pair['b'], $pair['a']];
            $anchor = $report['products'][$anchorKey];
            $confidence = $pair['count'] / $anchor['orders'];
            $lift = $pair['count'] * $orders / ($a['orders'] * $b['orders']);

            if ($confidence >= $cfg['confidence'] && $lift >= $cfg['lift']) {
                $candidates[] = compact('anchorKey', 'otherKey', 'confidence', 'lift') + ['count' => $pair['count'], 'score' => $lift * $pair['count']];
            }
        }

        usort($candidates, fn ($x, $y) => $y['score'] <=> $x['score']);

        return array_map(function ($combo) use ($report, $catalog, $cfg) {
            $anchor = $report['products'][$combo['anchorKey']];
            $other = $report['products'][$combo['otherKey']];
            $anchorProduct = $anchor['product_id'] ? $catalog->get($anchor['product_id']) : null;
            $otherProduct = $other['product_id'] ? $catalog->get($other['product_id']) : null;

            $actions = [];
            if ($this->isSellable($anchorProduct) && $this->isSellable($otherProduct)) {
                $now = CarbonImmutable::now();
                $actions[] = $this->couponAction('Criar cupom do combo', [
                    'produtos' => $anchorProduct->id.','.$otherProduct->id,
                    'valor' => $cfg['discount'],
                    'inicio' => $now,
                    'fim' => $now->addDays(14)->setTime(23, 59),
                    'nome' => Str::limit('Combo '.$anchor['name'].' + '.$other['name'], 80, ''),
                    'codigo' => 'COMBO'.$anchorProduct->id.'X'.$otherProduct->id,
                ]);
                $actions[] = ['label' => 'Criar produto combo', 'route' => 'admin.products.create', 'params' => []];
            }

            return new SalesInsight(
                type: 'combo',
                scope: 'product',
                priority: SalesInsight::PRIORITY_OPPORTUNITY,
                title: 'Combo natural',
                message: "Quem pede {$anchor['name']} leva {$other['name']} em ".$this->percent($combo['confidence'])."% das vezes ({$combo['count']} pedidos). Monte um combo {$anchor['name']} + {$other['name']} ou ofereça {$other['name']} com desconto para quem pede {$anchor['name']}.",
                evidence: ["{$combo['count']} pedidos com os dois", $this->decimal($combo['lift']).'× mais que o acaso'],
                productKey: $combo['anchorKey'],
                productId: $anchorProduct?->id,
                actions: $actions,
                weight: $anchor['revenue'] + $other['revenue'],
                relatedKeys: [$combo['otherKey']],
            );
        }, array_slice($candidates, 0, $cfg['top']));
    }

    private function weakHours(array $report): array
    {
        $cfg = config('sales_insights.rules.weak_hour');
        if ($report['meta']['days'] < $cfg['min_window_days'] || $report['kpis']['orders'] < $cfg['min_orders']) {
            return [];
        }

        // Célula "operante": a loja costuma ter pedido nesse dia/hora (não conta horário fechado).
        $occurrences = $report['meta']['weekday_occurrences'];
        $averages = [];
        foreach ($report['heatmap'] as $weekday => $hours) {
            foreach ($hours as $hour => $orders) {
                if ($occurrences[$weekday] > 0 && $report['heatmap_days'][$weekday][$hour] / $occurrences[$weekday] >= $cfg['operating_share']) {
                    $averages[$weekday][$hour] = $orders / $occurrences[$weekday];
                }
            }
        }

        $values = collect($averages)->flatten();
        if ($values->count() < 4 || ($median = (float) $values->median()) <= 0) {
            return [];
        }

        $blocks = [];
        foreach ($averages as $weekday => $hours) {
            foreach ($hours as $hour => $average) {
                if (isset($hours[$hour + 1])) {
                    $ratio = ($average + $hours[$hour + 1]) / 2 / $median;
                    if ($ratio <= $cfg['weak_ratio']) {
                        $blocks[] = compact('weekday', 'hour', 'ratio');
                    }
                }
            }
        }
        usort($blocks, fn ($x, $y) => $x['ratio'] <=> $y['ratio']);

        $picked = [];
        foreach ($blocks as $block) {
            foreach ($picked as $other) {
                if ($other['weekday'] === $block['weekday'] && abs($other['hour'] - $block['hour']) < 2) {
                    continue 2;
                }
            }
            $picked[] = $block;
            if (count($picked) >= $cfg['blocks']) {
                break;
            }
        }

        return array_map(function ($block) use ($cfg) {
            ['weekday' => $weekday, 'hour' => $hour] = $block;
            [$start, $end] = $this->nextWindow($weekday, $hour, $hour + 2);
            $short = self::WEEKDAYS_SHORT[$weekday];

            return new SalesInsight(
                type: 'weak_hour',
                scope: 'store',
                priority: SalesInsight::PRIORITY_OPPORTUNITY,
                title: 'Horário fraco',
                message: Str::ucfirst($this->weekdayPhrase($weekday)).", das {$hour}h às ".($hour + 2).'h, a loja vende '.$this->percent(1 - $block['ratio']).'% menos que nos horários normais. Teste uma promoção relâmpago só nessa janela.',
                evidence: [$this->percent($block['ratio']).'% do movimento típico'],
                actions: [$this->couponAction('Criar promoção relâmpago', [
                    'escopo' => 'order',
                    'valor' => $cfg['discount'],
                    'inicio' => $start,
                    'fim' => $end,
                    'nome' => "Relâmpago {$short} {$hour}h",
                    'codigo' => 'RELAMPAGO'.strtoupper($short).$hour,
                    'max_usos' => $cfg['max_uses'],
                ])],
                weight: 0.0,
            );
        }, $picked);
    }

    private function promosWithoutEffect(array $report, Collection $catalog): array
    {
        $cfg = config('sales_insights.rules.promo_no_effect');
        $insights = [];

        foreach ($catalog as $product) {
            $key = SoldItemsReportService::productKey($product->id, null);
            $sold = $report['products'][$key] ?? null;
            if (! $sold || ! $product->promo_price_enabled || ! $this->isSellable($product)
                || $sold['prev_qty'] < $cfg['min_previous'] || $sold['qty'] > $sold['prev_qty'] * $cfg['max_ratio']) {
                continue;
            }

            $insights[] = new SalesInsight(
                type: 'promo_no_effect',
                scope: 'product',
                priority: SalesInsight::PRIORITY_OPPORTUNITY,
                title: 'Promoção sem efeito',
                message: "{$product->name} está com preço promocional ({$this->money((float) $product->price)} → {$this->money($product->effective_price)}) e as vendas não reagiram nos últimos {$report['meta']['days']} dias ({$sold['prev_qty']} → {$sold['qty']} un.). Divulgue a promoção no WhatsApp e no Instagram ou volte ao preço cheio.",
                evidence: ["{$sold['prev_qty']} un. no período anterior", "{$sold['qty']} un. agora"],
                productKey: $key,
                productId: $product->id,
                actions: [$this->editAction($product->id)],
                weight: $sold['revenue'],
            );
        }

        return $insights;
    }

    private function ifoodDependency(Company $company, array $report, Collection $catalog, SoldItemsFilters $window, bool $storeRules): array
    {
        // Com filtro de canal a fatia do iFood perde sentido (100% ou 0%).
        if ($window->channel !== null) {
            return [];
        }

        $cfg = config('sales_insights.rules.ifood_dependency');
        $link = route('chat.company', ['company' => $company->slug]);
        $insights = [];
        $ifood = $report['channels']['ifood'] ?? null;

        if ($storeRules && $ifood && $ifood['orders'] >= $cfg['min_orders'] && $report['kpis']['revenue'] > 0
            && $cfg['revenue_share'] <= $ifood['revenue'] / $report['kpis']['revenue']) {
            $now = CarbonImmutable::now();
            $insights[] = new SalesInsight(
                type: 'ifood_dependency',
                scope: 'store',
                priority: SalesInsight::PRIORITY_OPPORTUNITY,
                title: 'Dependência do iFood',
                message: $this->percent($ifood['revenue'] / $report['kpis']['revenue'])."% do faturamento veio do iFood, que cobra comissão. Divulgue seu link próprio ({$link}) na embalagem e nas redes, com um cupom de primeira compra.",
                evidence: ["{$ifood['orders']} pedidos pelo iFood"],
                actions: [$this->couponAction('Criar cupom de 1ª compra', [
                    'escopo' => 'order',
                    'valor' => $cfg['discount'],
                    'inicio' => $now,
                    'fim' => $now->addDays(30)->setTime(23, 59),
                    'nome' => 'Primeiro pedido direto',
                    'codigo' => $cfg['code'],
                ])],
                weight: (float) $ifood['revenue'],
            );
        }

        foreach ($report['products'] as $key => $sold) {
            $share = $sold['qty'] > 0 ? ($sold['channels']['ifood'] ?? 0) / $sold['qty'] : 0;
            if ($sold['qty'] < $cfg['product_min_units'] || $share < $cfg['product_share']) {
                continue;
            }

            $product = $sold['product_id'] ? $catalog->get($sold['product_id']) : null;
            $insights[] = new SalesInsight(
                type: 'ifood_dependency',
                scope: 'product',
                priority: SalesInsight::PRIORITY_IDEA,
                title: 'Vende quase só no iFood',
                message: "{$sold['name']}: ".$this->percent($share)."% das vendas vêm do iFood. Divulgue o {$sold['name']} no seu link próprio ({$link}), onde não há comissão.",
                evidence: [$this->percent($share).'% das unidades pelo iFood'],
                productKey: $key,
                productId: $product?->id,
                weight: $sold['revenue'],
            );
        }

        return $insights;
    }

    // ─── Ordenação e limites ────────────────────────────────────────────────

    private function catalogPresentation(array $report, Collection $catalog): array
    {
        $insights = [];
        foreach ($report['products'] as $key => $sold) {
            $product = $sold['product_id'] ? $catalog->get($sold['product_id']) : null;
            if (! $product || $product->trashed() || ! $product->active || $sold['qty'] < (int) config('sales_insights.rules.catalog_presentation.min_units', 10)) {
                continue;
            }

            $missing = [];
            if (! $product->image_path) {
                $missing[] = 'foto';
            }
            if (trim((string) $product->description) === '') {
                $missing[] = 'descrição';
            }
            if ($missing === []) {
                continue;
            }

            $insights[] = new SalesInsight(
                type: 'catalog_presentation',
                scope: 'product',
                priority: SalesInsight::PRIORITY_IDEA,
                title: 'Melhore a apresentação de '.$product->name,
                message: $product->name.' já tem procura. Complete '.implode(' e ', $missing).' para ajudar o cliente a escolher no cardápio.',
                evidence: [$sold['qty'].' unidades vendidas', 'Falta '.implode(' e ', $missing)],
                productKey: $key,
                productId: $product->id,
                actions: [$this->editAction($product->id)],
                // Melhorias de cadastro ficam depois das oportunidades baseadas em vendas.
                weight: 0.0,
            );
        }

        return $insights;
    }

    /** @param  list<SalesInsight>  $insights */
    private function rank(array $insights): array
    {
        usort($insights, fn (SalesInsight $a, SalesInsight $b) => [$a->priority, $b->weight] <=> [$b->priority, $a->weight]);

        $maxTotal = (int) config('sales_insights.max_total', 20);
        $maxPerProduct = (int) config('sales_insights.max_per_product', 2);
        $perProduct = [];
        $ranked = [];

        foreach ($insights as $insight) {
            if (count($ranked) >= $maxTotal) {
                break;
            }
            if ($insight->scope === 'product' && $insight->productKey) {
                $count = $perProduct[$insight->productKey] ?? 0;
                if ($count >= $maxPerProduct) {
                    continue;
                }
                $perProduct[$insight->productKey] = $count + 1;
            }
            $ranked[] = $insight;
        }

        return $ranked;
    }

    // ─── Apoio ───────────────────────────────────────────────────────────────

    private function isSellable(?Product $product): bool
    {
        return $product !== null && ! $product->trashed() && $product->active;
    }

    /** Filiais ativas do produto (ou só a filial filtrada). */
    private function branchPivots(Product $product, ?int $branchId): Collection
    {
        return $product->branches->filter(fn ($branch) => $branch->active && (! $branchId || $branch->id === $branchId))->values();
    }

    /** Dia da semana com maior média por ocorrência, comparado à média diária do período. */
    private function bestWeekday(array $sold, array $occurrences, int $days): ?array
    {
        $minOccurrences = (int) config('sales_insights.rules.peak_time.min_weekday_occurrences', 2);
        $dailyMean = $days > 0 ? $sold['qty'] / $days : 0;
        $best = null;

        foreach ($sold['weekdays'] as $weekday => $quantity) {
            if ($occurrences[$weekday] < $minOccurrences || $dailyMean <= 0) {
                continue;
            }
            $ratio = $quantity / $occurrences[$weekday] / $dailyMean;
            if (! $best || $ratio > $best['ratio']) {
                $best = ['weekday' => $weekday, 'ratio' => $ratio];
            }
        }

        return $best;
    }

    /** Bloco de 2h com a maior fatia das unidades. */
    private function bestHourBlock(array $sold): ?array
    {
        if ($sold['qty'] <= 0) {
            return null;
        }

        $best = null;
        for ($hour = 0; $hour < 23; $hour++) {
            $share = ($sold['hours'][$hour] + $sold['hours'][$hour + 1]) / $sold['qty'];
            if (! $best || $share > $best['share']) {
                $best = ['hour' => $hour, 'share' => $share];
            }
        }

        return $best;
    }

    /**
     * Próxima janela [$fromHour, $toHour) no dia da semana (ou em qualquer dia) que ainda não
     * acabou. Se a de hoje já começou, começa agora: o pico de hoje ainda pode ser aproveitado.
     * $toHour = 24 significa até 23:59.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function nextWindow(?int $weekday, int $fromHour, int $toHour): array
    {
        $now = CarbonImmutable::now();

        for ($offset = 0; $offset <= 7; $offset++) {
            $day = $now->startOfDay()->addDays($offset);
            if ($weekday !== null && $day->dayOfWeekIso !== $weekday) {
                continue;
            }

            $end = $toHour >= 24 ? $day->setTime(23, 59) : $day->setTime($toHour, 0);
            if ($end->lessThanOrEqualTo($now)) {
                continue;
            }

            $start = $day->setTime($fromHour, 0);

            return [$start->greaterThan($now) ? $start : $now->startOfMinute(), $end];
        }

        // Inalcançável (sempre há uma ocorrência em 8 dias); mantém o retorno tipado.
        return [$now, $now->setTime(23, 59)];
    }

    private function couponAction(string $label, array $params): array
    {
        $scope = $params['escopo'] ?? 'product';
        unset($params['escopo']);

        foreach (['inicio', 'fim'] as $field) {
            if (isset($params[$field]) && $params[$field] instanceof CarbonImmutable) {
                $params[$field] = $params[$field]->format('Y-m-d\TH:i');
            }
        }

        return [
            'label' => $label,
            'route' => 'admin.coupons.index',
            'params' => ['sugestao' => 1, 'escopo' => $scope] + $params,
        ];
    }

    private function editAction(int $productId, ?int $promo = null): array
    {
        return [
            'label' => $promo ? "Testar {$promo}% de desconto" : 'Editar produto',
            'route' => 'admin.products.edit',
            'params' => array_filter(['product' => $productId, 'promo' => $promo]),
        ];
    }

    private function stockAction(string $productName, ?int $branchId = null, string $label = 'Ver estoque'): array
    {
        return [
            'label' => $label,
            'route' => 'admin.stock.index',
            'params' => array_filter(['search' => $productName, 'filial' => $branchId]),
        ];
    }

    private function weekdayName(int $weekday): string
    {
        return self::WEEKDAYS[$weekday];
    }

    private function weekdayPhrase(int $weekday): string
    {
        return in_array($weekday, [6, 7], true)
            ? 'aos '.self::WEEKDAYS[$weekday].'s'
            : 'às '.self::WEEKDAYS[$weekday].'s';
    }

    private function joinNames(array $names): string
    {
        $names = array_values($names);
        $last = array_pop($names);

        return $names ? implode(', ', $names).' e '.$last : (string) $last;
    }

    private function money(float $value): string
    {
        return 'R$ '.number_format($value, 2, ',', '.');
    }

    private function percent(float $ratio): int
    {
        return (int) round($ratio * 100);
    }

    private function decimal(float $value): string
    {
        return number_format($value, 1, ',', '.');
    }
}
