<div class="space-y-4 {{ $userStation === 'entrega' ? 'pb-24 lg:pb-4' : '' }}" x-data="stationPrintListener()">
    <x-admin.page-header :back-route="route('admin.orders.index')" :title="$order->order_number" title-class="font-mono">
        <x-slot:actions>
            @if (! $userStation)
                <a href="{{ route('admin.orders.receipt', $order) }}" target="_blank"
                   @click.prevent="printStation({{ $order->id }}, 'geral', $el.href)"
                   class="inline-flex items-center gap-1.5 text-sm font-medium bg-neutral-100 text-neutral-600 hover:bg-neutral-200 dark:bg-zinc-700 dark:text-neutral-300 dark:hover:bg-zinc-600 px-3 py-2 rounded-lg transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                    </svg>
                    Imprimir cupom
                </a>
            @endif
            @if ($order->isDeliveryOrder() && ($userStation === 'entrega' || ! $userStation))
                <a href="{{ route('admin.orders.receipt', ['order' => $order->id, 'station' => 'entrega']) }}" target="_blank"
                   @click.prevent="printStation({{ $order->id }}, 'entrega', $el.href)"
                   class="inline-flex items-center gap-1.5 text-sm font-medium bg-green-100 text-green-700 hover:bg-green-200 dark:bg-green-900/30 dark:text-green-400 dark:hover:bg-green-900/50 px-3 py-2 rounded-lg transition-colors">
                    Cupom entrega
                </a>
            @endif
            @if ($userStation === 'cozinha' || ! $userStation)
                <a href="{{ route('admin.orders.receipt', ['order' => $order->id, 'station' => 'cozinha']) }}" target="_blank"
                   @click.prevent="printStation({{ $order->id }}, 'cozinha', $el.href)"
                   class="inline-flex items-center gap-1.5 text-sm font-medium bg-orange-100 text-orange-700 hover:bg-orange-200 dark:bg-orange-900/30 dark:text-orange-400 dark:hover:bg-orange-900/50 px-3 py-2 rounded-lg transition-colors">
                    Cupom cozinha
                </a>
            @endif
            @if ($userStation === 'bar' || ! $userStation)
                <a href="{{ route('admin.orders.receipt', ['order' => $order->id, 'station' => 'bar']) }}" target="_blank"
                   @click.prevent="printStation({{ $order->id }}, 'bar', $el.href)"
                   class="inline-flex items-center gap-1.5 text-sm font-medium bg-blue-100 text-blue-700 hover:bg-blue-200 dark:bg-blue-900/30 dark:text-blue-400 dark:hover:bg-blue-900/50 px-3 py-2 rounded-lg transition-colors">
                    Cupom bar
                </a>
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    @if (session('status'))
        <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg text-sm dark:bg-green-900/30 dark:border-green-700 dark:text-green-400">
            {{ session('status') }}
        </div>
    @endif

    @if (session('error'))
        <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg text-sm dark:bg-red-900/30 dark:border-red-700 dark:text-red-400">
            {{ session('error') }}
        </div>
    @endif

    @error('status')
        <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg text-sm dark:bg-red-900/30 dark:border-red-700 dark:text-red-400">
            {{ $message }}
        </div>
    @enderror

    @if ($order->scheduled_at)
        <div class="flex items-center gap-3 bg-amber-50 border border-amber-300 rounded-xl px-4 py-3 dark:bg-amber-900/20 dark:border-amber-700">
            <span class="text-2xl shrink-0">🕐</span>
            <div>
                <p class="font-bold text-amber-800 dark:text-amber-300">Pedido Agendado</p>
                <p class="text-sm text-amber-700 dark:text-amber-400">
                    Entrega/Retirada prevista para
                    <strong>{{ $order->scheduled_at->setTimezone(config('app.timezone'))->format('d/m/Y \à\s H:i') }}</strong>
                </p>
            </div>
        </div>
    @endif

    {{-- Main Grid: left (items + notes) | right (status, payment) --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

        {{-- Left Column --}}
        <div class="lg:col-span-2 space-y-4">

            {{-- Customer + Branch --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-admin.form-card padding="p-4">
                    <p class="text-xs text-neutral-400 uppercase tracking-wide mb-2 dark:text-neutral-500">Cliente</p>
                    <p class="font-semibold text-neutral-800 dark:text-neutral-100">{{ $order->customer->name }}</p>
                    <p class="text-sm text-neutral-500 dark:text-neutral-400">{{ $order->customer->phone }}</p>
                    <p class="text-sm text-neutral-500 dark:text-neutral-400">{{ $order->customer->email }}</p>
                    <p class="text-sm text-neutral-400 mt-1 dark:text-neutral-500">{{ $order->customer->address }}, {{ $order->customer->neighborhood }}</p>
                </x-admin.form-card>
                <x-admin.form-card padding="p-4">
                    <p class="text-xs text-neutral-400 uppercase tracking-wide mb-2 dark:text-neutral-500">Filial</p>
                    <p class="font-semibold text-neutral-800 dark:text-neutral-100">{{ $order->branch->name }}</p>
                    <p class="text-sm text-neutral-500 dark:text-neutral-400">{{ $order->branch->address }}</p>
                    <p class="text-xs text-neutral-400 mt-2 dark:text-neutral-500">Pedido em {{ $order->created_at->format('d/m/Y \à\s H:i') }}</p>
                </x-admin.form-card>
            </div>

            {{-- Tipo de pedido --}}
            @if ($order->isDeliveryOrder())
                @php
                    $mapboxToken     = config('services.mapbox.token');
                    $deliveryRecord  = $order->deliveryAddressRecord;
                    $customerRecord  = $order->customer?->addressRecord;
                    $sourceRecord    = $deliveryRecord ?? $customerRecord;

                    $addrFull = implode(', ', array_filter([
                        $sourceRecord?->line1,
                        $sourceRecord?->number,
                        $sourceRecord?->complement,
                        $sourceRecord?->neighborhood,
                        $sourceRecord?->city,
                        $sourceRecord?->cep,
                    ]));
                    $googleMapsUrl = 'https://maps.google.com/?q=' . urlencode($addrFull);

                    $rawPhone = preg_replace('/\D/', '', $order->customer?->phone ?? '');
                    $whatsappPhone = strlen($rawPhone) <= 11 ? '55' . $rawPhone : $rawPhone;
                    $whatsappUrl = 'https://wa.me/' . $whatsappPhone;

                    $motoboyMsg = "🛵 *Entrega #{$order->order_number}*\n\n📍 *Endereço:* {$addrFull} \n\n {$googleMapsUrl}" ;
                    $motoboyWhatsappUrl = 'https://wa.me/?text=' . rawurlencode($motoboyMsg);
                @endphp

                <div class="bg-white border rounded-xl shadow-sm overflow-hidden dark:bg-zinc-800 dark:border-zinc-700">
                    <div class="flex items-center justify-between px-4 py-3 border-b dark:border-zinc-700">
                        <div class="flex items-center gap-2">
                            <span class="text-lg">🛵</span>
                            <div>
                                <p class="font-semibold text-sm text-neutral-800 dark:text-neutral-100">Entrega</p>
                                @if ($addrFull)
                                    <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ $addrFull }}</p>
                                @endif
                            </div>
                        </div>
                        <div class="flex items-center gap-3 shrink-0">
                            @if ($userStation === 'entrega')
                                <div class="text-right">
                                    <p class="text-[10px] uppercase tracking-wide text-neutral-400 dark:text-neutral-500">Taxa de frete</p>
                                    <p class="text-sm font-bold text-neutral-800 dark:text-neutral-100">
                                        @if ($order->delivery_fee > 0)
                                            R$ {{ number_format($order->delivery_fee, 2, ',', '.') }}
                                        @else
                                            <span class="text-green-600 dark:text-green-400">Grátis</span>
                                        @endif
                                    </p>
                                </div>
                            @endif
                            @if ($canUpdate && $order->isEditable() && !$editingAddress)
                                <button type="button" wire:click="startEditAddress"
                                        class="text-xs font-medium text-amber-600 hover:text-amber-700 dark:text-amber-400 dark:hover:text-amber-300 transition-colors">
                                    Editar endereço
                                </button>
                            @endif
                        </div>
                    </div>

                    @if ($editingAddress)
                        <div class="px-4 py-4 space-y-3"
                             x-data="{
                                 cepLoading: false,
                                 async fetchCep(val) {
                                     const digits = val.replace(/\D/g, '');
                                     if (digits.length !== 8) return;
                                     this.cepLoading = true;
                                     try {
                                         const res = await fetch('https://viacep.com.br/ws/' + digits + '/json/');
                                         const d = await res.json();
                                         if (!d.erro) {
                                             if (d.logradouro) $wire.set('editDeliveryAddress', d.logradouro);
                                             if (d.bairro)     $wire.set('editDeliveryNeighborhood', d.bairro);
                                             if (d.localidade) $wire.set('editDeliveryCity', d.localidade);
                                         }
                                     } catch(e) {}
                                     this.cepLoading = false;
                                 }
                             }">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="text-xs font-medium text-neutral-600 dark:text-neutral-400">CEP</label>
                                    <div class="relative">
                                        <flux:input wire:model="editDeliveryCep" type="text" maxlength="9"
                                                    x-on:input.debounce.500ms="fetchCep($event.target.value)" />
                                        <span x-show="cepLoading"
                                              class="absolute right-2 top-1/2 -translate-y-1/2 text-xs text-neutral-400">
                                            Buscando...
                                        </span>
                                    </div>
                                </div>
                                <div>
                                    <label class="text-xs font-medium text-neutral-600 dark:text-neutral-400">Complemento</label>
                                    <flux:input wire:model="editDeliveryComplement" type="text" />
                                </div>
                            </div>
                            <div class="grid grid-cols-3 gap-3">
                                <div class="col-span-2">
                                    <label class="text-xs font-medium text-neutral-600 dark:text-neutral-400">Rua / Avenida <span class="text-red-500">*</span></label>
                                    <flux:input wire:model="editDeliveryAddress" type="text" />
                                    @error('editDeliveryAddress') <p class="text-xs text-red-500 mt-0.5">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="text-xs font-medium text-neutral-600 dark:text-neutral-400">Número</label>
                                    <flux:input wire:model="editDeliveryNumber" type="text" />
                                </div>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="text-xs font-medium text-neutral-600 dark:text-neutral-400">Bairro</label>
                                    <flux:input wire:model="editDeliveryNeighborhood" type="text" />
                                </div>
                                <div>
                                    <label class="text-xs font-medium text-neutral-600 dark:text-neutral-400">Cidade <span class="text-red-500">*</span></label>
                                    <flux:input wire:model="editDeliveryCity" type="text" />
                                    @error('editDeliveryCity') <p class="text-xs text-red-500 mt-0.5">{{ $message }}</p> @enderror
                                </div>
                            </div>
                            <div class="flex justify-end gap-3 pt-1">
                                <button wire:click="cancelEditAddress"
                                        class="text-sm text-neutral-500 hover:text-neutral-700 dark:text-neutral-400 dark:hover:text-neutral-200">
                                    Cancelar
                                </button>
                                <button wire:click="saveAddress"
                                        wire:loading.attr="disabled"
                                        class="px-4 py-2 text-sm font-medium bg-amber-500 hover:bg-amber-600 disabled:opacity-50 text-white rounded-lg transition-colors">
                                    <span wire:loading.remove wire:target="saveAddress">Salvar</span>
                                    <span wire:loading wire:target="saveAddress">Salvando...</span>
                                </button>
                            </div>
                        </div>
                    @else
                        @once
                            <link href="https://api.mapbox.com/mapbox-gl-js/v3.3.0/mapbox-gl.css" rel="stylesheet">
                            <script src="https://api.mapbox.com/mapbox-gl-js/v3.3.0/mapbox-gl.js"></script>
                        @endonce

                        <div class="w-full h-52 relative">
                            <div id="customer-map-{{ $order->id }}"
                                 wire:ignore
                                 wire:key="customer-map-{{ $order->id }}-{{ md5($addrFull) }}"
                                 data-token="{{ $mapboxToken }}"
                                 data-address="{{ $addrFull }}"
                                 class="w-full h-full"></div>
                            <div id="customer-map-loader-{{ $order->id }}"
                                 class="absolute inset-0 flex items-center justify-center text-sm text-neutral-400 dark:text-neutral-500 bg-neutral-100 dark:bg-zinc-700 pointer-events-none">
                                Carregando mapa...
                            </div>
                        </div>

                        <div class="grid grid-cols-2 sm:flex gap-2 px-4 py-3 border-t dark:border-zinc-700">
                            <a href="{{ $googleMapsUrl }}" target="_blank"
                               class="sm:flex-1 flex items-center justify-center gap-1.5 text-xs font-medium bg-blue-50 text-blue-700 hover:bg-blue-100 dark:bg-blue-900/30 dark:text-blue-400 dark:hover:bg-blue-900/50 px-3 py-3 rounded-lg transition-colors">
                                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/></svg>
                                Google Maps
                            </a>
                            <button onclick="navigator.clipboard.writeText({{ Js::from($addrFull) }})" style="cursor: pointer"
                                    class="sm:flex-1 flex items-center justify-center gap-1.5 text-xs font-medium bg-neutral-50 text-neutral-600 hover:bg-neutral-100 dark:bg-zinc-700 dark:text-neutral-300 dark:hover:bg-zinc-600 px-3 py-3 rounded-lg transition-colors">
                                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                Copiar
                            </button>
                            @if ($whatsappPhone)
                                <a href="{{ $whatsappUrl }}" target="_blank"
                                   class="sm:flex-1 flex items-center justify-center gap-1.5 text-xs font-medium bg-green-50 text-green-700 hover:bg-green-100 dark:bg-green-900/30 dark:text-green-400 dark:hover:bg-green-900/50 px-3 py-3 rounded-lg transition-colors">
                                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413z"/></svg>
                                    Cliente
                                </a>
                            @endif
                            @if ($addrFull)
                                <a href="{{ $motoboyWhatsappUrl }}" target="_blank"
                                   class="sm:flex-1 flex items-center justify-center gap-1.5 text-xs font-medium bg-orange-50 text-orange-700 hover:bg-orange-100 dark:bg-orange-900/30 dark:text-orange-400 dark:hover:bg-orange-900/50 px-3 py-3 rounded-lg transition-colors">
                                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413z"/></svg>
                                    Motoboy
                                </a>
                            @endif
                        </div>
                    @endif
                </div>

                @script
                <script>
                    const orderId = @js($order->id);
                    initCustomerMap(orderId);
                    window.addEventListener('deliveryAddressUpdated', () => initCustomerMap(orderId));
                    document.addEventListener('livewire:navigated', () => initCustomerMap(orderId));
                    Livewire.hook('commit', ({ succeed }) => {
                        succeed(() => initCustomerMap(orderId));
                    });
                </script>
                @endscript
            @else
                <x-admin.form-card padding="p-4">
                    <div class="flex items-center gap-3">
                        <span class="inline-flex items-center justify-center w-9 h-9 rounded-full bg-blue-100 dark:bg-blue-900/40 text-blue-600 dark:text-blue-400 text-lg">🏪</span>
                        <div>
                            <p class="font-semibold text-neutral-800 dark:text-neutral-100">Retirada no local</p>
                            <p class="text-sm text-neutral-500 dark:text-neutral-400">{{ $order->branch->name }} — {{ $order->branch->address }}</p>
                        </div>
                    </div>
                </x-admin.form-card>
            @endif

            {{-- Items --}}
            <div class="bg-white border rounded-xl shadow-sm overflow-hidden dark:bg-zinc-800 dark:border-zinc-700">
                <div class="flex items-center justify-between px-4 py-3 border-b dark:border-zinc-700">
                    <p class="font-semibold text-neutral-700 dark:text-neutral-200">
                        Itens do pedido
                        @if ($userStation === 'cozinha')
                            <span class="ml-1 text-xs px-1.5 py-0.5 rounded-full bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-400">Cozinha</span>
                        @elseif ($userStation === 'bar')
                            <span class="ml-1 text-xs px-1.5 py-0.5 rounded-full bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-400">Bar</span>
                        @elseif ($userStation === 'entrega')
                            <span class="ml-1 text-xs px-1.5 py-0.5 rounded-full bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400">Entrega</span>
                        @endif
                    </p>
                    <div class="flex items-center gap-3">
                        @if ($canUpdate && $order->isEditable() && !$editingItems && !$userStation)
                            <button wire:click="startEditItems"
                                    class="text-xs font-medium text-amber-600 hover:text-amber-700 dark:text-amber-400 dark:hover:text-amber-300 transition-colors">
                                Editar itens
                            </button>
                        @endif
                    </div>
                </div>

                @if ($userStation)
                    <div class="divide-y dark:divide-zinc-700">
                        @forelse ($this->visibleItems as $item)
                            <div class="flex items-center justify-between px-4 py-3">
                                <div class="min-w-0">
                                    <p class="font-medium text-sm text-neutral-800 dark:text-neutral-100">{{ $item->quantity }}x {{ $item->product_name }}</p>
                                    @foreach (($item->options ?? []) as $group)
                                        @if (!empty($group['selections']))
                                            <p class="text-xs font-medium text-neutral-500 mt-0.5 dark:text-neutral-400">{{ $group['group_name'] ?? 'Opções' }}:</p>
                                            @foreach ($group['selections'] as $sel)
                                                <p class="text-xs text-neutral-400 leading-tight dark:text-neutral-500">{{ $sel['qty'] ?? 0 }}× {{ $sel['name'] ?? '-' }}</p>
                                            @endforeach
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        @empty
                            <div class="px-4 py-6 text-center text-sm text-neutral-400 dark:text-neutral-500">
                                Nenhum item desta estação neste pedido.
                            </div>
                        @endforelse
                    </div>
                @elseif ($editingItems)
                    <div class="divide-y dark:divide-zinc-700">
                        @foreach ($editableItems as $index => $item)
                            <div class="flex items-center gap-3 px-4 py-3">
                                <div class="flex-1 min-w-0">
                                    <p class="font-medium text-sm text-neutral-800 dark:text-neutral-100 truncate">{{ $item['product_name'] }}</p>
                                    @php $hasItemOptions = !empty($item['options'] ?? null); @endphp
                                    @if ($hasItemOptions)
                                        @foreach (($item['options'] ?? []) as $group)
                                            <p class="text-xs font-medium text-neutral-500 mt-0.5 dark:text-neutral-400">{{ $group['group_name'] ?? 'Opções' }}:</p>
                                            @foreach (($group['selections'] ?? []) as $sel)
                                                <p class="text-xs text-neutral-400 leading-tight dark:text-neutral-500">
                                                    {{ $sel['qty'] ?? 0 }}× {{ $sel['name'] ?? '-' }}@if ((float) ($sel['additional_price'] ?? 0) > 0) <span class="text-amber-600 dark:text-amber-400">(+R$ {{ number_format((float) ($sel['additional_price'] ?? 0), 2, ',', '.') }})</span>@endif
                                                </p>
                                            @endforeach
                                        @endforeach
                                    @else
                                        <p class="text-xs text-neutral-400 dark:text-neutral-500">R$ {{ number_format($item['unit_price'], 2, ',', '.') }} cada</p>
                                    @endif
                                </div>
                                <div class="flex items-center gap-2 shrink-0">
                                    <button wire:click="updateItemQuantity({{ $index }}, {{ $item['quantity'] - 1 }})"
                                            @if($item['quantity'] <= 1) disabled @endif
                                            class="w-7 h-7 flex items-center justify-center rounded-full border text-neutral-500 hover:bg-neutral-100 disabled:opacity-40 disabled:cursor-not-allowed dark:border-zinc-600 dark:hover:bg-zinc-700 dark:text-neutral-400 transition-colors">
                                        −
                                    </button>
                                    <span class="w-6 text-center text-sm font-semibold text-neutral-800 dark:text-neutral-100">{{ $item['quantity'] }}</span>
                                    <button wire:click="updateItemQuantity({{ $index }}, {{ $item['quantity'] + 1 }})"
                                            class="w-7 h-7 flex items-center justify-center rounded-full border text-neutral-500 hover:bg-neutral-100 dark:border-zinc-600 dark:hover:bg-zinc-700 dark:text-neutral-400 transition-colors">
                                        +
                                    </button>
                                </div>
                                <p class="w-20 text-right font-semibold text-sm text-neutral-800 dark:text-neutral-100">
                                    R$ {{ number_format($item['subtotal'], 2, ',', '.') }}
                                </p>
                                <button wire:click="openSwapItem({{ $index }})"
                                        class="text-xs font-medium text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300 transition-colors shrink-0">
                                    Trocar
                                </button>
                                <button wire:click="removeItem({{ $index }})"
                                        class="text-red-400 hover:text-red-600 dark:hover:text-red-400 transition-colors shrink-0">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                    </svg>
                                </button>
                            </div>
                        @endforeach

                        <div class="px-4 py-3 space-y-2">
                            <div class="relative">
                                <flux:input wire:model.live.debounce.300ms="productSearch"
                                       type="text"
                                       placeholder="Buscar produto para adicionar..."
                                        />
                                @if (!empty($productResults))
                                    <div class="absolute z-20 w-full mt-1 bg-white dark:bg-zinc-800 border dark:border-zinc-600 rounded-lg shadow-lg divide-y dark:divide-zinc-700 max-h-52 overflow-y-auto">
                                        @foreach ($productResults as $result)
                                            <button wire:click="addProductToEdit({{ $result['id'] }})"
                                                    class="w-full flex items-center justify-between px-3 py-2 text-left hover:bg-neutral-50 dark:hover:bg-zinc-700 transition-colors">
                                                <span class="text-sm text-neutral-800 dark:text-neutral-100">{{ $result['name'] }}</span>
                                                <span class="text-xs text-neutral-500 dark:text-neutral-400 shrink-0 ml-3">R$ {{ number_format($result['price'], 2, ',', '.') }}</span>
                                            </button>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                            @error('editableItems') <p class="text-xs text-red-500">{{ $message }}</p> @enderror
                        </div>

                        @php
                            $editSubtotal = collect($editableItems)->sum('subtotal');
                            $editTotal = max(0, $editSubtotal + $order->delivery_fee - $order->discount);
                        @endphp
                        <div class="px-4 py-3 bg-neutral-50 dark:bg-zinc-700/50 space-y-1.5">
                            <div class="flex items-center justify-between text-sm text-neutral-500 dark:text-neutral-400">
                                <span>Subtotal</span>
                                <span>R$ {{ number_format($editSubtotal, 2, ',', '.') }}</span>
                            </div>
                            @if ($order->delivery_fee > 0)
                                <div class="flex items-center justify-between text-sm text-neutral-500 dark:text-neutral-400">
                                    <span>Frete</span>
                                    <span>R$ {{ number_format($order->delivery_fee, 2, ',', '.') }}</span>
                                </div>
                            @endif
                            @if ($order->service_fee > 0)
                                <div class="flex items-center justify-between text-sm text-neutral-500 dark:text-neutral-400">
                                    <span>Taxa de serviço</span>
                                    <span>R$ {{ number_format($order->service_fee, 2, ',', '.') }}</span>
                                </div>
                            @endif
                            @if ($order->couvert_fee > 0)
                                <div class="flex items-center justify-between text-sm text-neutral-500 dark:text-neutral-400">
                                    <span>Couvert artístico</span>
                                    <span>R$ {{ number_format($order->couvert_fee, 2, ',', '.') }}</span>
                                </div>
                            @endif
                            @if ($order->discount > 0)
                                <div class="flex items-center justify-between text-sm text-green-600 dark:text-green-400">
                                    <span>Desconto</span>
                                    <span>− R$ {{ number_format($order->discount, 2, ',', '.') }}</span>
                                </div>
                            @endif
                            @if ($order->manual_discount > 0)
                                <div class="flex items-center justify-between text-sm text-green-600 dark:text-green-400">
                                    <span>Desconto manual</span>
                                    <span>− R$ {{ number_format($order->manual_discount, 2, ',', '.') }}</span>
                                </div>
                            @endif
                            <div class="flex items-center justify-between pt-1 border-t dark:border-zinc-600">
                                <p class="font-bold text-neutral-700 dark:text-neutral-200">Total</p>
                                <p class="font-bold text-lg text-amber-600 dark:text-amber-400">R$ {{ number_format($editTotal, 2, ',', '.') }}</p>
                            </div>
                        </div>

                        <div class="flex justify-end gap-3 px-4 py-3 border-t dark:border-zinc-700">
                            <button wire:click="cancelEditItems"
                                    class="text-sm text-neutral-500 hover:text-neutral-700 dark:text-neutral-400 dark:hover:text-neutral-200">
                                Cancelar
                            </button>
                            <button wire:click="saveItems"
                                    wire:loading.attr="disabled"
                                    class="px-4 py-2 text-sm font-medium bg-amber-500 hover:bg-amber-600 disabled:opacity-50 text-white rounded-lg transition-colors">
                                <span wire:loading.remove wire:target="saveItems">Salvar itens</span>
                                <span wire:loading wire:target="saveItems">Salvando...</span>
                            </button>
                        </div>
                    </div>
                @else
                    <div class="divide-y dark:divide-zinc-700">
                        @foreach ($order->items as $item)
                            <div class="flex items-center justify-between px-4 py-3">
                                <div class="min-w-0">
                                    <p class="font-medium text-sm text-neutral-800 dark:text-neutral-100">{{ $item->product_name }}</p>
                                    @php $hasItemOptions = !empty($item->options); @endphp
                                    @if ($hasItemOptions)
                                        @foreach (($item->options ?? []) as $group)
                                            <p class="text-xs font-medium text-neutral-500 mt-0.5 dark:text-neutral-400">{{ $group['group_name'] ?? 'Opções' }}:</p>
                                            @foreach (($group['selections'] ?? []) as $sel)
                                                <p class="text-xs text-neutral-400 leading-tight dark:text-neutral-500">
                                                    {{ $sel['qty'] ?? 0 }}× {{ $sel['name'] ?? '-' }}@if ((float) ($sel['additional_price'] ?? 0) > 0) <span class="text-amber-600 dark:text-amber-400">(+R$ {{ number_format((float) ($sel['additional_price'] ?? 0), 2, ',', '.') }})</span>@endif
                                                </p>
                                            @endforeach
                                        @endforeach
                                    @else
                                        <p class="text-xs text-neutral-400 dark:text-neutral-500">{{ $item->quantity }}x R$ {{ number_format($item->unit_price, 2, ',', '.') }}</p>
                                    @endif
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
                            @if ($order->service_fee > 0)
                                <div class="flex items-center justify-between text-sm text-neutral-500 dark:text-neutral-400">
                                    <span>Taxa de serviço</span>
                                    <span>R$ {{ number_format($order->service_fee, 2, ',', '.') }}</span>
                                </div>
                            @endif
                            @if ($order->couvert_fee > 0)
                                <div class="flex items-center justify-between text-sm text-neutral-500 dark:text-neutral-400">
                                    <span>Couvert artístico</span>
                                    <span>R$ {{ number_format($order->couvert_fee, 2, ',', '.') }}</span>
                                </div>
                            @endif
                            @if ($order->discount > 0)
                                <div class="flex items-center justify-between text-sm text-green-600 dark:text-green-400">
                                    <span>Desconto</span>
                                    <span>− R$ {{ number_format($order->discount, 2, ',', '.') }}</span>
                                </div>
                            @endif
                            @if ($order->manual_discount > 0)
                                <div class="flex items-center justify-between text-sm text-green-600 dark:text-green-400">
                                    <span>Desconto manual</span>
                                    <span>− R$ {{ number_format($order->manual_discount, 2, ',', '.') }}</span>
                                </div>
                            @endif
                            <div class="flex items-center justify-between pt-1 border-t dark:border-zinc-600">
                                <p class="font-bold text-neutral-700 dark:text-neutral-200">Total</p>
                                <p class="font-bold text-lg text-amber-600 dark:text-amber-400">R$ {{ number_format($order->total, 2, ',', '.') }}</p>
                            </div>
                        </div>
                    </div>
                @endif
            </div>

            {{-- Swap item modal --}}
            @if ($swappingItem)
                <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
                    <div class="bg-white dark:bg-zinc-800 border dark:border-zinc-700 rounded-2xl shadow-xl w-full max-w-2xl mx-4 overflow-hidden">
                        <div class="flex items-center justify-between px-5 py-4 border-b dark:border-zinc-700">
                            <div>
                                <p class="font-semibold text-neutral-800 dark:text-neutral-100">Trocar item/opções</p>
                                <p class="text-xs text-neutral-500 dark:text-neutral-400">Permitido somente se o valor unitário continuar igual.</p>
                            </div>
                            <button wire:click="cancelSwapItem"
                                    class="text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200 transition-colors text-xl leading-none">✕</button>
                        </div>

                        <div class="p-5 space-y-4">
                            @if ($swapError)
                                <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg text-sm dark:bg-red-900/30 dark:border-red-700 dark:text-red-400">
                                    {{ $swapError }}
                                </div>
                            @endif

                            @php
                                $currentUnit = $swapItemIndex !== null ? (float) ($editableItems[$swapItemIndex]['unit_price'] ?? 0) : 0;
                                $candidateUnit = (float) $swapCalculatedUnitPrice;
                                $samePrice = abs($currentUnit - $candidateUnit) <= 0.009;
                            @endphp
                            <div class="grid grid-cols-2 gap-3">
                                <div class="bg-neutral-50 dark:bg-zinc-700/50 border dark:border-zinc-600 rounded-xl p-3">
                                    <p class="text-xs text-neutral-500 dark:text-neutral-400">Valor unitário atual</p>
                                    <p class="font-mono font-bold text-neutral-800 dark:text-neutral-100">R$ {{ number_format($currentUnit, 2, ',', '.') }}</p>
                                </div>
                                <div class="bg-neutral-50 dark:bg-zinc-700/50 border dark:border-zinc-600 rounded-xl p-3">
                                    <p class="text-xs text-neutral-500 dark:text-neutral-400">Valor unitário novo</p>
                                    <p class="font-mono font-bold {{ $samePrice ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">R$ {{ number_format($candidateUnit, 2, ',', '.') }}</p>
                                </div>
                            </div>

                            @if ($swapItemIndex !== null)
                                @php $maxSwapQty = (int) ($editableItems[$swapItemIndex]['quantity'] ?? 1); @endphp
                                <div class="flex items-center justify-between gap-3 bg-neutral-50 dark:bg-zinc-700/50 border dark:border-zinc-600 rounded-xl p-3">
                                    <div>
                                        <p class="text-xs text-neutral-500 dark:text-neutral-400">Quantidade a trocar</p>
                                        <p class="text-sm text-neutral-700 dark:text-neutral-200">Máx: {{ $maxSwapQty }}</p>
                                    </div>
                                    <div class="flex items-center gap-2 shrink-0">
                                        <button wire:click="updateSwapQuantity({{ max(1, $swapQuantity - 1) }})"
                                                @if ($swapQuantity <= 1) disabled @endif
                                                class="w-8 h-8 flex items-center justify-center rounded-full border text-neutral-500 hover:bg-neutral-100 disabled:opacity-40 disabled:cursor-not-allowed dark:border-zinc-600 dark:hover:bg-zinc-700 dark:text-neutral-400 transition-colors">
                                            −
                                        </button>
                                        <span class="w-10 text-center text-sm font-semibold text-neutral-800 dark:text-neutral-100">{{ $swapQuantity }}</span>
                                        <button wire:click="updateSwapQuantity({{ min($maxSwapQty, $swapQuantity + 1) }})"
                                                @if ($swapQuantity >= $maxSwapQty) disabled @endif
                                                class="w-8 h-8 flex items-center justify-center rounded-full border text-neutral-500 hover:bg-neutral-100 disabled:opacity-40 disabled:cursor-not-allowed dark:border-zinc-600 dark:hover:bg-zinc-700 dark:text-neutral-400 transition-colors">
                                            +
                                        </button>
                                    </div>
                                </div>
                            @endif

                            <div class="space-y-2">
                                <label class="text-xs font-semibold text-neutral-600 dark:text-neutral-300 uppercase tracking-wide">Buscar produto</label>
                                <div class="relative" x-data @click.outside="$wire.closeSwapProductResults()">
                                    <input wire:model.live.debounce.300ms="swapProductSearch"
                                           wire:keydown.escape="$set('swapProductSearch',''); $wire.closeSwapProductResults()"
                                           type="text"
                                           placeholder="Digite para buscar..."
                                           class="w-full text-sm border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-400 dark:bg-zinc-700 dark:border-zinc-600 dark:text-neutral-100 dark:placeholder-neutral-400" />
                                    @if (!empty($swapProductResults))
                                        <div class="absolute z-20 w-full mt-1 bg-white dark:bg-zinc-800 border dark:border-zinc-600 rounded-lg shadow-lg divide-y dark:divide-zinc-700 max-h-56 overflow-y-auto">
                                            @foreach ($swapProductResults as $result)
                                                <button wire:click="selectSwapProduct({{ $result['id'] }})"
                                                        class="w-full flex items-center justify-between px-3 py-2 text-left hover:bg-neutral-50 dark:hover:bg-zinc-700 transition-colors">
                                                    <span class="text-sm text-neutral-800 dark:text-neutral-100">{{ $result['name'] }}</span>
                                                    <span class="text-xs text-neutral-500 dark:text-neutral-400 shrink-0 ml-3">Base R$ {{ number_format($result['base_price'], 2, ',', '.') }}</span>
                                                </button>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            </div>

                            @if ($swapProductId)
                                <div class="space-y-3">
                                    <p class="text-xs font-semibold text-neutral-600 dark:text-neutral-300 uppercase tracking-wide">Opções</p>

                                    @if (empty($swapGroups))
                                        <p class="text-sm text-neutral-500 dark:text-neutral-400">Este produto não possui opções.</p>
                                    @else
                                        <div class="space-y-3">
                                            @foreach ($swapGroups as $group)
                                                @php
                                                    $gid = (int) $group['id'];
                                                    $groupTotal = collect($swapSelections[$gid] ?? [])->sum();
                                                    $groupLimit = (int) ($group['total_qty'] ?? 0);
                                                    $isFixed = (bool) ($group['fixed'] ?? false);
                                                    $overLimit = ! $isFixed && $groupLimit > 0 && $groupTotal > $groupLimit;
                                                @endphp
                                                <div class="border dark:border-zinc-700 rounded-xl p-3">
                                                    <div class="flex items-center justify-between">
                                                        <p class="font-medium text-sm text-neutral-800 dark:text-neutral-100">{{ $group['name'] }}</p>
                                                        <p class="text-xs {{ $overLimit ? 'text-red-600 dark:text-red-400' : 'text-neutral-500 dark:text-neutral-400' }}">
                                                            {{ $isFixed ? 'Fixo' : 'Selecionado '.$groupTotal.' / '.$groupLimit }}
                                                        </p>
                                                    </div>

                                                    <div class="mt-2 space-y-2">
                                                        @foreach (($group['options'] ?? []) as $opt)
                                                            @php
                                                                $oid = (int) $opt['id'];
                                                                $qty = (int) ($swapSelections[$gid][$oid] ?? 0);
                                                                $paused = (bool) ($opt['paused'] ?? false);
                                                            @endphp
                                                            <div class="flex items-center justify-between gap-3">
                                                                <div class="min-w-0">
                                                                    <p class="text-sm text-neutral-800 dark:text-neutral-100 truncate">{{ $opt['name'] }}</p>
                                                                    @if ((float) ($opt['additional_price'] ?? 0) > 0)
                                                                        <p class="text-xs text-neutral-500 dark:text-neutral-400">+R$ {{ number_format((float) ($opt['additional_price'] ?? 0), 2, ',', '.') }}</p>
                                                                    @endif
                                                                    @if ($paused)
                                                                        <p class="text-[11px] text-amber-600 dark:text-amber-400">Em pausa</p>
                                                                    @endif
                                                                </div>
                                                                <div class="flex items-center gap-2 shrink-0">
                                                                    <button wire:click="updateSwapSelection({{ $gid }}, {{ $oid }}, {{ max(0, $qty - 1) }})"
                                                                            @if ($paused) disabled @endif
                                                                            class="w-7 h-7 flex items-center justify-center rounded-full border text-neutral-500 hover:bg-neutral-100 disabled:opacity-40 disabled:cursor-not-allowed dark:border-zinc-600 dark:hover:bg-zinc-700 dark:text-neutral-400 transition-colors">
                                                                        −
                                                                    </button>
                                                                    <span class="w-6 text-center text-sm font-semibold text-neutral-800 dark:text-neutral-100">{{ $qty }}</span>
                                                                    <button wire:click="updateSwapSelection({{ $gid }}, {{ $oid }}, {{ $qty + 1 }})"
                                                                            @if ($paused) disabled @endif
                                                                            class="w-7 h-7 flex items-center justify-center rounded-full border text-neutral-500 hover:bg-neutral-100 disabled:opacity-40 disabled:cursor-not-allowed dark:border-zinc-600 dark:hover:bg-zinc-700 dark:text-neutral-400 transition-colors">
                                                                        +
                                                                    </button>
                                                                </div>
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            @endif
                        </div>

                        <div class="flex items-center justify-end gap-3 px-5 py-4 border-t dark:border-zinc-700 bg-neutral-50 dark:bg-zinc-900/20">
                            <button wire:click="cancelSwapItem"
                                    class="text-sm text-neutral-500 hover:text-neutral-700 dark:text-neutral-400 dark:hover:text-neutral-200">
                                Cancelar
                            </button>
                            <button wire:click="applySwapItem"
                                    @if (! $samePrice) disabled @endif
                                    class="px-4 py-2 text-sm font-medium bg-blue-600 hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed text-white rounded-lg transition-colors">
                                Aplicar troca
                            </button>
                        </div>
                    </div>
                </div>
            @endif

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
            <x-admin.form-card padding="p-4 space-y-3">
                <div class="flex items-center justify-between">
                    <p class="font-semibold text-neutral-700 dark:text-neutral-200">Atualizar status</p>
                    <p class="text-xs text-neutral-400 dark:text-neutral-500">
                        Atual: <span class="font-semibold text-neutral-700 dark:text-neutral-300">{{ $order->status_label }}</span>
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    @foreach ($this->visibleStatusMap as $status => $meta)
                        <button wire:click="updateStatus('{{ $status }}')"
                            class="px-4 py-2 rounded-lg text-sm font-medium transition-colors {{ $order->status === $status ? $meta['active'] : $meta['inactive'] }}">
                            {{ $meta['label'] }}
                        </button>
                    @endforeach
                </div>
            </x-admin.form-card>

            {{-- Payment --}}
            @if (! $userStation)
            <x-admin.form-card padding="p-4">
                <p class="font-semibold text-neutral-700 mb-2 dark:text-neutral-200">Pagamento</p>
                <div class="grid grid-cols-2 gap-2 text-sm">
                    <div class="text-neutral-500 dark:text-neutral-400">Método</div>
                    <div class="font-semibold text-neutral-800 dark:text-neutral-100">
                        @if ($order->payment_method === 'pix') PIX
                        @elseif ($order->payment_method === 'card') Cartão de Crédito
                        @elseif ($order->payment_method === 'cash') Dinheiro
                        @elseif ($order->payment_method === 'split') Dividido
                        @else {{ $order->payment_method }}
                        @endif
                    </div>
                    @if ($order->payments->count() === 1)
                        @php $singlePayment = $order->payments->first(); @endphp
                        <div class="text-neutral-500 dark:text-neutral-400">Status</div>
                        <div>
                            <span class="px-2 py-0.5 rounded-full text-xs
                                {{ $singlePayment->status == 'paid' ? 'bg-green-100 text-green-700 dark:bg-green-900/50 dark:text-green-400' : 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/50 dark:text-yellow-400' }}">
                                {{ $singlePayment->status == 'paid' ? 'Pago' : ($singlePayment->status == 'pending' ? 'Pendente' : ucfirst($singlePayment->status)) }}
                            </span>
                        </div>
                        @if ($singlePayment->paid_at)
                            <div class="text-neutral-500 dark:text-neutral-400">Pago em</div>
                            <div class="text-neutral-700 dark:text-neutral-300">{{ $singlePayment->paid_at->format('d/m/Y H:i') }}</div>
                        @endif
                        <div class="text-neutral-500 dark:text-neutral-400">Valor</div>
                        <div class="font-bold text-neutral-800 dark:text-neutral-100">R$ {{ number_format($singlePayment->amount, 2, ',', '.') }}</div>
                    @endif
                </div>

                @if ($order->payments->count() > 1)
                    <div class="mt-3 space-y-1.5">
                        @foreach ($order->payments as $partPayment)
                            <div class="flex items-center justify-between text-sm border rounded-lg px-3 py-1.5 dark:border-zinc-700">
                                <span class="text-neutral-600 dark:text-neutral-300">
                                    @if ($partPayment->payment_gateway === 'cash') Dinheiro
                                    @elseif ($partPayment->payment_gateway === 'card_machine') Cartão
                                    @elseif ($partPayment->payment_gateway === 'pix_manual') PIX
                                    @else {{ $partPayment->payment_gateway }}
                                    @endif
                                </span>
                                <span class="flex items-center gap-2">
                                    <span class="px-2 py-0.5 rounded-full text-xs
                                        {{ $partPayment->status == 'paid' ? 'bg-green-100 text-green-700 dark:bg-green-900/50 dark:text-green-400' : 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/50 dark:text-yellow-400' }}">
                                        {{ $partPayment->status == 'paid' ? 'Pago' : ($partPayment->status == 'pending' ? 'Pendente' : ucfirst($partPayment->status)) }}
                                    </span>
                                    <span class="font-bold text-neutral-800 dark:text-neutral-100">R$ {{ number_format($partPayment->amount, 2, ',', '.') }}</span>
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif

                @if ($order->payments->isEmpty() && $order->order_type === 'pdv' && $order->status === 'awaiting_payment')
                    <div class="mt-3 bg-yellow-50 border border-yellow-200 rounded-xl p-3 dark:bg-yellow-900/20 dark:border-yellow-700">
                        <p class="text-xs text-yellow-700 dark:text-yellow-400 mb-2">A receber na entrega.</p>
                        <button wire:click="openConfirmPaymentModal"
                                class="w-full text-sm bg-green-100 text-green-700 hover:bg-green-200 dark:bg-green-900/30 dark:text-green-400 dark:hover:bg-green-900/50 px-4 py-2 rounded-lg transition-colors">
                            Confirmar pagamento
                        </button>
                    </div>
                @endif
                @if ($order->payments->where('status', 'paid')->isNotEmpty())
                    <button wire:click="openManualRefundModal"
                            class="mt-3 w-full text-sm bg-red-100 text-red-700 hover:bg-red-200 dark:bg-red-900/30 dark:text-red-400 dark:hover:bg-red-900/50 px-4 py-2 rounded-lg transition-colors">
                        Iniciar Reembolso
                    </button>
                @endif
            </x-admin.form-card>
            @endif

            {{-- Refund Timeline --}}
            @php $refunds = ! $userStation ? $order->refunds()->latest()->get() : collect(); @endphp
            @if ($refunds->isNotEmpty())
                <x-admin.form-card padding="p-4">
                    <p class="font-semibold text-neutral-700 mb-3 dark:text-neutral-200">Histórico de Estornos</p>
                    <div class="space-y-3">
                        @foreach ($refunds as $refund)
                            @php
                                $statusColor = match($refund->status) {
                                    'succeeded' => 'bg-green-100 text-green-700 dark:bg-green-900/50 dark:text-green-400',
                                    'failed' => 'bg-red-100 text-red-700 dark:bg-red-900/50 dark:text-red-400',
                                    'in_progress' => 'bg-blue-100 text-blue-700 dark:bg-blue-900/50 dark:text-blue-400',
                                    default => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/50 dark:text-yellow-400',
                                };
                            @endphp
                            <div class="border rounded-lg p-3 dark:border-zinc-700">
                                <div class="flex items-center justify-between mb-1">
                                    <span class="text-xs font-medium text-neutral-500 dark:text-neutral-400">
                                        #{{ $refund->id }} · {{ ucfirst($refund->gateway) }}
                                    </span>
                                    <span class="px-2 py-0.5 rounded-full text-xs {{ $statusColor }}">
                                        {{ $refund->status_label }}
                                    </span>
                                </div>
                                <div class="grid grid-cols-2 gap-1 text-xs text-neutral-600 dark:text-neutral-400">
                                    <span>Valor</span>
                                    <span class="font-semibold text-neutral-800 dark:text-neutral-100">R$ {{ number_format($refund->amount, 2, ',', '.') }}</span>
                                    <span>Solicitado por</span>
                                    <span>{{ ucfirst($refund->requested_by_type ?? '—') }}</span>
                                    @if ($refund->requested_at)
                                        <span>Solicitado em</span>
                                        <span>{{ $refund->requested_at->format('d/m/Y H:i') }}</span>
                                    @endif
                                    @if ($refund->processed_at)
                                        <span>Processado em</span>
                                        <span>{{ $refund->processed_at->format('d/m/Y H:i') }}</span>
                                    @endif
                                    @if ($refund->external_refund_id)
                                        <span>ID externo</span>
                                        <span class="font-mono truncate">{{ $refund->external_refund_id }}</span>
                                    @endif
                                    @if ($refund->failure_message)
                                        <span class="text-red-600 dark:text-red-400 col-span-2">{{ $refund->failure_message }}</span>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </x-admin.form-card>
            @endif
        </div>
    </div>

    {{-- Fiscal Note Section --}}
    @php
        $activeFiscalNote = $order->activeFiscalNote;
        $fiscalCompany = app()->bound('current.company') ? app('current.company') : null;
        $fiscalEnabled = $fiscalCompany?->canUseFiscalNotes();
        $canShowFiscal = $fiscalEnabled && in_array($order->status, ['paid', 'delivered', 'preparing', 'ready']);
    @endphp
    @if($canShowFiscal || $activeFiscalNote)
        <div class="mt-6">
            <x-admin.form-card padding="p-4">
                <p class="font-semibold text-neutral-700 mb-3 dark:text-neutral-200">Nota Fiscal (NFC-e)</p>

                @if($activeFiscalNote)
                    @php
                        $noteStatusColors = [
                            'pending'    => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-300',
                            'authorized' => 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300',
                            'error'      => 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300',
                        ];
                        $noteStatusLabels = ['pending' => 'Pendente', 'authorized' => 'Autorizada', 'error' => 'Erro'];
                    @endphp
                    <div class="flex items-center gap-3 flex-wrap">
                        <span class="px-2 py-1 rounded-full text-xs font-medium {{ $noteStatusColors[$activeFiscalNote->status] ?? '' }}">
                            {{ $noteStatusLabels[$activeFiscalNote->status] ?? $activeFiscalNote->status }}
                        </span>
                        @if($activeFiscalNote->danfe_url)
                            <a href="{{ $activeFiscalNote->danfe_url }}" target="_blank"
                                class="text-xs text-blue-600 dark:text-blue-400 hover:underline font-medium">
                                Baixar DANFE
                            </a>
                        @endif
                        @if($activeFiscalNote->xml_url)
                            <a href="{{ $activeFiscalNote->xml_url }}" target="_blank"
                                class="text-xs text-blue-600 dark:text-blue-400 hover:underline font-medium">
                                Baixar XML
                            </a>
                        @endif
                        @if($activeFiscalNote->error_message)
                            <span class="text-xs text-red-500 dark:text-red-400">{{ $activeFiscalNote->error_message }}</span>
                        @endif
                    </div>
                @elseif($canShowFiscal && $canIssueFiscal)
                    <button wire:click="openFiscalModal"
                        class="mt-1 text-sm bg-purple-100 text-purple-700 hover:bg-purple-200 dark:bg-purple-900/30 dark:text-purple-300 dark:hover:bg-purple-900/50 px-4 py-2 rounded-lg transition-colors">
                        Emitir NFC-e
                    </button>
                @elseif($canShowFiscal)
                    <p class="text-sm text-neutral-400 dark:text-neutral-500">Nenhuma nota emitida.</p>
                @endif
            </x-admin.form-card>
        </div>
    @endif

    {{-- Fiscal Note Modal --}}
    @if($showFiscalModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
            <div class="bg-white dark:bg-zinc-800 rounded-xl shadow-xl p-6 w-full max-w-md mx-4 space-y-4">
                <h3 class="text-lg font-semibold text-neutral-800 dark:text-neutral-100">Emitir NFC-e</h3>
                <p class="text-sm text-neutral-500 dark:text-neutral-400">Informe o CPF/CNPJ do consumidor (opcional).</p>
                <div>
                    <flux:input wire:model="fiscalCustomerDocument" label="CPF ou CNPJ do consumidor" placeholder="000.000.000-00" />
                    @error('fiscalCustomerDocument') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div class="flex justify-end gap-3">
                    <button wire:click="closeFiscalModal"
                        class="px-4 py-2 text-sm text-neutral-600 hover:text-neutral-800 dark:text-neutral-400 dark:hover:text-neutral-200">
                        Cancelar
                    </button>
                    <button wire:click="issueFiscalNote"
                        wire:loading.attr="disabled"
                        class="px-4 py-2 text-sm font-medium bg-purple-600 hover:bg-purple-700 disabled:opacity-50 text-white rounded-lg transition-colors">
                        <span wire:loading.remove wire:target="issueFiscalNote">Emitir</span>
                        <span wire:loading wire:target="issueFiscalNote">Enfileirando...</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Manual Refund Modal --}}
    @if ($showConfirmPaymentModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
            <div class="bg-white dark:bg-zinc-800 rounded-xl shadow-xl p-6 w-full max-w-md mx-4 space-y-5">
                <div class="flex items-start gap-4">
                    <div class="w-10 h-10 rounded-full bg-green-100 dark:bg-green-900/30 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold text-neutral-800 dark:text-neutral-100">Confirmar pagamento</h3>
                        <p class="text-sm text-neutral-500 dark:text-neutral-400 mt-1">Confirmar que o pagamento foi recebido na entrega?</p>
                    </div>
                </div>

                <div class="flex justify-end gap-3 pt-1">
                    <button wire:click="closeConfirmPaymentModal"
                            class="px-4 py-2 text-sm text-neutral-600 hover:text-neutral-800 dark:text-neutral-400 dark:hover:text-neutral-200">
                        Cancelar
                    </button>
                    <button wire:click="confirmPayment"
                            wire:loading.attr="disabled"
                            class="px-4 py-2 text-sm font-medium bg-green-600 hover:bg-green-700 disabled:opacity-50 text-white rounded-lg transition-colors">
                        <span wire:loading.remove wire:target="confirmPayment">Confirmar pagamento</span>
                        <span wire:loading wire:target="confirmPayment">Confirmando...</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    @if ($showManualRefundModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
            <div class="bg-white dark:bg-zinc-800 rounded-xl shadow-xl p-6 w-full max-w-md mx-4 space-y-5">
                <div class="flex items-start gap-4">
                    <div class="w-10 h-10 rounded-full bg-red-100 dark:bg-red-900/30 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold text-neutral-800 dark:text-neutral-100">Iniciar Reembolso</h3>
                        <p class="text-sm text-neutral-500 dark:text-neutral-400 mt-1">Escolha como o reembolso será processado.</p>
                    </div>
                </div>

                <div class="space-y-3">
                    <label class="flex items-start gap-3 p-3 border rounded-lg cursor-pointer {{ $manualRefundType === 'gateway' ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20' : 'dark:border-zinc-600' }}">
                        <input type="radio" wire:model.live="manualRefundType" value="gateway" class="mt-0.5">
                        <div>
                            <p class="font-medium text-sm text-neutral-800 dark:text-neutral-100">Via gateway</p>
                            <p class="text-xs text-neutral-500 dark:text-neutral-400">Solicita o estorno diretamente ao gateway de pagamento (Asaas/Vindi).</p>
                        </div>
                    </label>
                    <label class="flex items-start gap-3 p-3 border rounded-lg cursor-pointer {{ $manualRefundType === 'offline' ? 'border-amber-500 bg-amber-50 dark:bg-amber-900/20' : 'dark:border-zinc-600' }}">
                        <input type="radio" wire:model.live="manualRefundType" value="offline" class="mt-0.5">
                        <div>
                            <p class="font-medium text-sm text-neutral-800 dark:text-neutral-100">Reembolso offline</p>
                            <p class="text-xs text-neutral-500 dark:text-neutral-400">Registra como reembolsado sem acionar o gateway (devolução manual, espécie, etc.).</p>
                        </div>
                    </label>
                </div>

                @if ($manualRefundType === 'offline')
                    <div>
                        <label class="text-sm font-medium text-neutral-700 dark:text-neutral-300">Justificativa <span class="text-red-500">*</span></label>
                        <textarea wire:model="manualRefundJustification"
                                  rows="3"
                                  placeholder="Descreva como o reembolso foi realizado offline..."
                                  class="mt-1 w-full text-sm border rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-amber-400 dark:bg-zinc-700 dark:border-zinc-600 dark:text-neutral-100 dark:placeholder-neutral-400"></textarea>
                        @error('manualRefundJustification')
                            <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                @endif

                <div class="flex justify-end gap-3 pt-1">
                    <button wire:click="closeManualRefundModal"
                            class="px-4 py-2 text-sm text-neutral-600 hover:text-neutral-800 dark:text-neutral-400 dark:hover:text-neutral-200">
                        Cancelar
                    </button>
                    <button wire:click="manualRefund"
                            wire:loading.attr="disabled"
                            class="px-4 py-2 text-sm font-medium bg-red-600 hover:bg-red-700 disabled:opacity-50 text-white rounded-lg transition-colors">
                        <span wire:loading.remove wire:target="manualRefund">Confirmar reembolso</span>
                        <span wire:loading wire:target="manualRefund">Processando...</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Barra de status fixa embaixo (mobile) — ação principal sempre na zona do polegar --}}
    @if ($userStation === 'entrega')
        <div class="lg:hidden fixed inset-x-0 bottom-0 z-30 bg-white border-t dark:bg-zinc-800 dark:border-zinc-700 px-3 py-3 pb-[calc(0.75rem+env(safe-area-inset-bottom))] shadow-[0_-4px_12px_rgba(0,0,0,0.06)]">
            <div class="flex gap-2">
                @foreach ($this->visibleStatusMap as $status => $meta)
                    <button wire:click="updateStatus('{{ $status }}')"
                        wire:loading.attr="disabled"
                        class="flex-1 py-3.5 rounded-xl text-sm font-bold transition-colors disabled:opacity-50 {{ $order->status === $status ? $meta['active'] : $meta['inactive'] }}">
                        {{ $meta['label'] }}
                    </button>
                @endforeach
            </div>
        </div>
    @endif
</div>
