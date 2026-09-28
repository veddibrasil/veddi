{{-- Pedido iFood: número do iFood, prazo de aceite e as informações que o iFood exige mostrar à loja. --}}
@php
    $ifood = $order->ifoodDetails();
    $deadline = $ifood->confirmationDeadline();
    $cancelRequest = $ifood->cancellationRequest();
    $cancellation = $ifood->cancellation();
    $localizerExpires = $ifood->phoneLocalizerExpiresAt();
    $money = fn ($value) => 'R$ '.number_format((float) $value, 2, ',', '.');
@endphp
<div data-testid="ifood-order-summary" class="rounded-xl border border-red-200 bg-white dark:border-red-900/60 dark:bg-zinc-800 overflow-hidden">
    <div class="flex flex-wrap items-start justify-between gap-3 px-4 py-3 bg-red-50 dark:bg-red-900/20">
        <div class="min-w-0">
            <p class="text-xs font-semibold uppercase tracking-wide text-red-600 dark:text-red-400">
                Pedido iFood
                @if ($ifood->isTest())
                    <span class="ml-1 rounded bg-neutral-200 px-1.5 py-0.5 text-[10px] text-neutral-600 dark:bg-zinc-700 dark:text-neutral-300">teste</span>
                @endif
            </p>
            <p class="text-3xl font-bold text-neutral-800 dark:text-neutral-100">#{{ $ifood->displayId() }}</p>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ $ifood->orderTypeLabel() }}</p>
        </div>
        <div class="text-right">
            <p class="text-lg font-semibold text-neutral-800 dark:text-neutral-100">{{ $order->status_label }}</p>
            @if ($ifood->pickupCode())
                <p class="text-sm text-neutral-600 dark:text-neutral-300">Código de coleta <strong class="font-mono text-base text-neutral-900 dark:text-white">{{ $ifood->pickupCode() }}</strong></p>
            @endif
        </div>
    </div>

    @if ($deadline && $ifood->confirmationExpired())
        <div role="alert" class="px-4 py-3 border-b border-red-200 bg-red-100 text-sm text-red-800 dark:border-red-800 dark:bg-red-900/40 dark:text-red-200">
            <p class="font-semibold">Prazo de aceite encerrado às {{ $deadline->format('H:i') }}.</p>
            <p>Sem confirmação, o iFood cancela o pedido. Confira a situação no Gestor de Pedidos do iFood.</p>
        </div>
    @elseif ($deadline)
        <div x-data="acceptanceCountdown({{ $deadline->getTimestampMs() }})"
             class="px-4 py-3 border-b border-red-100 dark:border-red-900/40 space-y-2"
             :class="urgent ? 'bg-red-600 text-white' : 'bg-amber-50 text-amber-900 dark:bg-amber-900/20 dark:text-amber-200'">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm font-semibold">Aceite ou recuse até {{ $deadline->format('H:i') }}: o iFood cancela pedido não confirmado.</p>
                <p class="font-mono text-2xl font-bold" x-text="label"></p>
            </div>
            {{-- No celular a coluna de ações fica no fim da página; aqui o aceite fica junto do prazo. --}}
            @if ($canUpdate && in_array($userStation, [null, 'cozinha', 'bar'], true))
                <div class="flex gap-2 lg:hidden">
                    <button wire:click="updateStatus('preparing')" wire:loading.attr="disabled" wire:target="updateStatus"
                            class="flex-1 rounded-lg bg-green-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-green-700 disabled:opacity-50">Aceitar pedido</button>
                    @unless ($userStation)
                        <button wire:click="openIfoodCancelModal" wire:loading.attr="disabled"
                                class="rounded-lg bg-white px-4 py-2.5 text-sm font-semibold text-red-700 hover:bg-red-50 disabled:opacity-50">Recusar</button>
                    @endunless
                </div>
            @endif
        </div>
    @elseif ($ifood->awaitingConfirmation())
        <div class="px-4 py-3 border-b border-amber-100 bg-amber-50 text-sm font-semibold text-amber-900 dark:border-amber-900/40 dark:bg-amber-900/20 dark:text-amber-200">
            Pedido agendado aguardando aceite.
        </div>
    @endif

    @if ($ifood->isScheduled())
        <div class="px-4 py-2 border-b border-neutral-100 text-sm text-neutral-700 dark:border-zinc-700 dark:text-neutral-300">
            🕐 Entrega agendada: <strong>{{ $ifood->scheduleWindow() }}</strong>
            @if ($ifood->preparationStartAt())
                · iniciar preparo às <strong>{{ $ifood->preparationStartAt()->format('d/m H:i') }}</strong>
            @endif
        </div>
    @endif

    @if ($cancelRequest && ($cancelRequest['status'] ?? null) === 'requested' && $order->status !== 'cancelled')
        <div class="px-4 py-2 border-b border-neutral-100 bg-neutral-50 text-sm text-neutral-700 dark:border-zinc-700 dark:bg-zinc-700/40 dark:text-neutral-200">
            Cancelamento solicitado ao iFood{{ ! empty($cancelRequest['reason']) ? ' ('.$cancelRequest['reason'].')' : '' }}. Aguardando confirmação.
        </div>
    @elseif ($cancelRequest && ($cancelRequest['status'] ?? null) === 'failed' && $order->status !== 'cancelled')
        <div role="alert" class="px-4 py-3 border-b border-red-200 bg-red-100 text-sm text-red-800 dark:border-red-800 dark:bg-red-900/40 dark:text-red-200">
            <p class="font-semibold">O iFood recusou o cancelamento. O pedido continua ativo.</p>
            @if (! empty($cancelRequest['failure_reason']))
                <p>Motivo: {{ $cancelRequest['failure_reason'] }}</p>
            @endif
        </div>
    @endif

    @if ($order->status === 'cancelled' && ($cancellation || $order->cancellation_reason))
        <div class="px-4 py-2 border-b border-red-100 bg-red-50 text-sm text-red-700 dark:border-red-900/40 dark:bg-red-900/20 dark:text-red-300">
            {{ $order->cancellation_reason }}
        </div>
    @endif

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 px-4 py-3 text-sm">
        @if ($ifood->customerPhone())
            <div>
                <p class="text-xs uppercase tracking-wide text-neutral-500 dark:text-neutral-400">Telefone do cliente (iFood)</p>
                <p class="font-medium text-neutral-800 dark:text-neutral-100">{{ $ifood->customerPhone() }}</p>
                @if ($ifood->phoneLocalizer())
                    <p class="text-neutral-600 dark:text-neutral-300">Localizador <strong class="font-mono">{{ $ifood->phoneLocalizer() }}</strong>
                        @if ($localizerExpires)
                            <span class="text-xs text-neutral-500 dark:text-neutral-400">(válido até {{ $localizerExpires->format('d/m H:i') }})</span>
                        @endif
                    </p>
                @endif
            </div>
        @endif

        <div>
            <p class="text-xs uppercase tracking-wide text-neutral-500 dark:text-neutral-400">CPF/CNPJ na nota</p>
            <p class="font-medium text-neutral-800 dark:text-neutral-100">{{ $ifood->customerDocument() ?? 'Não solicitado' }}</p>
        </div>

        @if ($ifood->deliveryObservations())
            <div class="sm:col-span-2">
                <p class="text-xs uppercase tracking-wide text-neutral-500 dark:text-neutral-400">{{ $ifood->isDelivery() ? 'Observação de entrega' : 'Observação' }}</p>
                <p class="text-neutral-800 dark:text-neutral-100">{{ $ifood->deliveryObservations() }}</p>
            </div>
        @endif

        @if ($ifood->deliveryReference())
            <div class="sm:col-span-2">
                <p class="text-xs uppercase tracking-wide text-neutral-500 dark:text-neutral-400">Ponto de referência</p>
                <p class="text-neutral-800 dark:text-neutral-100">{{ $ifood->deliveryReference() }}</p>
            </div>
        @endif

        @if ($ifood->indoorTable())
            <div>
                <p class="text-xs uppercase tracking-wide text-neutral-500 dark:text-neutral-400">Mesa</p>
                <p class="font-medium text-neutral-800 dark:text-neutral-100">{{ $ifood->indoorTable() }}</p>
            </div>
        @endif
    </div>

    @if (! $userStation || $userStation === 'entrega')
        <div class="border-t border-neutral-100 px-4 py-3 text-sm dark:border-zinc-700">
            <p class="text-xs uppercase tracking-wide text-neutral-500 dark:text-neutral-400 mb-1">Pagamento no iFood</p>
            <ul class="space-y-1">
                @forelse ($ifood->paymentLines() as $line)
                    <li class="flex flex-wrap items-baseline justify-between gap-x-3">
                        <span class="text-neutral-800 dark:text-neutral-100">
                            {{ $line['label'] }}
                            <span @class([
                                'ml-1 rounded px-1.5 py-0.5 text-xs font-medium',
                                'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400' => $line['prepaid'],
                                'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300' => ! $line['prepaid'],
                            ])>{{ $line['prepaid'] ? 'pago online' : 'cobrar na entrega' }}</span>
                        </span>
                        <span class="font-semibold text-neutral-800 dark:text-neutral-100">{{ $money($line['value']) }}</span>
                        @if ($line['change_for'])
                            <span class="w-full text-amber-700 dark:text-amber-400">Troco para {{ $money($line['change_for']) }}@if ($line['change']) · levar {{ $money($line['change']) }} de troco @endif</span>
                        @endif
                    </li>
                @empty
                    <li class="text-neutral-600 dark:text-neutral-300">Pago online pelo iFood</li>
                @endforelse
            </ul>
            @if ($ifood->pendingAmount() > 0)
                <p class="mt-2 rounded-lg bg-amber-100 px-3 py-2 font-semibold text-amber-900 dark:bg-amber-900/40 dark:text-amber-200">
                    Cobrar do cliente: {{ $money($ifood->pendingAmount()) }}
                </p>
            @endif

            @foreach ($ifood->benefitLines() as $benefit)
                <p class="mt-2 flex flex-wrap justify-between gap-x-3 text-green-700 dark:text-green-400">
                    <span>{{ $benefit['label'] }}@if ($benefit['sponsors']) <span class="text-neutral-500 dark:text-neutral-400">— {{ $benefit['sponsors'] }}</span>@endif</span>
                    <span>− {{ $money($benefit['value']) }}</span>
                </p>
            @endforeach

            @foreach ($ifood->additionalFeeLines() as $fee)
                <p class="mt-1 flex justify-between gap-x-3 text-neutral-600 dark:text-neutral-300">
                    <span>{{ $fee['label'] }}</span>
                    <span>{{ $money($fee['value']) }}</span>
                </p>
            @endforeach

            @if ($ifood->reportedTotal() !== null && abs($ifood->reportedTotal() - (float) $order->total) >= 0.01)
                <p class="mt-2 text-xs text-neutral-500 dark:text-neutral-400">
                    Total no iFood: {{ $money($ifood->reportedTotal()) }} (o cardápio da loja calcula {{ $money($order->total) }}).
                </p>
            @endif
        </div>
    @endif

    <p class="border-t border-neutral-100 px-4 py-2 text-xs text-neutral-500 break-all dark:border-zinc-700 dark:text-neutral-400">
        Pedido no sistema {{ $order->order_number }} · ID iFood {{ $order->external_order_id }}
    </p>
</div>
