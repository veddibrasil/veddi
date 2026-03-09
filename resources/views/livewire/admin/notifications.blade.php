<div
    class="fixed bottom-4 right-4 z-50 flex flex-col gap-2 pointer-events-none"
    aria-live="polite"
    aria-atomic="false"
>
    @foreach ($notifications as $notification)
        <div
            wire:key="{{ $notification['id'] }}"
            x-data="{
                show: false,
                init() {
                    this.$nextTick(() => { this.show = true });
                    setTimeout(() => {
                        this.show = false;
                        setTimeout(() => $wire.dismiss('{{ $notification['id'] }}'), 400);
                    }, 8000);
                }
            }"
            x-show="show"
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0 translate-y-4 scale-95"
            x-transition:enter-end="opacity-100 translate-y-0 scale-100"
            x-transition:leave="transition ease-in duration-300"
            x-transition:leave-start="opacity-100 translate-y-0 scale-100"
            x-transition:leave-end="opacity-0 translate-y-4 scale-95"
            class="pointer-events-auto w-80 bg-white dark:bg-zinc-800 border border-neutral-200 dark:border-zinc-700 rounded-xl shadow-lg overflow-hidden"
        >
            <div class="h-1 bg-green-500"></div>

            <div class="p-4">
                <div class="flex items-start justify-between gap-3">
                    <div class="flex items-start gap-3">
                        <div class="w-8 h-8 rounded-full bg-green-100 dark:bg-green-900/40 flex items-center justify-center shrink-0">
                            <svg class="w-4 h-4 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 11H4L5 9z"/>
                            </svg>
                        </div>

                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold text-neutral-800 dark:text-neutral-100">
                                Novo pedido!
                            </p>
                            <p class="text-xs text-neutral-500 dark:text-neutral-400 font-mono">
                                {{ $notification['order_number'] }}
                            </p>
                            <p class="text-xs text-neutral-600 dark:text-neutral-300 mt-0.5 truncate">
                                {{ $notification['customer_name'] }}
                            </p>
                            <p class="text-sm font-bold text-neutral-800 dark:text-neutral-100 mt-1">
                                R$ {{ number_format($notification['total'], 2, ',', '.') }}
                            </p>
                        </div>
                    </div>

                    <button
                        wire:click="dismiss('{{ $notification['id'] }}')"
                        class="text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-200 shrink-0 mt-0.5"
                        aria-label="Fechar"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <div class="mt-3">
                    <a
                        href="{{ route('admin.orders.show', $notification['order_id']) }}"
                        wire:navigate
                        class="block w-full text-center text-xs font-medium text-white bg-green-600 hover:bg-green-700 rounded-lg px-3 py-1.5 transition-colors"
                    >
                        Ver pedido
                    </a>
                </div>
            </div>
        </div>
    @endforeach
</div>
