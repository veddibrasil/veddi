<div class="space-y-6">
    <h1 class="text-2xl font-bold text-neutral-800">Dashboard</h1>

    @if (session('status'))
        <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg text-sm">
            {{ session('status') }}
        </div>
    @endif

    <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
        <div class="bg-white border rounded-xl p-4 shadow-sm">
            <p class="text-xs text-neutral-500 uppercase tracking-wide">Pedidos hoje</p>
            <p class="text-3xl font-bold text-amber-600 mt-1">{{ $todayOrders->count() }}</p>
        </div>
        <div class="bg-white border rounded-xl p-4 shadow-sm">
            <p class="text-xs text-neutral-500 uppercase tracking-wide">Receita hoje</p>
            <p class="text-2xl font-bold text-green-600 mt-1">R$ {{ number_format($todayRevenue, 2, ',', '.') }}</p>
        </div>
        <div class="bg-white border rounded-xl p-4 shadow-sm">
            <p class="text-xs text-neutral-500 uppercase tracking-wide">Em preparo</p>
            <p class="text-3xl font-bold text-blue-600 mt-1">{{ $pendingOrders }}</p>
        </div>
        <div class="bg-white border rounded-xl p-4 shadow-sm">
            <p class="text-xs text-neutral-500 uppercase tracking-wide">Total pedidos</p>
            <p class="text-3xl font-bold text-neutral-800 mt-1">{{ $totalOrders }}</p>
        </div>
    </div>

    <div class="bg-white border rounded-xl shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b flex items-center justify-between">
            <h2 class="font-semibold text-neutral-700">Pedidos de hoje</h2>
            <a href="{{ route('admin.orders.index') }}" class="text-xs text-amber-600 hover:underline">Ver todos</a>
        </div>
        <div class="divide-y">
            @forelse ($todayOrders->sortByDesc('created_at')->take(10) as $order)
                <a href="{{ route('admin.orders.show', $order) }}"
                    class="flex items-center justify-between px-4 py-3 hover:bg-neutral-50 transition-colors">
                    <div>
                        <p class="font-mono text-sm font-semibold">{{ $order->order_number }}</p>
                        <p class="text-xs text-neutral-500">{{ $order->customer->name ?? '—' }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-sm font-bold text-neutral-800">R$ {{ number_format($order->total, 2, ',', '.') }}</p>
                        <span class="text-xs px-2 py-0.5 rounded-full
                            @if($order->status === 'paid') bg-green-100 text-green-700
                            @elseif($order->status === 'preparing') bg-blue-100 text-blue-700
                            @elseif($order->status === 'cancelled') bg-red-100 text-red-700
                            @else bg-neutral-100 text-neutral-600 @endif">
                            {{ $order->status_label }}
                        </span>
                    </div>
                </a>
            @empty
                <p class="px-4 py-6 text-sm text-neutral-500 text-center">Nenhum pedido hoje.</p>
            @endforelse
        </div>
    </div>
</div>
