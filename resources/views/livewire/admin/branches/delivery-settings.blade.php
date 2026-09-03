<div class="w-full space-y-6"
    x-data="{
        feeType: @entangle('fee_type'),
        initialLat: @json($branch_latitude !== '' ? (float) $branch_latitude : null),
        initialLng: @json($branch_longitude !== '' ? (float) $branch_longitude : null),
        mapOpen: false,
        mapInstance: null,
        mapMarker: null,
        mapLat: null,
        mapLng: null,
        mapLoading: false,
        mapError: null,
        zoneMapInstance: null,
        zonePolygonDrawer: null,
        zoneBranchMarker: null,
        zoneSettingLocation: false,
        zoneDrawing: false,
        zoneModalOpen: false,
        zoneDraftPolygon: [],
        zoneDraftName: '',
        zoneDraftFee: '',
        init() {
            this.$watch('feeType', val => {
                if (val === 'distance' && !this.mapOpen) this.mapOpenWithCurrentValues();
                if (val !== 'distance') { this.mapOpen = false; this.mapDestroy(); }
                if (val === 'zone' && !this.zoneMapInstance) { var self = this; setTimeout(() => self.zoneMapBuild(), 100); }
                if (val !== 'zone') this.zoneMapDestroy();
            });
            this.$watch('mapOpen', val => { if (!val) this.mapDestroy(); });
            if (this.feeType === 'distance') {
                var self = this;
                setTimeout(() => { if (!self.mapOpen) self.mapOpenWithCurrentValues(); }, 200);
            }
            if (this.feeType === 'zone') {
                var self = this;
                setTimeout(() => self.zoneMapBuild(), 200);
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
            if (this.mapLat === null && this.initialLat !== null) { this.mapLat = this.initialLat; this.mapLng = this.initialLng; }
            this.mapOpen = true;
            var self = this;
            setTimeout(() => self.mapBuild(), 100);
        },
        zoneColor(i) {
            var palette = ['#f59e0b', '#3b82f6', '#10b981', '#ef4444', '#8b5cf6', '#ec4899', '#14b8a6', '#f97316'];
            return palette[i % palette.length];
        },
        zoneMapBuild() {
            this.zoneMapDestroy();
            var el = document.getElementById('zone-map-el');
            if (!el) return;
            var self = this;
            var lat = this.mapLat ?? this.initialLat ?? -15.7801;
            var lng = this.mapLng ?? this.initialLng ?? -47.9292;
            this.zoneMapInstance = L.map(el, { zoomControl: true }).setView([lat, lng], 13);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© <a href=\'https://www.openstreetmap.org/copyright\'>OpenStreetMap</a>',
                maxZoom: 19,
            }).addTo(this.zoneMapInstance);

            // Lê direto do $wire (não do valor travado no x-data no primeiro render) — senão uma
            // área desenhada agora só aparece no mapa depois de recarregar a página.
            var existingZones = $wire.zones;
            existingZones.forEach(function(zone, idx) {
                if (!zone.polygon || zone.polygon.length < 3) return;
                L.polygon(zone.polygon, { color: self.zoneColor(idx), weight: 2 })
                    .addTo(self.zoneMapInstance)
                    .bindTooltip(zone.name + ' — R$ ' + zone.fee);
            });

            var branchLat = this.mapLat ?? this.initialLat;
            var branchLng = this.mapLng ?? this.initialLng;
            if (branchLat !== null && branchLat !== undefined && branchLng !== null && branchLng !== undefined) {
                this.zoneEnsureBranchMarker(branchLat, branchLng);
            }

            this.zonePolygonDrawer = new L.Draw.Polygon(this.zoneMapInstance, {
                shapeOptions: { color: '#f59e0b', weight: 3 },
                showLength: false,
                metric: false,
            });

            this.zoneMapInstance.on(L.Draw.Event.DRAWSTART, function() { self.zoneDrawing = true; });
            this.zoneMapInstance.on(L.Draw.Event.DRAWSTOP, function() { self.zoneDrawing = false; });

            this.zoneMapInstance.on(L.Draw.Event.CREATED, function(e) {
                var latlngs = e.layer.getLatLngs()[0];
                self.zoneDraftPolygon = latlngs.map(function(p) { return [p.lat, p.lng]; });
                self.zoneDraftName = '';
                self.zoneDraftFee = '';
                self.zoneModalOpen = true;
            });

            this.zoneMapInstance.on('click', function(e) {
                if (!self.zoneSettingLocation) return;
                self.zoneSettingLocation = false;
                self.mapLat = e.latlng.lat;
                self.mapLng = e.latlng.lng;
                $wire.set('branch_latitude', e.latlng.lat);
                $wire.set('branch_longitude', e.latlng.lng);
                self.zoneEnsureBranchMarker(e.latlng.lat, e.latlng.lng);
            });

            requestAnimationFrame(() => requestAnimationFrame(() => { if (this.zoneMapInstance) this.zoneMapInstance.invalidateSize(); }));
        },
        zoneMapDestroy() {
            if (this.zoneMapInstance) { this.zoneMapInstance.remove(); this.zoneMapInstance = null; this.zonePolygonDrawer = null; this.zoneBranchMarker = null; }
        },
        zoneEnsureBranchMarker(lat, lng) {
            if (!this.zoneMapInstance) return;
            var self = this;
            if (this.zoneBranchMarker) {
                this.zoneBranchMarker.setLatLng([lat, lng]);
                return;
            }
            this.zoneBranchMarker = L.marker([lat, lng], {
                draggable: true,
                icon: L.icon({
                    iconUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon.png',
                    iconRetinaUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon-2x.png',
                    shadowUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-shadow.png',
                    iconSize: [25, 41], iconAnchor: [12, 41],
                }),
            }).addTo(this.zoneMapInstance).bindTooltip('Local do restaurante (arraste para ajustar)');
            this.zoneBranchMarker.on('dragend', function() {
                var pos = self.zoneBranchMarker.getLatLng();
                self.mapLat = pos.lat;
                self.mapLng = pos.lng;
                $wire.set('branch_latitude', pos.lat);
                $wire.set('branch_longitude', pos.lng);
            });
        },
        zoneUseLocation() {
            if (!navigator.geolocation) { this.mapError = 'Geolocalização não suportada pelo navegador.'; return; }
            this.mapLoading = true;
            this.mapError = null;
            var self = this;
            navigator.geolocation.getCurrentPosition(
                function(pos) {
                    self.mapLoading = false;
                    self.mapLat = pos.coords.latitude;
                    self.mapLng = pos.coords.longitude;
                    $wire.set('branch_latitude', self.mapLat);
                    $wire.set('branch_longitude', self.mapLng);
                    self.zoneEnsureBranchMarker(self.mapLat, self.mapLng);
                    if (self.zoneMapInstance) self.zoneMapInstance.setView([self.mapLat, self.mapLng], 15);
                },
                function() {
                    self.mapLoading = false;
                    self.mapError = 'Não foi possível obter localização. Verifique as permissões do navegador.';
                },
                { enableHighAccuracy: true, timeout: 12000 }
            );
        },
        zoneStartDraw() {
            this.zoneSettingLocation = false;
            if (this.zonePolygonDrawer) this.zonePolygonDrawer.enable();
        },
        zoneCancelDrawMode() {
            if (this.zonePolygonDrawer) this.zonePolygonDrawer.disable();
        },
        zoneToggleSetLocation() {
            this.zoneCancelDrawMode();
            this.zoneSettingLocation = !this.zoneSettingLocation;
        },
        zoneConfirmDraw() {
            if (!this.zoneDraftName || this.zoneDraftFee === '') return;
            $wire.addZoneFromDraw(this.zoneDraftName, this.zoneDraftFee, this.zoneDraftPolygon);
            this.zoneModalOpen = false;
            this.zoneDraftPolygon = [];
            this.zoneDraftName = '';
            this.zoneDraftFee = '';
            var self = this;
            setTimeout(() => self.zoneMapBuild(), 400);
        },
        zoneCancelDraw() {
            this.zoneModalOpen = false;
            this.zoneDraftPolygon = [];
        },
    }">
    @once
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin=""/>
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
    @endonce

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
                    <option value="zone">Por área no mapa</option>
                </flux:select>
                @error('fee_type') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="pb-1 flex items-end">
                <flux:checkbox wire:model="active" label="Entrega ativa para esta filial" />
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
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
            <div>
                <flux:input wire:model="service_radius_km" type="number" step="0.01" min="0"
                    label="Raio de atuação (km)"
                    placeholder="Deixe em branco para sem limite" />
                <p class="text-xs text-neutral-400 dark:text-neutral-500 mt-1">
                    Distância máxima de entrega a partir desta filial.
                </p>
                @error('service_radius_km') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
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

            @error('distanceTiers') <p class="text-red-500 text-xs">{{ $message }}</p> @enderror

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

    {{-- Por Área no Mapa --}}
    <div x-show="feeType === 'zone'" x-cloak>
        <x-admin.form-card>
            @once
                <link rel="stylesheet" href="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.css" crossorigin=""/>
                <script src="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.js" crossorigin=""></script>
            @endonce

            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">Áreas de entrega no mapa</h2>
                <button type="button" @click="zoneDrawing ? zoneCancelDrawMode() : zoneStartDraw()"
                    class="text-xs px-3 py-1.5 rounded-lg transition-colors"
                    :class="zoneDrawing ? 'bg-neutral-200 text-neutral-700 hover:bg-neutral-300 dark:bg-zinc-600 dark:text-neutral-200' : 'bg-amber-500 text-white hover:bg-amber-600'">
                    <span x-show="!zoneDrawing">+ Nova área</span>
                    <span x-show="zoneDrawing" x-cloak>Cancelar desenho</span>
                </button>
            </div>

            <p class="text-xs text-neutral-500 dark:text-neutral-400">
                Clique em "+ Nova área", depois marque os pontos do polígono no mapa. Dê duplo clique (ou clique no primeiro ponto) para fechar a área. Áreas sobrepostas: a primeira da lista abaixo tem prioridade.
            </p>

            <div class="flex items-center gap-3 flex-wrap">
                <button type="button" @click="zoneToggleSetLocation()"
                    :class="zoneSettingLocation ? 'bg-blue-100 border-blue-300 dark:bg-blue-900/50' : 'bg-blue-50 border-blue-200 dark:bg-blue-900/30 dark:border-blue-700'"
                    class="inline-flex items-center gap-1.5 text-xs text-blue-700 border px-3 py-1.5 rounded-lg hover:bg-blue-100 transition-colors dark:text-blue-300">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-4.724A1 1 0 013 14.382V5a1 1 0 011-1h16a1 1 0 011 1v9.382a1 1 0 01-.553.894L15 20M12 4v16"/></svg>
                    <span x-text="zoneSettingLocation ? 'Clique no mapa para marcar o local' : 'Marcar local do restaurante'"></span>
                </button>
                <button type="button" @click="zoneUseLocation()" :disabled="mapLoading"
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

            <div class="relative rounded-xl overflow-hidden border border-neutral-200 dark:border-zinc-700">
                <div x-show="zoneDrawing" x-cloak
                    class="absolute inset-x-0 top-0 z-2000 flex items-center justify-between gap-2 bg-amber-50 border-b border-amber-200 px-3 py-2 dark:bg-amber-900/40 dark:border-amber-700">
                    <span class="text-xs font-medium text-amber-700 dark:text-amber-300">Clique no mapa para marcar os pontos. Duplo clique para fechar a área.</span>
                    <button type="button" @click="zoneCancelDrawMode()" class="text-amber-500 hover:text-amber-800 text-lg leading-none">×</button>
                </div>
                <div x-show="zoneSettingLocation" x-cloak
                    class="absolute inset-x-0 top-0 z-2000 flex items-center justify-between gap-2 bg-blue-50 border-b border-blue-200 px-3 py-2 dark:bg-blue-900/40 dark:border-blue-700">
                    <span class="text-xs font-medium text-blue-700 dark:text-blue-300">Clique no mapa para marcar o local do restaurante.</span>
                    <button type="button" @click="zoneSettingLocation = false" class="text-blue-500 hover:text-blue-800 text-lg leading-none">×</button>
                </div>
                <div id="zone-map-el" wire:ignore style="height:380px; width:100%;"></div>
            </div>

            @error('zones') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            @error('branch_latitude') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror

            @if (count($zones) === 0)
                <p class="text-sm text-neutral-400 dark:text-neutral-500">Nenhuma área cadastrada. Desenhe um polígono no mapa acima para começar.</p>
            @else
                <div class="space-y-2">
                    <div class="grid grid-cols-12 gap-2 text-xs font-medium text-neutral-400 uppercase px-1">
                        <span class="col-span-5">Nome da área</span>
                        <span class="col-span-3">Taxa (R$)</span>
                        <span class="col-span-2">Ativo</span>
                        <span class="col-span-2 text-right">Prioridade</span>
                    </div>

                    @foreach ($zones as $i => $zone)
                        <div class="grid grid-cols-12 gap-2 items-center">
                            <div class="col-span-5">
                                <flux:input wire:model="zones.{{ $i }}.name" placeholder="Ex: Centro" />
                                @error("zones.{$i}.name") <p class="text-red-500 text-xs mt-0.5">{{ $message }}</p> @enderror
                            </div>
                            <div class="col-span-3">
                                <flux:input wire:model="zones.{{ $i }}.fee" type="number" step="0.01" min="0" placeholder="13,00" />
                                @error("zones.{$i}.fee") <p class="text-red-500 text-xs mt-0.5">{{ $message }}</p> @enderror
                            </div>
                            <div class="col-span-2 flex items-center">
                                <flux:checkbox wire:model="zones.{{ $i }}.active" />
                            </div>
                            <div class="col-span-2 flex justify-end gap-1">
                                <button wire:click="moveZoneUp({{ $i }})" type="button" @if($i === 0) disabled @endif
                                    class="text-neutral-400 hover:text-amber-600 disabled:opacity-30 text-sm leading-none px-1">↑</button>
                                <button wire:click="moveZoneDown({{ $i }})" type="button" @if($i === count($zones) - 1) disabled @endif
                                    class="text-neutral-400 hover:text-amber-600 disabled:opacity-30 text-sm leading-none px-1">↓</button>
                                <button wire:click="removeZone({{ $i }})" type="button"
                                    class="text-neutral-400 hover:text-red-500 text-lg leading-none px-1">×</button>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            <div x-show="zoneModalOpen" x-cloak
                class="fixed inset-0 z-2000 flex items-center justify-center bg-black/40 px-4"
                @keydown.escape.window="zoneCancelDraw()">
                <div class="bg-white dark:bg-zinc-800 rounded-xl shadow-lg w-full max-w-sm p-5 space-y-4">
                    <div class="flex items-center justify-between">
                        <h3 class="font-semibold text-neutral-700 dark:text-neutral-200">Área de Entrega Mapa</h3>
                        <button type="button" @click="zoneCancelDraw()" class="text-neutral-400 hover:text-neutral-700 text-lg leading-none">×</button>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">Nome da Área</label>
                        <input type="text" x-model="zoneDraftName" placeholder="area01"
                            class="w-full rounded-lg border border-neutral-300 px-3 py-1.5 text-sm dark:bg-zinc-700 dark:border-zinc-600 dark:text-neutral-200" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">Taxa de Entrega</label>
                        <input type="number" step="0.01" min="0" x-model="zoneDraftFee" placeholder="13,00"
                            class="w-full rounded-lg border border-neutral-300 px-3 py-1.5 text-sm dark:bg-zinc-700 dark:border-zinc-600 dark:text-neutral-200" />
                    </div>
                    <div class="flex gap-2 pt-1">
                        <button type="button" @click="zoneCancelDraw()"
                            class="flex-1 text-xs border border-neutral-300 text-neutral-600 px-3 py-1.5 rounded-lg hover:bg-neutral-50 dark:border-zinc-600 dark:text-neutral-300 dark:hover:bg-zinc-700">
                            Cancelar
                        </button>
                        <button type="button" @click="zoneConfirmDraw()"
                            class="flex-1 text-xs bg-amber-500 text-white px-3 py-1.5 rounded-lg hover:bg-amber-600 transition-colors font-medium">
                            OK
                        </button>
                    </div>
                </div>
            </div>
        </x-admin.form-card>
    </div>

    <x-admin.form-actions
        save-label="Salvar configurações"
        :cancel-route="route('admin.branches.index')"
    />
</div>
