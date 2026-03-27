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

            {{-- Tipo de pedido --}}
            @if ($order->order_type === 'delivery')
                @php
                    $mapboxToken    = config('services.mapbox.token');
                    $customerAddr   = implode(', ', array_filter([
                        $order->customer->address,
                        $order->customer->neighborhood,
                        $order->customer->city,
                    ]));
                    $googleMapsUrl  = 'https://maps.google.com/?q=' . urlencode($customerAddr);
                    $wazeUrl        = 'https://waze.com/ul?q=' . urlencode($customerAddr);
                @endphp

                <div class="bg-white border rounded-xl shadow-sm overflow-hidden dark:bg-zinc-800 dark:border-zinc-700">
                    {{-- Header --}}
                    <div class="flex items-center justify-between px-4 py-3 border-b dark:border-zinc-700">
                        <div class="flex items-center gap-2">
                            <span class="text-lg">🛵</span>
                            <div>
                                <p class="font-semibold text-sm text-neutral-800 dark:text-neutral-100">Entrega</p>
                                <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ $customerAddr }}</p>
                            </div>
                        </div>
                    </div>

                    {{-- Mapa --}}
                    <div wire:ignore class="w-full h-52 bg-neutral-100 dark:bg-zinc-700 relative overflow-hidden">
                        <img id="customer-map-{{ $order->id }}"
                             data-token="{{ $mapboxToken }}"
                             data-address="{{ $customerAddr }}"
                             class="w-full h-full object-cover"
                             alt="Mapa do endereço de entrega" />
                        <div id="customer-map-loader-{{ $order->id }}"
                             class="absolute inset-0 flex items-center justify-center text-sm text-neutral-400 dark:text-neutral-500">
                            Carregando mapa...
                        </div>
                    </div>

                    {{-- Botões de compartilhar --}}
                    <div class="flex gap-2 px-4 py-3 border-t dark:border-zinc-700">
                        <a href="{{ $googleMapsUrl }}" target="_blank"
                           class="flex-1 flex items-center justify-center gap-1.5 text-xs font-medium bg-blue-50 text-blue-700 hover:bg-blue-100 dark:bg-blue-900/30 dark:text-blue-400 dark:hover:bg-blue-900/50 px-3 py-2 rounded-lg transition-colors">
                            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/></svg>
                            Google Maps
                        </a>
                        <a href="{{ $wazeUrl }}" target="_blank"
                           class="flex-1 flex items-center justify-center gap-1.5 text-xs font-medium bg-sky-50 text-sky-700 hover:bg-sky-100 dark:bg-sky-900/30 dark:text-sky-400 dark:hover:bg-sky-900/50 px-3 py-2 rounded-lg transition-colors">
                            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="currentColor"><path d="M20.54 6.63C19.08 4.15 16.71 2.37 14 1.76V1.5a2 2 0 0 0-4 0v.26C7.29 2.37 4.92 4.15 3.46 6.63A9.94 9.94 0 0 0 2 12c0 3.58 1.88 6.9 4.96 8.77.33.2.7.3 1.07.3.41 0 .82-.12 1.17-.36l1.3-.87c.44-.3.7-.79.7-1.32v-2.04c0-.88-.72-1.6-1.6-1.6H8v-1.26c0-.88.72-1.6 1.6-1.6h4.8c.88 0 1.6.72 1.6 1.6v1.26h-1.6c-.88 0-1.6.72-1.6 1.6v2.04c0 .53.26 1.02.7 1.32l1.3.87c.35.24.76.36 1.17.36.37 0 .74-.1 1.07-.3C20.12 18.9 22 15.58 22 12c0-1.96-.51-3.85-1.46-5.37z"/></svg>
                            Waze
                        </a>
                        <button onclick="navigator.clipboard.writeText({{ Js::from($customerAddr) }})"
                                class="flex-1 flex items-center justify-center gap-1.5 text-xs font-medium bg-neutral-50 text-neutral-600 hover:bg-neutral-100 dark:bg-zinc-700 dark:text-neutral-300 dark:hover:bg-zinc-600 px-3 py-2 rounded-lg transition-colors">
                            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                            Copiar
                        </button>
                    </div>
                </div>

                @script
                <script>
                    (function () {
                        const img    = document.getElementById('customer-map-{{ $order->id }}');
                        const loader = document.getElementById('customer-map-loader-{{ $order->id }}');
                        if (!img || img.dataset.initialized) return;
                        img.dataset.initialized = 'true';

                        const token   = img.dataset.token;
                        const address = img.dataset.address;
                        if (!token) return;

                        fetch('https://api.mapbox.com/geocoding/v5/mapbox.places/' + encodeURIComponent(address) + '.json?access_token=' + token + '&country=BR&limit=1')
                            .then(function (r) { return r.json(); })
                            .then(function (data) {
                                var coords = data.features && data.features[0] && data.features[0].center;
                                if (!coords) {
                                    if (loader) loader.textContent = 'Endereço não encontrado.';
                                    return;
                                }
                                var lng = coords[0];
                                var lat = coords[1];
                                var pin = 'pin-s+f59e0b(' + lng + ',' + lat + ')';
                                var src = 'https://api.mapbox.com/styles/v1/mapbox/streets-v12/static/'
                                    + pin + '/' + lng + ',' + lat + ',15/800x208@2x'
                                    + '?access_token=' + token;

                                var el = document.getElementById('customer-map-{{ $order->id }}');
                                if (!el) return;
                                el.onload = function () {
                                    if (loader) loader.style.display = 'none';
                                };
                                el.onerror = function () {
                                    if (loader) loader.textContent = 'Erro ao carregar o mapa.';
                                };
                                el.src = src;
                            })
                            .catch(function () {
                                if (loader) loader.textContent = 'Erro ao buscar endereço.';
                            });
                    })();
                </script>
                @endscript
            @else
                <div class="bg-white border rounded-xl p-4 shadow-sm dark:bg-zinc-800 dark:border-zinc-700">
                    <div class="flex items-center gap-3">
                        <span class="inline-flex items-center justify-center w-9 h-9 rounded-full bg-blue-100 dark:bg-blue-900/40 text-blue-600 dark:text-blue-400 text-lg">🏪</span>
                        <div>
                            <p class="font-semibold text-neutral-800 dark:text-neutral-100">Retirada no local</p>
                            <p class="text-sm text-neutral-500 dark:text-neutral-400">{{ $order->branch->name }} — {{ $order->branch->address }}</p>
                        </div>
                    </div>
                </div>
            @endif

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
                    <div class="px-4 py-3 bg-neutral-50 dark:bg-zinc-700/50 space-y-1.5">
                        <div class="flex items-center justify-between text-sm text-neutral-500 dark:text-neutral-400">
                            <span>Subtotal</span>
                            <span>R$ {{ number_format($order->subtotal, 2, ',', '.') }}</span>
                        </div>
                        @if ($order->delivery_fee > 0)
                            <div class="flex items-center justify-between text-sm text-neutral-500 dark:text-neutral-400">
                                <span>Frete</span>
                                <span>R$ {{ number_format($order->delivery_fee, 2, ',', '.') }}</span>
                            </div>
                        @elseif ($order->order_type === 'delivery')
                            <div class="flex items-center justify-between text-sm text-neutral-500 dark:text-neutral-400">
                                <span>Frete</span>
                                <span class="text-green-600 dark:text-green-400">Grátis</span>
                            </div>
                        @endif
                        @if ($order->discount > 0)
                            <div class="flex items-center justify-between text-sm text-green-600 dark:text-green-400">
                                <span>Desconto</span>
                                <span>− R$ {{ number_format($order->discount, 2, ',', '.') }}</span>
                            </div>
                        @endif
                        <div class="flex items-center justify-between pt-1 border-t dark:border-zinc-600">
                            <p class="font-bold text-neutral-700 dark:text-neutral-200">Total</p>
                            <p class="font-bold text-lg text-amber-600 dark:text-amber-400">R$ {{ number_format($order->total, 2, ',', '.') }}</p>
                        </div>
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
                    'awaiting_payment' => ['label' => 'Ag. Pagamento', 'active' => 'bg-yellow-500 text-white',  'inactive' => 'bg-yellow-50 text-yellow-700 hover:bg-yellow-100 dark:bg-yellow-900/30 dark:text-yellow-400 dark:hover:bg-yellow-900/50'],
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
            <div class="bg-white border rounded-xl shadow-sm p-4 dark:bg-zinc-800 dark:border-zinc-700">
                <p class="font-semibold text-neutral-700 mb-2 dark:text-neutral-200">Pagamento</p>
                <div class="grid grid-cols-2 gap-2 text-sm">
                    <div class="text-neutral-500 dark:text-neutral-400">Método</div>
                    <div class="font-semibold text-neutral-800 dark:text-neutral-100">
                        @if ($order->payment_method === 'PIX') PIX
                        @elseif ($order->payment_method === 'CARD') Cartão de Crédito
                        @elseif ($order->payment_method === 'CASH') Dinheiro
                        @else {{ $order->payment_method }}
                        @endif
                    </div>
                    @if ($order->payment)
                        <div class="text-neutral-500 dark:text-neutral-400">ID Asaas</div>
                        <div class="font-mono text-xs text-neutral-600 truncate dark:text-neutral-300">{{ $order->payment->asaas_payment_id }}</div>
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
                    @endif
                </div>
                @if ($order->payment && $order->payment->status === 'paid')
                    <flux:modal.trigger name="confirm-manual-refund">
                        <button class="mt-3 w-full text-sm bg-red-100 text-red-700 hover:bg-red-200 dark:bg-red-900/30 dark:text-red-400 dark:hover:bg-red-900/50 px-4 py-2 rounded-lg transition-colors">
                            Marcar como Reembolsado (Manual)
                        </button>
                    </flux:modal.trigger>
                @endif
            </div>
        </div>
    </div>

    <flux:modal name="confirm-manual-refund" class="max-w-sm">
        <div class="space-y-5">
            <div class="flex items-start gap-4">
                <div class="w-10 h-10 rounded-full bg-red-100 dark:bg-red-900/30 flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
                    </svg>
                </div>
                <div>
                    <flux:heading size="lg">Confirmar reembolso?</flux:heading>
                    <flux:subheading class="mt-1">O pedido será cancelado e o pagamento marcado como reembolsado. Esta ação não pode ser desfeita.</flux:subheading>
                </div>
            </div>
            <div class="flex justify-end gap-3 pt-1">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancelar</flux:button>
                </flux:modal.close>
                <flux:modal.close>
                    <flux:button wire:click="manualRefund" variant="danger">Confirmar reembolso</flux:button>
                </flux:modal.close>
            </div>
        </div>
    </flux:modal>
</div>
