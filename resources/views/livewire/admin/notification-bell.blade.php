<div
    x-data="{}"
    x-init="$watch(() => $wire.confirmingClearAll, val => val ? $flux.modal('{{ $modalName }}').show() : $flux.modal('{{ $modalName }}').close())"
>
    <div
        x-data="{
            open: false,
            pos: { top: 0, left: 0 },
            updatePos() {
                const rect = this.$refs.bell.getBoundingClientRect();
                this.pos.top = rect.bottom + 8;
                this.pos.left = Math.min(rect.left, window.innerWidth - 320 - 8);
            }
        }"
        x-on:click.outside="open = false"
        class="relative"
    >
        {{-- Bell button --}}
        <button
            x-ref="bell"
            type="button"
            x-on:click="open = !open; updatePos()"
            class="relative flex items-center justify-center w-9 h-9 rounded-lg text-white hover:bg-white/20 dark:text-purple-200 dark:hover:bg-zinc-700 transition-colors"
            title="Notificações"
        >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
            </svg>

            @if($unreadCount > 0)
                <span class="absolute -top-0.5 -right-0.5 flex items-center justify-center min-w-4.5 h-4.5 px-1 text-[10px] font-bold leading-none text-white bg-red-500 rounded-full">
                    {{ $unreadCount > 99 ? '99+' : $unreadCount }}
                </span>
            @endif
        </button>

        {{-- Dropdown --}}
        <template x-teleport="body">
        <div
            x-show="open"
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-100"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
            x-cloak
            :style="'position: fixed; top: ' + pos.top + 'px; left: ' + pos.left + 'px; z-index: 2147483647;'"
            class="w-80 bg-white dark:bg-zinc-800 border border-neutral-200 dark:border-zinc-700 rounded-xl shadow-xl overflow-hidden"
        >
            {{-- Header --}}
            <div class="flex items-center justify-between px-4 py-3 border-b border-neutral-100 dark:border-zinc-700">
                <span class="text-sm font-semibold text-neutral-800 dark:text-neutral-100">
                    Notificações
                    @if($unreadCount > 0)
                        <span class="ml-1.5 text-xs font-normal text-neutral-400">({{ $unreadCount }} não lidas)</span>
                    @endif
                </span>
                <div class="flex items-center gap-2">
                    @if($unreadCount > 0)
                        <button
                            type="button"
                            wire:click="markAllRead"
                            class="text-xs text-blue-500 hover:text-blue-700 dark:hover:text-blue-300 transition-colors"
                            title="Marcar todas como lidas"
                        >
                            Marcar lidas
                        </button>
                    @endif
                    @if($notifications->count() > 0)
                        <button
                            type="button"
                            wire:click="confirmClearAll"
                            x-on:click="open = false"
                            class="text-xs text-neutral-400 hover:text-red-500 dark:hover:text-red-400 transition-colors"
                            title="Limpar todas"
                        >
                            Limpar
                        </button>
                    @endif
                </div>
            </div>

            {{-- Notification list --}}
            <div class="max-h-96 overflow-y-auto divide-y divide-neutral-100 dark:divide-zinc-700">
                @forelse($notifications as $notif)
                    <div @class([
                        'flex items-start gap-3 px-4 py-3 group transition-colors',
                        'bg-blue-50/50 dark:bg-blue-900/10' => ! $notif->isRead(),
                        'hover:bg-neutral-50 dark:hover:bg-zinc-700/50' => $notif->isRead(),
                        'hover:bg-blue-50 dark:hover:bg-blue-900/20' => ! $notif->isRead(),
                    ])>
                        {{-- Icon --}}
                        <div @class([
                            'w-8 h-8 rounded-full flex items-center justify-center shrink-0 mt-0.5',
                            'bg-green-100 dark:bg-green-900/40' => $notif->type === 'order',
                            'bg-blue-100 dark:bg-blue-900/40'  => $notif->type === 'support',
                        ])>
                            @if($notif->type === 'order')
                                <svg class="w-4 h-4 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 11H4L5 9z"/>
                                </svg>
                            @else
                                <svg class="w-4 h-4 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                                </svg>
                            @endif
                        </div>

                        {{-- Content --}}
                        <a
                            href="{{ $notif->link ?? '#' }}"
                            wire:navigate
                            wire:click="markRead({{ $notif->id }})"
                            x-on:click="open = false"
                            class="flex-1 min-w-0"
                        >
                            <div class="flex items-center gap-1.5">
                                <p class="text-sm font-medium text-neutral-800 dark:text-neutral-100 truncate">
                                    {{ $notif->title }}
                                </p>
                                @if(! $notif->isRead())
                                    <span class="w-2 h-2 rounded-full bg-blue-500 shrink-0"></span>
                                @endif
                            </div>
                            <p class="text-xs text-neutral-500 dark:text-neutral-400 truncate">
                                {{ $notif->subtitle }}
                            </p>
                            <p class="text-xs text-neutral-400 dark:text-neutral-500 mt-0.5">
                                {{ $notif->created_at->diffForHumans() }}
                            </p>
                        </a>

                        {{-- Actions --}}
                        <div class="flex flex-col items-end gap-1 shrink-0 opacity-0 group-hover:opacity-100 transition-opacity">
                            @if(! $notif->isRead())
                                <button
                                    type="button"
                                    wire:click="markRead({{ $notif->id }})"
                                    title="Marcar como lida"
                                    class="text-blue-400 hover:text-blue-600 transition-colors"
                                >
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                                    </svg>
                                </button>
                            @endif
                            <button
                                type="button"
                                wire:click="delete({{ $notif->id }})"
                                title="Remover"
                                class="text-neutral-300 hover:text-red-500 transition-colors"
                            >
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        </div>
                    </div>
                @empty
                    <div class="flex flex-col items-center justify-center py-8 text-neutral-400 dark:text-neutral-500">
                        <svg class="w-8 h-8 mb-2 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                        </svg>
                        <p class="text-xs">Nenhuma notificação</p>
                    </div>
                @endforelse
            </div>
        </div>
        </template>
    </div>

    {{-- Modal de confirmação --}}
    <flux:modal name="{{ $modalName }}" class="max-w-sm">
        <div class="space-y-5">
            <div class="flex items-start gap-4">
                <div class="w-10 h-10 rounded-full bg-red-100 dark:bg-red-900/30 flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
                    </svg>
                </div>
                <div>
                    <flux:heading size="lg">Limpar notificações?</flux:heading>
                    <flux:subheading class="mt-1">Esta ação não pode ser desfeita. <br> Todas as notificações serão removidas permanentemente.</flux:subheading>
                </div>
            </div>
            <div class="flex justify-end gap-3 pt-1">
                <flux:modal.close>
                    <flux:button wire:click="cancelClearAll" variant="ghost">Cancelar</flux:button>
                </flux:modal.close>
                <flux:modal.close>
                    <flux:button wire:click="clearAll" variant="danger">Limpar tudo</flux:button>
                </flux:modal.close>
            </div>
        </div>
    </flux:modal>
</div>
