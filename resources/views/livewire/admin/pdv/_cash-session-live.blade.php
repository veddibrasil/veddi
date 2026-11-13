{{--
    Andamento de um caixa ainda aberto (visão do administrador): o que o caixa já gerou até agora.
    Recebe $liveReport = CashClosingReportService::build($session). Usado no overlay de fechamentos
    do PDV (_closing-reports) e no modal do Relatório PDV. Nunca incluir para o caixa: o fechamento
    dele é cego (ver _cash-close).
--}}
@php
    $liveSession = $liveReport['session'];
    $livePayments = $liveReport['payments'];
@endphp
<div class="space-y-4">
    <div class="text-center">
        <p class="text-sm font-bold text-neutral-800 dark:text-neutral-100">{{ $liveSession->terminal_name ?: 'Terminal' }}</p>
        <p class="text-xs text-neutral-500 dark:text-neutral-400">
            {{ $liveSession->branch?->name }} · {{ $liveSession->user?->name ?? '—' }}
        </p>
        <p class="mt-1 inline-flex items-center gap-1.5 rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700 dark:bg-green-900/30 dark:text-green-400">
            <span class="size-1.5 rounded-full bg-green-500"></span>
            Aberto em {{ $liveSession->created_at->format('d/m/Y H:i') }} · há {{ $liveSession->created_at->diffForHumans(now(), true) }}
        </p>
    </div>

    <div class="grid grid-cols-2 gap-2 text-sm">
        <div class="rounded-xl border bg-white p-3 dark:border-zinc-700 dark:bg-zinc-800">
            <p class="text-xs text-neutral-500 dark:text-neutral-400">Faturamento</p>
            <p class="font-bold text-neutral-800 dark:text-neutral-100">R$ {{ number_format($liveReport['revenue'], 2, ',', '.') }}</p>
        </div>
        <div class="rounded-xl border bg-white p-3 dark:border-zinc-700 dark:bg-zinc-800">
            <p class="text-xs text-neutral-500 dark:text-neutral-400">Pedidos</p>
            <p class="font-bold text-neutral-800 dark:text-neutral-100">{{ $liveReport['orders_count'] }}</p>
        </div>
        <div class="rounded-xl border bg-white p-3 dark:border-zinc-700 dark:bg-zinc-800">
            <p class="text-xs text-neutral-500 dark:text-neutral-400">Descontos</p>
            <p class="font-bold text-neutral-800 dark:text-neutral-100">R$ {{ number_format($liveReport['discounts'], 2, ',', '.') }}</p>
        </div>
        <div class="rounded-xl border bg-white p-3 dark:border-zinc-700 dark:bg-zinc-800">
            <p class="text-xs text-neutral-500 dark:text-neutral-400">Cancelados</p>
            <p class="font-bold {{ $liveReport['cancelled_count'] > 0 ? 'text-red-600 dark:text-red-400' : 'text-neutral-800 dark:text-neutral-100' }}">{{ $liveReport['cancelled_count'] }}</p>
        </div>
    </div>

    <div class="rounded-xl border bg-neutral-50 p-4 text-sm space-y-2 dark:border-zinc-700 dark:bg-zinc-800">
        <p class="text-xs font-semibold uppercase tracking-wide text-neutral-500 dark:text-neutral-400">Recebido por forma de pagamento</p>
        <div class="flex justify-between">
            <span class="text-neutral-600 dark:text-neutral-400">Dinheiro</span>
            <span class="font-medium">R$ {{ number_format($livePayments['cash'], 2, ',', '.') }}</span>
        </div>
        <div class="flex justify-between">
            <span class="text-neutral-600 dark:text-neutral-400">PIX</span>
            <span class="font-medium">R$ {{ number_format($livePayments['pix'], 2, ',', '.') }}</span>
        </div>
        <div class="flex justify-between">
            <span class="text-neutral-600 dark:text-neutral-400">Cartão</span>
            <span class="font-medium">R$ {{ number_format($livePayments['credit_card'], 2, ',', '.') }}</span>
        </div>
    </div>

    <div class="rounded-xl border bg-neutral-50 p-4 text-sm space-y-2 dark:border-zinc-700 dark:bg-zinc-800">
        <p class="text-xs font-semibold uppercase tracking-wide text-neutral-500 dark:text-neutral-400">Dinheiro na gaveta</p>
        <div class="flex justify-between">
            <span class="text-neutral-600 dark:text-neutral-400">Abertura</span>
            <span class="font-medium">R$ {{ number_format($liveReport['opening'], 2, ',', '.') }}</span>
        </div>
        <div class="flex justify-between">
            <span class="text-neutral-600 dark:text-neutral-400">Vendas em dinheiro</span>
            <span class="font-medium text-green-700 dark:text-green-400">+ R$ {{ number_format($liveReport['cash_sales'], 2, ',', '.') }}</span>
        </div>
        @if ($liveReport['supplies'] > 0)
            <div class="flex justify-between">
                <span class="text-neutral-600 dark:text-neutral-400">Suprimentos</span>
                <span class="font-medium text-green-700 dark:text-green-400">+ R$ {{ number_format($liveReport['supplies'], 2, ',', '.') }}</span>
            </div>
        @endif
        @if ($liveReport['withdrawals'] > 0)
            <div class="flex justify-between">
                <span class="text-neutral-600 dark:text-neutral-400">Sangrias</span>
                <span class="font-medium text-red-600 dark:text-red-400">- R$ {{ number_format($liveReport['withdrawals'], 2, ',', '.') }}</span>
            </div>
        @endif
        <div class="flex justify-between border-t pt-2 font-bold dark:border-zinc-600">
            <span>Esperado no caixa agora</span>
            <span class="text-amber-600 dark:text-amber-400">R$ {{ number_format($liveReport['expected'], 2, ',', '.') }}</span>
        </div>
    </div>

    @if ($liveReport['movements']->isNotEmpty())
        <div class="rounded-xl border bg-neutral-50 p-4 text-sm space-y-2 dark:border-zinc-700 dark:bg-zinc-800">
            <p class="text-xs font-semibold uppercase tracking-wide text-neutral-500 dark:text-neutral-400">Movimentações</p>
            @foreach ($liveReport['movements'] as $movement)
                <div class="flex justify-between gap-3">
                    <span class="min-w-0 text-neutral-600 dark:text-neutral-400">
                        {{ \Illuminate\Support\Carbon::parse($movement->created_at)->format('H:i') }}
                        · {{ $movement->action === 'cash_withdrawal' ? 'Sangria' : 'Suprimento' }}
                        @if ($movement->reason)
                            <span class="block truncate text-xs">{{ $movement->reason }}</span>
                        @endif
                    </span>
                    <span class="shrink-0 font-medium {{ $movement->action === 'cash_withdrawal' ? 'text-red-600 dark:text-red-400' : 'text-green-700 dark:text-green-400' }}">
                        {{ $movement->action === 'cash_withdrawal' ? '-' : '+' }} R$ {{ number_format((float) $movement->amount, 2, ',', '.') }}
                    </span>
                </div>
            @endforeach
        </div>
    @endif

    @if ($liveReport['top_products']->isNotEmpty())
        <div class="rounded-xl border bg-neutral-50 p-4 text-sm space-y-2 dark:border-zinc-700 dark:bg-zinc-800">
            <p class="text-xs font-semibold uppercase tracking-wide text-neutral-500 dark:text-neutral-400">Mais vendidos</p>
            @foreach ($liveReport['top_products'] as $product)
                <div class="flex justify-between gap-3">
                    <span class="truncate text-neutral-600 dark:text-neutral-400">{{ $product->product_name }}</span>
                    <span class="shrink-0 font-medium">{{ (int) $product->total_qty }}</span>
                </div>
            @endforeach
        </div>
    @endif
</div>
