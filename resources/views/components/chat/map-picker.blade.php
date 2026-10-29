@props(['prefix', 'label' => 'Usar minha localização', 'collapsible' => false])

<div class="space-y-2" @if ($collapsible) x-data="{ mapToolsOpen: false }" @endif>
    @if ($collapsible)
        <button type="button" x-on:click="mapToolsOpen = !mapToolsOpen" :aria-expanded="mapToolsOpen.toString()" aria-controls="map-{{ $prefix }}-tools"
            class="w-full min-h-11 flex items-center justify-center gap-2 text-sm font-semibold text-blue-700 rounded-xl hover:bg-blue-50">
            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
            </svg>
            Não sabe o CEP? Marcar no mapa
        </button>
    @endif

    <div id="map-{{ $prefix }}-tools" class="space-y-2" @if ($collapsible) x-show="mapToolsOpen" style="display:none" @endif>
        {{-- Search input --}}
        <div class="flex gap-1.5">
            <label for="map-{{ $prefix }}-search-input" class="sr-only">Buscar endereço no mapa</label>
            <input
                type="text"
                id="map-{{ $prefix }}-search-input"
                placeholder="Digite rua e cidade"
                class="flex-1 min-h-11 text-sm border border-gray-200 rounded-xl px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-blue-300 focus:border-blue-300 bg-white placeholder-gray-500"
                onkeydown="if(event.key==='Enter'){event.preventDefault();mapPickerSearch('{{ $prefix }}');}"
            />
            <button
                type="button"
                id="map-{{ $prefix }}-search-btn"
                onclick="mapPickerSearch('{{ $prefix }}')"
                class="shrink-0 min-h-11 flex items-center gap-1.5 border border-blue-200 bg-blue-50 hover:bg-blue-100 active:bg-blue-200 text-blue-700 text-sm font-semibold rounded-xl px-3 py-2.5 transition-colors disabled:opacity-60"
            >
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11A6 6 0 1 1 5 11a6 6 0 0 1 12 0z"/>
                </svg>
                <span id="map-{{ $prefix }}-search-span-default">Buscar</span>
                <span id="map-{{ $prefix }}-search-span-loading" style="display:none">...</span>
            </button>
        </div>
        <p id="map-{{ $prefix }}-search-error" role="alert" style="display:none" class="text-red-600 text-sm flex items-center gap-1"></p>

        {{-- GPS button --}}
        <button type="button" id="map-{{ $prefix }}-loc-btn" onclick="mapPickerUseLocation('{{ $prefix }}')"
            class="w-full min-h-11 flex items-center justify-center gap-2 border border-gray-200 bg-gray-50 hover:bg-gray-100 active:bg-gray-200 text-gray-700 text-sm font-semibold rounded-xl py-2.5 transition-colors disabled:opacity-60">
            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
            </svg>
            <span id="map-{{ $prefix }}-span-default">{{ $label }}</span>
            <span id="map-{{ $prefix }}-span-loading" style="display:none" class="flex items-center gap-1.5">
                <svg class="animate-spin w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                </svg>
                Obtendo localização...
            </span>
        </button>
        <p id="map-{{ $prefix }}-error" role="alert" style="display:none" class="text-red-600 text-sm -mt-1 flex items-center gap-1"></p>
    </div>

    {{-- Fica fora da área recolhível: o servidor abre o mapa para confirmar o endereço digitado. --}}
    <div id="map-{{ $prefix }}-container" wire:ignore style="display:none" class="rounded-xl overflow-hidden border border-blue-200 shadow-sm">
        <div class="bg-blue-50 px-3 py-1 flex items-center justify-between">
            <span class="text-sm font-semibold text-blue-800">📍 Arraste o pino até sua casa</span>
            <button type="button" onclick="mapPickerCloseMap('{{ $prefix }}')" aria-label="Fechar mapa"
                class="w-11 h-11 -mr-2 flex items-center justify-center text-blue-600 hover:text-blue-800 text-2xl leading-none">×</button>
        </div>
        <div id="map-{{ $prefix }}-el" style="height:220px; width:100%;"></div>
        <div class="flex gap-2 p-2 bg-gray-50 border-t border-gray-100">
            <button type="button" onclick="mapPickerCloseMap('{{ $prefix }}')" class="mc-btn-secondary flex-1">Cancelar</button>
            <button type="button" id="map-{{ $prefix }}-confirm-btn" x-on:click="mapPickerConfirmLocation('{{ $prefix }}', $wire)"
                class="mc-btn-primary flex-1 disabled:opacity-60">
                <span id="map-{{ $prefix }}-span-confirm">Confirmar local →</span>
                <span id="map-{{ $prefix }}-span-geocoding" style="display:none" class="flex items-center justify-center gap-1.5">
                    <svg class="animate-spin w-3 h-3" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                    </svg>
                    Salvando local...
                </span>
            </button>
        </div>
    </div>
</div>
