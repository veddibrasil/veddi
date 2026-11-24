{{-- Atualiza sozinha: caixa, cozinha e bar pausam ao mesmo tempo e cada um vê o que o outro fez. --}}
<div class="space-y-4" wire:poll.15s>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100">Pausar produtos</h1>
            <p class="text-sm text-neutral-500 dark:text-neutral-400 mt-1">
                Pausar tira o produto de venda nesta filial no chat, no PDV e no iFood, 99Food e Keeta ao mesmo tempo. Vale até alguém retomar.
            </p>
        </div>
        @if ($pausedCount > 0)
            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300" data-test="paused-count">
                {{ $pausedCount }} {{ $pausedCount === 1 ? 'produto pausado' : 'produtos pausados' }}
            </span>
        @endif
    </div>

    @if ($notice !== '')
        <div class="{{ $noticeIsError
                ? 'bg-red-50 border-red-200 text-red-700 dark:bg-red-900/20 dark:border-red-800 dark:text-red-400'
                : 'bg-green-50 border-green-200 text-green-700 dark:bg-green-900/30 dark:border-green-700 dark:text-green-400' }} border px-4 py-3 rounded-lg text-sm"
            role="status">
            {{ $notice }}
        </div>
    @endif

    <div class="bg-white border rounded-xl shadow-sm p-4 dark:bg-zinc-800 dark:border-zinc-700">
        <div class="flex flex-wrap gap-3 items-end">
            @if ($lockedBranchId)
                <div class="flex-1 min-w-40">
                    <p class="text-xs font-medium text-neutral-500 mb-1 dark:text-neutral-400">Filial</p>
                    <p class="text-sm font-semibold text-neutral-800 dark:text-neutral-100">{{ $branch?->name }}</p>
                </div>
            @else
                <div class="flex-1 min-w-40">
                    <label class="block text-xs font-medium text-neutral-500 mb-1 dark:text-neutral-400">Filial</label>
                    <flux:select wire:model.live="branchId">
                        @foreach ($branches as $option)
                            <flux:select.option value="{{ $option->id }}">{{ $option->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
            @endif

            <div class="flex-1 min-w-48">
                <label class="block text-xs font-medium text-neutral-500 mb-1 dark:text-neutral-400">Buscar produto</label>
                <flux:input wire:model.live.debounce.300ms="search" placeholder="Nome do produto..." icon="magnifying-glass" />
            </div>

            <flux:radio.group wire:model.live="filter" variant="segmented" size="sm">
                <flux:radio value="all" label="Todos" />
                <flux:radio value="paused" label="Pausados" />
            </flux:radio.group>
        </div>
    </div>

    <div class="bg-white border rounded-xl shadow-sm dark:bg-zinc-800 dark:border-zinc-700">
        <ul class="divide-y dark:divide-zinc-700">
            @forelse ($items as $item)
                @php
                    $outOfStock = $item->track_stock && (int) $item->quantity <= 0;
                @endphp
                <li class="flex items-center justify-between gap-3 px-4 py-3 {{ $item->paused_at ? 'bg-red-50/60 dark:bg-red-900/10' : '' }}"
                    wire:key="pause-{{ $item->product_id }}" data-test="pause-row-{{ $item->product_id }}">
                    <div class="min-w-0">
                        <p class="font-semibold text-sm text-neutral-800 dark:text-neutral-100 truncate">{{ $item->product_name }}</p>
                        <p class="text-xs text-neutral-400 dark:text-neutral-500">{{ $item->category_name }}</p>
                        @if ($item->paused_at)
                            <p class="text-xs text-red-600 dark:text-red-400 mt-0.5">
                                Pausado{{ $item->paused_by_name ? ' por '.$item->paused_by_name : '' }}
                                {{ $item->paused_at->isToday() ? 'às '.$item->paused_at->format('H:i') : 'em '.$item->paused_at->format('d/m H:i') }}
                            </p>
                        @elseif ($outOfStock)
                            <p class="text-xs text-neutral-500 dark:text-neutral-400 mt-0.5">Sem estoque: volta sozinho quando repuser.</p>
                        @endif
                    </div>

                    <div class="flex items-center gap-3 shrink-0">
                        @if ($item->paused_at)
                            <span class="hidden sm:inline-flex text-xs px-2 py-0.5 rounded-full bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300">Pausado</span>
                            <flux:button wire:click="resume({{ $item->product_id }})" wire:loading.attr="disabled" variant="primary" size="sm" icon="play">
                                Retomar
                            </flux:button>
                        @else
                            @if ($outOfStock || ! $item->available)
                                <span class="hidden sm:inline-flex text-xs px-2 py-0.5 rounded-full bg-neutral-100 text-neutral-600 dark:bg-zinc-700 dark:text-neutral-300">Sem estoque</span>
                            @else
                                <span class="hidden sm:inline-flex text-xs px-2 py-0.5 rounded-full bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300">À venda</span>
                            @endif
                            <flux:button wire:click="pause({{ $item->product_id }})" wire:loading.attr="disabled" variant="danger" size="sm" icon="pause">
                                Pausar
                            </flux:button>
                        @endif
                    </div>
                </li>
            @empty
                <li class="px-4 py-10 text-center text-sm text-neutral-500 dark:text-neutral-400">
                    {{ $filter === 'paused' ? 'Nenhum produto pausado nesta filial.' : 'Nenhum produto encontrado nesta filial.' }}
                </li>
            @endforelse
        </ul>
    </div>

    {{ $items->links() }}
</div>
