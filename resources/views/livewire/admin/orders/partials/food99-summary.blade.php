{{-- Pedido 99Food: order_index grande + order_id completo (exigência da 99), prazo de aceite, códigos, entregador e pagamento. --}}
@php
    $food99 = $order->food99Details();
    $deadline = $food99->confirmationDeadline();
    $cancellation = $food99->cancellation();
    $rider = $food99->rider();
    $partials = $food99->get('partial_cancellations', []);
    $money = fn ($value) => 'R$ '.number_format((float) $value, 2, ',', '.');
@endphp
<div data-testid="food99-order-summary" class="rounded-xl border border-amber-200 bg-white dark:border-amber-900/60 dark:bg-zinc-800 overflow-hidden">
    <div class="flex flex-wrap items-start justify-between gap-3 px-4 py-3 bg-amber-50 dark:bg-amber-900/20">
        <div class="min-w-0">
            <p class="text-xs font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-400">
                Pedido 99Food
                @if ($food99->isTest())
                    <flux:badge size="sm" color="zinc" class="ml-1 normal-case tracking-normal">teste</flux:badge>
                @endif
            </p>
            <p class="text-3xl font-bold text-neutral-800 dark:text-neutral-100">#{{ $food99->displayId() }}</p>
            <p class="font-mono text-xs text-neutral-500 dark:text-neutral-400">ID 99Food {{ $food99->orderId() }}</p>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ $food99->orderTypeLabel() }}</p>
        </div>
        <div class="text-right space-y-0.5">
            <p class="text-lg font-semibold text-neutral-800 dark:text-neutral-100">{{ $order->status_label }}</p>
            @if ($food99->handoverCode())
                <p class="text-sm text-neutral-600 dark:text-neutral-300">Código p/ entregador <strong class="font-mono text-base text-neutral-900 dark:text-white">{{ $food99->handoverCode() }}</strong></p>
            @endif
            @if ($food99->isPickup() && $food99->takeawayCode())
                <p class="text-sm text-neutral-600 dark:text-neutral-300">Código de retirada <strong class="font-mono text-base text-neutral-900 dark:text-white">{{ $food99->takeawayCode() }}</strong></p>
            @endif
        </div>
    </div>

    @if ($deadline && $food99->confirmationExpired())
        <div role="alert" class="px-4 py-3 border-b border-red-200 bg-red-100 text-sm text-red-800 dark:border-red-800 dark:bg-red-900/40 dark:text-red-200">
            <p class="font-semibold">Prazo de aceite encerrado às {{ $deadline->format('H:i') }}.</p>
            <p>Sem confirmação, a 99Food cancela o pedido. Confira a situação no app da 99Food.</p>
        </div>
    @elseif ($deadline)
        <div x-data="acceptanceCountdown({{ $deadline->getTimestampMs() }})"
             class="px-4 py-3 border-b border-amber-100 dark:border-amber-900/40 space-y-2"
             :class="urgent ? 'bg-red-600 text-white' : 'bg-amber-50 text-amber-900 dark:bg-amber-900/20 dark:text-amber-200'">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm font-semibold">Aceite ou recuse até {{ $deadline->format('H:i') }}: a 99Food cancela pedido não confirmado em 5 minutos.</p>
                <p class="font-mono text-2xl font-bold" x-text="label" aria-hidden="true"></p>
            </div>
            @if ($canUpdate && in_array($userStation, [null, 'cozinha', 'bar'], true))
                <div class="flex gap-2 lg:hidden">
                    <button wire:click="updateStatus('preparing')" wire:loading.attr="disabled" wire:target="updateStatus"
                            class="flex-1 rounded-lg bg-green-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-green-700 disabled:opacity-50">Aceitar pedido</button>
                    @unless ($userStation)
                        <button wire:click="openFood99CancelModal({{ $order->id }})" wire:loading.attr="disabled"
                                class="rounded-lg bg-white px-4 py-2.5 text-sm font-semibold text-red-700 hover:bg-red-50 disabled:opacity-50">Recusar</button>
                    @endunless
                </div>
            @endif
        </div>
    @endif

    @if ($order->status === 'cancelled' && ($cancellation || $order->cancellation_reason))
        <div class="px-4 py-2 border-b border-red-100 bg-red-50 text-sm text-red-700 dark:border-red-900/40 dark:bg-red-900/20 dark:text-red-300">
            {{ $order->cancellation_reason }}
            @if (! empty($cancellation['refund']))
                · reembolso ao cliente {{ $money($cancellation['refund']) }}
            @endif
        </div>
    @endif

    @foreach ($partials as $partial)
        <div class="px-4 py-2 border-b border-amber-100 bg-amber-50/60 text-sm text-amber-900 dark:border-amber-900/40 dark:bg-amber-900/10 dark:text-amber-200">
            Itens cancelados:
            {{ collect($partial['items'] ?? [])->map(fn ($item) => ($item['cancel_amount'] ?? $item['amount'] ?? '').'x '.($item['name'] ?? collect($food99->get('order_items', []))->firstWhere('mdu_id', $item['mdu_id'] ?? null)['name'] ?? 'item'))->implode(', ') }}
            @if (! empty($partial['refund'])) · reembolso {{ $money($partial['refund']) }} @endif
        </div>
    @endforeach

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 px-4 py-3 text-sm">
        @if ($food99->customerPhone())
            <div>
                <p class="text-xs uppercase tracking-wide text-neutral-500 dark:text-neutral-400">Telefone do cliente (99Food)</p>
                <p class="font-medium text-neutral-800 dark:text-neutral-100">{{ $food99->customerPhone() }}</p>
                @if ($food99->phoneLocalizer())
                    <p class="text-neutral-600 dark:text-neutral-300">Localizador <strong class="font-mono">{{ $food99->phoneLocalizer() }}</strong></p>
                @endif
            </div>
        @endif

        <div>
            <p class="text-xs uppercase tracking-wide text-neutral-500 dark:text-neutral-400">CPF na nota</p>
            <p class="font-medium text-neutral-800 dark:text-neutral-100">{{ $food99->customerDocument() ?? 'Não solicitado' }}</p>
        </div>

        @if ($rider)
            <div>
                <p class="text-xs uppercase tracking-wide text-neutral-500 dark:text-neutral-400">Entregador 99Food</p>
                <p class="font-medium text-neutral-800 dark:text-neutral-100">{{ $rider['name'] ?? 'Atribuído' }}{{ ! empty($rider['phone']) ? ' · '.$rider['phone'] : '' }}</p>
                @if (! empty($rider['label']))
                    <p class="text-neutral-600 dark:text-neutral-300">{{ $rider['label'] }}</p>
                @endif
            </div>
        @endif

        <div>
            <p class="text-xs uppercase tracking-wide text-neutral-500 dark:text-neutral-400">Talheres</p>
            <p class="font-medium text-neutral-800 dark:text-neutral-100">{{ $food99->needCutlery() ? 'Enviar talheres' : 'Não precisa' }}</p>
        </div>

        @if ($food99->cancelPreferenceLabel())
            <div>
                <p class="text-xs uppercase tracking-wide text-neutral-500 dark:text-neutral-400">Se faltar item</p>
                <p class="font-medium text-neutral-800 dark:text-neutral-100">{{ $food99->cancelPreferenceLabel() }}</p>
            </div>
        @endif

        <div class="sm:col-span-2">
            <p class="text-xs uppercase tracking-wide text-neutral-500 dark:text-neutral-400">Pagamento</p>
            @foreach ($food99->paymentLines() as $payment)
                <p class="text-neutral-800 dark:text-neutral-100">
                    {{ $payment['label'] }}: <strong>{{ $money($payment['value']) }}</strong>
                    <span @class(['text-xs', 'text-green-700 dark:text-green-400' => $payment['prepaid'], 'font-semibold text-red-700 dark:text-red-400' => ! $payment['prepaid']])>
                        {{ $payment['prepaid'] ? 'pago online' : ($food99->isDeliveredByPlatform() ? 'entregador 99 recebe do cliente' : 'cobrar na entrega') }}
                    </span>
                    @if ($payment['change_for'])
                        · troco para {{ $money($payment['change_for']) }}{{ $payment['change'] ? ' (levar '.$money($payment['change']).')' : '' }}
                    @endif
                </p>
            @endforeach
            @if ($food99->isDeliveredByPlatform() && (float) $food99->get('shop_paid_money', 0) > 0)
                <p class="text-neutral-700 dark:text-neutral-300">Entregador 99 paga à loja {{ $money($food99->get('shop_paid_money')) }}{{ $food99->get('cash_confirmed_at') ? ' (recebimento confirmado)' : '' }}</p>
            @endif
            @foreach ($food99->benefitLines() as $benefit)
                <p class="text-neutral-700 dark:text-neutral-300">{{ $benefit['label'] }}: -{{ $money($benefit['value']) }}{{ $benefit['sponsors'] ? ' ('.$benefit['sponsors'].')' : '' }}</p>
            @endforeach
            @foreach ($food99->additionalFeeLines() as $fee)
                <p class="text-neutral-700 dark:text-neutral-300">{{ $fee['label'] }}: {{ $money($fee['value']) }}</p>
            @endforeach
            @if ($food99->reportedTotal() !== null && abs($food99->reportedTotal() - (float) $order->total) > 0.01)
                <p class="text-xs text-amber-700 dark:text-amber-400">Total informado pela 99Food: {{ $money($food99->reportedTotal()) }} (o VEDDI recalcula itens ligados pelo seu cardápio).</p>
            @endif
        </div>
    </div>
</div>
