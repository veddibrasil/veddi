<?php

namespace App\Services\Sales;

use App\DTOs\SalesInsight;
use App\DTOs\SoldItemsFilters;
use App\Models\Company;
use App\Models\CompanyNotification;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Avisos de vendas no sino do painel, só para company_admin (is_admin_only):
 *
 * - diário: sugestões "Agir agora" (estoque acabando, campeão fora de venda, queda forte) dos
 *   últimos 28 dias até ontem. Como nos monitores de WhatsApp/iFood, o alerta enviado fica
 *   registrado (companies.sales_notifications_state), volta como lembrete depois de REMINDER_DAYS
 *   dias enquanto o problema durar e sai do registro quando o problema some (se voltar, avisa de
 *   novo na hora). O registro não depende da notificação: limpar o sino não reenvia;
 * - semanal (segunda): resumo da semana anterior com faturamento dos itens vs semana anterior,
 *   campeões e as principais sugestões.
 *
 * Roda no agendador, fora de request: tudo por company_id explícito, nunca pelo tenant bindado.
 */
class SalesNotifier
{
    public const REMINDER_DAYS = 7;

    /** Mais que isso num dia vira ruído: o restante entra nos dias seguintes (já vem por peso). */
    public const MAX_DAILY = 5;

    public function __construct(
        private SoldItemsReportService $reports,
        private SalesInsightsService $insights,
    ) {}

    /**
     * Empresas ativas com venda confirmada nos últimos $days dias (as demais não têm o que avisar).
     *
     * @return Collection<int, Company>
     */
    public function companies(int $days, ?int $companyId = null): Collection
    {
        $since = CarbonImmutable::today()->subDays($days);

        return Company::query()
            ->where('active', true)
            ->when($companyId, fn ($q) => $q->whereKey($companyId))
            // EXISTS por empresa usa o índice orders(company_id, created_at); um DISTINCT varreria a tabela toda.
            ->whereExists(fn ($q) => $q->selectRaw('1')
                ->from('orders')
                ->whereColumn('orders.company_id', 'companies.id')
                ->whereIn('orders.status', Order::CONFIRMED_STATUSES)
                ->where('orders.created_at', '>=', $since))
            ->orderBy('id')
            ->get();
    }

    /** Alertas urgentes do dia. Devolve quantas notificações foram criadas. */
    public function daily(Company $company): int
    {
        $yesterday = CarbonImmutable::yesterday();
        $filters = new SoldItemsFilters($yesterday->subDays(27)->startOfDay(), $yesterday->endOfDay());

        $urgent = [];
        foreach ($this->insights->urgent($company, $filters) as $insight) {
            $urgent[$this->alertKey($insight)] ??= $insight;
        }

        $state = (array) ($company->sales_notifications_state ?? []);
        $today = CarbonImmutable::today();
        $reminderFrom = $today->subDays(self::REMINDER_DAYS);

        // Só os problemas que ainda existem continuam registrados; os resolvidos saem daqui.
        $registered = array_intersect_key((array) ($state['alerts'] ?? []), $urgent);

        $due = array_filter(
            $urgent,
            fn (SalesInsight $insight, string $key) => ! isset($registered[$key])
                || CarbonImmutable::parse($registered[$key])->lessThanOrEqualTo($reminderFrom),
            ARRAY_FILTER_USE_BOTH,
        );
        $due = array_slice($due, 0, self::MAX_DAILY, true);

        if ($due !== []) {
            $products = $this->reports->build($company->id, $filters)['products'];

            foreach ($due as $key => $insight) {
                $subject = $insight->productKey ? ($products[$insight->productKey]['name'] ?? null) : null;

                CompanyNotification::create([
                    'company_id' => $company->id,
                    'type' => 'sales_alert',
                    'is_admin_only' => true,
                    'title' => Str::limit($insight->title.($subject ? ': '.$subject : ''), 250),
                    'subtitle' => Str::limit($insight->message, 250),
                    'link' => route('admin.reports.sold-items', array_filter(['produto' => $insight->productKey]), absolute: false),
                ]);

                $registered[$key] = $today->toDateString();
            }

            Log::info('Vendas: alertas do dia no sino do admin', ['company_id' => $company->id, 'alerts' => count($due)]);
        }

        $this->saveState($company, ['alerts' => $registered] + $state);

        return count($due);
    }

    /** Resumo da semana anterior (segunda a domingo). Devolve 1 se criou a notificação. */
    public function weekly(Company $company): int
    {
        $weekStart = CarbonImmutable::today()->startOfWeek(CarbonImmutable::MONDAY)->subWeek();
        $state = (array) ($company->sales_notifications_state ?? []);

        if (($state['weekly'] ?? null) === $weekStart->toDateString()) {
            return 0;
        }

        $filters = new SoldItemsFilters($weekStart, $weekStart->addDays(6)->endOfDay());
        $report = $this->reports->build($company->id, $filters);
        $kpis = $report['kpis'];

        if ($kpis['orders'] === 0) {
            return 0;
        }

        $previousRevenue = (float) $report['previous_kpis']['revenue'];
        $percent = $previousRevenue > 0 ? (int) round(($kpis['revenue'] / $previousRevenue - 1) * 100) : null;
        $variation = $percent === null ? '' : ' ('.($percent > 0 ? '+' : '').number_format($percent, 0, ',', '.').'% vs semana anterior)';

        // Mesmo critério do selo "Campeão" da tela; sem campeões claros, cita os mais vendidos.
        $championKeys = array_slice($this->insights->championKeys($report), 0, 3);
        $highlights = $championKeys !== []
            ? 'Campeões: '.implode(', ', array_map(fn ($key) => $report['products'][$key]['name'], $championKeys))
            : 'Mais vendidos: '.collect($report['products'])->filter(fn ($p) => $p['qty'] > 0)->sortByDesc('revenue')->take(3)->pluck('name')->implode(', ');

        // As sugestões usam no mínimo 28 dias (SalesInsightsService): o texto deixa isso claro.
        $ideas = collect($this->insights->generate($company, $filters)['insights'])
            ->take(3)
            ->map(fn (SalesInsight $insight) => $insight->productKey && isset($report['products'][$insight->productKey])
                ? $insight->title.' ('.$report['products'][$insight->productKey]['name'].')'
                : $insight->title)
            ->all();

        CompanyNotification::create([
            'company_id' => $company->id,
            'type' => 'sales_summary',
            'is_admin_only' => true,
            'title' => Str::limit('Resumo da semana: '.$this->money($kpis['revenue']).' em itens'.$variation, 250),
            'subtitle' => Str::limit(
                $kpis['orders'].' pedidos · '.$highlights.($ideas ? ' · Sugestões (últimos 28 dias): '.implode('; ', $ideas) : ''),
                250,
            ),
            'link' => route('admin.reports.sold-items', [
                'periodo' => 'personalizado',
                'de' => $filters->start->format('Y-m-d'),
                'ate' => $filters->end->format('Y-m-d'),
            ], absolute: false),
        ]);

        $this->saveState($company, ['weekly' => $weekStart->toDateString()] + $state);

        return 1;
    }

    /** Mesmo tipo + produto (+ filial, no estoque) = mesmo alerta. */
    private function alertKey(SalesInsight $insight): string
    {
        $branch = collect($insight->actions)->pluck('params.filial')->filter()->first();

        return $insight->type.'|'.$insight->productKey.'|'.$branch;
    }

    private function saveState(Company $company, array $state): void
    {
        if ($state === (array) ($company->sales_notifications_state ?? [])) {
            return;
        }

        Company::query()->whereKey($company->id)->update(['sales_notifications_state' => json_encode($state)]);
        $company->sales_notifications_state = $state;
        $company->syncOriginalAttribute('sales_notifications_state');
    }

    private function money(float $value): string
    {
        return 'R$ '.number_format($value, 2, ',', '.');
    }
}
