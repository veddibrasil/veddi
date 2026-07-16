<div class="w-full space-y-6"
    x-data="{
        feeType: @entangle('fee_type'),
        mapOpen: false,
        mapInstance: null,
        mapMarker: null,
        mapLat: null,
        mapLng: null,
        mapLoading: false,
        mapError: null,
        init() {
            this.$watch('feeType', val => {
                if (val === 'distance' && !this.mapOpen) this.mapOpenWithCurrentValues();
                if (val !== 'distance') { this.mapOpen = false; this.mapDestroy(); }
            });
            this.$watch('mapOpen', val => { if (!val) this.mapDestroy(); });
            if (this.feeType === 'distance') {
                var self = this;
                setTimeout(() => { if (!self.mapOpen) self.mapOpenWithCurrentValues(); }, 200);
            }
        },
        mapBuild() {
            this.mapDestroy();
            var lat = this.mapLat ?? -15.7801;
            var lng = this.mapLng ?? -47.9292;
            var el = document.getElementById('branch-map-el');
            if (!el) return;
            var self = this;
            this.mapInstance = L.map(el, { zoomControl: true }).setView([lat, lng], 15);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© <a href=\'https://www.openstreetmap.org/copyright\'>OpenStreetMap</a>',
                maxZoom: 19,
            }).addTo(this.mapInstance);
            this.mapMarker = L.marker([lat, lng], { draggable: true }).addTo(this.mapInstance);
            this.mapMarker.on('dragend', function() {
                var pos = self.mapMarker.getLatLng();
                self.mapLat = pos.lat;
                self.mapLng = pos.lng;
            });
            this.mapInstance.on('click', function(e) {
                self.mapLat = e.latlng.lat;
                self.mapLng = e.latlng.lng;
                self.mapMarker.setLatLng([self.mapLat, self.mapLng]);
            });
            requestAnimationFrame(() => requestAnimationFrame(() => { if (this.mapInstance) this.mapInstance.invalidateSize(); }));
        },
        mapDestroy() {
            if (this.mapInstance) { this.mapInstance.remove(); this.mapInstance = null; this.mapMarker = null; }
        },
        mapUseLocation() {
            if (!navigator.geolocation) { this.mapError = 'Geolocalização não suportada pelo navegador.'; return; }
            this.mapLoading = true;
            this.mapError = null;
            var self = this;
            navigator.geolocation.getCurrentPosition(
                function(pos) {
                    self.mapLoading = false;
                    self.mapLat = pos.coords.latitude;
                    self.mapLng = pos.coords.longitude;
                    if (self.mapOpen) {
                        if (self.mapInstance) { self.mapInstance.setView([self.mapLat, self.mapLng], 17); self.mapMarker.setLatLng([self.mapLat, self.mapLng]); }
                    } else {
                        self.mapOpen = true;
                        setTimeout(() => self.mapBuild(), 100);
                    }
                },
                function() {
                    self.mapLoading = false;
                    self.mapError = 'Não foi possível obter localização. Verifique as permissões do navegador.';
                },
                { enableHighAccuracy: true, timeout: 12000 }
            );
        },
        mapConfirm() {
            if (this.mapMarker) { var pos = this.mapMarker.getLatLng(); this.mapLat = pos.lat; this.mapLng = pos.lng; }
            $wire.set('branch_latitude', this.mapLat);
            $wire.set('branch_longitude', this.mapLng);
            this.mapOpen = false;
            this.mapDestroy();
        },
        mapOpenWithCurrentValues() {
            var wireLat = $wire.get('branch_latitude');
            var wireLng = $wire.get('branch_longitude');
            if (wireLat && wireLng) { this.mapLat = parseFloat(wireLat); this.mapLng = parseFloat(wireLng); }
            this.mapOpen = true;
            var self = this;
            setTimeout(() => self.mapBuild(), 100);
        },
    }">

    <x-admin.page-header
        :back-route="route('admin.branches.index')"
        :title="'Configurar Entrega — ' . $branch->name"
    />

    @if (session('status'))
        <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg text-sm dark:bg-green-900/30 dark:border-green-700 dark:text-green-400">
            {{ session('status') }}
        </div>
    @endif

    <x-admin.form-card title="Configurações gerais">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">Tipo de cálculo de frete</label>
                <flux:select wire:model.live="fee_type" x-model="feeType">
                    <option value="flat">Taxa fixa</option>
                    <option value="neighborhood">Por bairro</option>
                    <option value="distance">Por distância (km)</option>
                </flux:select>
                @error('fee_type') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="pb-1 flex items-end">
                <flux:checkbox wire:model="active" label="Entrega ativa para esta filial" />
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <flux:input wire:model="minimum_order_value" type="number" step="0.01" min="0"
                    label="Pedido mínimo para entrega (R$)"
                    placeholder="0,00 = sem mínimo" />
                @error('minimum_order_value') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <flux:input wire:model="free_delivery_above" type="number" step="0.01" min="0"
                    label="Frete grátis acima de (R$)"
                    placeholder="Deixe em branco para não usar" />
                @error('free_delivery_above') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
        </div>

    </x-admin.form-card>

    {{-- Taxa Fixa --}}
    <div x-show="feeType === 'flat'" x-cloak>
        <x-admin.form-card title="Taxa fixa de entrega">
            <div class="max-w-xs">
                <flux:input wire:model="flat_fee" type="number" step="0.01" min="0"
                    label="Valor do frete (R$)" placeholder="Ex: 5,00" />
                @error('flat_fee') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
        </x-admin.form-card>
    </div>

    {{-- Por Bairro --}}
    <div x-show="feeType === 'neighborhood'" x-cloak>
        <x-admin.form-card>
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">Bairros atendidos</h2>
                <button wire:click="addNeighborhood" type="button"
                    class="text-xs bg-amber-500 text-white px-3 py-1.5 rounded-lg hover:bg-amber-600 transition-colors">
                    + Adicionar bairro
                </button>
            </div>

            @if (count($neighborhoods) === 0)
                <p class="text-sm text-neutral-400 dark:text-neutral-500">Nenhum bairro cadastrado. Clique em "Adicionar bairro" para começar.</p>
            @else
                <div class="space-y-2">
                    <div class="grid grid-cols-12 gap-2 text-xs font-medium text-neutral-400 uppercase px-1">
                        <span class="col-span-6">Bairro</span>
                        <span class="col-span-3">Taxa (R$)</span>
                        <span class="col-span-2">Ativo</span>
                        <span class="col-span-1"></span>
                    </div>

                    @foreach ($neighborhoods as $i => $n)
                        <div class="grid grid-cols-12 gap-2 items-center">
                            <div class="col-span-6">
                                <flux:input wire:model="neighborhoods.{{ $i }}.neighborhood" placeholder="Ex: Centro" />
                                @error("neighborhoods.{$i}.neighborhood") <p class="text-red-500 text-xs mt-0.5">{{ $message }}</p> @enderror
                            </div>
                            <div class="col-span-3">
                                <flux:input wire:model="neighborhoods.{{ $i }}.fee" type="number" step="0.01" min="0" placeholder="5,00" />
                                @error("neighborhoods.{$i}.fee") <p class="text-red-500 text-xs mt-0.5">{{ $message }}</p> @enderror
                            </div>
                            <div class="col-span-2 flex items-center">
                                <flux:checkbox wire:model="neighborhoods.{{ $i }}.active" />
                            </div>
                            <div class="col-span-1 flex justify-end">
                                <button wire:click="removeNeighborhood({{ $i }})" type="button"
                                    class="text-neutral-400 hover:text-red-500 text-lg leading-none">×</button>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-admin.form-card>
    </div>

    {{-- Por Distância --}}
    <div x-show="feeType === 'distance'" x-cloak>
        <x-admin.form-card title="Frete por distância">
            @once
                <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin=""/>
                <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
            @endonce

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <flux:input wire:model="branch_latitude" type="number" step="any"
                        label="Latitude da filial" placeholder="Ex: -23.5505" />
                    @error('branch_latitude') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <flux:input wire:model="branch_longitude" type="number" step="any"
                        label="Longitude da filial" placeholder="Ex: -46.6333" />
                    @error('branch_longitude') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="flex items-center gap-3 flex-wrap">
                <button type="button" @click="mapOpenWithCurrentValues()"
                    class="inline-flex items-center gap-1.5 text-xs bg-blue-50 text-blue-700 border border-blue-200 px-3 py-1.5 rounded-lg hover:bg-blue-100 transition-colors dark:bg-blue-900/30 dark:text-blue-300 dark:border-blue-700">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-4.724A1 1 0 013 14.382V5a1 1 0 011-1h16a1 1 0 011 1v9.382a1 1 0 01-.553.894L15 20M12 4v16"/></svg>
                    Selecionar no mapa
                </button>
                <button type="button" @click="mapUseLocation()" :disabled="mapLoading"
                    class="inline-flex items-center gap-1.5 text-xs bg-neutral-50 text-neutral-600 border border-neutral-200 px-3 py-1.5 rounded-lg hover:bg-neutral-100 transition-colors disabled:opacity-60 dark:bg-zinc-700 dark:text-neutral-300 dark:border-zinc-600">
                    <template x-if="!mapLoading">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    </template>
                    <template x-if="mapLoading">
                        <svg class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                    </template>
                    <span x-text="mapLoading ? 'Obtendo localização...' : 'Usar minha localização'"></span>
                </button>
                <p x-show="mapError" x-text="'⚠ ' + mapError" class="text-red-500 text-xs"></p>
            </div>

            <div x-show="mapOpen" x-cloak
                class="rounded-xl overflow-hidden border border-blue-200 shadow-sm dark:border-blue-700">
                <div class="flex items-center justify-between bg-blue-50 px-3 py-2 dark:bg-blue-900/30">
                    <span class="text-xs font-medium text-blue-700 dark:text-blue-300">Arraste o pin ou clique no mapa para posicionar a filial</span>
                    <button type="button" @click="mapOpen = false; mapDestroy()" class="text-blue-400 hover:text-blue-700 text-lg leading-none">×</button>
                </div>
                <div id="branch-map-el" wire:ignore style="height:280px; width:100%;"></div>
                <div class="flex gap-2 bg-blue-50 px-3 py-2 dark:bg-blue-900/30">
                    <button type="button" @click="mapOpen = false; mapDestroy()"
                        class="flex-1 text-xs border border-neutral-300 text-neutral-600 px-3 py-1.5 rounded-lg hover:bg-neutral-50 dark:border-zinc-600 dark:text-neutral-300 dark:hover:bg-zinc-700">
                        Cancelar
                    </button>
                    <button type="button" @click="mapConfirm()"
                        class="flex-1 text-xs bg-amber-500 text-white px-3 py-1.5 rounded-lg hover:bg-amber-600 transition-colors font-medium">
                        Confirmar local →
                    </button>
                </div>
            </div>

            <div class="flex items-center justify-between pt-2">
                <h3 class="font-medium text-neutral-700 text-sm dark:text-neutral-300">Faixas de distância</h3>
                <button wire:click="addDistanceTier" type="button"
                    class="text-xs bg-amber-500 text-white px-3 py-1.5 rounded-lg hover:bg-amber-600 transition-colors">
                    + Adicionar faixa
                </button>
            </div>

            @if (count($distanceTiers) === 0)
                <p class="text-sm text-neutral-400 dark:text-neutral-500">Nenhuma faixa cadastrada. Clique em "Adicionar faixa" para começar.</p>
            @else
                <div class="space-y-2">
                    <div class="grid grid-cols-12 gap-2 text-xs font-medium text-neutral-400 uppercase px-1">
                        <span class="col-span-3">De (km)</span>
                        <span class="col-span-3">Até (km)</span>
                        <span class="col-span-4">Taxa (R$)</span>
                        <span class="col-span-2"></span>
                    </div>

                    @foreach ($distanceTiers as $i => $tier)
                        <div class="grid grid-cols-12 gap-2 items-center">
                            <div class="col-span-3">
                                <flux:input wire:model="distanceTiers.{{ $i }}.min_km" type="number" step="0.1" min="0" placeholder="0" />
                                @error("distanceTiers.{$i}.min_km") <p class="text-red-500 text-xs mt-0.5">{{ $message }}</p> @enderror
                            </div>
                            <div class="col-span-3">
                                <flux:input wire:model="distanceTiers.{{ $i }}.max_km" type="number" step="0.1" min="0" placeholder="sem limite" />
                                @error("distanceTiers.{$i}.max_km") <p class="text-red-500 text-xs mt-0.5">{{ $message }}</p> @enderror
                            </div>
                            <div class="col-span-4">
                                <flux:input wire:model="distanceTiers.{{ $i }}.fee" type="number" step="0.01" min="0" placeholder="5,00" />
                                @error("distanceTiers.{$i}.fee") <p class="text-red-500 text-xs mt-0.5">{{ $message }}</p> @enderror
                            </div>
                            <div class="col-span-2 flex justify-end">
                                <button wire:click="removeDistanceTier({{ $i }})" type="button"
                                    class="text-neutral-400 hover:text-red-500 text-lg leading-none">×</button>
                            </div>
                        </div>
                    @endforeach

                    <p class="text-xs text-neutral-400 dark:text-neutral-500">Deixe "Até" em branco na última faixa para cobrir qualquer distância.</p>
                </div>
            @endif
        </x-admin.form-card>
    </div>

    <x-admin.form-actions
        save-label="Salvar configurações"
        :cancel-route="route('admin.branches.index')"
    />
</div>
