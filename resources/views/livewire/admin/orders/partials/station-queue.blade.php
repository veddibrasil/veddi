{{-- ── COZINHA/BAR: fila mobile em cards com ticket por estação ──────────────── --}}
@php
    $stationLabel = $userStation === 'cozinha' ? 'Cozinha' : 'Bar';
    $stationEmoji = $userStation === 'cozinha' ? '🍳' : '🍹';
@endphp
<div class="flex items-center justify-between">
    <h1 class="text-xl font-bold text-neutral-800 dark:text-neutral-100">{{ $stationEmoji }} Minha fila · {{ $stationLabel }}</h1>
    <span class="text-xs font-medium px-2 py-1 rounded-full bg-neutral-100 text-neutral-500 dark:bg-zinc-700 dark:text-neutral-400">{{ $orders->total() }}</span>
</div>

<flux:input wire:model.live.debounce.400ms="search" placeholder="Buscar por número ou cliente..." />

<div class="flex gap-2">
    <button wire:click="$set('statusFilter', '')"
            class="flex-1 py-2.5 rounded-lg text-sm font-medium transition-colors {{ $statusFilter === '' ? 'bg-amber-500 text-white' : 'bg-neutral-100 text-neutral-600 dark:bg-zinc-700 dark:text-neutral-300' }}">
        Todos
    </button>
    <button wire:click="$set('statusFilter', 'new')"
            class="flex-1 py-2.5 rounded-lg text-sm font-medium transition-colors {{ $statusFilter === 'new' ? 'bg-blue-600 text-white' : 'bg-neutral-100 text-neutral-600 dark:bg-zinc-700 dark:text-neutral-300' }}">
        Novos
    </button>
    <button wire:click="$set('statusFilter', 'preparing')"
            class="flex-1 py-2.5 rounded-lg text-sm font-medium transition-colors {{ $statusFilter === 'preparing' ? 'bg-indigo-600 text-white' : 'bg-neutral-100 text-neutral-600 dark:bg-zinc-700 dark:text-neutral-300' }}">
        Preparando
    </button>
    <button wire:click="$set('statusFilter', 'ready')"
            class="flex-1 py-2.5 rounded-lg text-sm font-medium transition-colors {{ $statusFilter === 'ready' ? 'bg-emerald-600 text-white' : 'bg-neutral-100 text-neutral-600 dark:bg-zinc-700 dark:text-neutral-300' }}">
        Prontos
    </button>
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
    @forelse ($orders as $order)
        @php
            $stationItems = $order->items->filter(fn ($item) => $item->matchesStation($userStation));
            $referenceTime = in_array($order->status, ['pending', 'awaiting_payment', 'paid']) ? $order->created_at : $order->updated_at;
            $elapsedMinutes = $referenceTime->diffInMinutes(now());

            $urgency = match (true) {
                $order->status === 'ready' => 'done',
                $elapsedMinutes >= $this::URGENCY_CRITICAL_MINUTES => 'critical',
                $elapsedMinutes >= $this::URGENCY_WARNING_MINUTES => 'warning',
                default => 'ok',
            };

            $urgencyClasses = match ($urgency) {
                'done' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-400',
                'critical' => 'bg-red-100 text-red-700 dark:bg-red-900/50 dark:text-red-400 animate-pulse',
                'warning' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/50 dark:text-amber-400',
                default => 'bg-neutral-100 text-neutral-600 dark:bg-zinc-700 dark:text-neutral-300',
            };
        @endphp
        <div wire:key="station-card-{{ $order->id }}" class="bg-white border rounded-xl shadow-sm overflow-hidden dark:bg-zinc-800 dark:border-zinc-700">
            <div class="flex items-start justify-between gap-2 px-4 pt-4 pb-2">
                <div class="min-w-0">
                    <p class="font-mono font-semibold text-xs text-neutral-400 dark:text-neutral-500">{{ $order->order_number }}</p>
                    <p class="text-sm font-semibold text-neutral-800 dark:text-neutral-100">{{ $order->table_label ?: $order->origin_label }}</p>
                </div>
                <div class="flex items-center gap-2 shrink-0">
                    <span class="text-xs px-2 py-1 rounded-full font-medium {{ $urgencyClasses }}">
                        {{ $this->formatElapsed($elapsedMinutes) }}
                    </span>
                    <a href="{{ route('admin.orders.receipt', ['order' => $order->id, 'station' => $userStation]) }}" target="_blank"
                       title="Imprimir cupom {{ $userStation }}"
                       @click.prevent="printStation({{ $order->id }}, '{{ $userStation }}', $el.href)"
                       class="shrink-0 p-1 text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200 rounded-lg hover:bg-neutral-100 dark:hover:bg-zinc-700 transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                        </svg>
                    </a>
                </div>
            </div>

            <div class="px-4 pb-3 divide-y dark:divide-zinc-700">
                @foreach ($stationItems as $item)
                    <div class="py-2 first:pt-0">
                        <p class="text-sm font-medium text-neutral-800 dark:text-neutral-100">{{ $item->quantity }}x {{ $item->product_name }}</p>
                        @foreach (($item->options ?? []) as $group)
                            <p class="text-xs font-medium text-neutral-500 mt-0.5 dark:text-neutral-400">{{ $group['group_name'] ?? 'Opções' }}:</p>
                            @foreach (($group['selections'] ?? []) as $sel)
                                <p class="text-xs text-neutral-400 leading-tight dark:text-neutral-500">{{ $sel['qty'] ?? 0 }}× {{ $sel['name'] ?? '-' }}</p>
                            @endforeach
                        @endforeach
                    </div>
                @endforeach
            </div>

            @if ($order->notes)
                <div class="mx-4 mb-3 px-3 py-2 rounded-lg bg-amber-50 dark:bg-amber-900/20 text-xs text-amber-800 dark:text-amber-300">
                    {{ $order->notes }}
                </div>
            @endif

            @if ($canUpdate && in_array($order->status, ['pending', 'awaiting_payment', 'paid']))
                <button wire:click="updateOrderStatus({{ $order->id }}, 'preparing')"
                        wire:loading.attr="disabled"
                        class="w-full py-3.5 text-sm font-bold text-white bg-indigo-600 hover:bg-indigo-700 active:bg-indigo-800 disabled:opacity-50 transition-colors border-t dark:border-zinc-700">
                    Iniciar preparo
                </button>
            @elseif ($canUpdate && $order->status === 'preparing')
                <button wire:click="updateOrderStatus({{ $order->id }}, 'ready')"
                        wire:loading.attr="disabled"
                        class="w-full py-3.5 text-sm font-bold text-white bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800 disabled:opacity-50 transition-colors border-t dark:border-zinc-700">
                    Marcar como pronto
                </button>
            @elseif ($order->status === 'ready')
                <div class="w-full py-3 text-center text-xs font-medium text-emerald-700 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-900/20 border-t dark:border-zinc-700">
                    Pronto — aguardando retirada/entrega
                </div>
            @endif
        </div>
    @empty
        <div class="px-4 py-16 text-center">
            <div class="text-4xl mb-2">{{ $stationEmoji }}</div>
            <p class="text-sm text-neutral-500 dark:text-neutral-400">Nenhum pedido na fila.</p>
        </div>
    @endforelse
</div>

<div>{{ $orders->links() }}</div>
