{{-- Alarme de aceite. `contents` tira a raiz do grid do layout. --}}
<div class="contents" @if ($canAccept) x-data="orderAcceptanceAlarm()" x-on:orders-awaiting-acceptance.window="sync($event.detail.ids)" @endif>
    @if ($canAccept)
        @php
            $awaitingOrders = $this->awaitingOrders;
            $awaitingIds = $awaitingOrders->pluck('id')->all();
        @endphp
        {{-- O poll cobre Reverb ou fila parados: aperta enquanto toca para parar logo quando outro perfil aceitar. A chave troca o span quando a lista muda, e é isso que aplica o novo intervalo. --}}
        <span wire:key="order-acceptance-{{ md5(implode(',', $awaitingIds)) }}"
              @if ($awaitingIds) wire:poll.3s.keep-alive @else wire:poll.15s.keep-alive @endif
              x-init="$dispatch('orders-awaiting-acceptance', { ids: @js($awaitingIds) })" class="hidden"></span>

        {{-- O navegador só libera som depois de um toque na página: avisa antes de chegar pedido. --}}
        <button x-show="!audioReady" x-cloak x-on:click="enableAudio()" type="button" data-testid="order-alarm-sound-off"
                class="fixed top-3 left-1/2 z-[60] -translate-x-1/2 rounded-full bg-amber-500 px-4 py-2 text-sm font-semibold text-white shadow-lg hover:bg-amber-600">
            Som dos pedidos desligado: toque aqui para ativar
        </button>

        @if ($awaitingOrders->isNotEmpty())
            <section data-testid="order-acceptance-alert" class="fixed top-20 right-4 z-[60] w-80 max-w-[calc(100vw-2rem)] max-h-[60vh] overflow-y-auto rounded-xl border border-red-300 bg-white p-3 shadow-xl dark:border-red-700 dark:bg-zinc-800">
                <p role="alert" class="text-sm font-bold text-red-700 dark:text-red-300">{{ $awaitingOrders->count() }} {{ $awaitingOrders->count() === 1 ? 'pedido aguardando aceite' : 'pedidos aguardando aceite' }}</p>
                @foreach ($awaitingOrders as $pending)
                    <div wire:key="order-awaiting-{{ $pending->id }}" class="mt-3 space-y-2 border-t border-neutral-200 pt-3 dark:border-zinc-700">
                        <a href="{{ route('admin.orders.show', $pending) }}" wire:navigate class="block text-sm font-semibold text-neutral-800 hover:underline dark:text-neutral-100">{{ $pending->displayNumber() }} · {{ $pending->customer?->name ?? 'Cliente' }}</a>
                        <p class="text-xs text-neutral-600 dark:text-neutral-300">R$ {{ number_format($pending->total, 2, ',', '.') }} · {{ $pending->origin_label }}</p>
                        @if ($pending->scheduled_at)
                            <p class="text-xs text-amber-700 dark:text-amber-300">Agendado: {{ $pending->scheduled_at->format('d/m H:i') }}</p>
                        @endif
                        @include('livewire.admin.orders.partials.order-acceptance', ['order' => $pending, 'canAccept' => true, 'canReject' => $canReject, 'large' => true])
                        @isset ($acceptanceErrors[$pending->id])
                            <p role="alert" class="text-sm text-red-600 dark:text-red-400">{{ $acceptanceErrors[$pending->id] }}</p>
                        @endisset
                    </div>
                @endforeach
            </section>
        @endif
    @endif
</div>
