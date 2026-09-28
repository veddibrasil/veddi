{{-- Ações de pedido iFood, na ordem que o iFood exige: aceitar → (iniciar preparo) → pronto → despachar. --}}
@php
    $ifood = $order->ifoodDetails();
    $allowed = match ($userStation) {
        'cozinha', 'bar' => ['preparing', 'ready'],
        'entrega' => ['out_for_delivery'],
        default => ['preparing', 'ready', 'out_for_delivery', 'cancelled'],
    };
    $buttonBase = 'inline-flex items-center justify-center gap-1.5 rounded-lg px-4 py-2.5 text-sm font-semibold transition-colors disabled:opacity-50';
@endphp
<div class="space-y-3">
    <div class="flex items-center justify-between gap-2">
        <p class="font-semibold text-neutral-700 dark:text-neutral-200">Etapa no iFood</p>
        <p class="text-xs text-neutral-500 dark:text-neutral-400">
            Atual: <span class="font-semibold text-neutral-700 dark:text-neutral-300">{{ $order->status_label }}</span>
        </p>
    </div>

    @if ($canUpdate)
        <div class="flex flex-wrap gap-2">
            @if ($ifood->awaitingConfirmation() && ! $ifood->confirmationExpired() && in_array('preparing', $allowed, true))
                <button wire:click="updateStatus('preparing')" wire:loading.attr="disabled" wire:target="updateStatus"
                        class="{{ $buttonBase }} flex-1 bg-green-600 text-white hover:bg-green-700">
                    Aceitar pedido
                </button>
            @endif
            @if ($ifood->awaitingConfirmation() && ! $ifood->confirmationExpired() && in_array('cancelled', $allowed, true))
                <button wire:click="openIfoodCancelModal" wire:loading.attr="disabled"
                        class="{{ $buttonBase }} bg-red-50 text-red-700 hover:bg-red-100 dark:bg-red-900/30 dark:text-red-400 dark:hover:bg-red-900/50">
                    Recusar
                </button>
            @endif
            @if ($ifood->canStartPreparation() && in_array('preparing', $allowed, true))
                <button wire:click="updateStatus('preparing')" wire:loading.attr="disabled" wire:target="updateStatus"
                        class="{{ $buttonBase }} flex-1 bg-blue-600 text-white hover:bg-blue-700">
                    Iniciar preparo
                </button>
            @endif
            @if ($ifood->canMarkReady() && in_array('ready', $allowed, true))
                <button wire:click="updateStatus('ready')" wire:loading.attr="disabled" wire:target="updateStatus"
                        class="{{ $buttonBase }} flex-1 bg-indigo-600 text-white hover:bg-indigo-700">
                    Pedido pronto
                </button>
            @endif
            @if ($ifood->canDispatch() && in_array('out_for_delivery', $allowed, true))
                <button wire:click="updateStatus('out_for_delivery')" wire:loading.attr="disabled" wire:target="updateStatus"
                        class="{{ $buttonBase }} flex-1 bg-purple-600 text-white hover:bg-purple-700">
                    Despachar (saiu para entrega)
                </button>
            @endif
        </div>
    @endif

    @if ($ifood->canBeDispatchedByStore() && in_array($ifood->status(), ['CONFIRMED', 'PREPARATION_STARTED'], true))
        <p class="text-xs text-neutral-500 dark:text-neutral-400">O despacho libera depois do aviso de pronto.</p>
    @endif

    @if ($ifood->waitingMessage())
        <p class="rounded-lg bg-neutral-50 px-3 py-2 text-sm text-neutral-600 dark:bg-zinc-700/50 dark:text-neutral-300">{{ $ifood->waitingMessage() }}</p>
    @endif

    @if ($canUpdate && ! $ifood->awaitingConfirmation() && ! $ifood->isFinished() && in_array('cancelled', $allowed, true))
        <button wire:click="openIfoodCancelModal" wire:loading.attr="disabled"
                class="text-sm font-medium text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300">
            Solicitar cancelamento ao iFood
        </button>
    @endif
</div>
