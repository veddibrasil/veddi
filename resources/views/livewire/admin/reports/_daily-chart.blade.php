@php
    // Um dia só: mostra por hora; senão, por dia.
    $byHour = $report['meta']['days'] === 1;
    $series = $byHour
        ? collect($report['hourly'])->mapWithKeys(fn ($bucket, $hour) => [sprintf('%02dh', $hour) => $bucket])
        : collect($report['daily'])->mapWithKeys(fn ($bucket, $date) => [\Carbon\CarbonImmutable::parse($date)->format('d/m') => $bucket + ['weekday' => \Carbon\CarbonImmutable::parse($date)->dayOfWeekIso, 'date' => $date]]);
    $maxRevenue = max(1, (float) $series->max('revenue'));
    $labels = $series->keys();
    $best = $series->sortByDesc('revenue')->keys()->first();
@endphp

<section class="bg-white border rounded-xl shadow-sm p-4 sm:p-5 h-full dark:bg-zinc-800 dark:border-zinc-700">
    <div class="flex flex-wrap items-baseline justify-between gap-2 mb-4">
        <h2 class="text-lg font-semibold text-neutral-800 dark:text-neutral-100">{{ $byHour ? 'Vendas por hora' : 'Vendas por dia' }}</h2>
        @if ($kpis['revenue'] > 0)
            <p class="text-xs text-neutral-500 dark:text-neutral-400">Melhor {{ $byHour ? 'hora' : 'dia' }}: <strong>{{ $best }}</strong> · {{ $money($series[$best]['revenue']) }}</p>
        @endif
    </div>

    @if ($kpis['revenue'] <= 0)
        <p class="text-sm text-neutral-500 dark:text-neutral-400 py-10 text-center">Nenhuma venda no período.</p>
    @else
        <div class="flex items-end gap-px sm:gap-0.5 h-44" role="group" aria-label="{{ $byHour ? 'Faturamento por hora' : 'Faturamento por dia' }}">
            @foreach ($series as $label => $bucket)
                @php
                    $height = $bucket['revenue'] > 0 ? max(2, round($bucket['revenue'] / $maxRevenue * 100)) : 0;
                    $weekend = ! $byHour && in_array($bucket['weekday'] ?? 0, [6, 7], true);
                @endphp
                <x-admin.report-tooltip class="block h-full flex-1" trigger-class="flex h-full items-end rounded-sm"
                    :heading="$byHour ? $label.' a '.sprintf('%02dh', (int) $label + 1) : $weekdays[$bucket['weekday']].', '.\Carbon\CarbonImmutable::parse($bucket['date'])->format('d/m/Y')"
                    :label="'Detalhes das vendas em '.$label"
                    :rows="['Faturamento dos itens' => $money($bucket['revenue']), 'Unidades vendidas' => $number($bucket['items']), 'Pedidos' => $number($bucket['orders']), 'Ticket médio dos itens' => $money($bucket['orders'] > 0 ? $bucket['revenue'] / $bucket['orders'] : 0), 'Participação no período' => $number($bucket['revenue'] / $kpis['revenue'] * 100, 1).'%']"
                    :note="$bucket['orders'] > 0 ? 'Valores dos filtros selecionados. Frete, descontos e taxas do pedido não entram no faturamento dos itens.' : 'Nenhum pedido com itens vendidos neste intervalo.'">
                    <span class="block w-full rounded-t {{ $weekend ? 'bg-amber-400 dark:bg-amber-500' : 'bg-amber-300 dark:bg-amber-600/80' }} group-hover:bg-amber-500 group-focus-visible:bg-amber-500 transition-colors duration-150"
                         style="height: {{ $height }}%"></span>
                </x-admin.report-tooltip>
            @endforeach
        </div>
        <div class="flex justify-between text-[11px] text-neutral-400 mt-2">
            <span>{{ $labels->first() }}</span>
            @if ($labels->count() > 2)
                <span>{{ $labels[intdiv($labels->count(), 2)] }}</span>
            @endif
            <span>{{ $labels->last() }}</span>
        </div>
        @unless ($byHour)
            <p class="text-[11px] text-neutral-400 mt-2">Barras mais fortes = fim de semana. Passe o mouse, toque ou use Enter sobre uma barra para ver os valores.</p>
        @endunless
    @endif
</section>
