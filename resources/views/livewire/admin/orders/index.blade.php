<div class="space-y-4">
    <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100">Pedidos</h1>

    <div class="flex gap-2">
        <div class="flex-1">
            <flux:input wire:model.live="search" placeholder="Buscar por número ou cliente..." />
        </div>
        @if(auth()->user()->isSuperAdmin())
        <select wire:model.live="companyFilter"
            class="border rounded-lg px-3 py-2 text-sm text-neutral-700 focus:outline-none focus:ring-2 focus:ring-amber-400 dark:bg-zinc-800 dark:border-zinc-600 dark:text-neutral-200 dark:focus:ring-amber-500">
            <option value="">Todas as empresas</option>
            @foreach ($companies as $company)
                <option value="{{ $company->id }}">{{ $company->name }}</option>
            @endforeach
        </select>
        @endif
        <select wire:model.live="statusFilter"
            class="border rounded-lg px-3 py-2 text-sm text-neutral-700 focus:outline-none focus:ring-2 focus:ring-amber-400 dark:bg-zinc-800 dark:border-zinc-600 dark:text-neutral-200 dark:focus:ring-amber-500">
            <option value="">Todos os status</option>
            <option value="pending">Pendente</option>
            <option value="awaiting_payment">Aguardando Pagamento</option>
            <option value="paid">Pago</option>
            <option value="preparing">Preparando</option>
            <option value="ready">Pronto</option>
            <option value="delivered">Entregue</option>
            <option value="cancelled">Cancelado</option>
        </select>
    </div>

    <div class="bg-white border rounded-xl shadow-sm overflow-hidden dark:bg-zinc-800 dark:border-zinc-700">
        {{-- Cabeçalho de colunas --}}
        <div class="flex items-center justify-between px-4 py-2 border-b bg-neutral-50 dark:bg-zinc-700/50 dark:border-zinc-700">
            <span class="text-xs font-medium text-neutral-400 uppercase tracking-wide">Pedido / Cliente</span>
            <div class="flex items-center gap-6">
                <span class="text-xs font-medium text-neutral-400 uppercase tracking-wide">Total</span>
                <span class="text-xs font-medium text-neutral-400 uppercase tracking-wide">Status</span>
            </div>
        </div>
        <div class="divide-y dark:divide-zinc-700">
            @forelse ($orders as $order)
                <div class="flex items-center px-4 py-4 hover:bg-neutral-50 transition-colors dark:hover:bg-zinc-700/50">
                    <a href="{{ route('admin.orders.show', $order) }}"
                        class="flex-1 flex items-center justify-between gap-4 min-w-0">
                        <div class="min-w-0">
                            <p class="font-mono font-semibold text-sm text-neutral-800 dark:text-neutral-100">{{ $order->order_number }}</p>
                            @if(auth()->user()->isSuperAdmin() && $order->company)
                                <p class="text-xs font-medium text-amber-600 dark:text-amber-400">{{ $order->company->name }}</p>
                            @endif
                            <p class="text-sm text-neutral-600 dark:text-neutral-400">{{ $order->customer->name ?? '—' }}</p>
                            <p class="text-xs text-neutral-400 dark:text-neutral-500">{{ $order->branch->name ?? '—' }} · {{ $order->created_at->format('d/m H:i') }}</p>
                        </div>
                        <div class="flex items-center gap-4 shrink-0">
                            <p class="font-bold text-sm text-neutral-800 dark:text-neutral-100">R$ {{ number_format($order->total, 2, ',', '.') }}</p>
                            <span class="text-xs px-2 py-1 rounded-full min-w-20 text-center
                                @if($order->status === 'paid' || $order->status === 'delivered') bg-green-100 text-green-700 dark:bg-green-900/50 dark:text-green-400
                                @elseif($order->status === 'preparing' || $order->status === 'ready') bg-blue-100 text-blue-700 dark:bg-blue-900/50 dark:text-blue-400
                                @elseif($order->status === 'cancelled') bg-red-100 text-red-700 dark:bg-red-900/50 dark:text-red-400
                                @elseif($order->status === 'awaiting_payment') bg-yellow-100 text-yellow-700 dark:bg-yellow-900/50 dark:text-yellow-400
                                @else bg-neutral-100 text-neutral-600 dark:bg-zinc-700 dark:text-neutral-300 @endif">
                                {{ $order->status_label }}
                            </span>
                        </div>
                    </a>
                    <a href="{{ route('admin.orders.receipt', $order) }}" target="_blank"
                       title="Imprimir cupom"
                       class="ml-3 shrink-0 p-1.5 text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200 rounded-lg hover:bg-neutral-100 dark:hover:bg-zinc-700 transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                        </svg>
                    </a>
                </div>
            @empty
                <div class="px-4 py-12 text-center">
                    <div class="text-3xl mb-2">📋</div>
                    <p class="text-sm text-neutral-500 dark:text-neutral-400">Nenhum pedido encontrado.</p>
                </div>
            @endforelse
        </div>
    </div>

    <div>{{ $orders->links() }}</div>
</div>
