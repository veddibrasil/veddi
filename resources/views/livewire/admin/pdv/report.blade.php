<div class="space-y-5">

    {{-- Header --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100">Relatório PDV</h1>
    </div>

    {{-- Filtros --}}
    <div class="bg-white border rounded-xl shadow-sm p-4 dark:bg-zinc-800 dark:border-zinc-700">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div>
                <label class="block text-xs font-medium text-neutral-500 dark:text-neutral-400 mb-1">Data inicial</label>
                <flux:input wire:model.live="dateStart" type="date" />
            </div>
            <div>
                <label class="block text-xs font-medium text-neutral-500 dark:text-neutral-400 mb-1">Data final</label>
                <flux:input wire:model.live="dateEnd" type="date" />
            </div>
            <div>
                <flux:select wire:model.live="branchFilter" label="Filial" :disabled="$isCaixa">
                    @unless ($isCaixa)
                        <flux:select.option value="">Todas</flux:select.option>
                    @endunless
                    @foreach ($branches as $branch)
                        <flux:select.option value="{{ $branch->id }}">{{ $branch->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        </div>
    </div>

    @unless ($isCaixa)
        {{-- Cards de resumo --}}
        <div class="grid grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-4">
            <div class="bg-white border rounded-xl p-4 dark:bg-zinc-800 dark:border-zinc-700 col-span-2 lg:col-span-1 xl:col-span-2">
                <p class="text-xs font-medium text-neutral-500 dark:text-neutral-400 uppercase tracking-wide">Total PDV</p>
                <p class="text-2xl font-bold text-neutral-800 dark:text-neutral-100 mt-1">
                    R$ {{ number_format($totalRevenue, 2, ',', '.') }}
                </p>
            </div>
            <div class="bg-white border rounded-xl p-4 dark:bg-zinc-800 dark:border-zinc-700">
                <p class="text-xs font-medium text-neutral-500 dark:text-neutral-400 uppercase tracking-wide">Dinheiro</p>
                <p class="text-2xl font-bold text-green-600 dark:text-green-400 mt-1">
                    R$ {{ number_format($cashTotal, 2, ',', '.') }}
                </p>
            </div>
            <div class="bg-white border rounded-xl p-4 dark:bg-zinc-800 dark:border-zinc-700">
                <p class="text-xs font-medium text-neutral-500 dark:text-neutral-400 uppercase tracking-wide">PIX</p>
                <p class="text-2xl font-bold text-blue-600 dark:text-blue-400 mt-1">
                    R$ {{ number_format($pixTotal, 2, ',', '.') }}
                </p>
            </div>
            <div class="bg-white border rounded-xl p-4 dark:bg-zinc-800 dark:border-zinc-700">
                <p class="text-xs font-medium text-neutral-500 dark:text-neutral-400 uppercase tracking-wide">Cartão</p>
                <p class="text-2xl font-bold text-purple-600 dark:text-purple-400 mt-1">
                    R$ {{ number_format($cardTotal, 2, ',', '.') }}
                </p>
            </div>
            <div class="bg-white border rounded-xl p-4 dark:bg-zinc-800 dark:border-zinc-700">
                <p class="text-xs font-medium text-neutral-500 dark:text-neutral-400 uppercase tracking-wide">Descontos</p>
                <p class="text-2xl font-bold text-amber-600 dark:text-amber-400 mt-1">
                    R$ {{ number_format($totalDiscounts, 2, ',', '.') }}
                </p>
            </div>
            <div class="bg-white border rounded-xl p-4 dark:bg-zinc-800 dark:border-zinc-700">
                <p class="text-xs font-medium text-neutral-500 dark:text-neutral-400 uppercase tracking-wide">Cancelados</p>
                <p class="text-2xl font-bold {{ $cancelledCount > 0 ? 'text-red-600 dark:text-red-400' : 'text-neutral-400' }} mt-1">
                    {{ $cancelledCount }}
                </p>
            </div>
        </div>
    @endunless

    {{-- Tabela de sessões de caixa --}}
    <div class="bg-white border rounded-xl shadow-sm dark:bg-zinc-800 dark:border-zinc-700">
        <div class="px-4 py-3 border-b dark:border-zinc-700">
            <h2 class="font-semibold text-neutral-800 dark:text-neutral-100">Sessões de caixa</h2>
        </div>
        @if ($sessions->isEmpty())
            <div class="p-6 text-center text-sm text-neutral-400 dark:text-neutral-500">Nenhuma sessão no período.</div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b text-xs text-neutral-500 dark:border-zinc-700 dark:text-neutral-400">
                            <th class="px-4 py-2 text-left">Filial</th>
                            <th class="px-4 py-2 text-left">Terminal</th>
                            <th class="px-4 py-2 text-left">Operador</th>
                            <th class="px-4 py-2 text-left">Abertura</th>
                            <th class="px-4 py-2 text-right">Esperado</th>
                            <th class="px-4 py-2 text-right">Contado</th>
                            <th class="px-4 py-2 text-right">Diferença</th>
                            <th class="px-4 py-2 text-center">Status</th>
                            @unless ($isCaixa)
                                <th class="px-4 py-2 text-center">Ações</th>
                            @endunless
                        </tr>
                    </thead>
                    <tbody class="divide-y dark:divide-zinc-700">
                        @foreach ($sessions as $session)
                            @php
                                $diff = $session->closed_at
                                    ? round($session->closing_amount - $session->expected_amount, 2)
                                    : null;
                                $diffSeverity = $diff === null ? null : (abs($diff) <= 0.01 ? 'ok' : (abs($diff) <= 5.0 ? 'warn' : 'alert'));
                            @endphp
                            <tr class="hover:bg-neutral-50 dark:hover:bg-zinc-700/50">
                                <td class="px-4 py-3 text-neutral-800 dark:text-neutral-100">{{ $session->branch?->name ?? '—' }}</td>
                                <td class="px-4 py-3 text-neutral-500 dark:text-neutral-400 text-xs">
                                    {{ $session->terminal_name ?? '—' }}
                                </td>
                                <td class="px-4 py-3 text-neutral-600 dark:text-neutral-300">{{ $session->user?->name ?? '—' }}</td>
                                <td class="px-4 py-3 text-neutral-500 dark:text-neutral-400 text-xs">
                                    {{ $session->created_at->format('d/m/Y H:i') }}
                                    @if ($session->closed_at)
                                        <span class="text-neutral-300 dark:text-neutral-600">→</span>
                                        {{ $session->closed_at->format('H:i') }}
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right font-medium text-neutral-700 dark:text-neutral-200">
                                    @if ($session->closed_at)
                                        R$ {{ number_format($session->expected_amount, 2, ',', '.') }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right font-medium text-neutral-700 dark:text-neutral-200">
                                    @if ($session->closed_at)
                                        R$ {{ number_format($session->closing_amount, 2, ',', '.') }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right font-medium">
                                    @if ($diff !== null)
                                        @if ($diffSeverity === 'ok')
                                            <span class="text-green-600 dark:text-green-400">OK</span>
                                        @elseif ($diffSeverity === 'warn')
                                            <span class="text-amber-600 dark:text-amber-400">
                                                {{ $diff >= 0 ? '+' : '' }}R$ {{ number_format(abs($diff), 2, ',', '.') }}
                                            </span>
                                        @else
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400">
                                                {{ $diff >= 0 ? '+' : '−' }}R$ {{ number_format(abs($diff), 2, ',', '.') }}
                                            </span>
                                        @endif
                                        @if ($session->reconciliation_notes)
                                            <p class="text-xs text-neutral-400 dark:text-neutral-500 mt-0.5 max-w-xs truncate" title="{{ $session->reconciliation_notes }}">
                                                {{ $session->reconciliation_notes }}
                                            </p>
                                        @endif
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-center">
                                    @if ($session->closed_at)
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-neutral-100 text-neutral-600 dark:bg-zinc-700 dark:text-neutral-300">
                                            Fechado
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400">
                                            <span class="size-1.5 rounded-full bg-green-500"></span>
                                            Aberto
                                        </span>
                                    @endif
                                </td>
                                @unless ($isCaixa)
                                    <td class="px-4 py-3 text-center">
                                        @if ($session->closed_at)
                                            <flux:button
                                                href="{{ route('admin.pdv.cash-session.print', $session) }}"
                                                target="_blank"
                                                variant="ghost"
                                                size="sm"
                                                icon="printer"
                                                title="Imprimir fechamento"
                                            />
                                        @endif
                                    </td>
                                @endunless
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- Tabela de pedidos PDV --}}
    <div class="bg-white border rounded-xl shadow-sm dark:bg-zinc-800 dark:border-zinc-700">
        <div class="px-4 py-3 border-b dark:border-zinc-700">
            <h2 class="font-semibold text-neutral-800 dark:text-neutral-100">Pedidos PDV</h2>
        </div>
        @if ($orders->isEmpty())
            <div class="p-6 text-center text-sm text-neutral-400 dark:text-neutral-500">Nenhum pedido no período.</div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b text-xs text-neutral-500 dark:border-zinc-700 dark:text-neutral-400">
                            <th class="px-4 py-2 text-left">Número</th>
                            <th class="px-4 py-2 text-left">Cliente</th>
                            <th class="px-4 py-2 text-left">Filial</th>
                            <th class="px-4 py-2 text-left">Data</th>
                            <th class="px-4 py-2 text-left">Pagamento</th>
                            <th class="px-4 py-2 text-left">Status</th>
                            <th class="px-4 py-2 text-right">Desconto</th>
                            <th class="px-4 py-2 text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y dark:divide-zinc-700">
                        @foreach ($orders as $order)
                            @php
                                $orderDiscount = ($order->discount ?? 0) + ($order->manual_discount ?? 0);
                            @endphp
                            <tr class="hover:bg-neutral-50 dark:hover:bg-zinc-700/50">
                                <td class="px-4 py-3 font-mono text-xs text-purple-600 dark:text-purple-400">{{ $order->order_number }}</td>
                                <td class="px-4 py-3 text-neutral-700 dark:text-neutral-200">{{ $order->customer?->name ?? '—' }}</td>
                                <td class="px-4 py-3 text-neutral-500 dark:text-neutral-400">{{ $order->branch?->name ?? '—' }}</td>
                                <td class="px-4 py-3 text-neutral-500 dark:text-neutral-400 text-xs">{{ $order->created_at->format('d/m/Y H:i') }}</td>
                                <td class="px-4 py-3 text-neutral-600 dark:text-neutral-300">
                                    @php
                                        $methodLabel = match(strtolower($order->payment_method ?? '')) {
                                            'pix' => 'PIX',
                                            'credit_card' => 'Cartão',
                                            'cash' => 'Dinheiro',
                                            'split' => 'Dividido',
                                            default => $order->payment_method,
                                        };
                                    @endphp
                                    {{ $methodLabel }}
                                </td>
                                <td class="px-4 py-3">
                                    <span class="text-xs font-medium px-2 py-0.5 rounded-full
                                        @if(in_array($order->status, ['cancelled', 'refunded'])) bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400
                                        @elseif($order->status === 'paid' || $order->status === 'delivered') bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400
                                        @else bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400 @endif">
                                        {{ $order->status_label ?? $order->status }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right text-xs {{ $orderDiscount > 0 ? 'text-green-600 dark:text-green-400' : 'text-neutral-400' }}">
                                    @if ($orderDiscount > 0)
                                        − R$ {{ number_format($orderDiscount, 2, ',', '.') }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right font-semibold text-neutral-800 dark:text-neutral-100">
                                    R$ {{ number_format($order->total, 2, ',', '.') }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="px-4 py-3 border-t dark:border-zinc-700">
                {{ $orders->links() }}
            </div>
        @endif
    </div>

</div>
