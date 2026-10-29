@php
    $channelLabels = \App\Livewire\Admin\Reports\SoldItems::CHANNELS;
    $total = max(0.01, (float) $kpis['revenue']);
    $sections = [
        'Por canal' => collect($report['channels'])->map(fn ($bucket, $channel) => ['name' => $channelLabels[$channel] ?? $channel] + $bucket),
        'Por categoria' => collect($report['categories'])->values(),
    ];
    if (count($report['branches']) > 1) {
        $sections['Por filial'] = collect($report['branches'])->values();
    }
@endphp

<section class="bg-white border rounded-xl shadow-sm p-4 sm:p-5 h-full dark:bg-zinc-800 dark:border-zinc-700 space-y-5">
    @foreach ($sections as $title => $rows)
        <div>
            <h2 class="text-sm font-semibold text-neutral-800 dark:text-neutral-100 mb-2">{{ $title }}</h2>
            @forelse ($rows->sortByDesc('revenue')->take(6) as $row)
                @php $share = $row['revenue'] / $total; @endphp
                @php
                    $detailRows = ['Faturamento dos itens' => $money($row['revenue']), 'Participação no período' => $number($share * 100, 1).'%', 'Unidades vendidas' => $number($row['items'])];
                    if (isset($row['orders'])) {
                        $detailRows['Pedidos'] = $number($row['orders']);
                        $detailRows['Ticket médio dos itens'] = $money($row['orders'] > 0 ? $row['revenue'] / $row['orders'] : 0);
                    }
                @endphp
                <x-admin.report-tooltip class="mb-2 block" :tooltip-key="'breakdown-'.$title.'-'.$loop->index" :heading="$title.': '.$row['name']" :rows="$detailRows" description="A participação compara o faturamento deste grupo com o total dos itens nos filtros selecionados." trigger-class="rounded-sm py-1">
                    <span class="flex justify-between gap-2 text-xs">
                        <span class="truncate text-neutral-700 dark:text-neutral-300">{{ $row['name'] }}</span>
                        <span class="shrink-0 text-neutral-500 dark:text-neutral-400">{{ $money($row['revenue']) }} · {{ $number($share * 100, 1) }}%</span>
                    </span>
                    <span class="block h-1.5 rounded-full bg-neutral-100 dark:bg-zinc-700 mt-1 overflow-hidden">
                        <span class="block h-full rounded-full bg-amber-400 group-hover:bg-amber-500 transition-colors duration-150" style="width: {{ min(100, round($share * 100, 1)) }}%"></span>
                    </span>
                </x-admin.report-tooltip>
            @empty
                <p class="text-xs text-neutral-400">Sem vendas.</p>
            @endforelse
        </div>
    @endforeach
</section>
