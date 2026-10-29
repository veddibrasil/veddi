<?php

namespace App\Services\Sales;

use App\DTOs\SoldItemsFilters;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Agregado de itens vendidos por produto, dia, hora, dia da semana, canal, categoria e filial.
 *
 * order_items não tem company_id nem escopo global: toda consulta junta orders e filtra
 * orders.company_id explicitamente. Os models também são lidos com withoutGlobalScopes() e
 * company_id explícito, para o resultado não depender do tenant bindado no container (o
 * service recebe a empresa por parâmetro e o agregado vai para cache por empresa).
 * Hora/dia são agrupados em PHP (testes em sqlite, produção em MySQL), num único cursor.
 */
class SoldItemsReportService
{
    public function build(int $companyId, SoldItemsFilters $filters): array
    {
        $ttl = $filters->includesToday()
            ? (int) config('sales_insights.cache_ttl_today', 300)
            : (int) config('sales_insights.cache_ttl_past', 21600);

        return Cache::remember($filters->cacheKey($companyId), $ttl, fn () => $this->aggregate($companyId, $filters));
    }

    public function forget(int $companyId, SoldItemsFilters $filters): void
    {
        Cache::forget($filters->cacheKey($companyId));
    }

    /** Chave estável do produto; item avulso do iFood (sem product_id) agrupa pelo nome. */
    public static function productKey(?int $productId, ?string $productName): string
    {
        return $productId
            ? 'p:'.$productId
            : 'n:'.md5(mb_strtolower(trim((string) $productName)));
    }

    public static function channelOf(?string $channel, ?string $orderType): string
    {
        if ($channel === 'ifood') {
            return 'ifood';
        }

        return $orderType === 'pdv' ? 'pdv' : 'chat';
    }

    /**
     * Última venda de cada produto do catálogo nos últimos $lookbackDays dias até $until.
     *
     * @return array<int, string> product_id => 'Y-m-d H:i:s'
     */
    public function lastSaleDates(int $companyId, ?int $branchId, CarbonImmutable $until, int $lookbackDays): array
    {
        return DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where('o.company_id', $companyId)
            ->whereIn('o.status', Order::CONFIRMED_STATUSES)
            ->where('o.is_open_tab', false)
            ->whereBetween('o.created_at', [$until->subDays($lookbackDays)->startOfDay(), $until])
            ->when($branchId, fn ($q) => $q->where('o.branch_id', $branchId))
            ->whereNotNull('oi.product_id')
            ->where('oi.subtotal', '>', 0)
            ->groupBy('oi.product_id')
            ->selectRaw('oi.product_id, MAX(o.created_at) as last_sale')
            ->pluck('last_sale', 'oi.product_id')
            ->map(fn ($date) => (string) $date)
            ->all();
    }

    /**
     * Catálogo atual (sempre fresco, fora do cache: disponibilidade e estoque mudam rápido).
     * Inclui excluídos que aparecem nas vendas, para o ranking mostrar o nome e o selo.
     * $onlySold = true traz só os vendidos (ranking/detalhe), sem o resto do cardápio.
     *
     * @return Collection<int, Product>
     */
    public function catalog(int $companyId, array $soldProductIds = [], bool $onlySold = false): Collection
    {
        return Product::withoutGlobalScopes()
            ->withTrashed()
            ->where('company_id', $companyId)
            ->when(
                $onlySold,
                fn ($q) => $q->whereIn('id', $soldProductIds ?: [0]),
                fn ($q) => $q->where(fn ($q) => $q->whereNull('deleted_at')->orWhereIn('id', $soldProductIds ?: [0])),
            )
            ->with([
                'category' => fn ($q) => $q->withoutGlobalScopes()->where('company_id', $companyId),
                'branches' => fn ($q) => $q->withoutGlobalScopes()->where('branches.company_id', $companyId),
            ])
            ->get()
            ->keyBy('id');
    }

    private function baseQuery(int $companyId, SoldItemsFilters $filters): Builder
    {
        return DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->leftJoin('products as p', function ($join) {
                $join->on('p.id', '=', 'oi.product_id')->on('p.company_id', '=', 'o.company_id');
            })
            ->where('o.company_id', $companyId)
            ->whereIn('o.status', Order::CONFIRMED_STATUSES)
            // Comanda aberta ainda não fechou a venda.
            ->where('o.is_open_tab', false)
            // whereBetween (e não whereDate) para usar o índice orders(company_id, created_at).
            ->whereBetween('o.created_at', [$filters->start, $filters->end])
            ->when($filters->branchId, fn ($q) => $q->where('o.branch_id', $filters->branchId))
            ->when($filters->categoryId, fn ($q) => $q->where('p.product_category_id', $filters->categoryId))
            ->when($filters->channel, fn ($q) => $this->applyChannel($q, $filters->channel));
    }

    /** Mesma precedência de channelOf(): iFood > PDV > chat. */
    private function applyChannel(Builder $query, string $channel): void
    {
        match ($channel) {
            'ifood' => $query->where('o.channel', 'ifood'),
            'pdv' => $query->where('o.order_type', 'pdv')
                ->where(fn ($q) => $q->whereNull('o.channel')->orWhere('o.channel', '<>', 'ifood')),
            default => $query
                ->where(fn ($q) => $q->whereNull('o.channel')->orWhere('o.channel', '<>', 'ifood'))
                ->where(fn ($q) => $q->whereNull('o.order_type')->orWhere('o.order_type', '<>', 'pdv')),
        };
    }

    private function aggregate(int $companyId, SoldItemsFilters $filters): array
    {
        $recentFrom = $filters->end->startOfDay()
            ->subDays((int) config('sales_insights.rules.stock_running_out.recent_days', 14) - 1)
            ->format('Y-m-d');

        $emptyBucket = ['orders' => 0, 'items' => 0, 'revenue' => 0.0];
        $daily = [];
        for ($day = $filters->start; $day->lessThanOrEqualTo($filters->end); $day = $day->addDay()) {
            $daily[$day->format('Y-m-d')] = $emptyBucket;
        }
        $hourly = array_fill(0, 24, $emptyBucket);
        $heatmap = array_fill(1, 7, array_fill(0, 24, 0));
        $heatmapDates = [];
        $channels = [];
        $branches = [];
        $categories = [];
        $products = [];
        $pairs = [];
        $kpis = ['revenue' => 0.0, 'items' => 0, 'orders' => 0, 'free_items' => 0];
        $weekdayOf = [];

        $current = null; // pedido em andamento no cursor (as linhas vêm ordenadas por pedido)

        $flush = function () use (&$current, &$daily, &$hourly, &$heatmap, &$heatmapDates, &$channels, &$branches, &$products, &$pairs, &$kpis) {
            if ($current === null || $current['keys'] === []) {
                $current = null;

                return;
            }

            ['date' => $date, 'hour' => $hour, 'weekday' => $weekday] = $current;
            $kpis['orders']++;
            $daily[$date]['orders'] = ($daily[$date]['orders'] ?? 0) + 1;
            $hourly[$hour]['orders']++;
            $heatmap[$weekday][$hour]++;
            $heatmapDates[$weekday][$hour][$date] = true;
            $channels[$current['channel']]['orders'] = ($channels[$current['channel']]['orders'] ?? 0) + 1;
            $branches[$current['branch_id']]['orders'] = ($branches[$current['branch_id']]['orders'] ?? 0) + 1;

            $keys = array_keys($current['keys']);
            foreach ($keys as $key) {
                $products[$key]['orders']++;
            }

            $maxProducts = (int) config('sales_insights.pairs.max_products_per_order', 12);
            $count = count($keys);
            if ($count >= 2 && $count <= $maxProducts) {
                sort($keys);
                for ($i = 0; $i < $count; $i++) {
                    for ($j = $i + 1; $j < $count; $j++) {
                        $pair = $keys[$i].'|'.$keys[$j];
                        $pairs[$pair] = ($pairs[$pair] ?? 0) + 1;
                    }
                }
            }

            $current = null;
        };

        $rows = $this->baseQuery($companyId, $filters)
            ->orderBy('o.id')
            ->select([
                'o.id as order_id', 'o.created_at', 'o.branch_id', 'o.channel', 'o.order_type',
                'oi.product_id', 'oi.product_name', 'oi.quantity', 'oi.subtotal',
                'p.name as current_name', 'p.product_category_id', 'p.deleted_at as product_deleted_at',
            ])
            ->cursor();

        foreach ($rows as $row) {
            if ($current === null || $current['id'] !== (int) $row->order_id) {
                $flush();

                $createdAt = (string) $row->created_at;
                $date = substr($createdAt, 0, 10);
                $current = [
                    'id' => (int) $row->order_id,
                    'date' => $date,
                    'hour' => (int) substr($createdAt, 11, 2),
                    'weekday' => $weekdayOf[$date] ??= CarbonImmutable::parse($date)->dayOfWeekIso,
                    'channel' => self::channelOf($row->channel, $row->order_type),
                    'branch_id' => (int) $row->branch_id,
                    'keys' => [],
                ];
            }

            $quantity = (int) $row->quantity;
            $subtotal = (float) $row->subtotal;
            $productId = $row->product_id ? (int) $row->product_id : null;
            $key = self::productKey($productId, $row->product_name);

            // Brinde (subtotal zero) não é venda: fica de fora de quantidade, faturamento e regras.
            if ($subtotal <= 0) {
                $kpis['free_items'] += $quantity;
                $products[$key]['free_qty'] = ($products[$key]['free_qty'] ?? 0) + $quantity;

                continue;
            }

            ['date' => $date, 'hour' => $hour, 'weekday' => $weekday, 'channel' => $channel, 'branch_id' => $branchId] = $current;

            if (! isset($products[$key]['name'])) {
                $products[$key] = $this->emptyProduct(
                    $productId,
                    (string) ($row->current_name ?? $row->product_name),
                    $row->product_category_id ? (int) $row->product_category_id : null,
                    $row->product_deleted_at !== null,
                    $products[$key]['free_qty'] ?? 0,
                );
            }

            $product = &$products[$key];
            $product['qty'] += $quantity;
            $product['revenue'] += $subtotal;
            $product['hours'][$hour] += $quantity;
            $product['weekdays'][$weekday] += $quantity;
            $product['daily'][$date] = ($product['daily'][$date] ?? 0) + $quantity;
            $product['channels'][$channel] = ($product['channels'][$channel] ?? 0) + $quantity;
            $product['branches'][$branchId] = ($product['branches'][$branchId] ?? 0) + $quantity;
            if ($date >= $recentFrom) {
                $product['recent_qty_by_branch'][$branchId] = ($product['recent_qty_by_branch'][$branchId] ?? 0) + $quantity;
            }
            $product['first_sale'] ??= (string) $row->created_at;
            $product['last_sale'] = max($product['last_sale'] ?? '', (string) $row->created_at);
            unset($product);

            $current['keys'][$key] = true;
            $kpis['items'] += $quantity;
            $kpis['revenue'] += $subtotal;
            $daily[$date]['items'] += $quantity;
            $daily[$date]['revenue'] += $subtotal;
            $hourly[$hour]['items'] += $quantity;
            $hourly[$hour]['revenue'] += $subtotal;
            $channels[$channel]['items'] = ($channels[$channel]['items'] ?? 0) + $quantity;
            $channels[$channel]['revenue'] = ($channels[$channel]['revenue'] ?? 0) + $subtotal;
            $branches[$branchId]['items'] = ($branches[$branchId]['items'] ?? 0) + $quantity;
            $branches[$branchId]['revenue'] = ($branches[$branchId]['revenue'] ?? 0) + $subtotal;
        }
        $flush();

        // Só brinde no período: não houve venda do produto. O brinde volta se ele vendeu no período anterior.
        $freeOnly = array_map(fn ($product) => $product['free_qty'], array_filter($products, fn ($product) => ! isset($product['name'])));
        $products = array_filter($products, fn ($product) => isset($product['name']));

        $previousProducts = $this->previousByProduct($companyId, $filters->previous());
        foreach ($previousProducts as $key => $previous) {
            if (! isset($products[$key])) {
                $products[$key] = $this->emptyProduct($previous['product_id'], $previous['name'], $previous['category_id'], $previous['deleted'], $freeOnly[$key] ?? 0);
            }
            $products[$key]['prev_qty'] = $previous['qty'];
            $products[$key]['prev_revenue'] = $previous['revenue'];
        }

        foreach ($products as $product) {
            if ($product['qty'] <= 0) {
                continue;
            }
            $categoryId = $product['category_id'] ?? 0;
            $categories[$categoryId]['items'] = ($categories[$categoryId]['items'] ?? 0) + $product['qty'];
            $categories[$categoryId]['revenue'] = ($categories[$categoryId]['revenue'] ?? 0) + $product['revenue'];
        }

        $categoryNames = ProductCategory::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('id', array_keys($categories))
            ->pluck('name', 'id');
        foreach (array_keys($categories) as $id) {
            $categories[$id]['name'] = $categoryNames[$id] ?? 'Sem categoria';
        }

        $branchNames = Branch::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('id', array_keys($branches))
            ->pluck('name', 'id');
        foreach ($branches as $id => $branch) {
            $branches[$id] = ['name' => $branchNames[$id] ?? 'Filial #'.$id] + $branch + $emptyBucket;
        }
        foreach ($channels as $id => $channel) {
            $channels[$id] = $channel + $emptyBucket;
        }

        $heatmapDays = array_fill(1, 7, array_fill(0, 24, 0));
        foreach ($heatmapDates as $weekday => $hours) {
            foreach ($hours as $hour => $dates) {
                $heatmapDays[$weekday][$hour] = count($dates);
            }
        }

        $minSupport = (int) config('sales_insights.pairs.min_support', 2);
        $pairs = array_filter($pairs, fn ($count) => $count >= $minSupport);
        arsort($pairs);
        $pairs = array_slice($pairs, 0, (int) config('sales_insights.pairs.cap', 500), true);

        $kpis['products'] = count(array_filter($products, fn ($product) => $product['qty'] > 0));

        return [
            'meta' => [
                'start' => $filters->start->format('Y-m-d'),
                'end' => $filters->end->format('Y-m-d'),
                'days' => $filters->days(),
                'weekday_occurrences' => $filters->weekdayOccurrences(),
                'generated_at' => now()->format('Y-m-d H:i:s'),
            ],
            'kpis' => $this->withRatios($kpis),
            'previous_kpis' => $this->withRatios($this->previousTotals($companyId, $filters->previous()) + ['products' => count($previousProducts)]),
            'daily' => $daily,
            'hourly' => $hourly,
            'heatmap' => $heatmap,
            'heatmap_days' => $heatmapDays,
            'channels' => $channels,
            'categories' => $categories,
            'branches' => $branches,
            'products' => $products,
            'pairs' => array_map(
                fn ($pair, $count) => ['a' => explode('|', $pair)[0], 'b' => explode('|', $pair)[1], 'count' => $count],
                array_keys($pairs),
                $pairs,
            ),
        ];
    }

    private function emptyProduct(?int $productId, string $name, ?int $categoryId, bool $deleted, int $freeQty): array
    {
        return [
            'product_id' => $productId,
            'name' => $name,
            'category_id' => $categoryId,
            'deleted' => $deleted,
            'qty' => 0,
            'revenue' => 0.0,
            'orders' => 0,
            'free_qty' => $freeQty,
            'prev_qty' => 0,
            'prev_revenue' => 0.0,
            'first_sale' => null,
            'last_sale' => null,
            'hours' => array_fill(0, 24, 0),
            'weekdays' => array_fill(1, 7, 0),
            'daily' => [],
            'channels' => [],
            'branches' => [],
            'recent_qty_by_branch' => [],
        ];
    }

    /** Vendas do período anterior por produto (GROUP BY portável, sem função de data). */
    private function previousByProduct(int $companyId, SoldItemsFilters $previous): array
    {
        $rows = $this->baseQuery($companyId, $previous)
            ->where('oi.subtotal', '>', 0)
            ->groupBy('oi.product_id', 'oi.product_name')
            ->selectRaw('oi.product_id, oi.product_name, MAX(p.name) as current_name, MAX(p.product_category_id) as category_id, MAX(p.deleted_at) as deleted_at, SUM(oi.quantity) as qty, SUM(oi.subtotal) as revenue')
            ->get();

        $byKey = [];
        foreach ($rows as $row) {
            $productId = $row->product_id ? (int) $row->product_id : null;
            $key = self::productKey($productId, $row->product_name);
            $byKey[$key] ??= [
                'product_id' => $productId,
                'name' => (string) ($row->current_name ?? $row->product_name),
                'category_id' => $row->category_id ? (int) $row->category_id : null,
                'deleted' => $row->deleted_at !== null,
                'qty' => 0,
                'revenue' => 0.0,
            ];
            $byKey[$key]['qty'] += (int) $row->qty;
            $byKey[$key]['revenue'] += (float) $row->revenue;
        }

        return $byKey;
    }

    private function previousTotals(int $companyId, SoldItemsFilters $previous): array
    {
        $row = $this->baseQuery($companyId, $previous)
            ->where('oi.subtotal', '>', 0)
            ->selectRaw('COUNT(DISTINCT o.id) as orders, COALESCE(SUM(oi.quantity), 0) as items, COALESCE(SUM(oi.subtotal), 0) as revenue')
            ->first();

        return [
            'revenue' => (float) ($row->revenue ?? 0),
            'items' => (int) ($row->items ?? 0),
            'orders' => (int) ($row->orders ?? 0),
            'free_items' => 0,
        ];
    }

    private function withRatios(array $kpis): array
    {
        $orders = $kpis['orders'];

        return $kpis + [
            'items_per_order' => $orders > 0 ? $kpis['items'] / $orders : 0.0,
            'ticket' => $orders > 0 ? $kpis['revenue'] / $orders : 0.0,
        ];
    }
}
