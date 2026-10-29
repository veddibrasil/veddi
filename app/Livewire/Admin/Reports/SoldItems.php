<?php

namespace App\Livewire\Admin\Reports;

use App\DTOs\SalesInsight;
use App\DTOs\SoldItemsFilters;
use App\Livewire\Admin\Concerns\RequiresCompanyAdmin;
use App\Models\Branch;
use App\Models\Company;
use App\Models\ProductCategory;
use App\Services\Sales\SalesInsightsService;
use App\Services\Sales\SoldItemsReportService;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Itens vendidos por produto/dia/hora/canal e sugestões de venda. Só company_admin
 * (rota no grupo company.role:company_admin + RequiresCompanyAdmin em toda requisição).
 *
 * O agregado fica em cache no service e só chega à view por #[Computed]: nada dele vai para
 * propriedade pública (snapshot pequeno e sem como o cliente adulterar números).
 */
class SoldItems extends Component
{
    use RequiresCompanyAdmin;
    use WithPagination;

    public const PERIODS = [
        'hoje' => 'Hoje',
        'ontem' => 'Ontem',
        '7d' => 'Últimos 7 dias',
        '30d' => 'Últimos 30 dias',
        'mes' => 'Este mês',
        'mes-passado' => 'Mês passado',
        'personalizado' => 'Personalizado',
    ];

    public const SORTS = [
        'qty' => 'Mais vendidos',
        'revenue' => 'Maior faturamento',
        'growth' => 'Maior alta',
        'decline' => 'Maior queda',
        'last_sale' => 'Última venda',
        'name' => 'Nome',
    ];

    public const CHANNELS = ['chat' => 'Chat', 'pdv' => 'PDV', 'ifood' => 'iFood'];

    private const PER_PAGE = 25;

    #[Url(as: 'periodo', except: '30d')]
    public string $period = '30d';

    #[Url(as: 'de', except: '')]
    public string $dateStart = '';

    #[Url(as: 'ate', except: '')]
    public string $dateEnd = '';

    // Filtros vindos da URL: revalidados contra o tenant em SoldItemsFilters::fromInput().
    #[Url(as: 'filial', except: '')]
    public string $branchFilter = '';

    #[Url(as: 'canal', except: '')]
    public string $channelFilter = '';

    #[Url(as: 'categoria', except: '')]
    public string $categoryFilter = '';

    #[Url(as: 'busca', except: '')]
    public string $search = '';

    #[Url(as: 'ordem', except: 'qty')]
    public string $sort = 'qty';

    #[Url(as: 'prioridade', except: 'all')]
    public string $insightPriority = 'all';

    #[Url(as: 'sugestao', except: 'all')]
    public string $insightType = 'all';

    /** Produto aberto no detalhe (link das notificações abre direto nele); conferido contra o agregado. */
    #[Url(as: 'produto', except: null)]
    public ?string $detailKey = null;

    public bool $showDetail = false;

    public function mount(): void
    {
        if (! array_key_exists($this->period, self::PERIODS)) {
            $this->period = '30d';
        }

        if ($this->period !== 'personalizado' || $this->dateStart === '' || $this->dateEnd === '') {
            $this->period = $this->period === 'personalizado' ? '30d' : $this->period;
            $this->applyPeriod();
        }

        if (! array_key_exists($this->sort, self::SORTS)) {
            $this->sort = 'qty';
        }

        if ($this->detailKey !== null) {
            $this->showDetail = isset($this->report['products'][$this->detailKey]);
            $this->detailKey = $this->showDetail ? $this->detailKey : null;
        }
    }

    public function updatedPeriod(): void
    {
        if (! array_key_exists($this->period, self::PERIODS)) {
            $this->period = '30d';
        }

        if ($this->period !== 'personalizado') {
            $this->applyPeriod();
        }

        $this->resetPage();
    }

    public function updatedDateStart(): void
    {
        $this->period = 'personalizado';
        $this->resetPage();
    }

    public function updatedDateEnd(): void
    {
        $this->period = 'personalizado';
        $this->resetPage();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['branchFilter', 'channelFilter', 'categoryFilter', 'search', 'sort'], true)) {
            $this->resetPage();
        }
    }

    private function applyPeriod(): void
    {
        $today = CarbonImmutable::today();

        [$start, $end] = match ($this->period) {
            'hoje' => [$today, $today],
            'ontem' => [$today->subDay(), $today->subDay()],
            '7d' => [$today->subDays(6), $today],
            'mes' => [$today->startOfMonth(), $today],
            'mes-passado' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()],
            default => [$today->subDays(29), $today],
        };

        $this->dateStart = $start->format('Y-m-d');
        $this->dateEnd = $end->format('Y-m-d');
    }

    private function company(): Company
    {
        return app('current.company');
    }

    #[Computed]
    public function filters(): SoldItemsFilters
    {
        return SoldItemsFilters::fromInput(
            $this->company()->id,
            $this->dateStart,
            $this->dateEnd,
            $this->branchFilter,
            $this->channelFilter ?: null,
            $this->categoryFilter,
        );
    }

    #[Computed]
    public function report(): array
    {
        return app(SoldItemsReportService::class)->build($this->company()->id, $this->filters);
    }

    /** @return array{insights: list<SalesInsight>, window: SoldItemsFilters, extended: bool, enough_data: bool, orders: int} */
    #[Computed]
    public function insights(): array
    {
        return app(SalesInsightsService::class)->generate($this->company(), $this->filters);
    }

    #[Computed]
    public function filteredInsights(): array
    {
        return array_values(array_filter($this->insights['insights'], fn (SalesInsight $insight) => (! in_array($this->insightPriority, ['1', '2', '3'], true) || $insight->priority === (int) $this->insightPriority)
            && (! isset(SalesInsight::TYPES[$this->insightType]) || $insight->type === $this->insightType)
        ));
    }

    public function clearInsightFilters(): void
    {
        $this->insightPriority = 'all';
        $this->insightType = 'all';
        unset($this->filteredInsights);
    }

    #[Computed]
    public function catalog(): Collection
    {
        $soldIds = array_values(array_filter(array_column($this->report['products'], 'product_id')));

        return app(SoldItemsReportService::class)->catalog($this->company()->id, $soldIds, onlySold: true);
    }

    /** Opções dos filtros: o CompanyScope já restringe ao tenant atual. */
    #[Computed]
    public function branches(): Collection
    {
        return Branch::orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function categories(): Collection
    {
        return ProductCategory::orderBy('name')->get(['id', 'name']);
    }

    /** Linhas do ranking (todas, já filtradas pela busca e ordenadas). */
    #[Computed]
    public function rows(): array
    {
        $report = $this->report;
        $catalog = $this->catalog;
        $total = (float) $report['kpis']['revenue'];
        $champions = array_flip(app(SalesInsightsService::class)->championKeys($report));
        $search = Str::lower(Str::ascii(trim($this->search)));
        $rows = [];

        foreach ($report['products'] as $key => $item) {
            $product = $item['product_id'] ? $catalog->get($item['product_id']) : null;
            $name = $product?->name ?? $item['name'];
            $category = $item['product_id'] ? ($product?->category?->name ?? '—') : 'Avulso iFood';

            // Busca pelo nome ou pela categoria ("burg" acha a categoria Burgers).
            if ($search !== '' && ! Str::contains(Str::lower(Str::ascii($name.' '.$category)), $search)) {
                continue;
            }

            $variation = $item['prev_qty'] > 0 ? ($item['qty'] - $item['prev_qty']) / $item['prev_qty'] : null;

            $rows[] = [
                'key' => $key,
                'name' => $name,
                'category' => $category,
                'qty' => $item['qty'],
                'free_qty' => $item['free_qty'],
                'revenue' => $item['revenue'],
                'share' => $total > 0 ? $item['revenue'] / $total : 0.0,
                'orders' => $item['orders'],
                'avg_price' => $item['qty'] > 0 ? $item['revenue'] / $item['qty'] : 0.0,
                'prev_qty' => $item['prev_qty'],
                'variation' => $variation,
                'last_sale' => $item['last_sale'],
                'image_url' => $product?->image_url,
                'badges' => $this->badges($key, $item, $product, $variation, $champions),
            ];
        }

        usort($rows, match ($this->sort) {
            'revenue' => fn ($a, $b) => $b['revenue'] <=> $a['revenue'],
            'growth' => fn ($a, $b) => [$a['variation'] === null, $b['variation']] <=> [$b['variation'] === null, $a['variation']],
            'decline' => fn ($a, $b) => [$a['variation'] === null, $a['variation']] <=> [$b['variation'] === null, $b['variation']],
            'last_sale' => fn ($a, $b) => ($b['last_sale'] ?? '') <=> ($a['last_sale'] ?? ''),
            'name' => fn ($a, $b) => Str::ascii(Str::lower($a['name'])) <=> Str::ascii(Str::lower($b['name'])),
            default => fn ($a, $b) => [$b['qty'], $b['revenue']] <=> [$a['qty'], $a['revenue']],
        });

        return $rows;
    }

    private function badges(string $key, array $item, $product, ?float $variation, array $champions): array
    {
        $badges = [];

        if (isset($champions[$key])) {
            $badges[] = ['label' => 'Campeão', 'color' => 'amber'];
        }
        if ($variation !== null && $variation >= 0.3 && $item['prev_qty'] >= config('sales_insights.rules.trending_up.min_previous')) {
            $badges[] = ['label' => 'Em alta', 'color' => 'green'];
        }
        if ($variation !== null && $variation <= -0.3 && $item['prev_qty'] >= config('sales_insights.rules.trending_down.min_previous')) {
            $badges[] = ['label' => 'Em queda', 'color' => 'red'];
        }
        if ($product?->promo_price_enabled) {
            $badges[] = ['label' => 'Promo', 'color' => 'purple'];
        }
        if ($product && ! $product->active && ! $product->trashed()) {
            $badges[] = ['label' => 'Inativo', 'color' => 'zinc'];
        }
        if ($item['deleted'] || $product?->trashed()) {
            $badges[] = ['label' => 'Excluído', 'color' => 'zinc'];
        }
        if (! $item['product_id']) {
            $badges[] = ['label' => 'Avulso iFood', 'color' => 'zinc'];
        }

        return $badges;
    }

    #[Computed]
    public function ranking(): LengthAwarePaginator
    {
        $rows = $this->rows;
        $page = max(1, (int) $this->getPage());

        return new LengthAwarePaginator(
            array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            count($rows),
            self::PER_PAGE,
            $page,
        );
    }

    #[Computed]
    public function detail(): ?array
    {
        $report = $this->report;
        $item = $this->detailKey ? ($report['products'][$this->detailKey] ?? null) : null;

        if (! $item) {
            return null;
        }

        $product = $item['product_id'] ? $this->catalog->get($item['product_id']) : null;

        $pairs = collect($report['pairs'])
            ->filter(fn ($pair) => $pair['a'] === $this->detailKey || $pair['b'] === $this->detailKey)
            ->map(function ($pair) use ($report, $item) {
                $otherKey = $pair['a'] === $this->detailKey ? $pair['b'] : $pair['a'];

                return [
                    'name' => $report['products'][$otherKey]['name'] ?? '—',
                    'count' => $pair['count'],
                    'share' => $item['orders'] > 0 ? $pair['count'] / $item['orders'] : 0.0,
                ];
            })
            ->sortByDesc('count')
            ->take(5)
            ->values()
            ->all();

        $daily = [];
        foreach (array_keys($report['daily']) as $date) {
            $daily[$date] = $item['daily'][$date] ?? 0;
        }

        return [
            'key' => $this->detailKey,
            'item' => $item,
            'product' => $product,
            'daily' => $daily,
            'pairs' => $pairs,
            'stock' => $product
                ? $product->branches->map(fn ($branch) => [
                    'name' => $branch->name,
                    'available' => (bool) $branch->pivot->available,
                    'track_stock' => (bool) $branch->pivot->track_stock,
                    'quantity' => (int) $branch->pivot->quantity,
                ])->all()
                : [],
            'insights' => array_values(array_filter(
                app(SalesInsightsService::class)->generate(
                    $this->company(), $this->filters, productIds: $item['product_id'] ? [$item['product_id']] : [],
                )['insights'],
                fn (SalesInsight $insight) => $insight->concerns($this->detailKey),
            )),
        ];
    }

    public function openDetail(string $key): void
    {
        if (! isset($this->report['products'][$key])) {
            return;
        }

        $this->detailKey = $key;
        $this->showDetail = true;
        unset($this->detail);
    }

    /** Modal fechado pelo Esc/clique fora (wire:model): tira o produto da URL também. */
    public function updatedShowDetail(bool $value): void
    {
        if (! $value) {
            $this->detailKey = null;
        }
    }

    public function closeDetail(): void
    {
        $this->showDetail = false;
        $this->detailKey = null;
    }

    /** Descarta o cache do agregado (período exibido e janela das sugestões). */
    public function refresh(): void
    {
        $service = app(SoldItemsReportService::class);
        $companyId = $this->company()->id;
        $service->forget($companyId, $this->filters);
        $service->forget($companyId, $this->filters->withMinimumWindow((int) config('sales_insights.min_window_days', 28)));
        // Alerta de estoque lê a janela sem filtro de canal (estoque é um só para todos os canais).
        $service->forget($companyId, $this->filters->withMinimumWindow((int) config('sales_insights.min_window_days', 28))->withChannel(null));
        app(SalesInsightsService::class)->forget($companyId, $this->filters);

        unset($this->report, $this->insights, $this->filteredInsights, $this->catalog, $this->rows, $this->ranking, $this->detail);
    }

    public function exportCsv()
    {
        $rows = $this->rows;
        $filters = $this->filters;
        $filename = 'itens-vendidos-'.$filters->start->format('Y-m-d').'-a-'.$filters->end->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel

            fputcsv($handle, [
                'Produto', 'Categoria', 'Quantidade', 'Faturamento (R$)', '% do faturamento', 'Pedidos',
                'Preço médio (R$)', 'Qtd. período anterior', 'Variação (%)', 'Última venda',
            ], ';', '"', '');

            // Nome de item avulso vem do payload do iFood: "=", "+", "-", "@" no início viraria fórmula no Excel.
            $text = fn (string $value) => preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;

            foreach ($rows as $row) {
                fputcsv($handle, [
                    $text($row['name']),
                    $text($row['category']),
                    $row['qty'],
                    number_format($row['revenue'], 2, ',', '.'),
                    number_format($row['share'] * 100, 1, ',', '.'),
                    $row['orders'],
                    number_format($row['avg_price'], 2, ',', '.'),
                    $row['prev_qty'],
                    $row['variation'] === null ? '—' : number_format($row['variation'] * 100, 1, ',', '.'),
                    $row['last_sale'] ? CarbonImmutable::parse($row['last_sale'])->format('d/m/Y H:i') : '—',
                ], ';', '"', '');
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function render()
    {
        return view('livewire.admin.reports.sold-items')
            ->layout('layouts.app', ['title' => 'Itens vendidos']);
    }
}
