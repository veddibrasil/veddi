<div class="max-w-xl space-y-4">
    <h1 class="text-2xl font-bold text-neutral-800">Categorias</h1>

    @if (session('status'))
        <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg text-sm">
            {{ session('status') }}
        </div>
    @endif

    {{-- Form --}}
    <div class="bg-white border rounded-xl shadow-sm p-4 space-y-3">
        <p class="font-semibold text-sm text-neutral-700">
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
                <flux:button wire:click="cancelEdit" class="!bg-neutral-100 !text-neutral-700 text-sm">
                    Cancelar
                </flux:button>
            @endif
        </div>
    </div>

    {{-- List --}}
    <div class="bg-white border rounded-xl shadow-sm overflow-hidden">
        <div class="divide-y">
            @forelse ($categories as $cat)
                <div class="flex items-center justify-between px-4 py-3">
                    <div>
                        <p class="font-semibold text-sm text-neutral-800">{{ $cat->name }}</p>
                        <p class="text-xs text-neutral-400">Ordem: {{ $cat->sort_order }}</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <button wire:click="edit({{ $cat->id }})" class="text-xs text-amber-600 hover:underline">
                            Editar
                        </button>
                        @if ($deletingId === $cat->id)
                            <span class="text-xs text-neutral-500">Confirmar?</span>
                            <button wire:click="delete" class="text-xs text-red-600 font-semibold hover:underline">Sim</button>
                            <button wire:click="cancelDelete" class="text-xs text-neutral-500 hover:underline">Não</button>
                        @else
                            <button wire:click="confirmDelete({{ $cat->id }})"
                                class="text-xs text-red-400 hover:text-red-600">Excluir</button>
                        @endif
                    </div>
                </div>
            @empty
                <p class="px-4 py-6 text-sm text-neutral-500 text-center">Nenhuma categoria cadastrada.</p>
            @endforelse
        </div>
    </div>
</div>
