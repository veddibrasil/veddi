@php
    // Média de pedidos por ocorrência do dia da semana: 5 sextas no período não pesam mais que 4 terças.
    $occurrences = $report['meta']['weekday_occurrences'];
    $averages = [];
    foreach ($report['heatmap'] as $weekday => $hours) {
        foreach ($hours as $hour => $orders) {
            $averages[$weekday][$hour] = $occurrences[$weekday] > 0 ? $orders / $occurrences[$weekday] : 0;
        }
    }
    $maxAverage = max(0.0001, collect($averages)->flatten()->max());
    $activeHours = collect(range(0, 23))->filter(fn ($hour) => collect($averages)->sum(fn ($hours) => $hours[$hour]) > 0);
    $hours = $activeHours->isEmpty() ? collect(range(0, 23)) : collect(range($activeHours->min(), $activeHours->max()));
    $peak = null;
    foreach ($averages as $weekday => $row) {
        foreach ($row as $hour => $average) {
            if ($average > 0 && (! $peak || $average > $peak['average'])) {
                $peak = compact('weekday', 'hour', 'average');
            }
        }
    }
@endphp

<section class="bg-white border rounded-xl shadow-sm p-4 sm:p-5 dark:bg-zinc-800 dark:border-zinc-700">
    <div class="flex flex-wrap items-baseline justify-between gap-2 mb-4">
        <h2 class="text-lg font-semibold text-neutral-800 dark:text-neutral-100">Quando a loja vende</h2>
        @if ($peak)
            <p class="text-xs text-neutral-500 dark:text-neutral-400">
                Pico: <strong>{{ $weekdays[$peak['weekday']] }} às {{ $peak['hour'] }}h</strong> ({{ $number($peak['average'], 1) }} pedidos em média)
            </p>
        @endif
    </div>

    @if (! $peak)
        <p class="text-sm text-neutral-500 dark:text-neutral-400 py-6 text-center">Nenhuma venda no período.</p>
    @else
        <div class="relative overflow-x-auto">
            <table class="border-separate border-spacing-0.5 text-[11px] min-w-max" aria-label="Pedidos por dia da semana e hora">
                <thead>
                    <tr>
                        <th class="w-9"></th>
                        @foreach ($hours as $hour)
                            <th class="font-normal text-neutral-400 w-7 text-center">{{ $hour }}h</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($weekdays as $weekday => $label)
                        <tr>
                            <th scope="row" class="font-medium text-neutral-500 dark:text-neutral-400 text-left pr-1">{{ $label }}</th>
                            @foreach ($hours as $hour)
                                @php
                                    $average = $averages[$weekday][$hour];
                                    $alpha = $average > 0 ? max(0.08, round($average / $maxAverage, 2)) : 0;
                                @endphp
                                <td class="h-7 w-7 p-0">
                                    <x-admin.report-tooltip class="block h-7 w-7" :heading="$label.', '.$hour.'h a '.($hour + 1).'h'" :label="'Detalhes de '.$label.' às '.$hour.'h'" trigger-class="flex h-7 items-center justify-center rounded hover:ring-2 hover:ring-amber-500 hover:ring-inset"
                                        description="Média calculada por ocorrência deste dia da semana, incluindo os dias sem vendas."
                                        :rows="['Pedidos no período' => $number($report['heatmap'][$weekday][$hour]), 'Ocorrências deste dia' => $number($occurrences[$weekday]), 'Dias com vendas neste horário' => $number($report['heatmap_days'][$weekday][$hour]), 'Média de pedidos' => $number($average, 2)]"
                                        note="O horário corresponde à criação do pedido; não ao horário de entrega.">
                                        <span class="block h-full w-full rounded {{ $average > 0 ? '' : 'bg-neutral-50 dark:bg-zinc-900/40' }}" @if ($average > 0) style="background-color: rgb(245 158 11 / {{ $alpha }})" @endif></span>
                                    </x-admin.report-tooltip>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="text-[11px] text-neutral-400 mt-2">Cor mais forte = mais pedidos naquele dia e hora (hora em que o pedido foi feito). Use os horários fortes para divulgar e os fracos para promoções relâmpago.</p>
    @endif
</section>
