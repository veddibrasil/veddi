@php
    $groups = [
        1 => ['label' => 'Agir agora', 'dot' => 'bg-red-500'],
        2 => ['label' => 'Oportunidades', 'dot' => 'bg-amber-500'],
        3 => ['label' => 'Ideias', 'dot' => 'bg-blue-500'],
    ];
    $allInsights = collect($insightsResult['insights']);
    $byPriority = collect($this->filteredInsights)->groupBy('priority');
@endphp

<section class="min-w-0 rounded-xl border bg-white p-4 shadow-sm sm:p-5 dark:border-zinc-700 dark:bg-zinc-800" aria-label="Sugestões para vender mais">
    <div class="mb-4 flex flex-wrap items-start justify-between gap-2">
        <div>
            <h2 class="text-lg font-semibold text-neutral-800 dark:text-neutral-100">Sugestões para vender mais</h2>
            <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                {{ $insightsResult['orders'] }} pedidos analisados · {{ $insightsResult['window']->start->format('d/m/Y') }} a {{ $insightsResult['window']->end->format('d/m/Y') }}
                @if ($insightsResult['extended'])
                    · janela ampliada para {{ $insightsResult['window']->days() }} dias para identificar padrões.
                @endif
            </p>
        </div>
        <span class="rounded-full bg-neutral-100 px-2.5 py-1 text-xs font-medium text-neutral-600 dark:bg-zinc-700 dark:text-neutral-300">{{ $allInsights->count() }} sugestões</span>
    </div>

    @if ($allInsights->isNotEmpty())
        <div class="mb-4 flex flex-col justify-between gap-3 xl:flex-row xl:items-center">
            <div class="flex flex-wrap gap-2" role="group" aria-label="Filtrar sugestões por prioridade">
                @foreach (['all' => ['label' => 'Todas']] + $groups as $priority => $group)
                    @php $selected = (string) $priority === $insightPriority; @endphp
                    <button type="button" wire:click="$set('insightPriority', '{{ $priority }}')" aria-pressed="{{ $selected ? 'true' : 'false' }}"
                        class="inline-flex min-h-11 items-center gap-2 rounded-lg border px-3 py-2 text-xs font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-600 {{ $selected ? 'border-amber-500 bg-amber-50 text-amber-900 dark:border-amber-600 dark:bg-amber-900/30 dark:text-amber-200' : 'border-neutral-200 text-neutral-600 hover:bg-neutral-50 dark:border-zinc-600 dark:text-neutral-300 dark:hover:bg-zinc-700' }}">
                        @isset($group['dot']) <span aria-hidden="true" class="h-2 w-2 rounded-full {{ $group['dot'] }}"></span> @endisset
                        {{ $group['label'] }}
                        <span>{{ $priority === 'all' ? $allInsights->count() : $allInsights->where('priority', $priority)->count() }}</span>
                    </button>
                @endforeach
            </div>
            <div class="w-full xl:w-60">
                <flux:select wire:model.live="insightType" aria-label="Tipo de sugestão">
                    <flux:select.option value="all">Todos os tipos de sugestão</flux:select.option>
                    @foreach (\App\DTOs\SalesInsight::TYPES as $type => $label)
                        @if ($allInsights->contains('type', $type))
                            <flux:select.option value="{{ $type }}">{{ $label }}</flux:select.option>
                        @endif
                    @endforeach
                </flux:select>
            </div>
        </div>
    @endif

    <div wire:loading.class="opacity-50" wire:target="insightPriority,insightType" aria-live="polite">
        @if (! $insightsResult['enough_data'])
            <p class="text-sm text-neutral-600 dark:text-neutral-300">
                Ainda há poucos pedidos ({{ $insightsResult['orders'] }}) para sugestões confiáveis. Precisamos de pelo menos
                {{ config('sales_insights.min_orders') }} pedidos com itens vendidos no período analisado.
            </p>
        @elseif ($allInsights->isEmpty())
            <p class="text-sm text-neutral-600 dark:text-neutral-300">Nenhuma sugestão atende aos critérios no período analisado. Explore o ranking e os horários de venda para acompanhar os produtos.</p>
        @elseif ($byPriority->isEmpty())
            <div class="rounded-lg bg-neutral-50 p-4 text-sm text-neutral-600 dark:bg-zinc-900/40 dark:text-neutral-300">
                <p>Nenhuma sugestão corresponde aos filtros selecionados.</p>
                <button type="button" wire:click="clearInsightFilters" class="mt-2 min-h-11 rounded-lg px-3 py-2 font-medium text-amber-800 hover:bg-amber-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-600 dark:text-amber-300 dark:hover:bg-amber-900/30">Limpar filtros de sugestões</button>
            </div>
        @else
            <div class="space-y-5">
                @foreach ($groups as $priority => $group)
                    @continue(! $byPriority->has($priority))
                    <div>
                        <p class="mb-2 flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-neutral-500 dark:text-neutral-400">
                            <span aria-hidden="true" class="h-2 w-2 rounded-full {{ $group['dot'] }}"></span>
                            {{ $group['label'] }} · {{ $byPriority[$priority]->count() }}
                        </p>
                        <div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
                            @foreach ($byPriority[$priority] as $insight)
                                <x-admin.sales-insight-card :insight="$insight" :inline-detail="true" :show-detail="$insight->productKey && isset($report['products'][$insight->productKey])" />
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
    @if ($allInsights->isNotEmpty())
        <p class="mt-4 text-xs text-neutral-500 dark:text-neutral-400">Os atalhos abrem os formulários para revisão: as alterações só entram em vigor ao salvar. Cupons valem no chat próprio (não no iFood nem no PDV).</p>
    @endif
</section>
