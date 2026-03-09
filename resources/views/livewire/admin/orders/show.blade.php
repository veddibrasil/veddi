<div class="space-y-4">
    {{-- Header --}}
    <div class="flex items-center gap-3">
        <a href="{{ route('admin.orders.index') }}" class="inline-flex items-center gap-1 text-sm text-neutral-400 hover:text-neutral-600 dark:text-neutral-500 dark:hover:text-neutral-300">
            ← Pedidos
        </a>
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

    {{-- Main Grid: left (items + notes) | right (customer, branch, status, payment) --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

        {{-- Left Column --}}
        <div class="lg:col-span-2 space-y-4">

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
        </div>

        {{-- Right Column --}}
        <div class="space-y-4">

            {{-- Status --}}
            @php
                $statusMap = [
                    'paid'      => ['label' => 'Pago',       'active' => 'bg-green-500 text-white',  'inactive' => 'bg-green-50 text-green-700 hover:bg-green-100 dark:bg-green-900/30 dark:text-green-400 dark:hover:bg-green-900/50'],
                    'preparing' => ['label' => 'Preparando',  'active' => 'bg-blue-500 text-white',   'inactive' => 'bg-blue-50 text-blue-700 hover:bg-blue-100 dark:bg-blue-900/30 dark:text-blue-400 dark:hover:bg-blue-900/50'],
                    'ready'     => ['label' => 'Pronto',      'active' => 'bg-indigo-500 text-white', 'inactive' => 'bg-indigo-50 text-indigo-700 hover:bg-indigo-100 dark:bg-indigo-900/30 dark:text-indigo-400 dark:hover:bg-indigo-900/50'],
                    'delivered' => ['label' => 'Entregue',    'active' => 'bg-teal-500 text-white',   'inactive' => 'bg-teal-50 text-teal-700 hover:bg-teal-100 dark:bg-teal-900/30 dark:text-teal-400 dark:hover:bg-teal-900/50'],
                    'cancelled' => ['label' => 'Cancelado',   'active' => 'bg-red-500 text-white',    'inactive' => 'bg-red-50 text-red-700 hover:bg-red-100 dark:bg-red-900/30 dark:text-red-400 dark:hover:bg-red-900/50'],
                ];
            @endphp
            <div class="bg-white border rounded-xl shadow-sm p-4 space-y-3 dark:bg-zinc-800 dark:border-zinc-700">
                <div class="flex items-center justify-between">
                    <p class="font-semibold text-neutral-700 dark:text-neutral-200">Atualizar status</p>
                    <p class="text-xs text-neutral-400 dark:text-neutral-500">
                        Atual: <span class="font-semibold text-neutral-700 dark:text-neutral-300">{{ $order->status_label }}</span>
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    @foreach ($statusMap as $status => $meta)
                        <button wire:click="updateStatus('{{ $status }}')"
                            class="px-4 py-2 rounded-lg text-sm font-medium transition-colors {{ $order->status === $status ? $meta['active'] : $meta['inactive'] }}">
                            {{ $meta['label'] }}
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- Mini chat com o cliente --}}
            <div
                class="bg-white border rounded-xl shadow-sm flex flex-col dark:bg-zinc-800 dark:border-zinc-700"
                x-data="{ scrollToBottom() { const el = $refs.msgList; if (el) el.scrollTop = el.scrollHeight; } }"
                x-init="scrollToBottom()"
                x-on:livewire:updated.window="$nextTick(() => scrollToBottom())"
            >
                <div class="px-4 py-3 border-b dark:border-zinc-700">
                    <p class="font-semibold text-neutral-700 dark:text-neutral-200 text-sm">Chat com o cliente</p>
                </div>

                {{-- Poll de fallback caso Echo não chegue --}}
                <div wire:poll.3s="loadMessages" class="hidden"></div>

                {{-- Área de mensagens --}}
                <div
                    x-ref="msgList"
                    class="overflow-y-auto px-3 py-3 space-y-2 min-h-40 max-h-75"
                >
                    @forelse ($chatMessages as $msg)
                        <div class="flex {{ $msg['sender'] === 'admin' ? 'justify-end' : 'justify-start' }}">
                            <div class="max-w-[85%] px-3 py-2 rounded-2xl text-sm leading-snug
                                {{ $msg['sender'] === 'admin'
                                    ? 'bg-amber-500 text-white rounded-br-sm'
                                    : 'bg-neutral-100 text-neutral-800 rounded-bl-sm dark:bg-zinc-700 dark:text-neutral-100' }}">
                                <p>{{ $msg['message'] }}</p>
                                <p class="text-[10px] mt-0.5 {{ $msg['sender'] === 'admin' ? 'text-amber-100' : 'text-neutral-400 dark:text-neutral-500' }} text-right">
                                    {{ $msg['created_at'] }}
                                </p>
                            </div>
                        </div>
                    @empty
                        <p class="text-center text-xs text-neutral-400 dark:text-neutral-500 py-6">Nenhuma mensagem ainda. Inicie a conversa!</p>
                    @endforelse
                </div>

                {{-- Input --}}
                <div class="border-t dark:border-zinc-700 px-3 py-3 flex gap-2">
                    <input
                        wire:model="adminMessage"
                        wire:keydown.enter="sendMessage"
                        type="text"
                        placeholder="Digite uma mensagem..."
                        class="flex-1 text-sm rounded-xl border border-neutral-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-amber-400 dark:bg-zinc-700 dark:border-zinc-600 dark:text-neutral-100 dark:placeholder-neutral-400"
                    />
                    <button
                        wire:click="sendMessage"
                        wire:loading.attr="disabled"
                        class="shrink-0 bg-amber-500 hover:bg-amber-600 disabled:opacity-50 text-white text-sm font-medium px-4 py-2 rounded-xl transition-colors"
                    >
                        <span wire:loading.remove wire:target="sendMessage">Enviar</span>
                        <span wire:loading wire:target="sendMessage">...</span>
                    </button>
                </div>
                @error('adminMessage')
                    <p class="text-xs text-red-500 px-3 pb-2">{{ $message }}</p>
                @enderror
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
    </div>
</div>
