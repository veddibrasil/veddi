<div class="w-full space-y-6"
    x-data="{ feeType: @entangle('fee_type') }">

    <div class="flex items-center gap-3">
        <a href="{{ route('admin.branches.index') }}" class="text-neutral-400 hover:text-neutral-600 dark:text-neutral-500 dark:hover:text-neutral-300">←</a>
        <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100">
            Configurar Entrega — {{ $branch->name }}
        </h1>
    </div>

    @if (session('status'))
        <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg text-sm dark:bg-green-900/30 dark:border-green-700 dark:text-green-400">
            {{ session('status') }}
        </div>
    @endif

    {{-- Configurações Gerais --}}
    <div class="bg-white border rounded-xl shadow-sm p-6 space-y-4 dark:bg-zinc-800 dark:border-zinc-700">
        <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">Configurações gerais</h2>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">Tipo de cálculo de frete</label>
                <select wire:model.live="fee_type" x-model="feeType"
                    class="w-full border border-neutral-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-amber-500 dark:bg-zinc-700 dark:border-zinc-600 dark:text-neutral-100">
                    <option value="flat">Taxa fixa</option>
                    <option value="neighborhood">Por bairro</option>
                    <option value="distance">Por distância (km)</option>
                </select>
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
    </div>

    {{-- Taxa Fixa --}}
    <div x-show="feeType === 'flat'" x-cloak
        class="bg-white border rounded-xl shadow-sm p-6 space-y-4 dark:bg-zinc-800 dark:border-zinc-700">
        <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">Taxa fixa de entrega</h2>
        <div class="max-w-xs">
            <flux:input wire:model="flat_fee" type="number" step="0.01" min="0"
                label="Valor do frete (R$)" placeholder="Ex: 5,00" />
            @error('flat_fee') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
        </div>
    </div>

    {{-- Por Bairro --}}
    <div x-show="feeType === 'neighborhood'" x-cloak
        class="bg-white border rounded-xl shadow-sm p-6 space-y-4 dark:bg-zinc-800 dark:border-zinc-700">
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
                {{-- Cabeçalho --}}
                <div class="grid grid-cols-12 gap-2 text-xs font-medium text-neutral-400 uppercase px-1">
                    <span class="col-span-6">Bairro</span>
                    <span class="col-span-3">Taxa (R$)</span>
                    <span class="col-span-2">Ativo</span>
                    <span class="col-span-1"></span>
                </div>
                @foreach ($neighborhoods as $i => $n)
                    <div class="grid grid-cols-12 gap-2 items-center">
                        <div class="col-span-6">
                            <flux:input wire:model="neighborhoods.{{ $i }}.neighborhood"
                                placeholder="Ex: Centro" />
                            @error("neighborhoods.{$i}.neighborhood") <p class="text-red-500 text-xs mt-0.5">{{ $message }}</p> @enderror
                        </div>
                        <div class="col-span-3">
                            <flux:input wire:model="neighborhoods.{{ $i }}.fee"
                                type="number" step="0.01" min="0" placeholder="5,00" />
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
    </div>

    {{-- Por Distância --}}
    <div x-show="feeType === 'distance'" x-cloak
        class="bg-white border rounded-xl shadow-sm p-6 space-y-4 dark:bg-zinc-800 dark:border-zinc-700">
        <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">Frete por distância</h2>

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
        <p class="text-xs text-neutral-400 dark:text-neutral-500">
            Obtenha as coordenadas da filial no
            <a href="https://maps.google.com" target="_blank" rel="noopener" class="underline hover:text-amber-600">Google Maps</a>
            (clique com botão direito → "O que há aqui?").
        </p>

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
                            <flux:input wire:model="distanceTiers.{{ $i }}.min_km"
                                type="number" step="0.1" min="0" placeholder="0" />
                            @error("distanceTiers.{$i}.min_km") <p class="text-red-500 text-xs mt-0.5">{{ $message }}</p> @enderror
                        </div>
                        <div class="col-span-3">
                            <flux:input wire:model="distanceTiers.{{ $i }}.max_km"
                                type="number" step="0.1" min="0" placeholder="sem limite" />
                            @error("distanceTiers.{$i}.max_km") <p class="text-red-500 text-xs mt-0.5">{{ $message }}</p> @enderror
                        </div>
                        <div class="col-span-4">
                            <flux:input wire:model="distanceTiers.{{ $i }}.fee"
                                type="number" step="0.01" min="0" placeholder="5,00" />
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
    </div>

    <div class="flex gap-3 pb-8">
        <flux:button wire:click="save" class="!bg-amber-500 !text-white hover:!bg-amber-600"
            wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="save">Salvar configurações</span>
            <span wire:loading wire:target="save">Salvando...</span>
        </flux:button>
        <a href="{{ route('admin.branches.index') }}"
            class="inline-flex items-center px-4 py-2 text-sm text-neutral-600 hover:text-neutral-800 dark:text-neutral-400 dark:hover:text-neutral-200">
            Cancelar
        </a>
    </div>
</div>
