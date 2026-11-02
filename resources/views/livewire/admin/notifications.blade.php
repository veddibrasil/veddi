{{-- Pilha de toasts: o mais novo fica na frente, os anteriores aparecem atrás; passar o mouse abre a lista e pausa o fechamento. --}}
<div
    x-data="chatOrderAlerts()"
    x-on:chat-orders-pending.window="sync($event.detail.ids)"
    wire:poll.15s.keep-alive
    class="notif-stack fixed bottom-4 right-4 z-[60] w-72 max-w-[calc(100vw-2rem)] pointer-events-none"
    aria-live="polite"
    aria-atomic="false"
>
    @php
        $pendingChatOrders = $this->pendingChatOrders;
        $pendingChatIds = $pendingChatOrders->pluck('id')->all();
    @endphp
    <span wire:key="chat-pending-{{ md5(implode(',', $pendingChatIds)) }}"
          x-init="$dispatch('chat-orders-pending', { ids: @js($pendingChatIds) })" class="hidden"></span>
    @if ($pendingChatOrders->isNotEmpty())
        <section data-testid="chat-awaiting-alert" class="pointer-events-auto fixed top-20 right-4 w-80 max-w-[calc(100vw-2rem)] max-h-[60vh] overflow-y-auto rounded-xl border border-purple-300 bg-white p-3 shadow-xl dark:border-purple-700 dark:bg-zinc-800">
            <p role="alert" class="text-sm font-bold text-purple-800 dark:text-purple-200">{{ $pendingChatOrders->count() }} {{ $pendingChatOrders->count() === 1 ? 'pedido do chat aguardando aceite' : 'pedidos do chat aguardando aceite' }}</p>
            <button x-show="!audioReady" x-on:click="enableAudio()" type="button" class="mt-2 rounded-lg bg-purple-100 px-3 py-2 text-sm font-semibold text-purple-800 dark:bg-purple-900/50 dark:text-purple-200">Ativar som dos pedidos</button>
            @error('chatAcceptance')
                <p role="alert" class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror
            @foreach ($pendingChatOrders as $pending)
                <div wire:key="chat-awaiting-{{ $pending->id }}" class="mt-3 space-y-2 border-t border-neutral-200 pt-3 dark:border-zinc-700">
                    <a href="{{ route('admin.orders.show', $pending) }}" wire:navigate class="block text-sm font-semibold text-neutral-800 hover:underline dark:text-neutral-100">{{ $pending->order_number }} · {{ $pending->customer?->name ?? 'Cliente' }}</a>
                    <p class="text-xs text-neutral-600 dark:text-neutral-300">R$ {{ number_format($pending->total, 2, ',', '.') }} · {{ $pending->origin_label }}</p>
                    @if ($pending->scheduled_at)
                        <p class="text-xs text-amber-700 dark:text-amber-300">Agendado: {{ $pending->scheduled_at->format('d/m H:i') }}</p>
                    @endif
                    @if ($pending->chat_acceptance_expires_at && $pending->company->chat_acceptance_timeout_minutes > 0)
                        <p class="text-xs text-neutral-600 dark:text-neutral-300">Aceitar até {{ $pending->chat_acceptance_expires_at->format('d/m H:i') }}</p>
                    @endif
                    <button wire:click="acceptChatOrder({{ $pending->id }})" wire:loading.attr="disabled" wire:target="acceptChatOrder({{ $pending->id }})"
                            aria-label="Aceitar pedido {{ $pending->order_number }}" class="w-full rounded-lg bg-green-600 px-3 py-2 text-sm font-semibold text-white hover:bg-green-700 disabled:opacity-50">
                        <span wire:loading.remove wire:target="acceptChatOrder({{ $pending->id }})">Aceitar pedido</span>
                        <span wire:loading wire:target="acceptChatOrder({{ $pending->id }})">Aceitando…</span>
                    </button>
                </div>
            @endforeach
        </section>
    @endif
    <div @class(['relative', 'h-14' => count($notifications) > 0])>
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
                    playNotificationSound(@js(($notification['channel'] ?? null) === 'chat' && ($notification['order_type'] ?? null) !== 'pdv' ? 'chat' : 'default'));
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
</div>

<script>
    window.__notifCount = window.__notifCount || 0;
    window.__origTitle = window.__origTitle || document.title;

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
