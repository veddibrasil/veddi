<div class="max-w-2xl space-y-6">
    <div class="flex items-center gap-3">
        <a href="{{ route('admin.orders.index') }}" class="text-neutral-400 hover:text-neutral-600 dark:text-neutral-500 dark:hover:text-neutral-300">←</a>
        <h1 class="text-2xl font-bold text-neutral-800 font-mono dark:text-neutral-100">{{ $order->order_number }}</h1>
    </div>

    @if (session('status'))
        <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg text-sm dark:bg-green-900/30 dark:border-green-700 dark:text-green-400">
            {{ session('status') }}
        </div>
    @endif

    @error('status')
        <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg text-sm dark:bg-red-900/30 dark:border-red-700 dark:text-red-400">
            {{ $message }}
        </div>
    @enderror

    {{-- Customer + Branch --}}
    <div class="grid grid-cols-2 gap-4">
        <div class="bg-white border rounded-xl p-4 shadow-sm dark:bg-zinc-800 dark:border-zinc-700">
            <p class="text-xs text-neutral-400 uppercase tracking-wide mb-2 dark:text-neutral-500">Cliente</p>
            <p class="font-semibold text-neutral-800 dark:text-neutral-100">{{ $order->customer->name }}</p>
            <p class="text-sm text-neutral-500 dark:text-neutral-400">{{ $order->customer->phone }}</p>
            <p class="text-sm text-neutral-500 dark:text-neutral-400">{{ $order->customer->email }}</p>
            <p class="text-sm text-neutral-400 mt-1 dark:text-neutral-500">{{ $order->customer->address }}, {{ $order->customer->neighborhood }}</p>
        </div>
        <div class="bg-white border rounded-xl p-4 shadow-sm dark:bg-zinc-800 dark:border-zinc-700">
            <p class="text-xs text-neutral-400 uppercase tracking-wide mb-2 dark:text-neutral-500">Filial</p>
            <p class="font-semibold text-neutral-800 dark:text-neutral-100">{{ $order->branch->name }}</p>
            <p class="text-sm text-neutral-500 dark:text-neutral-400">{{ $order->branch->address }}</p>
            <p class="text-xs text-neutral-400 mt-2 dark:text-neutral-500">Pedido em {{ $order->created_at->format('d/m/Y \à\s H:i') }}</p>
        </div>
    </div>

    {{-- Items --}}
    <div class="bg-white border rounded-xl shadow-sm overflow-hidden dark:bg-zinc-800 dark:border-zinc-700">
        <div class="px-4 py-3 border-b dark:border-zinc-700">
            <p class="font-semibold text-neutral-700 dark:text-neutral-200">Itens do pedido</p>
        </div>
        <div class="divide-y dark:divide-zinc-700">
            @foreach ($order->items as $item)
                <div class="flex items-center justify-between px-4 py-3">
                    <div>
                        <p class="font-medium text-sm text-neutral-800 dark:text-neutral-100">{{ $item->product_name }}</p>
                        <p class="text-xs text-neutral-400 dark:text-neutral-500">{{ $item->quantity }}x R$ {{ number_format($item->unit_price, 2, ',', '.') }}</p>
                    </div>
                    <p class="font-semibold text-sm text-neutral-800 dark:text-neutral-100">
                        R$ {{ number_format($item->subtotal, 2, ',', '.') }}
                    </p>
                </div>
            @endforeach
            <div class="flex items-center justify-between px-4 py-3 bg-neutral-50 dark:bg-zinc-700/50">
                <p class="font-bold text-neutral-700 dark:text-neutral-200">Total</p>
                <p class="font-bold text-lg text-amber-600 dark:text-amber-400">R$ {{ number_format($order->total, 2, ',', '.') }}</p>
            </div>
        </div>
    </div>

    @if ($order->notes)
        <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 dark:bg-amber-900/20 dark:border-amber-700">
            <p class="text-xs font-semibold text-amber-700 mb-1 dark:text-amber-400">Observações do cliente</p>
            <p class="text-sm text-amber-800 dark:text-amber-300">{{ $order->notes }}</p>
        </div>
    @endif

    {{-- Status --}}
    <div class="bg-white border rounded-xl shadow-sm p-4 space-y-3 dark:bg-zinc-800 dark:border-zinc-700">
        <p class="font-semibold text-neutral-700 dark:text-neutral-200">Atualizar status</p>
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
                            : "bg-{$meta['color']}-50 text-{$meta['color']}-700 hover:bg-{$meta['color']}-100 dark:bg-{$meta['color']}-900/30 dark:text-{$meta['color']}-400 dark:hover:bg-{$meta['color']}-900/50" }}">
                    {{ $meta['label'] }}
                </button>
            @endforeach
        </div>
        <p class="text-xs text-neutral-400 dark:text-neutral-500">
            Status atual:
            <span class="font-semibold text-neutral-700 dark:text-neutral-300">{{ $order->status_label }}</span>
        </p>
    </div>

    {{-- Payment --}}
    @if ($order->payment)
        <div class="bg-white border rounded-xl shadow-sm p-4 dark:bg-zinc-800 dark:border-zinc-700">
            <p class="font-semibold text-neutral-700 mb-2 dark:text-neutral-200">Pagamento</p>
            <div class="grid grid-cols-2 gap-2 text-sm">
                <div class="text-neutral-500 dark:text-neutral-400">ID Abacate Pay</div>
                <div class="font-mono text-xs text-neutral-600 truncate dark:text-neutral-300">{{ $order->payment->abacatepay_billing_id }}</div>
                <div class="text-neutral-500 dark:text-neutral-400">Status</div>
                <div>
                    <span class="px-2 py-0.5 rounded-full text-xs
                        {{ $order->payment->status === 'paid' ? 'bg-green-100 text-green-700 dark:bg-green-900/50 dark:text-green-400' : 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/50 dark:text-yellow-400' }}">
                        {{ ucfirst($order->payment->status) }}
                    </span>
                </div>
                @if ($order->payment->paid_at)
                    <div class="text-neutral-500 dark:text-neutral-400">Pago em</div>
                    <div class="text-neutral-700 dark:text-neutral-300">{{ $order->payment->paid_at->format('d/m/Y H:i') }}</div>
                @endif
                <div class="text-neutral-500 dark:text-neutral-400">Valor</div>
                <div class="font-bold text-neutral-800 dark:text-neutral-100">R$ {{ number_format($order->payment->amount, 2, ',', '.') }}</div>
            </div>
        </div>
    @endif
</div>
