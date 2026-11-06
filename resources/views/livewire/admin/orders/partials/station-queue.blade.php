{{--
    ── COZINHA/BAR: fila mobile em cards com ticket por estação ──────────────────
    Entrega e retirada ficam numa lista própria, sempre no topo e em ordem de chegada (ou do
    horário agendado), para não se perderem entre as vendas de balcão. Abas com contador fazem
    a procura: a busca é só para achar pedido antigo. wire:poll mantém os tempos andando mesmo
    sem evento do Reverb.
--}}
@php
    $stationLabel = $userStation === 'cozinha' ? 'Cozinha' : 'Bar';
    $stationEmoji = $userStation === 'cozinha' ? '🍳' : '🍹';
    $searching = trim($search) !== '';
    $tabs = [
        '' => ['label' => 'Agora', 'count' => $stationCounts['delivery'] + $stationCounts['counter'], 'active' => 'bg-amber-500 text-white'],
        'delivery' => ['label' => '🛵 Entregas', 'count' => $stationCounts['delivery'], 'active' => 'bg-violet-600 text-white'],
        'counter' => ['label' => '🍽️ Balcão e mesas', 'count' => $stationCounts['counter'], 'active' => 'bg-neutral-700 text-white dark:bg-zinc-200 dark:text-zinc-900'],
        'scheduled' => ['label' => '⏰ Agendados', 'count' => $stationCounts['scheduled'], 'active' => 'bg-amber-600 text-white'],
        'ready' => ['label' => '✅ Prontos', 'count' => $stationCounts['ready'], 'active' => 'bg-emerald-600 text-white'],
    ];
    $sectionTitle = match ($stationTab) {
        'scheduled' => 'Agendados para mais tarde',
        'ready' => 'Prontos nas últimas horas',
        default => 'Balcão e mesas',
    };
@endphp
<div class="space-y-4" wire:poll.30s>
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-bold text-neutral-800 dark:text-neutral-100">{{ $stationEmoji }} Minha fila · {{ $stationLabel }}</h1>
    </div>

    <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" clearable placeholder="Buscar nº do pedido, iFood/99/Keeta, cliente ou telefone" />

    @unless ($searching)
        <div class="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1" role="group" aria-label="Filtrar pedidos">
            @foreach ($tabs as $value => $tab)
                @php $isActive = $stationTab === $value; @endphp
                <button wire:click="$set('statusFilter', '{{ $value }}')" aria-pressed="{{ $isActive ? 'true' : 'false' }}"
                        class="shrink-0 inline-flex items-center gap-1.5 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors {{ $isActive ? $tab['active'] : 'bg-neutral-100 text-neutral-600 dark:bg-zinc-700 dark:text-neutral-300' }}">
                    {{ $tab['label'] }}
                    <span class="min-w-5 rounded-full px-1.5 text-xs font-bold {{ $isActive ? 'bg-white/25' : ($value === 'delivery' && $tab['count'] > 0 ? 'bg-violet-600 text-white' : 'bg-white text-neutral-500 dark:bg-zinc-800 dark:text-neutral-400') }}">{{ $tab['count'] }}</span>
                </button>
            @endforeach
        </div>
    @endunless

    @if ($searching)
        <h2 class="text-sm font-semibold text-neutral-500 dark:text-neutral-400">Resultado da busca ({{ $orders->total() }})</h2>
    @elseif (in_array($stationTab, ['', 'delivery'], true))
        <section aria-labelledby="station-handoff-title" class="space-y-2">
            <h2 id="station-handoff-title" class="flex items-center gap-2 text-sm font-bold text-violet-700 dark:text-violet-300">
                🛵 Entregas e retiradas
                <span class="rounded-full bg-violet-100 px-2 text-xs dark:bg-violet-900/40">{{ $handoffOrders->count() }}</span>
            </h2>
            @if ($handoffOrders->isEmpty())
                <p class="rounded-xl border border-dashed px-4 py-4 text-center text-sm text-neutral-500 dark:border-zinc-700 dark:text-neutral-400">Nenhuma entrega ou retirada para fazer agora.</p>
            @else
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    @foreach ($handoffOrders as $order)
                        @include('livewire.admin.orders.partials.station-card')
                    @endforeach
                </div>
            @endif
        </section>
    @endif

    @if ($searching || $stationTab !== 'delivery')
        <section class="space-y-2">
            @unless ($searching)
                <h2 class="text-sm font-bold text-neutral-600 dark:text-neutral-300">{{ $sectionTitle }}</h2>
            @endunless
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                @forelse ($orders as $order)
                    @include('livewire.admin.orders.partials.station-card')
                @empty
                    <div class="px-4 py-12 text-center sm:col-span-2">
                        <div class="text-4xl mb-2">{{ $stationEmoji }}</div>
                        <p class="text-sm text-neutral-500 dark:text-neutral-400">{{ $searching ? 'Nenhum pedido encontrado.' : 'Nenhum pedido na fila.' }}</p>
                    </div>
                @endforelse
            </div>
            <div>{{ $orders->links() }}</div>
        </section>
    @endif
</div>
