<div class="max-w-2xl space-y-6">
    <div class="flex items-center gap-3">
        <a href="{{ route('admin.orders.index') }}" class="text-neutral-400 hover:text-neutral-600">←</a>
        <h1 class="text-2xl font-bold text-neutral-800 font-mono">{{ $order->order_number }}</h1>
    </div>

    @if (session('status'))
        <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg text-sm">
            {{ session('status') }}
        </div>
    @endif

    @error('status')
        <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg text-sm">
            {{ $message }}
        </div>
    @enderror

    {{-- Customer + Branch --}}
    <div class="grid grid-cols-2 gap-4">
        <div class="bg-white border rounded-xl p-4 shadow-sm">
            <p class="text-xs text-neutral-400 uppercase tracking-wide mb-2">Cliente</p>
            <p class="font-semibold text-neutral-800">{{ $order->customer->name }}</p>
            <p class="text-sm text-neutral-500">{{ $order->customer->phone }}</p>
            <p class="text-sm text-neutral-500">{{ $order->customer->email }}</p>
            <p class="text-sm text-neutral-400 mt-1">{{ $order->customer->address }}, {{ $order->customer->neighborhood }}</p>
        </div>
        <div class="bg-white border rounded-xl p-4 shadow-sm">
            <p class="text-xs text-neutral-400 uppercase tracking-wide mb-2">Filial</p>
            <p class="font-semibold text-neutral-800">{{ $order->branch->name }}</p>
            <p class="text-sm text-neutral-500">{{ $order->branch->address }}</p>
            <p class="text-xs text-neutral-400 mt-2">Pedido em {{ $order->created_at->format('d/m/Y \à\s H:i') }}</p>
        </div>
    </div>

    {{-- Items --}}
    <div class="bg-white border rounded-xl shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b">
            <p class="font-semibold text-neutral-700">Itens do pedido</p>
        </div>
        <div class="divide-y">
            @foreach ($order->items as $item)
                <div class="flex items-center justify-between px-4 py-3">
                    <div>
                        <p class="font-medium text-sm text-neutral-800">{{ $item->product_name }}</p>
                        <p class="text-xs text-neutral-400">{{ $item->quantity }}x R$ {{ number_format($item->unit_price, 2, ',', '.') }}</p>
                    </div>
                    <p class="font-semibold text-sm text-neutral-800">
                        R$ {{ number_format($item->subtotal, 2, ',', '.') }}
                    </p>
                </div>
            @endforeach
            <div class="flex items-center justify-between px-4 py-3 bg-neutral-50">
                <p class="font-bold text-neutral-700">Total</p>
                <p class="font-bold text-lg text-amber-600">R$ {{ number_format($order->total, 2, ',', '.') }}</p>
            </div>
        </div>
    </div>

    @if ($order->notes)
        <div class="bg-amber-50 border border-amber-200 rounded-xl p-4">
            <p class="text-xs font-semibold text-amber-700 mb-1">Observações do cliente</p>
            <p class="text-sm text-amber-800">{{ $order->notes }}</p>
        </div>
    @endif

    {{-- Status --}}
    <div class="bg-white border rounded-xl shadow-sm p-4 space-y-3">
        <p class="font-semibold text-neutral-700">Atualizar status</p>
        <div class="flex flex-wrap gap-2">
            @foreach ([
                'paid'      => ['label' => 'Pago',      'color' => 'green'],
                'preparing' => ['label' => 'Preparando', 'color' => 'blue'],
                'ready'     => ['label' => 'Pronto',     'color' => 'indigo'],
                'delivered' => ['label' => 'Entregue',   'color' => 'teal'],
                'cancelled' => ['label' => 'Cancelado',  'color' => 'red'],
            ] as $status => $meta)
                <button wire:click="updateStatus('{{ $status }}')"
                    class="px-4 py-2 rounded-lg text-sm font-medium transition-colors
                        {{ $order->status === $status
                            ? "bg-{$meta['color']}-500 text-white"
                            : "bg-{$meta['color']}-50 text-{$meta['color']}-700 hover:bg-{$meta['color']}-100" }}">
                    {{ $meta['label'] }}
                </button>
            @endforeach
        </div>
        <p class="text-xs text-neutral-400">
            Status atual:
            <span class="font-semibold text-neutral-700">{{ $order->status_label }}</span>
        </p>
    </div>

    {{-- Payment --}}
    @if ($order->payment)
        <div class="bg-white border rounded-xl shadow-sm p-4">
            <p class="font-semibold text-neutral-700 mb-2">Pagamento</p>
            <div class="grid grid-cols-2 gap-2 text-sm">
                <div class="text-neutral-500">ID Abacate Pay</div>
                <div class="font-mono text-xs text-neutral-600 truncate">{{ $order->payment->abacatepay_billing_id }}</div>
                <div class="text-neutral-500">Status</div>
                <div>
                    <span class="px-2 py-0.5 rounded-full text-xs
                        {{ $order->payment->status === 'paid' ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700' }}">
                        {{ ucfirst($order->payment->status) }}
                    </span>
                </div>
                @if ($order->payment->paid_at)
                    <div class="text-neutral-500">Pago em</div>
                    <div class="text-neutral-700">{{ $order->payment->paid_at->format('d/m/Y H:i') }}</div>
                @endif
                <div class="text-neutral-500">Valor</div>
                <div class="font-bold text-neutral-800">R$ {{ number_format($order->payment->amount, 2, ',', '.') }}</div>
            </div>
        </div>
    @endif
</div>
