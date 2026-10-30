{{-- Ações de pedido 99Food, na ordem da 99: aceitar → pronto → (entrega própria: despachar → entregar | retirada: retirado). --}}
@php
    $food99 = $order->food99Details();
    $allowed = match ($userStation) {
        'cozinha', 'bar' => ['preparing', 'ready'],
        'entrega' => ['out_for_delivery', 'delivered'],
        default => ['preparing', 'ready', 'out_for_delivery', 'delivered', 'cancelled'],
    };
    $buttonBase = 'inline-flex items-center justify-center gap-1.5 rounded-lg px-4 py-2.5 text-sm font-semibold transition-colors disabled:opacity-50';
    $steps = array_filter([
        'CONFIRMED' => 'Aceito',
        'READY' => 'Pronto',
        'DISPATCHED' => $food99->isPickup() ? null : ($food99->isSelfDelivery() ? 'Saiu para entrega' : 'Coletado'),
        'FINISHED' => 'Concluído',
    ]);
    $cancelled = $food99->stage() === 'CANCELLED';
@endphp
<div class="space-y-3">
    <div class="flex items-center justify-between gap-2">
        <p class="font-semibold text-neutral-700 dark:text-neutral-200">Etapa na 99Food</p>
        <p class="text-xs text-neutral-500 dark:text-neutral-400">
            Atual: <span class="font-semibold text-neutral-700 dark:text-neutral-300">{{ $order->status_label }}</span>
        </p>
    </div>

    @unless ($cancelled)
        <ol class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs" aria-label="Etapas do pedido na 99Food">
            @foreach ($steps as $step => $label)
                @php $done = $food99->reached($step); @endphp
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
            @if ($food99->awaitingConfirmation() && ! $food99->confirmationExpired() && in_array('preparing', $allowed, true))
                <button wire:click="updateStatus('preparing')" wire:loading.attr="disabled" wire:target="updateStatus"
                        class="{{ $buttonBase }} flex-1 bg-green-600 text-white hover:bg-green-700">Aceitar pedido</button>
            @endif
            @if ($food99->awaitingConfirmation() && ! $food99->confirmationExpired() && in_array('cancelled', $allowed, true))
                <button wire:click="openFood99CancelModal({{ $order->id }})" wire:loading.attr="disabled"
                        class="{{ $buttonBase }} bg-red-50 text-red-700 hover:bg-red-100 dark:bg-red-900/30 dark:text-red-400 dark:hover:bg-red-900/50">Recusar</button>
            @endif
            @if ($food99->canMarkReady() && in_array('ready', $allowed, true))
                <button wire:click="updateStatus('ready')" wire:loading.attr="disabled" wire:target="updateStatus"
                        class="{{ $buttonBase }} flex-1 bg-indigo-600 text-white hover:bg-indigo-700">Pedido pronto</button>
            @endif
            @if ($food99->canDispatch() && in_array('out_for_delivery', $allowed, true))
                <button wire:click="updateStatus('out_for_delivery')" wire:loading.attr="disabled" wire:target="updateStatus"
                        class="{{ $buttonBase }} flex-1 bg-purple-600 text-white hover:bg-purple-700">Saiu para entrega</button>
            @endif
        </div>

        @if (($food99->canMarkDelivered() || $food99->canFinishPickup()) && in_array('delivered', $allowed, true))
            <div x-data="{ code: '' }" class="flex flex-wrap items-end gap-2">
                <div class="flex-1 min-w-32">
                    <label for="food99-finish-code" class="text-xs font-medium text-neutral-600 dark:text-neutral-300">
                        {{ $food99->isPickup() ? 'Código de retirada (opcional)' : 'Código de entrega do cliente (opcional)' }}
                    </label>
                    <input id="food99-finish-code" x-model="code" type="text" inputmode="text" maxlength="12"
                           class="mt-1 w-full rounded-lg border-neutral-300 text-sm dark:border-zinc-600 dark:bg-zinc-900">
                </div>
                <button x-on:click="$wire.finishFood99Order({{ $order->id }}, code)" wire:loading.attr="disabled" wire:target="finishFood99Order"
                        class="{{ $buttonBase }} bg-emerald-600 text-white hover:bg-emerald-700">
                    {{ $food99->isPickup() ? 'Cliente retirou' : 'Entregue' }}
                </button>
            </div>
        @endif

        @if ($food99->canConfirmCashPayment() && ! $userStation)
            <button wire:click="confirmFood99CashPayment({{ $order->id }})" wire:loading.attr="disabled" wire:target="confirmFood99CashPayment"
                    class="{{ $buttonBase }} w-full bg-amber-600 text-white hover:bg-amber-700">
                Recebi o dinheiro do entregador ({{ 'R$ '.number_format((float) $food99->get('shop_paid_money'), 2, ',', '.') }})
            </button>
        @endif
    @endif

    @if ($food99->waitingMessage())
        <p class="rounded-lg bg-neutral-50 px-3 py-2 text-sm text-neutral-600 dark:bg-zinc-700/50 dark:text-neutral-300">{{ $food99->waitingMessage() }}</p>
    @endif

    @if ($canUpdate && ! $food99->awaitingConfirmation() && ! $food99->isFinished() && in_array('cancelled', $allowed, true))
        <button wire:click="openFood99CancelModal({{ $order->id }})" wire:loading.attr="disabled"
                class="text-sm font-medium text-red-600 hover:text-red-700 disabled:opacity-50 dark:text-red-400 dark:hover:text-red-300">
            Cancelar pedido na 99Food
        </button>
    @endif
</div>
