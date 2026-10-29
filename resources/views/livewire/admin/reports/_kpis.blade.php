@php
    $cards = [
        ['key' => 'revenue', 'label' => 'Faturamento dos itens', 'value' => $money($kpis['revenue']), 'previous' => $money($previousKpis['revenue']), 'description' => 'Soma dos subtotais dos itens vendidos, antes dos descontos e taxas do pedido. Frete e taxas de serviço não entram neste valor.', 'color' => 'text-green-600 dark:text-green-400'],
        ['key' => 'items', 'label' => 'Itens vendidos', 'value' => $number($kpis['items']), 'previous' => $number($previousKpis['items']), 'description' => 'Quantidade de unidades vendidas. Um pedido com três unidades soma três itens. Brindes ficam fora desta contagem.', 'color' => 'text-amber-600 dark:text-amber-400'],
        ['key' => 'orders', 'label' => 'Pedidos', 'value' => $number($kpis['orders']), 'previous' => $number($previousKpis['orders']), 'description' => 'Pedidos confirmados com pelo menos um item vendido nos filtros selecionados. Comandas abertas e pedidos cancelados ficam fora.', 'color' => 'text-neutral-800 dark:text-neutral-100'],
        ['key' => 'ticket', 'label' => 'Ticket médio (itens)', 'value' => $money($kpis['ticket']), 'previous' => $money($previousKpis['ticket']), 'description' => 'Faturamento dos itens dividido pela quantidade de pedidos. Mostra o valor médio dos itens por pedido nos filtros selecionados.', 'color' => 'text-blue-600 dark:text-blue-400'],
        ['key' => 'items_per_order', 'label' => 'Itens por pedido', 'value' => $number($kpis['items_per_order'], 1), 'previous' => $number($previousKpis['items_per_order'], 1), 'description' => 'Unidades vendidas divididas pela quantidade de pedidos. Indica quantos itens, em média, saem em cada pedido.', 'color' => 'text-neutral-800 dark:text-neutral-100'],
        ['key' => 'products', 'label' => 'Produtos vendidos', 'value' => $number($kpis['products']), 'previous' => $number($previousKpis['products']), 'description' => 'Quantidade de produtos diferentes com vendas no período. Várias unidades do mesmo produto contam uma vez. Itens avulsos são agrupados pelo nome.', 'color' => 'text-neutral-800 dark:text-neutral-100'],
    ];
    $previousWindow = $filters->previous();
@endphp

<p class="text-xs text-neutral-500 dark:text-neutral-400">Passe o mouse, toque ou use o teclado sobre os indicadores e gráficos para ver detalhes.</p>
<div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3">
    @foreach ($cards as $card)
        @php
            $change = $delta($kpis[$card['key']], $previousKpis[$card['key']]);
            $rows = ['Período selecionado' => $card['value'], 'Período anterior' => $card['previous'], 'Variação' => $change === null ? 'Sem base anterior' : ($change >= 0 ? '+' : '').$number($change * 100, 1).'%'];
            if ($card['key'] === 'items' && $kpis['free_items'] > 0) $rows['Brindes (excluídos)'] = $number($kpis['free_items']);
        @endphp
        <x-admin.report-tooltip class="block" :heading="$card['label']" :description="$card['description']" :rows="$rows"
            :note="'Comparação com '.$previousWindow->start->format('d/m/Y').' a '.$previousWindow->end->format('d/m/Y H:i').($filters->includesToday() ? ' (mesmo horário de hoje).' : '.').' Quando o valor anterior é zero, não calculamos variação percentual.'"
            trigger-class="h-full rounded-xl border bg-white p-4 shadow-sm transition-colors duration-150 hover:bg-neutral-50 dark:border-zinc-700 dark:bg-zinc-800 dark:hover:bg-zinc-700/50">
            <span class="flex items-start justify-between gap-1 text-xs text-neutral-500 uppercase tracking-wide dark:text-neutral-400">
                {{ $card['label'] }}
                <svg aria-hidden="true" class="mt-0.5 h-3.5 w-3.5 shrink-0 text-neutral-400" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="10" cy="10" r="7.5"/><path d="M10 9v5m0-8v1"/></svg>
            </span>
            <span class="block text-xl sm:text-2xl xl:text-xl 2xl:text-2xl font-bold mt-1 whitespace-nowrap {{ $card['color'] }}">{{ $card['value'] }}</span>
            <span class="block text-xs mt-1">
                @if ($change === null)
                    <span class="text-neutral-400">— sem base anterior</span>
                @elseif ($change >= 0)
                    <span class="text-green-600 dark:text-green-400">▲ {{ $number($change * 100, 1) }}%</span>
                    <span class="text-neutral-400">vs anterior</span>
                @else
                    <span class="text-red-600 dark:text-red-400">▼ {{ $number(abs($change) * 100, 1) }}%</span>
                    <span class="text-neutral-400">vs anterior</span>
                @endif
            </span>
        </x-admin.report-tooltip>
    @endforeach
</div>

@if ($kpis['free_items'] > 0)
    <p class="text-xs text-neutral-500 dark:text-neutral-400 -mt-2">
        + {{ $number($kpis['free_items']) }} item(ns) de brinde no período (fora das contas acima).
    </p>
@endif
