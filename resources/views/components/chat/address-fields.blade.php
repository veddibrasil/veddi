<div>
    <label for="addr-cep" class="block text-sm font-semibold text-gray-700 mb-1">
        CEP
        <span x-show="cepLoading" class="text-blue-600 font-normal normal-case ml-1">Buscando endereço...</span>
    </label>
    <input
        id="addr-cep"
        wire:model="cep"
        type="text"
        placeholder="00000-000"
        class="mc-input"
        autocomplete="postal-code"
        inputmode="numeric"
        maxlength="9"
        aria-describedby="addr-cep-hint"
        x-on:input="
            $event.target.value = formatCep($event.target.value);
            if ($event.target.value.replace(/\D/g,'').length === 8) lookupCep($event.target.value, $wire);
        "
    />
    <p id="addr-cep-hint" class="text-xs text-gray-500 mt-1">Digite o CEP e preenchemos a rua, o bairro e a cidade.</p>
    @error('cep') <p role="alert" class="text-red-600 text-sm mt-0.5"><span aria-hidden="true">⚠</span> {{ $message }}</p> @enderror
</div>

<div class="flex gap-2">
    <div class="flex-1">
        <label for="addr-street" class="block text-sm font-semibold text-gray-700 mb-1">Rua</label>
        <input id="addr-street" wire:model="address" type="text" placeholder="Ex: Avenida Brasil" class="mc-input w-full" autocomplete="address-line1" />
        @error('address')
            <p role="alert" class="text-red-600 text-sm mt-0.5 flex items-center gap-1">
                <span aria-hidden="true">⚠</span> {{ $message }}
            </p>
        @enderror
    </div>
    <div class="w-24">
        <label for="addr-number" class="block text-sm font-semibold text-gray-700 mb-1">Número</label>
        <input id="addr-number" wire:model="number" type="text" inputmode="numeric" placeholder="Ex: 100" class="mc-input w-full" autocomplete="off" />
        @error('number')
            <p role="alert" class="text-red-600 text-sm mt-0.5">
                <span aria-hidden="true">⚠</span> {{ $message }}
            </p>
        @enderror
    </div>
</div>

<div>
    <label for="addr-complement" class="block text-sm font-semibold text-gray-700 mb-1">Complemento <span class="text-gray-500 font-normal">(opcional)</span></label>
    <input id="addr-complement" wire:model="complement" type="text" placeholder="Ex: Apto 12, Bloco B" class="mc-input" autocomplete="address-line2" />
    @error('complement') <p role="alert" class="text-red-600 text-sm mt-0.5"><span aria-hidden="true">⚠</span> {{ $message }}</p> @enderror
</div>

<div class="grid grid-cols-2 gap-2">
    <div>
        <label for="addr-neighborhood" class="block text-sm font-semibold text-gray-700 mb-1">Bairro</label>
        <input id="addr-neighborhood" wire:model="neighborhood" type="text" placeholder="Ex: Centro" class="mc-input" autocomplete="address-level3" />
        @error('neighborhood') <p role="alert" class="text-red-600 text-sm mt-0.5"><span aria-hidden="true">⚠</span> {{ $message }}</p> @enderror
    </div>
    <div>
        <label for="addr-city" class="block text-sm font-semibold text-gray-700 mb-1">Cidade</label>
        <input id="addr-city" wire:model="city" type="text" placeholder="Sua cidade" class="mc-input" autocomplete="address-level2" />
        @error('city') <p role="alert" class="text-red-600 text-sm mt-0.5"><span aria-hidden="true">⚠</span> {{ $message }}</p> @enderror
    </div>
</div>
