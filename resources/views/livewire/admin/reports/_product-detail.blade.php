<flux:modal wire:model.self="showDetail" class="w-full max-w-3xl">
    @php $detail = $showDetail ? $this->detail : null; @endphp

    @if ($detail)
        @php
            $item = $detail['item'];
            $product = $detail['product'];
            $maxDaily = max(1, max($detail['daily'] ?: [0]));
            $maxHour = max(1, max($item['hours']));
            $maxWeekday = max(1, max($item['weekdays']));
            $variation = $delta($item['qty'], $item['prev_qty']);
        @endphp

        <div class="space-y-5">
            <div class="flex items-start gap-3 pr-6">
                @if ($product?->image_url)
                    <img src="{{ $product->image_url }}" alt="" class="w-14 h-14 rounded-lg object-cover shrink-0">
                @endif
                <div class="min-w-0">
                    <flux:heading size="lg">{{ $product?->name ?? $item['name'] }}</flux:heading>
                    <p class="text-sm text-neutral-500 dark:text-neutral-400">
                        @if ($product)
                            {{ $product->category?->name ?? 'Sem categoria' }} ·
                            @if ($product->promo_price_enabled)
                                <span class="line-through">{{ $money($product->price) }}</span> {{ $money($product->effective_price) }}
                            @else
                                {{ $money($product->price) }}
                            @endif
                        @else
                            Item avulso do iFood (sem produto vinculado)
                        @endif
                    </p>
                </div>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <x-admin.report-tooltip class="block" heading="Unidades deste produto" description="Unidades vendidas nos filtros selecionados. Brindes não entram nesta quantidade."
                    :rows="['Período selecionado' => $number($item['qty']), 'Período anterior' => $number($item['prev_qty']), 'Brindes (excluídos)' => $number($item['free_qty'])]" trigger-class="h-full rounded-lg bg-neutral-50 p-3 dark:bg-zinc-900/40">
                    <span class="block text-[11px] uppercase text-neutral-500">Unidades</span>
                    <span class="block text-lg font-bold text-neutral-800 dark:text-neutral-100">{{ $number($item['qty']) }}</span>
                    <span class="block text-[11px] {{ $variation === null ? 'text-neutral-400' : ($variation >= 0 ? 'text-green-600' : 'text-red-600') }}">
                        {{ $variation === null ? 'sem base anterior' : ($variation >= 0 ? '+' : '').$number($variation * 100).'% vs anterior' }}
                    </span>
                </x-admin.report-tooltip>
                <x-admin.report-tooltip class="block" heading="Faturamento deste produto" description="Soma dos subtotais vendidos deste produto, antes dos descontos e taxas do pedido."
                    :rows="['Faturamento' => $money($item['revenue']), 'Período anterior' => $money($item['prev_revenue']), 'Participação no total' => $number($kpis['revenue'] > 0 ? $item['revenue'] / $kpis['revenue'] * 100 : 0, 1).'%']" trigger-class="h-full rounded-lg bg-neutral-50 p-3 dark:bg-zinc-900/40">
                    <span class="block text-[11px] uppercase text-neutral-500">Faturamento</span>
                    <span class="block text-lg font-bold text-neutral-800 dark:text-neutral-100">{{ $money($item['revenue']) }}</span>
                </x-admin.report-tooltip>
                <x-admin.report-tooltip class="block" heading="Pedidos com este produto" description="Cada pedido conta uma vez, mesmo que contenha várias unidades deste produto."
                    :rows="['Pedidos com o produto' => $number($item['orders']), 'Unidades vendidas' => $number($item['qty']), 'Participação nos pedidos' => $number($kpis['orders'] > 0 ? $item['orders'] / $kpis['orders'] * 100 : 0, 1).'%']" trigger-class="h-full rounded-lg bg-neutral-50 p-3 dark:bg-zinc-900/40">
                    <span class="block text-[11px] uppercase text-neutral-500">Pedidos</span>
                    <span class="block text-lg font-bold text-neutral-800 dark:text-neutral-100">{{ $number($item['orders']) }}</span>
                </x-admin.report-tooltip>
                <x-admin.report-tooltip class="block" heading="Preço médio vendido" description="Faturamento deste produto dividido pelas unidades vendidas. Pode diferir do preço atual do cadastro."
                    :rows="['Faturamento do produto' => $money($item['revenue']), 'Unidades vendidas' => $number($item['qty']), 'Preço médio' => $money($item['qty'] > 0 ? $item['revenue'] / $item['qty'] : 0)]" trigger-class="h-full rounded-lg bg-neutral-50 p-3 dark:bg-zinc-900/40">
                    <span class="block text-[11px] uppercase text-neutral-500">Preço médio</span>
                    <span class="block text-lg font-bold text-neutral-800 dark:text-neutral-100">{{ $money($item['qty'] > 0 ? $item['revenue'] / $item['qty'] : 0) }}</span>
                </x-admin.report-tooltip>
            </div>

            @if ($detail['insights'])
                <div class="space-y-2">
                    <h3 class="text-sm font-semibold text-neutral-800 dark:text-neutral-100">O que fazer com este produto</h3>
                    @foreach ($detail['insights'] as $insight)
                        <x-admin.sales-insight-card :insight="$insight" :show-detail="false" />
                    @endforeach
                </div>
            @endif

            <div>
                <h3 class="text-sm font-semibold text-neutral-800 dark:text-neutral-100 mb-2">Unidades por dia</h3>
                <div class="flex items-end gap-px h-24">
                    @foreach ($detail['daily'] as $date => $quantity)
                        <x-admin.report-tooltip class="block h-full flex-1" trigger-class="flex h-full items-end rounded-sm" :heading="'Unidades em '.\Carbon\CarbonImmutable::parse($date)->format('d/m/Y')"
                            :rows="['Unidades vendidas' => $number($quantity), 'Participação nas unidades' => $number($item['qty'] > 0 ? $quantity / $item['qty'] * 100 : 0, 1).'%']" note="Somente este produto, nos filtros selecionados. Brindes ficam fora da contagem.">
                            <span class="block w-full rounded-t bg-amber-400 group-hover:bg-amber-500" style="height: {{ $quantity > 0 ? max(3, round($quantity / $maxDaily * 100)) : 0 }}%"></span>
                        </x-admin.report-tooltip>
                    @endforeach
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div>
                    <h3 class="text-sm font-semibold text-neutral-800 dark:text-neutral-100 mb-2">Por hora do pedido</h3>
                    <div class="flex items-end gap-px h-20">
                        @foreach ($item['hours'] as $hour => $quantity)
                            <x-admin.report-tooltip class="block h-full flex-1" trigger-class="flex h-full items-end rounded-sm" :heading="'Unidades das '.$hour.'h às '.($hour + 1).'h'"
                                :rows="['Unidades vendidas' => $number($quantity), 'Participação nas unidades' => $number($item['qty'] > 0 ? $quantity / $item['qty'] * 100 : 0, 1).'%']" note="Horário de criação do pedido. Soma as vendas deste horário em todos os dias do período.">
                                <span class="block w-full rounded-t bg-amber-300 group-hover:bg-amber-500 dark:bg-amber-600" style="height: {{ $quantity > 0 ? max(3, round($quantity / $maxHour * 100)) : 0 }}%"></span>
                            </x-admin.report-tooltip>
                        @endforeach
                    </div>
                    <div class="flex justify-between text-[10px] text-neutral-400 mt-1"><span>0h</span><span>12h</span><span>23h</span></div>
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-neutral-800 dark:text-neutral-100 mb-2">Por dia da semana</h3>
                    <div class="space-y-1">
                        @foreach ($weekdays as $weekday => $label)
                            <x-admin.report-tooltip class="block" :heading="'Vendas do produto: '.$label" trigger-class="flex min-h-7 items-center gap-2 rounded-sm text-xs"
                                :rows="['Unidades no período' => $number($item['weekdays'][$weekday]), 'Ocorrências deste dia' => $number($report['meta']['weekday_occurrences'][$weekday]), 'Média por ocorrência' => $number($report['meta']['weekday_occurrences'][$weekday] > 0 ? $item['weekdays'][$weekday] / $report['meta']['weekday_occurrences'][$weekday] : 0, 1)]">
                                <span class="w-8 text-neutral-500">{{ $label }}</span>
                                <span class="block flex-1 h-2 rounded-full bg-neutral-100 dark:bg-zinc-700 overflow-hidden">
                                    <span class="block h-full bg-amber-400" style="width: {{ round($item['weekdays'][$weekday] / $maxWeekday * 100) }}%"></span>
                                </span>
                                <span class="w-8 text-right tabular-nums text-neutral-500">{{ $item['weekdays'][$weekday] }}</span>
                            </x-admin.report-tooltip>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div>
                    <h3 class="text-sm font-semibold text-neutral-800 dark:text-neutral-100 mb-2">Comprado junto</h3>
                    @forelse ($detail['pairs'] as $pair)
                        <div class="flex justify-between gap-2 text-sm py-1">
                            <span class="truncate text-neutral-700 dark:text-neutral-300">{{ $pair['name'] }}</span>
                            <span class="shrink-0 text-xs text-neutral-500">{{ $pair['count'] }} pedidos · {{ $number($pair['share'] * 100) }}%</span>
                        </div>
                    @empty
                        <p class="text-xs text-neutral-400">Ainda sem combinações frequentes.</p>
                    @endforelse
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-neutral-800 dark:text-neutral-100 mb-2">Canais e estoque</h3>
                    @foreach ($item['channels'] as $channel => $quantity)
                        <div class="flex justify-between text-sm py-0.5">
                            <span class="text-neutral-700 dark:text-neutral-300">{{ \App\Livewire\Admin\Reports\SoldItems::CHANNELS[$channel] ?? $channel }}</span>
                            <span class="text-xs text-neutral-500">{{ $quantity }} un. · {{ $number($item['qty'] > 0 ? $quantity / $item['qty'] * 100 : 0) }}%</span>
                        </div>
                    @endforeach
                    @foreach ($detail['stock'] as $stock)
                        <div class="flex justify-between text-sm py-0.5">
                            <span class="text-neutral-700 dark:text-neutral-300">{{ $stock['name'] }}</span>
                            <span class="text-xs {{ $stock['available'] ? 'text-neutral-500' : 'text-red-600' }}">
                                {{ $stock['available'] ? 'disponível' : 'indisponível' }}{{ $stock['track_stock'] ? ' · '.$stock['quantity'].' un.' : '' }}
                            </span>
                        </div>
                    @endforeach
                </div>
            </div>

            @if ($product && ! $product->trashed())
                <div class="flex justify-end gap-2 pt-2 border-t dark:border-zinc-700">
                    <a href="{{ route('admin.products.edit', $product) }}" class="text-sm font-medium px-4 py-2 rounded-lg border border-neutral-300 text-neutral-700 hover:bg-neutral-50 dark:border-zinc-600 dark:text-neutral-300 dark:hover:bg-zinc-700">Editar produto</a>
                </div>
            @endif
        </div>
    @endif
</flux:modal>
