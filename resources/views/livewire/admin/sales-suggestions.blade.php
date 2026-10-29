@php
    $result = $this->suggestions;
    $insights = collect($result['insights']);
    $titles = ['all' => 'Sugestões para vender mais', 'products' => 'Sugestões para seus produtos', 'orders' => 'Oportunidades nos pedidos', 'stock' => 'Sugestões para o estoque', 'coupons' => 'Oportunidades para cupons'];
    $reportParams = array_filter(['periodo' => 'personalizado', 'de' => $result['window']->start->format('Y-m-d'), 'ate' => $result['window']->end->format('Y-m-d'), 'filial' => $result['window']->branchId, 'categoria' => $result['window']->categoryId, 'produto' => $productId ? 'p:'.$productId : null]);
@endphp
<section x-data="{ expanded: false }" class="min-w-0 rounded-xl border border-neutral-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-800" aria-label="{{ $titles[$context] }}">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-base font-semibold text-neutral-800 dark:text-neutral-100">{{ $titles[$context] }}</h2>
            <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">{{ $result['window']->start->format('d/m') }} a {{ $result['window']->end->format('d/m') }} · {{ $result['orders'] }} pedidos analisados{{ $orderId ? ' · produtos deste pedido, na mesma filial' : '' }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" wire:click="refresh" wire:loading.attr="disabled" wire:target="refresh" class="min-h-11 rounded-lg px-3 py-2 text-xs font-medium text-neutral-600 hover:bg-neutral-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-600 disabled:opacity-50 dark:text-neutral-300 dark:hover:bg-zinc-700">
                <span wire:loading.remove wire:target="refresh">Atualizar sugestões</span>
                <span wire:loading wire:target="refresh" role="status">Atualizando…</span>
            </button>
            <a href="{{ route('admin.reports.sold-items', $reportParams) }}" wire:navigate class="inline-flex min-h-11 items-center rounded-lg px-3 py-2 text-xs font-semibold text-amber-800 hover:bg-amber-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-600 dark:text-amber-300 dark:hover:bg-amber-900/30">Ver relatório completo →</a>
        </div>
    </div>
    <div wire:loading.class="opacity-50" wire:target="refresh" class="mt-3">
        @if (! $result['enough_data'])
            <p class="text-sm text-neutral-600 dark:text-neutral-300">As sugestões aparecem a partir de {{ config('sales_insights.min_orders') }} pedidos com itens vendidos na janela analisada. Faltam {{ max(0, config('sales_insights.min_orders') - $result['orders']) }} pedidos para identificar padrões de venda.</p>
        @elseif ($insights->isEmpty())
            <p class="text-sm text-neutral-600 dark:text-neutral-300">Nenhuma sugestão para este contexto no momento. Veja o relatório completo para explorar as vendas.</p>
        @else
            <div id="sales-suggestions-{{ $this->getId() }}" class="grid grid-cols-1 gap-3 lg:grid-cols-3">
                @foreach ($insights->take(3) as $insight)
                    <div @class(['min-w-0', 'hidden lg:block' => ! $loop->first]) :style="expanded ? { display: 'block' } : {}">
                        <x-admin.sales-insight-card :insight="$insight" :report-params="$reportParams" />
                    </div>
                @endforeach
            </div>
            @if ($insights->count() > 1)
                <button type="button" @click="expanded = ! expanded" :aria-expanded="expanded.toString()" aria-controls="sales-suggestions-{{ $this->getId() }}" class="mt-3 min-h-11 rounded-lg border border-neutral-200 px-3 py-2 text-xs font-medium text-neutral-700 hover:bg-neutral-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-600 lg:hidden dark:border-zinc-600 dark:text-neutral-300 dark:hover:bg-zinc-700" x-text="expanded ? 'Mostrar menos' : 'Ver mais sugestões ({{ min(3, $insights->count()) - 1 }})'">Ver mais sugestões</button>
            @endif
            <p class="mt-3 text-xs text-neutral-500 dark:text-neutral-400">Sugestões prioritárias deste contexto ({{ $insights->count() }} no total). Os atalhos abrem os formulários para revisão; as alterações só entram em vigor ao salvar.</p>
        @endif
    </div>
</section>
