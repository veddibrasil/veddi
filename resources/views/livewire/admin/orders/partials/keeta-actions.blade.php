{{-- Ações de pedido Keeta, na ordem da Keeta: aceitar → pronto → (entrega própria: despachar → entregar). --}}
@php
    $keeta = $order->keetaDetails();
    $allowed = match ($userStation) {
        'cozinha', 'bar' => ['preparing', 'ready'],
        'entrega' => ['out_for_delivery', 'delivered'],
        default => ['preparing', 'ready', 'out_for_delivery', 'delivered', 'cancelled'],
    };
    $buttonBase = 'inline-flex items-center justify-center gap-1.5 rounded-lg px-4 py-2.5 text-sm font-semibold transition-colors disabled:opacity-50';
    $steps = array_filter([
        'CONFIRMED' => 'Aceito',
        'READY' => 'Pronto',
        'DISPATCHED' => $keeta->isPickup() ? null : ($keeta->isSelfDelivery() ? 'Saiu para entrega' : 'Coletado'),
        'DELIVERED' => 'Entregue',
    ]);
    $cancelled = $keeta->stage() === 'CANCELLED';
@endphp
<div class="space-y-3">
    <div class="flex items-center justify-between gap-2">
        <p class="font-semibold text-neutral-700 dark:text-neutral-200">Etapa na Keeta</p>
        <p class="text-xs text-neutral-500 dark:text-neutral-400">
            Atual: <span class="font-semibold text-neutral-700 dark:text-neutral-300">{{ $order->status_label }}</span>
        </p>
    </div>

    @unless ($cancelled)
        <ol class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs" aria-label="Etapas do pedido na Keeta">
            @foreach ($steps as $step => $label)
                @php $done = $keeta->reached($step); @endphp
                <li @class([
                    'inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-medium',
                    'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300' => $done,
                    'bg-neutral-100 text-neutral-600 dark:bg-zinc-700 dark:text-neutral-300' => ! $done,
                ])>
                    @if ($done)
                        <flux:icon.check variant="micro" class="size-3" />
                        <span class="sr-only">Concluída:</span>
                    @endif
                    {{ $label }}
                </li>
                @if (! $loop->last)
                    <li aria-hidden="true" class="text-neutral-400 dark:text-neutral-500">›</li>
                @endif
            @endforeach
        </ol>
    @endunless

    @if ($canUpdate)
        <div class="flex flex-wrap gap-2">
            @if ($keeta->canMarkReady() && in_array('ready', $allowed, true))
                <button wire:click="updateStatus('ready')" wire:loading.attr="disabled" wire:target="updateStatus"
                        class="{{ $buttonBase }} flex-1 bg-indigo-600 text-white hover:bg-indigo-700">Pedido pronto</button>
            @endif
            @if ($keeta->canDispatch() && in_array('out_for_delivery', $allowed, true))
                <button wire:click="updateStatus('out_for_delivery')" wire:loading.attr="disabled" wire:target="updateStatus"
                        class="{{ $buttonBase }} flex-1 bg-purple-600 text-white hover:bg-purple-700">Saiu para entrega</button>
            @endif
            @if ($keeta->canMarkDelivered() && in_array('delivered', $allowed, true))
                <button wire:click="updateStatus('delivered')" wire:loading.attr="disabled" wire:target="updateStatus"
                        class="{{ $buttonBase }} flex-1 bg-emerald-600 text-white hover:bg-emerald-700">Entregue</button>
            @endif
        </div>
    @endif

    @if ($keeta->waitingMessage())
        <p class="rounded-lg bg-neutral-50 px-3 py-2 text-sm text-neutral-600 dark:bg-zinc-700/50 dark:text-neutral-300">{{ $keeta->waitingMessage() }}</p>
    @endif

    @if ($canUpdate && ! $keeta->awaitingConfirmation() && ! $keeta->isFinished() && ! $keeta->cancellationPending() && in_array('cancelled', $allowed, true))
        <button wire:click="openKeetaCancelModal({{ $order->id }})" wire:loading.attr="disabled"
                class="text-sm font-medium text-red-600 hover:text-red-700 disabled:opacity-50 dark:text-red-400 dark:hover:text-red-300">
            Pedir cancelamento à Keeta
        </button>
    @endif
</div>
