<div class="max-w-xl space-y-4">
    <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100">Categorias</h1>

    @if (session('status'))
        <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg text-sm dark:bg-green-900/30 dark:border-green-700 dark:text-green-400">
            {{ session('status') }}
        </div>
    @endif

    {{-- Form --}}
    <div class="bg-white border rounded-xl shadow-sm p-4 space-y-3 dark:bg-zinc-800 dark:border-zinc-700">
        <p class="font-semibold text-sm text-neutral-700 dark:text-neutral-300">
            {{ $editingId ? 'Editar categoria' : 'Nova categoria' }}
        </p>
        <div class="flex gap-2">
            <div class="flex-1">
                <flux:input wire:model="name" placeholder="Ex: Coxinhas" />
                @error('name') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="w-24">
                <flux:input wire:model="sort_order" type="number" placeholder="Ordem" min="0" />
            </div>
        </div>
        <div class="flex gap-2">
            <flux:button wire:click="save" class="!bg-amber-500 !text-white hover:!bg-amber-600 text-sm">
                {{ $editingId ? 'Atualizar' : 'Adicionar' }}
            </flux:button>
            @if ($editingId)
                <flux:button wire:click="cancelEdit" class="!bg-neutral-100 !text-neutral-700 text-sm dark:!bg-zinc-700 dark:!text-neutral-300">
                    Cancelar
                </flux:button>
            @endif
        </div>
    </div>

    {{-- List --}}
    <div class="bg-white border rounded-xl shadow-sm overflow-hidden dark:bg-zinc-800 dark:border-zinc-700">
        <div class="divide-y dark:divide-zinc-700">
            @forelse ($categories as $cat)
                <div class="flex items-center justify-between px-4 py-3">
                    <div>
                        <p class="font-semibold text-sm text-neutral-800 dark:text-neutral-100">{{ $cat->name }}</p>
                        <p class="text-xs text-neutral-400 dark:text-neutral-500">Ordem: {{ $cat->sort_order }}</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <button wire:click="edit({{ $cat->id }})" class="text-xs text-amber-600 hover:underline dark:text-amber-400">
                            Editar
                        </button>
                        @if ($deletingId === $cat->id)
                            <span class="text-xs text-neutral-500 dark:text-neutral-400">Confirmar?</span>
                            <button wire:click="delete" class="text-xs text-red-600 font-semibold hover:underline dark:text-red-400">Sim</button>
                            <button wire:click="cancelDelete" class="text-xs text-neutral-500 hover:underline dark:text-neutral-400">Não</button>
                        @else
                            <button wire:click="confirmDelete({{ $cat->id }})"
                                class="text-xs text-red-400 hover:text-red-600 dark:hover:text-red-300">Excluir</button>
                        @endif
                    </div>
                </div>
            @empty
                <p class="px-4 py-6 text-sm text-neutral-500 dark:text-neutral-400 text-center">Nenhuma categoria cadastrada.</p>
            @endforelse
        </div>
    </div>
</div>
