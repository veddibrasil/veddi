{{-- Pedido Keeta: displayId grande + id completo, tipo, código de coleta, entregador, pagamento e reembolsos. --}}
@php
    $keeta = $order->keetaDetails();
    $cancellation = $keeta->cancellation();
    $rider = $keeta->rider();
    $refunds = $keeta->get('refunds', []);
    $postDelivery = $keeta->get('post_delivery_cancellation');
    $money = fn ($value) => 'R$ '.number_format((float) $value, 2, ',', '.');
@endphp
<div data-testid="keeta-order-summary" class="rounded-xl border border-yellow-200 bg-white dark:border-yellow-900/60 dark:bg-zinc-800 overflow-hidden">
    <div class="flex flex-wrap items-start justify-between gap-3 px-4 py-3 bg-yellow-50 dark:bg-yellow-900/20">
        <div class="min-w-0">
            <p class="text-xs font-semibold uppercase tracking-wide text-yellow-700 dark:text-yellow-400">Pedido Keeta</p>
            <p class="text-3xl font-bold text-neutral-800 dark:text-neutral-100">#{{ $keeta->displayId() }}</p>
            <p class="font-mono text-xs text-neutral-500 dark:text-neutral-400">ID Keeta {{ $keeta->orderId() }}</p>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ $keeta->orderTypeLabel() }}@if ($keeta->isScheduled()) · agendado para {{ $keeta->scheduleWindow() }}@endif</p>
        </div>
        <div class="text-right space-y-0.5">
            <p class="text-lg font-semibold text-neutral-800 dark:text-neutral-100">{{ $order->status_label }}</p>
            @if ($keeta->pickupCode())
                <p class="text-sm text-neutral-600 dark:text-neutral-300">Código de coleta <strong class="font-mono text-base text-neutral-900 dark:text-white">{{ $keeta->pickupCode() }}</strong></p>
            @endif
        </div>
    </div>

    @if ($order->status === 'cancelled' && ($cancellation || $order->cancellation_reason))
        <div class="px-4 py-2 border-b border-red-100 bg-red-50 text-sm text-red-700 dark:border-red-900/40 dark:bg-red-900/20 dark:text-red-300">
            {{ $order->cancellation_reason }}
        </div>
    @elseif ($keeta->cancellationPending())
        <div class="px-4 py-2 border-b border-amber-100 bg-amber-50 text-sm text-amber-800 dark:border-amber-900/40 dark:bg-amber-900/20 dark:text-amber-200">
            Cancelamento pedido à Keeta ({{ $keeta->get('cancellation_request')['reason'] ?? '' }}). O pedido é cancelado aqui assim que a Keeta confirmar.
        </div>
    @endif

    @if ($postDelivery)
        <div class="px-4 py-2 border-b border-red-100 bg-red-50 text-sm text-red-700 dark:border-red-900/40 dark:bg-red-900/20 dark:text-red-300">
            A Keeta cancelou este pedido depois da entrega{{ ! empty($postDelivery['reason']) ? ': '.$postDelivery['reason'] : '' }}. Confira o repasse no portal da Keeta.
        </div>
    @endif

    @foreach ($refunds as $refund)
        <div class="px-4 py-2 border-b border-yellow-100 bg-yellow-50/60 text-sm text-yellow-900 dark:border-yellow-900/40 dark:bg-yellow-900/10 dark:text-yellow-200">
            {{ ($refund['status'] ?? '') === 'refunded' ? 'Reembolso feito' : 'Reembolso não realizado' }}
            @if (! empty($refund['amount'])) · {{ $money($refund['amount']) }} @endif
            @if (! empty($refund['reason'])) · {{ $refund['reason'] }} @endif
        </div>
    @endforeach

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 px-4 py-3 text-sm">
        @if ($keeta->customerPhone())
            <div>
                <p class="text-xs uppercase tracking-wide text-neutral-500 dark:text-neutral-400">Telefone do cliente (Keeta)</p>
                <p class="font-medium text-neutral-800 dark:text-neutral-100">{{ $keeta->customerPhone() }}</p>
            </div>
        @endif

        <div>
            <p class="text-xs uppercase tracking-wide text-neutral-500 dark:text-neutral-400">CPF na nota</p>
            <p class="font-medium text-neutral-800 dark:text-neutral-100">{{ $keeta->customerDocument() ?? 'Não solicitado' }}</p>
        </div>

        @if ($rider)
            <div>
                <p class="text-xs uppercase tracking-wide text-neutral-500 dark:text-neutral-400">Entregador Keeta</p>
                <p class="font-medium text-neutral-800 dark:text-neutral-100">{{ $rider['status'] ?? 'Atribuído' }}</p>
                @if (! empty($rider['pickup_eta']))
                    <p class="text-neutral-600 dark:text-neutral-300">Chega na loja por volta de {{ \Carbon\Carbon::parse($rider['pickup_eta'])->setTimezone(config('app.timezone'))->format('H:i') }}</p>
                @endif
            </div>
        @endif

        @if ($keeta->get('recommended_preparation_start'))
            <div>
                <p class="text-xs uppercase tracking-wide text-neutral-500 dark:text-neutral-400">Começar o preparo</p>
                <p class="font-medium text-neutral-800 dark:text-neutral-100">{{ \Carbon\Carbon::parse($keeta->get('recommended_preparation_start'))->setTimezone(config('app.timezone'))->format('H:i') }}</p>
            </div>
        @endif

        <div class="sm:col-span-2">
            <p class="text-xs uppercase tracking-wide text-neutral-500 dark:text-neutral-400">Pagamento</p>
            @foreach ($keeta->paymentLines() as $payment)
                <p class="text-neutral-800 dark:text-neutral-100">
                    {{ $payment['label'] }}: <strong>{{ $money($payment['value']) }}</strong>
                    <span @class(['text-xs', 'text-green-700 dark:text-green-400' => $payment['prepaid'], 'font-semibold text-red-700 dark:text-red-400' => ! $payment['prepaid']])>
                        {{ $payment['prepaid'] ? 'pago online' : 'cobrar na entrega' }}
                    </span>
                    @if ($payment['change_for'])
                        · troco para {{ $money($payment['change_for']) }}{{ $payment['change'] ? ' (levar '.$money($payment['change']).')' : '' }}
                    @endif
                </p>
            @endforeach
            @foreach ($keeta->benefitLines() as $benefit)
                <p class="text-neutral-700 dark:text-neutral-300">{{ $benefit['label'] }}: -{{ $money($benefit['value']) }}{{ $benefit['sponsors'] ? ' ('.$benefit['sponsors'].')' : '' }}</p>
            @endforeach
            @foreach ($keeta->additionalFeeLines() as $fee)
                <p class="text-neutral-700 dark:text-neutral-300">{{ $fee['label'] }}: {{ $money($fee['value']) }}</p>
            @endforeach
            @if ($keeta->reportedTotal() !== null && abs($keeta->reportedTotal() - (float) $order->total) > 0.01)
                <p class="text-xs text-amber-700 dark:text-amber-400">Total informado pela Keeta: {{ $money($keeta->reportedTotal()) }} (o VEDDI recalcula itens ligados pelo seu cardápio).</p>
            @endif
            @if ((float) $keeta->get('keeta_order_amount', 0) > 0)
                <p class="text-xs text-neutral-500 dark:text-neutral-400">Valor pago pelo cliente na Keeta (com taxas da Keeta): {{ $money($keeta->get('keeta_order_amount')) }}</p>
            @endif
        </div>
    </div>
</div>
