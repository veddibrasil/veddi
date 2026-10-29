@php
    $report = $this->report;
    $kpis = $report['kpis'];
    $previousKpis = $report['previous_kpis'];
    $insightsResult = $this->insights;
    $money = fn ($value) => 'R$ '.number_format((float) $value, 2, ',', '.');
    $number = fn ($value, $decimals = 0) => number_format((float) $value, $decimals, ',', '.');
    $delta = fn ($current, $previous) => $previous > 0 ? ($current - $previous) / $previous : null;
    $weekdays = [1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb', 7 => 'Dom'];
    $filters = $this->filters;
@endphp

<div class="space-y-5" x-data="{}"
    @keydown.escape.window.capture="
        const popover = $el.querySelector('[data-report-tooltip] [popover]:popover-open');
        if (popover) {
            $event.preventDefault();
            $event.stopImmediatePropagation();
            popover.hidePopover();
        }
    ">

    {{-- Cabeçalho --}}
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100">Itens vendidos</h1>
            <p class="text-sm text-neutral-500 dark:text-neutral-400 mt-0.5">
                {{ $filters->start->format('d/m/Y') }} a {{ $filters->end->format('d/m/Y') }}
                · faturamento bruto dos itens, antes de descontos e taxas do pedido
            </p>
        </div>

        <div class="flex items-center gap-2">
            <button wire:click="refresh"
                    wire:loading.attr="disabled"
                    wire:target="refresh"
                    class="inline-flex items-center gap-1.5 text-sm font-medium px-4 py-2 rounded-lg border border-neutral-300 text-neutral-700 hover:bg-neutral-50 transition-colors dark:border-zinc-600 dark:text-neutral-300 dark:hover:bg-zinc-700">
                <svg class="w-4 h-4" wire:loading.class="animate-spin" wire:target="refresh" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
                Atualizar
            </button>

            <button wire:click="exportCsv"
                    wire:loading.attr="disabled"
                    wire:target="exportCsv"
                    class="inline-flex items-center gap-1.5 text-sm font-medium px-4 py-2 rounded-lg bg-amber-500 text-white hover:bg-amber-600 transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                </svg>
                <span wire:loading.remove wire:target="exportCsv">Exportar CSV</span>
                <span wire:loading wire:target="exportCsv">Gerando...</span>
            </button>
        </div>
    </div>

    {{-- Filtros --}}
    <div class="bg-white border rounded-xl shadow-sm p-4 dark:bg-zinc-800 dark:border-zinc-700">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3">
            <div class="lg:col-span-2">
                <flux:select wire:model.live="period" label="Período">
                    @foreach (\App\Livewire\Admin\Reports\SoldItems::PERIODS as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <div>
                <flux:input wire:model.live="dateStart" type="date" label="De" />
            </div>
            <div>
                <flux:input wire:model.live="dateEnd" type="date" label="Até" />
            </div>
            <div>
                <flux:select wire:model.live="branchFilter" label="Filial">
                    <flux:select.option value="">Todas</flux:select.option>
                    @foreach ($this->branches as $branch)
                        <flux:select.option value="{{ $branch->id }}">{{ $branch->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <div>
                <flux:select wire:model.live="channelFilter" label="Canal">
                    <flux:select.option value="">Todos</flux:select.option>
                    @foreach (\App\Livewire\Admin\Reports\SoldItems::CHANNELS as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <div class="lg:col-span-2">
                <flux:select wire:model.live="categoryFilter" label="Categoria">
                    <flux:select.option value="">Todas</flux:select.option>
                    @foreach ($this->categories as $category)
                        <flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        </div>
        <p class="text-xs text-neutral-400 mt-3">Período máximo de {{ config('sales_insights.max_range_days') }} dias. Comparação com os {{ $filters->days() }} dia(s) anteriores.</p>
    </div>

    <div wire:loading.class="opacity-60" wire:target="period,dateStart,dateEnd,branchFilter,channelFilter,categoryFilter,refresh" class="space-y-5 transition-opacity">
        @include('livewire.admin.reports._kpis')

        @include('livewire.admin.reports._insights')

        <div class="grid grid-cols-1 xl:grid-cols-5 gap-5">
            <div class="xl:col-span-3">
                @include('livewire.admin.reports._daily-chart')
            </div>
            <div class="xl:col-span-2">
                @include('livewire.admin.reports._breakdowns')
            </div>
        </div>

        @include('livewire.admin.reports._heatmap')

        @include('livewire.admin.reports._ranking')
    </div>

    @include('livewire.admin.reports._product-detail')
</div>
