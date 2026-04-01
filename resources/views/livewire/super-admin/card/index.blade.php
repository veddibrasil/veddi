<div class="w-full space-y-6">

    <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100">
        Configurações das taxas
    </h1>

    {{-- Seleção de empresa --}}
  <div class="bg-white border rounded-xl shadow-sm p-4 dark:bg-zinc-800 dark:border-zinc-700">
        <div class="max-w-md">
            <label class="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">
                Selecionar empresa
            </label>

            <select
                wire:model.live="selectedCompanyId"
                wire:key="company-{{ $selectedCompanyId }}"
                class="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500"
            >
                @foreach($companies as $company)
                    <option value="{{ $company->id }}">
                        {{ $company->name }}
                    </option>
                @endforeach
            </select>

            <div wire:loading wire:target="selectedCompanyId" class="text-xs text-neutral-500 mt-2">
                Carregando dados da empresa...
            </div>
        </div>
    </div>

    {{-- Mensagem global --}}
    @if(session('status'))
        <div class="bg-green-50 border border-green-200 text-green-700 rounded-lg px-4 py-3 text-sm dark:bg-green-900/30 dark:border-green-700 dark:text-green-400">
            {{ session('status') }}
        </div>
    @endif

    {{-- Card principal --}}
    <div class="bg-white border rounded-xl shadow-sm p-6 space-y-4 dark:bg-zinc-800 dark:border-zinc-700">

        {{-- ── Taxas de Cartão de Crédito ── --}}
        <div class="border-t border-neutral-200 dark:border-zinc-700 pt-8 space-y-6 pb-8">

            <div>
                <h2 class="text-base font-semibold text-neutral-800 dark:text-neutral-100">
                    Taxas de Cartão de Crédito
                </h2>
                <p class="text-sm text-neutral-500 dark:text-neutral-400 mt-1">
                    Configure as taxas cobradas ao cliente para cobrir o custo do cartão.
                    O sistema calcula automaticamente:
                    <strong>valor_cobrado = valor_pedido / (1 - taxa_total)</strong>
                </p>
            </div>


            {{-- Taxa cartão --}}
            <div>
                <h3 class="text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-3">
                    Taxa do cartão (%)
                </h3>

                <div class="max-w-xs">
                    <label class="block text-xs text-neutral-500 mb-1">
                        Taxa à vista (ex: 0.0299 = 2,99%)
                    </label>

                    <flux:input
                        wire:model="cardRate1x"
                        type="number" step="0.0001" min="0" max="1"/>

                    @error('cardRate1x')
                        <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            {{-- Antecipações --}}
            <div>
                <h3 class="text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-3">
                    Taxas de antecipação (%)
                </h3>

                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">

                    <div>
                        <label class="block text-xs text-neutral-500 mb-1">D+2</label>
                        <flux:input wire:model="anticipationD2" type="number" step="0.0001" min="0" max="1"/>
                        @error('anticipationD2')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label class="block text-xs text-neutral-500 mb-1">D+7</label>
                        <flux:input wire:model="anticipationD7" type="number" step="0.0001" min="0" max="1"
                             />
                        @error('anticipationD7')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label class="block text-xs text-neutral-500 mb-1">D+15</label>
                        <flux:input wire:model="anticipationD15" type="number" step="0.0001" min="0" max="1"
                             />
                        @error('anticipationD15')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label class="block text-xs text-neutral-500 mb-1">D+30 (sem antecipação)</label>
                        <flux:input wire:model="anticipationD30" type="number" step="0.0001" min="0" max="1"
                             />
                        @error('anticipationD30')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
                    </div>

                </div>
            </div>

            {{-- Configurações gerais --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

                <div>
                    <label class="block text-xs text-neutral-500 mb-1">
                        Margem adicional do sistema (%)
                    </label>

                    <flux:input wire:model="systemFeeRate" type="number" step="0.0001" min="0" max="1"
                         />

                    <p class="text-xs text-neutral-400 mt-1">
                        0 = sem margem adicional
                    </p>

                    @error('systemFeeRate')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="block text-xs text-neutral-500 mb-1">
                        Prazo padrão de recebimento (dias)
                    </label>

                    <select wire:model="defaultAnticipationDays"
                    class="w-full rounded-md border border-neutral-300 dark:border-zinc-600 bg-white dark:bg-zinc-700 text-neutral-800 dark:text-neutral-100 px-3 py-2 text-sm"
                        >

                        <option value="2">D+2</option>
                        <option value="7">D+7</option>
                        <option value="15">D+15</option>
                        <option value="30">D+30</option>

                    </select>

                    @error('defaultAnticipationDays')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
                </div>

            </div>

            {{-- Botão --}}
            <div class="flex gap-3">
                <flux:button
                    wire:click="saveCardSettings"
                    class="bg-amber-500! text-white! hover:bg-amber-600!"
                    wire:loading.attr="disabled"
                >
                    <span wire:loading.remove wire:target="saveCardSettings">
                        Salvar taxas de cartão
                    </span>
                    <span wire:loading wire:target="saveCardSettings">
                        Salvando...
                    </span>
                </flux:button>
            </div>

        </div>
    </div>

</div>