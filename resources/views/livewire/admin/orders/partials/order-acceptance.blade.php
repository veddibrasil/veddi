{{--
    Aceite igual para chat, iFood e 99Food em toda tela. Quem inclui tem acceptOrder(id) e
    rejectOrder(id) e informa $canAccept e $canReject; $large é o tamanho para toque (fila,
    detalhe e alarme). Prazo longo de agendado vira horário: contagem só faz sentido perto do fim.
--}}
@php
    $large ??= false;
    $acceptanceDeadline = $order->acceptanceDeadline();
    $acceptancePlatform = $order->marketplaceDetails()?->channelLabel();
    $acceptanceButton = $large ? 'py-2.5 text-sm' : 'py-1 text-xs';
@endphp
<div data-testid="order-acceptance" class="space-y-2">
    @if ($order->acceptanceExpired())
        <p role="alert" class="rounded-lg bg-red-50 px-2 py-1 text-xs font-semibold text-red-700 dark:bg-red-900/20 dark:text-red-300">
            Prazo de aceite encerrado{{ $acceptancePlatform ? ': confira no '.$acceptancePlatform : '' }}
        </p>
    @else
        <div class="flex items-center justify-between gap-2 rounded-lg bg-red-50 px-2 py-1 dark:bg-red-900/20">
            <span class="text-xs font-semibold text-red-700 dark:text-red-300">Aguardando aceite</span>
            @if ($acceptanceDeadline?->isBefore(now()->addHour()))
                <span x-data="acceptanceCountdown({{ $acceptanceDeadline->getTimestampMs() }})" x-text="label" aria-hidden="true"
                      class="font-mono text-xs font-bold" :class="urgent ? 'text-red-600 dark:text-red-400' : 'text-amber-700 dark:text-amber-400'"></span>
                <span class="sr-only">até {{ $acceptanceDeadline->timezone(config('app.timezone'))->format('H:i') }}</span>
            @elseif ($acceptanceDeadline)
                <span class="text-xs font-semibold text-neutral-600 dark:text-neutral-300">até {{ $acceptanceDeadline->timezone(config('app.timezone'))->format('d/m H:i') }}</span>
            @endif
        </div>
        @if ($canAccept || $canReject)
            <div class="flex gap-2">
                @if ($canAccept)
                    <button wire:click.stop="acceptOrder({{ $order->id }})" wire:loading.attr="disabled" wire:target="acceptOrder({{ $order->id }})"
                            aria-label="Aceitar pedido {{ $order->displayNumber() }}"
                            class="flex-1 rounded-lg bg-green-600 px-3 {{ $acceptanceButton }} font-semibold text-white hover:bg-green-700 disabled:opacity-50">
                        <span wire:loading.remove wire:target="acceptOrder({{ $order->id }})">Aceitar pedido</span>
                        <span wire:loading wire:target="acceptOrder({{ $order->id }})">Aceitando…</span>
                    </button>
                @endif
                @if ($canReject)
                    <button wire:click.stop="rejectOrder({{ $order->id }})" wire:loading.attr="disabled" wire:target="rejectOrder({{ $order->id }})"
                            aria-label="Recusar pedido {{ $order->displayNumber() }}"
                            class="rounded-lg bg-red-100 px-3 {{ $acceptanceButton }} font-semibold text-red-700 hover:bg-red-200 disabled:opacity-50 dark:bg-red-900/40 dark:text-red-300">Recusar</button>
                @endif
            </div>
        @endif
    @endif
</div>
