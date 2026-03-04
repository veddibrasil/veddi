<div class="space-y-4">
    <h1 class="text-2xl font-bold text-neutral-800">Pedidos</h1>

    <div class="flex gap-2">
        <div class="flex-1">
            <flux:input wire:model.live="search" placeholder="Buscar por número ou cliente..." />
        </div>
        <select wire:model.live="statusFilter"
            class="border rounded-lg px-3 py-2 text-sm text-neutral-700 focus:outline-none focus:ring-2 focus:ring-amber-400">
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

    <div class="bg-white border rounded-xl shadow-sm overflow-hidden">
        <div class="divide-y">
            @forelse ($orders as $order)
                <a href="{{ route('admin.orders.show', $order) }}"
                    class="flex items-center justify-between px-4 py-4 hover:bg-neutral-50 transition-colors">
                    <div>
                        <p class="font-mono font-semibold text-sm text-neutral-800">{{ $order->order_number }}</p>
                        <p class="text-sm text-neutral-600">{{ $order->customer->name ?? '—' }}</p>
                        <p class="text-xs text-neutral-400">{{ $order->branch->name ?? '—' }} · {{ $order->created_at->format('d/m H:i') }}</p>
                    </div>
                    <div class="text-right">
                        <p class="font-bold text-sm text-neutral-800">R$ {{ number_format($order->total, 2, ',', '.') }}</p>
                        <span class="text-xs px-2 py-0.5 rounded-full
                            @if($order->status === 'paid' || $order->status === 'delivered') bg-green-100 text-green-700
                            @elseif($order->status === 'preparing' || $order->status === 'ready') bg-blue-100 text-blue-700
                            @elseif($order->status === 'cancelled') bg-red-100 text-red-700
                            @elseif($order->status === 'awaiting_payment') bg-yellow-100 text-yellow-700
                            @else bg-neutral-100 text-neutral-600 @endif">
                            {{ $order->status_label }}
                        </span>
                    </div>
                </a>
            @empty
                <p class="px-4 py-8 text-sm text-neutral-500 text-center">Nenhum pedido encontrado.</p>
            @endforelse
        </div>
    </div>

    <div>{{ $orders->links() }}</div>
</div>
