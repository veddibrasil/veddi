{{-- Pilha de toasts: o mais novo fica na frente, os anteriores aparecem atrás; passar o mouse abre a lista e pausa o fechamento. --}}
<div
    class="notif-stack fixed bottom-4 right-4 z-[60] w-72 max-w-[calc(100vw-2rem)] pointer-events-none"
    aria-live="polite"
    aria-atomic="false"
>
    @foreach ($notifications as $notification)
        @php
            $depth = count($notifications) - 1 - $loop->index;
            $isUpdate = $notification['type'] === 'items_updated';
        @endphp
        <div
            wire:key="{{ $notification['id'] }}"
            class="notif-toast absolute bottom-0 right-0 w-full pointer-events-auto"
            style="--depth: {{ $depth }}"
            @if ($depth > 2) data-buried @endif
            x-data="{
                show: false,
                timer: null,
                init() {
                    this.$nextTick(() => { this.show = true });
                    playNotificationSound();
                    incrementTabBadge();
                    let left = 8000;
                    this.timer = setInterval(() => {
                        if (this.$el.closest('.notif-stack')?.matches(':hover')) return;
                        left -= 250;
                        if (left > 0) return;
                        clearInterval(this.timer);
                        this.show = false;
                        setTimeout(() => $wire.dismiss('{{ $notification['id'] }}'), 300);
                    }, 250);
                },
                destroy() { clearInterval(this.timer) }
            }"
        >
            <div
                x-show="show"
                x-transition:enter="transition ease-out duration-300"
                x-transition:enter-start="opacity-0 translate-y-3"
                x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="transition ease-in duration-200"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                class="relative flex items-center gap-2.5 h-14 pl-3 pr-2 bg-white dark:bg-zinc-800 border border-neutral-200 dark:border-zinc-700 border-l-4 {{ $isUpdate ? 'border-l-amber-500' : 'border-l-green-500' }} rounded-lg shadow-lg"
            >
                <a
                    href="{{ route('admin.orders.show', $notification['order_id']) }}"
                    wire:navigate
                    class="flex items-center gap-2.5 min-w-0 flex-1"
                >
                    <div class="w-7 h-7 rounded-full {{ $isUpdate ? 'bg-amber-100 dark:bg-amber-900/40' : 'bg-green-100 dark:bg-green-900/40' }} flex items-center justify-center shrink-0">
                        <svg class="w-3.5 h-3.5 {{ $isUpdate ? 'text-amber-600 dark:text-amber-400' : 'text-green-600 dark:text-green-400' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 11H4L5 9z"/>
                        </svg>
                    </div>

                    <div class="min-w-0 flex-1 leading-tight">
                        <p class="text-[13px] font-semibold text-neutral-800 dark:text-neutral-100 truncate">
                            {{ $isUpdate ? 'Pedido atualizado' : 'Novo pedido' }}
                            <span class="font-mono font-normal text-neutral-500 dark:text-neutral-400">{{ $notification['order_number'] }}</span>
                        </p>
                        @if ($isUpdate)
                            <p class="text-xs text-neutral-600 dark:text-neutral-300 truncate" title="{{ $notification['summary'] }}">
                                {{ $notification['summary'] }}
                            </p>
                        @else
                            <p class="text-xs text-neutral-600 dark:text-neutral-300 truncate">
                                <span class="font-semibold text-neutral-800 dark:text-neutral-100">R$ {{ number_format($notification['total'], 2, ',', '.') }}</span>
                                · {{ $notification['customer_name'] }}
                            </p>
                        @endif
                    </div>
                </a>

                @if ($depth === 0 && count($notifications) > 1)
                    <span class="notif-count shrink-0 min-w-5 h-5 px-1.5 rounded-full bg-neutral-800 dark:bg-neutral-100 text-white dark:text-neutral-900 text-[11px] font-bold flex items-center justify-center" title="{{ count($notifications) }} notificações">
                        {{ count($notifications) }}
                    </span>
                @endif

                <button
                    wire:click="dismiss('{{ $notification['id'] }}')"
                    class="p-1 text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-200 shrink-0"
                    aria-label="Fechar"
                >
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
        </div>
    @endforeach
</div>

<script>
    window.__notifCount = window.__notifCount || 0;
    window.__origTitle = window.__origTitle || document.title;

    function playNotificationSound() {
        try {
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.frequency.setValueAtTime(880, ctx.currentTime);
            osc.frequency.setValueAtTime(660, ctx.currentTime + 0.12);
            gain.gain.setValueAtTime(0.3, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.45);
            osc.start(ctx.currentTime);
            osc.stop(ctx.currentTime + 0.45);
        } catch (e) {}
    }

    function incrementTabBadge() {
        window.__notifCount++;
        document.title = '(' + window.__notifCount + ') ' + window.__origTitle;
    }

    if (!window.__notifFocusListenerAdded) {
        window.__notifFocusListenerAdded = true;
        window.addEventListener('focus', () => {
            window.__notifCount = 0;
            document.title = window.__origTitle;
        });
    }
</script>
