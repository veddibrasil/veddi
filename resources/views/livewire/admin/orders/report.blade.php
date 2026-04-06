<div class="space-y-5">

    {{-- Page Header --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100">Relatório de Pedidos</h1>

        <div class="flex items-center gap-2">
            {{-- Export CSV --}}
            <button wire:click="exportCsv"
                    wire:loading.attr="disabled"
                    wire:target="exportCsv"
                    class="inline-flex items-center gap-1.5 text-sm font-medium px-4 py-2 rounded-lg border border-neutral-300 text-neutral-700 hover:bg-neutral-50 transition-colors dark:border-zinc-600 dark:text-neutral-300 dark:hover:bg-zinc-700">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                </svg>
                <span wire:loading.remove wire:target="exportCsv">Exportar CSV</span>
                <span wire:loading wire:target="exportCsv">Gerando...</span>
            </button>

            {{-- Export PDF --}}
            <a href="{{ route('admin.orders.report.pdf', array_filter([
                'date_start'     => $dateStart,
                'date_end'       => $dateEnd,
                'status'         => $statusFilter,
                'branch_id'      => $branchFilter,
                'payment_method' => $paymentMethod,
                'order_type'     => $orderType,
            ])) }}"
               target="_blank"
               class="inline-flex items-center gap-1.5 text-sm font-medium px-4 py-2 rounded-lg bg-amber-500 text-white hover:bg-amber-600 transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                </svg>
                Exportar PDF
            </a>
        </div>
    </div>

    {{-- Filters --}}
    <div class="bg-white border rounded-xl shadow-sm p-4 dark:bg-zinc-800 dark:border-zinc-700">
        <div class="flow grid-cols-2 gap-3">

            {{-- Coluna 1 --}}
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
                    <label class="block text-xs font-medium text-neutral-500 dark:text-neutral-400 mb-1">Status</label>
                    <select wire:model.live="statusFilter"
                        class="w-full border rounded-lg px-3 py-2 text-sm text-neutral-700 focus:outline-none focus:ring-2 focus:ring-amber-400 dark:bg-zinc-800 dark:border-zinc-600 dark:text-neutral-200 dark:focus:ring-amber-500">
                        <option value="">Todos</option>
                        <option value="pending">Pendente</option>
                        <option value="awaiting_payment">Ag. Pagamento</option>
                        <option value="paid">Pago</option>
                        <option value="preparing">Preparando</option>
                        <option value="ready">Pronto</option>
                        <option value="delivered">Entregue</option>
                        <option value="cancelled">Cancelado</option>
                        <option value="refunded">Reembolsado</option>
                    </select>
                </div>

            </div>

            {{-- Coluna 2 --}}
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mt-3">

                <div>
                    <label class="block text-xs font-medium text-neutral-500 dark:text-neutral-400 mb-1">Filial</label>
                    <select wire:model.live="branchFilter"
                        class="w-full border rounded-lg px-3 py-2 text-sm text-neutral-700 focus:outline-none focus:ring-2 focus:ring-amber-400 dark:bg-zinc-800 dark:border-zinc-600 dark:text-neutral-200 dark:focus:ring-amber-500">
                        <option value="">Todas</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-medium text-neutral-500 dark:text-neutral-400 mb-1">Pagamento</label>
                    <select wire:model.live="paymentMethod"
                        class="w-full border rounded-lg px-3 py-2 text-sm text-neutral-700 focus:outline-none focus:ring-2 focus:ring-amber-400 dark:bg-zinc-800 dark:border-zinc-600 dark:text-neutral-200 dark:focus:ring-amber-500">
                        <option value="">Todos</option>
                        <option value="pix">PIX</option>
                        <option value="card">Cartão</option>
                        <option value="cash">Dinheiro</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-medium text-neutral-500 dark:text-neutral-400 mb-1">Tipo</label>
                    <select wire:model.live="orderType"
                        class="w-full border rounded-lg px-3 py-2 text-sm text-neutral-700 focus:outline-none focus:ring-2 focus:ring-amber-400 dark:bg-zinc-800 dark:border-zinc-600 dark:text-neutral-200 dark:focus:ring-amber-500">
                        <option value="">Todos</option>
                        <option value="delivery">Entrega</option>
                        <option value="pickup">Retirada</option>
                    </select>
                </div>

            </div>

        </div>
    </div>

    {{-- Summary Cards --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white border rounded-xl p-4 shadow-sm dark:bg-zinc-800 dark:border-zinc-700">
            <p class="text-xs text-neutral-500 uppercase tracking-wide dark:text-neutral-400">Total de pedidos</p>
            <p class="text-3xl font-bold text-amber-600 mt-1 dark:text-amber-400">{{ $totalCount }}</p>
        </div>
        <div class="bg-white border rounded-xl p-4 shadow-sm dark:bg-zinc-800 dark:border-zinc-700">
            <p class="text-xs text-neutral-500 uppercase tracking-wide dark:text-neutral-400">Receita total</p>
            <p class="text-2xl font-bold text-green-600 mt-1 dark:text-green-400">R$ {{ number_format($totalRevenue, 2, ',', '.') }}</p>
        </div>
        <div class="bg-white border rounded-xl p-4 shadow-sm dark:bg-zinc-800 dark:border-zinc-700">
            <p class="text-xs text-neutral-500 uppercase tracking-wide dark:text-neutral-400">Ticket médio</p>
            <p class="text-2xl font-bold text-blue-600 mt-1 dark:text-blue-400">R$ {{ number_format($averageTicket, 2, ',', '.') }}</p>
        </div>
        <div class="bg-white border rounded-xl p-4 shadow-sm dark:bg-zinc-800 dark:border-zinc-700">
            <p class="text-xs text-neutral-500 uppercase tracking-wide dark:text-neutral-400">Principal pagamento</p>
            <p class="text-2xl font-bold text-neutral-800 mt-1 dark:text-neutral-100">{{ $topPaymentLabel }}</p>
        </div>
    </div>

    {{-- Orders Table --}}
    <div class="bg-white border rounded-xl shadow-sm overflow-hidden dark:bg-zinc-800 dark:border-zinc-700">

        <div class="hidden lg:grid grid-cols-[1.5fr_1.5fr_1fr_1fr_0.8fr_0.8fr_1fr_0.8fr]
                    items-center px-4 py-2 border-b bg-neutral-50 gap-4
                    dark:bg-zinc-700/50 dark:border-zinc-700">
            <span class="text-xs font-medium text-neutral-400 uppercase tracking-wide">Número</span>
            <span class="text-xs font-medium text-neutral-400 uppercase tracking-wide">Cliente</span>
            <span class="text-xs font-medium text-neutral-400 uppercase tracking-wide">Filial</span>
            <span class="text-xs font-medium text-neutral-400 uppercase tracking-wide">Data</span>
            <span class="text-xs font-medium text-neutral-400 uppercase tracking-wide">Pagamento</span>
            <span class="text-xs font-medium text-neutral-400 uppercase tracking-wide">Tipo</span>
            <span class="text-xs font-medium text-neutral-400 uppercase tracking-wide">Status</span>
            <span class="text-xs font-medium text-neutral-400 uppercase tracking-wide text-right">Total</span>
        </div>

        <div class="divide-y dark:divide-zinc-700">
            @forelse ($orders as $order)
                <a href="{{ route('admin.orders.show', $order) }}"
                    class="flex flex-col lg:grid lg:grid-cols-[1.5fr_1.5fr_1fr_1fr_0.8fr_0.8fr_1fr_0.8fr]
                           items-start lg:items-center px-4 py-3 gap-2 lg:gap-4
                           hover:bg-neutral-50 transition-colors dark:hover:bg-zinc-700/50">

                    <p class="font-mono font-semibold text-sm text-neutral-800 dark:text-neutral-100">{{ $order->order_number }}</p>
                    <p class="text-sm text-neutral-700 dark:text-neutral-300">{{ $order->customer->name ?? '—' }}</p>
                    <p class="text-sm text-neutral-600 dark:text-neutral-400">{{ $order->branch->name ?? '—' }}</p>
                    <p class="text-xs text-neutral-400 dark:text-neutral-500">{{ $order->created_at->format('d/m/Y H:i') }}</p>
                    <p class="text-xs text-neutral-600 dark:text-neutral-300">
                        @if($order->payment_method === 'pix') PIX
                        @elseif($order->payment_method === 'card') Cartão
                        @elseif($order->payment_method === 'cash') Dinheiro
                        @else {{ $order->payment_method }}
                        @endif
                    </p>
                    <p class="text-xs text-neutral-600 dark:text-neutral-300">
                        {{ $order->order_type === 'delivery' ? 'Entrega' : 'Retirada' }}
                    </p>
                    <span class="text-xs px-2 py-1 rounded-full text-center inline-block
                        @if(in_array($order->status, ['paid','delivered'])) bg-green-100 text-green-700 dark:bg-green-900/50 dark:text-green-400
                        @elseif(in_array($order->status, ['preparing','ready'])) bg-blue-100 text-blue-700 dark:bg-blue-900/50 dark:text-blue-400
                        @elseif($order->status === 'cancelled') bg-red-100 text-red-700 dark:bg-red-900/50 dark:text-red-400
                        @elseif($order->status === 'awaiting_payment') bg-yellow-100 text-yellow-700 dark:bg-yellow-900/50 dark:text-yellow-400
                        @else bg-neutral-100 text-neutral-600 dark:bg-zinc-700 dark:text-neutral-300 @endif">
                        {{ $order->status_label }}
                    </span>
                    <p class="font-bold text-sm text-neutral-800 dark:text-neutral-100 lg:text-right">
                        R$ {{ number_format($order->total, 2, ',', '.') }}
                    </p>
                </a>
            @empty
                <div class="px-4 py-12 text-center">
                    <p class="text-sm text-neutral-500 dark:text-neutral-400">Nenhum pedido encontrado para o período selecionado.</p>
                </div>
            @endforelse
        </div>
    </div>

    @if ($orders->hasPages())
        <div>{{ $orders->links() }}</div>
    @endif

</div>
