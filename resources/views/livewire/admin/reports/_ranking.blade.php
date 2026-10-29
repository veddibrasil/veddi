@php
    $ranking = $this->ranking;
    $badgeColors = [
        'amber' => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
        'green' => 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300',
        'red' => 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300',
        'purple' => 'bg-purple-100 text-purple-800 dark:bg-purple-900/40 dark:text-purple-300',
        'zinc' => 'bg-neutral-100 text-neutral-600 dark:bg-zinc-700 dark:text-neutral-300',
    ];
@endphp

<section class="bg-white border rounded-xl shadow-sm overflow-hidden dark:bg-zinc-800 dark:border-zinc-700">
    <div class="flex flex-wrap items-end justify-between gap-3 p-4 sm:p-5 border-b dark:border-zinc-700">
        <h2 class="text-lg font-semibold text-neutral-800 dark:text-neutral-100">Ranking de produtos</h2>
        <div class="flex flex-col sm:flex-row gap-2 w-full sm:w-auto">
            <div class="sm:w-60">
                <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" placeholder="Buscar produto" aria-label="Buscar produto" />
            </div>
            <div class="sm:w-48">
                <flux:select wire:model.live="sort" aria-label="Ordenar por">
                    @foreach (\App\Livewire\Admin\Reports\SoldItems::SORTS as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        </div>
    </div>

    @if ($ranking->total() === 0)
        <p class="text-sm text-neutral-500 dark:text-neutral-400 p-8 text-center">
            {{ $search !== '' ? 'Nenhum produto encontrado para a busca.' : 'Nenhum item vendido no período.' }}
        </p>
    @else
        {{-- relative: o sr-only (absoluto) do cabeçalho não escapa do scroll e não estoura a página no celular --}}
        <div class="relative overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-zinc-700/50">
                    <tr class="text-xs font-medium text-neutral-400 uppercase tracking-wide">
                        <th class="text-left px-4 py-2">Produto</th>
                        <th class="text-right px-3 py-2">Qtd</th>
                        <th class="text-right px-3 py-2">Faturamento</th>
                        <th class="text-right px-3 py-2 hidden md:table-cell">% fat.</th>
                        <th class="text-right px-3 py-2 hidden lg:table-cell">Pedidos</th>
                        <th class="text-right px-3 py-2 hidden lg:table-cell">Preço médio</th>
                        <th class="text-right px-3 py-2 hidden sm:table-cell">Variação</th>
                        <th class="text-right px-3 py-2 hidden xl:table-cell">Última venda</th>
                        <th class="px-4 py-2"><span class="sr-only">Ações</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y dark:divide-zinc-700">
                    @foreach ($ranking as $row)
                        <tr wire:key="row-{{ $row['key'] }}" class="hover:bg-neutral-50 dark:hover:bg-zinc-700/40">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3 min-w-0">
                                    @if ($row['image_url'])
                                        <img src="{{ $row['image_url'] }}" alt="" class="w-9 h-9 rounded-lg object-cover shrink-0" loading="lazy">
                                    @else
                                        <div class="w-9 h-9 rounded-lg bg-neutral-100 dark:bg-zinc-700 shrink-0"></div>
                                    @endif
                                    <div class="min-w-0">
                                        <p class="font-medium text-neutral-800 dark:text-neutral-100 truncate max-w-[9rem] sm:max-w-xs">{{ $row['name'] }}</p>
                                        <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ $row['category'] }}</p>
                                        @if ($row['badges'])
                                            <div class="flex flex-wrap gap-1 mt-1">
                                                @foreach ($row['badges'] as $badge)
                                                    <span class="text-[10px] font-medium px-1.5 py-0.5 rounded {{ $badgeColors[$badge['color']] }}">{{ $badge['label'] }}</span>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td class="px-3 py-3 text-right font-semibold text-neutral-800 dark:text-neutral-100 tabular-nums">
                                <x-admin.report-tooltip class="block" :tooltip-key="'ranking-qty-'.$row['key']" :heading="'Unidades: '.$row['name']" description="Unidades vendidas do produto. Brindes são exibidos separadamente e ficam fora das vendas." :rows="['Período selecionado' => $number($row['qty']), 'Período anterior' => $number($row['prev_qty']), 'Brindes (excluídos)' => $number($row['free_qty'])]" trigger-class="min-h-7 rounded-sm text-right">
                                    {{ $number($row['qty']) }}
                                    @if ($row['free_qty'] > 0)
                                        <span class="block text-[10px] font-normal text-neutral-400">+{{ $row['free_qty'] }} brinde</span>
                                    @endif
                                </x-admin.report-tooltip>
                            </td>
                            <td class="px-3 py-3 text-right tabular-nums text-neutral-700 dark:text-neutral-300">
                                <x-admin.report-tooltip class="block" :tooltip-key="'ranking-revenue-'.$row['key']" :heading="'Faturamento: '.$row['name']" description="Soma dos subtotais deste produto, antes dos descontos e taxas do pedido." :rows="['Faturamento dos itens' => $money($row['revenue']), 'Unidades vendidas' => $number($row['qty']), 'Preço médio vendido' => $money($row['avg_price'])]" trigger-class="min-h-7 rounded-sm text-right">{{ $money($row['revenue']) }}</x-admin.report-tooltip>
                            </td>
                            <td class="px-3 py-3 text-right tabular-nums text-neutral-500 hidden md:table-cell">
                                <x-admin.report-tooltip class="block" :tooltip-key="'ranking-share-'.$row['key']" :heading="'Participação: '.$row['name']" description="Faturamento deste produto dividido pelo faturamento total dos itens nos filtros selecionados." :rows="['Produto' => $money($row['revenue']), 'Total dos itens' => $money($kpis['revenue']), 'Participação' => $number($row['share'] * 100, 1).'%']" trigger-class="min-h-7 rounded-sm text-right">{{ $number($row['share'] * 100, 1) }}%</x-admin.report-tooltip>
                            </td>
                            <td class="px-3 py-3 text-right tabular-nums text-neutral-500 hidden lg:table-cell">
                                <x-admin.report-tooltip class="block" :tooltip-key="'ranking-orders-'.$row['key']" :heading="'Pedidos: '.$row['name']" description="Pedidos com este produto, contando cada pedido uma vez, mesmo que contenha várias unidades." :rows="['Pedidos com o produto' => $number($row['orders']), 'Unidades vendidas' => $number($row['qty'])]" trigger-class="min-h-7 rounded-sm text-right">{{ $number($row['orders']) }}</x-admin.report-tooltip>
                            </td>
                            <td class="px-3 py-3 text-right tabular-nums text-neutral-500 hidden lg:table-cell">
                                <x-admin.report-tooltip class="block" :tooltip-key="'ranking-avg_price-'.$row['key']" :heading="'Preço médio: '.$row['name']" description="Faturamento do produto dividido pelas unidades vendidas. Pode diferir do preço atual do cadastro." :rows="['Faturamento' => $money($row['revenue']), 'Unidades' => $number($row['qty']), 'Preço médio vendido' => $money($row['avg_price'])]" trigger-class="min-h-7 rounded-sm text-right">{{ $money($row['avg_price']) }}</x-admin.report-tooltip>
                            </td>
                            <td class="px-3 py-3 text-right tabular-nums hidden sm:table-cell">
                                <x-admin.report-tooltip class="block" :tooltip-key="'ranking-variation-'.$row['key']" :heading="'Variação: '.$row['name']" description="Variação das unidades vendidas em relação ao período anterior de mesma duração. Quando a base anterior é zero, não calculamos percentual." :rows="['Período selecionado' => $number($row['qty']), 'Período anterior' => $number($row['prev_qty'])]" trigger-class="min-h-7 rounded-sm text-right">
                                    @if ($row['variation'] === null)
                                        <span class="text-neutral-400">novo</span>
                                    @elseif ($row['variation'] >= 0)
                                        <span class="text-green-600 dark:text-green-400">+{{ $number($row['variation'] * 100) }}%</span>
                                    @else
                                        <span class="text-red-600 dark:text-red-400">{{ $number($row['variation'] * 100) }}%</span>
                                    @endif
                                </x-admin.report-tooltip>
                            </td>
                            <td class="px-3 py-3 text-right text-xs text-neutral-500 hidden xl:table-cell">
                                {{ $row['last_sale'] ? \Carbon\CarbonImmutable::parse($row['last_sale'])->format('d/m H:i') : '—' }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <button type="button" wire:click="openDetail('{{ $row['key'] }}')"
                                        class="text-xs font-medium text-amber-600 hover:underline dark:text-amber-400">
                                    Detalhes
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($ranking->hasPages())
            <div class="px-4 py-3 border-t dark:border-zinc-700">
                {{ $ranking->links() }}
            </div>
        @endif
    @endif
</section>
