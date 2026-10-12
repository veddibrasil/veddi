<div class="w-full space-y-6">
    <x-admin.page-header
        :title="'Mesas e Comandas — ' . $branch->name"
    />

    <x-admin.branch-nav :branch="$branch" current="tables" />

    <x-admin.flash-status />


    <p class="text-sm text-neutral-500 dark:text-neutral-400">
        Cadastre as mesas desta filial uma única vez. No PDV, o atendente apenas seleciona o número da mesa
        para abrir a comanda — sem precisar digitar um nome a cada pedido.
    </p>

    @if ($canSave)
        <x-admin.form-card title="Gerar mesas">
            <div class="flex items-end gap-3">
                <div class="flex-1">
                    <flux:input wire:model="newCount" type="number" min="1" max="200"
                        label="Quantidade de mesas"
                        placeholder="Ex: 30" />
                    @error('newCount') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <flux:button wire:click="generate" variant="primary">Gerar mesas 1..N</flux:button>
            </div>
            <p class="text-xs text-neutral-500 dark:text-neutral-400 mt-2">
                Gera as mesas numeradas de 1 até a quantidade informada. Números já cadastrados não são duplicados.
            </p>
        </x-admin.form-card>
    @endif

    <x-admin.form-card title="Mesas cadastradas">
        @if ($this->tables->isEmpty())
            <p class="text-sm text-neutral-500 dark:text-neutral-400">Nenhuma mesa cadastrada ainda.</p>
        @else
            <div class="grid grid-cols-2 sm:grid-cols-4 md:grid-cols-6 gap-2">
                @foreach ($this->tables as $table)
                    <button
                        type="button"
                        @if ($canSave) wire:click="toggleActive({{ $table->id }})" @else disabled @endif
                        aria-pressed="{{ $table->active ? 'true' : 'false' }}"
                        class="px-3 py-2 rounded-lg text-sm font-semibold border transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-amber-500 {{ $table->active
                            ? 'bg-green-50 border-green-200 text-green-800 dark:bg-green-900/20 dark:border-green-700 dark:text-green-400'
                            : 'bg-neutral-100 border-neutral-200 text-neutral-600 dark:bg-zinc-700 dark:border-zinc-600 dark:text-neutral-400' }}"
                    >
                        Mesa {{ $table->number }}
                        @unless ($table->active)
                            <span class="block text-xs font-normal">inativa</span>
                        @endunless
                    </button>
                @endforeach
            </div>
            <p class="text-xs text-neutral-500 dark:text-neutral-400 mt-3">
                Clique numa mesa para ativar/desativar. Mesas desativadas somem da seleção no PDV.
            </p>
        @endif
    </x-admin.form-card>
</div>
