<div>
    <label class="block text-xs font-semibold text-gray-600 mb-1">
        CEP
        <span x-show="cepLoading" class="text-blue-500 font-normal normal-case ml-1">Buscando...</span>
    </label>
    <input
        wire:model="cep"
        type="text"
        placeholder="00000-000"
        class="mc-input"
        autocomplete="postal-code"
        inputmode="numeric"
        maxlength="9"
        x-on:input="
            $event.target.value = formatCep($event.target.value);
            if ($event.target.value.replace(/\D/g,'').length === 8) lookupCep($event.target.value, $wire);
        "
    />
    @error('cep') <p class="text-red-600 text-xs mt-0.5"><span>⚠</span> {{ $message }}</p> @enderror
</div>

<div class="flex gap-2">
    <div class="flex-1">
        <label class="block text-xs font-semibold text-gray-600 mb-1">Rua</label>
        <input wire:model="address" type="text" placeholder="Ex: Av. Brasil, 100" class="mc-input w-full" autocomplete="street-address" />
        @error('address')
            <p class="text-red-600 text-xs mt-0.5 flex items-center gap-1">
                <span>⚠</span> {{ $message }}
            </p>
        @enderror
    </div>
    <div class="w-24">
        <label class="block text-xs font-semibold text-gray-600 mb-1">Número</label>
        <input wire:model="number" type="text" placeholder="123" class="mc-input w-full" autocomplete="number-address" />
        @error('number')
            <p class="text-red-600 text-xs mt-0.5">
                <span>⚠</span> {{ $message }}
            </p>
        @enderror
    </div>
</div>

<div>
    <label class="block text-xs font-semibold text-gray-600 mb-1">Complemento <span class="text-gray-400 font-normal">(opcional)</span></label>
    <input wire:model="complement" type="text" placeholder="Apto 12, Bloco B..." class="mc-input" autocomplete="address-line2" />
    @error('complement') <p class="text-red-600 text-xs mt-0.5"><span>⚠</span> {{ $message }}</p> @enderror
</div>

<div class="grid grid-cols-2 gap-2">
    <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1">Bairro</label>
        <input wire:model="neighborhood" type="text" placeholder="Zona 1" class="mc-input" autocomplete="address-level3" />
        @error('neighborhood') <p class="text-red-600 text-xs mt-0.5"><span>⚠</span> {{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1">Cidade</label>
        <input wire:model="city" type="text" placeholder="Maringá" class="mc-input" autocomplete="address-level2" />
        @error('city') <p class="text-red-600 text-xs mt-0.5"><span>⚠</span> {{ $message }}</p> @enderror
    </div>
</div>
