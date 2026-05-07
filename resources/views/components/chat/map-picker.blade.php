@props(['prefix', 'label' => 'Usar minha localização'])

<button type="button" id="map-{{ $prefix }}-loc-btn" onclick="mapPickerUseLocation('{{ $prefix }}')"
    class="w-full flex items-center justify-center gap-2 border border-blue-200 bg-blue-50 hover:bg-blue-100 active:bg-blue-200 text-blue-700 text-xs font-semibold rounded-xl py-2.5 transition-colors disabled:opacity-60">
    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
        <path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
    </svg>
    <span id="map-{{ $prefix }}-span-default">{{ $label }}</span>
    <span id="map-{{ $prefix }}-span-loading" style="display:none" class="flex items-center gap-1.5">
        <svg class="animate-spin w-3.5 h-3.5" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
        </svg>
        Obtendo localização...
    </span>
</button>
<p id="map-{{ $prefix }}-error" style="display:none" class="text-red-500 text-xs -mt-1 flex items-center gap-1"></p>

<div id="map-{{ $prefix }}-container" style="display:none" class="rounded-xl overflow-hidden border border-blue-200 shadow-sm">
    <div class="bg-blue-50 px-3 py-1.5 flex items-center justify-between">
        <span class="text-xs font-semibold text-blue-700">📍 Arraste o pin para ajustar</span>
        <button type="button" onclick="mapPickerCloseMap('{{ $prefix }}')" class="text-blue-400 hover:text-blue-700 text-lg leading-none">×</button>
    </div>
    <div id="map-{{ $prefix }}-el" style="height:200px; width:100%;"></div>
    <div class="flex gap-2 p-2 bg-gray-50 border-t border-gray-100">
        <button type="button" onclick="mapPickerCloseMap('{{ $prefix }}')" class="mc-btn-secondary flex-1 !py-1.5 text-xs">Cancelar</button>
        <button type="button" id="map-{{ $prefix }}-confirm-btn" x-on:click="mapPickerConfirmLocation('{{ $prefix }}', $wire)"
            class="mc-btn-primary flex-1 !py-1.5 text-xs disabled:opacity-60">
            <span id="map-{{ $prefix }}-span-confirm">Confirmar local →</span>
            <span id="map-{{ $prefix }}-span-geocoding" style="display:none" class="flex items-center justify-center gap-1.5">
                <svg class="animate-spin w-3 h-3" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                </svg>
                Buscando endereço...
            </span>
        </button>
    </div>
</div>
