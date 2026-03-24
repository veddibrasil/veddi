<div class="space-y-4"
    x-data="{}"
    x-init="$watch(() => $wire.deletingId, val => val ? $flux.modal('confirm-delete-category').show() : $flux.modal('confirm-delete-category').close())">
    <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100">Categorias</h1>

    @if (session('status'))
        <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg text-sm dark:bg-green-900/30 dark:border-green-700 dark:text-green-400">
            {{ session('status') }}
        </div>
    @endif

    {{-- Form --}}
    @if($editingId ? $canUpdate : $canCreate)
    <div class="bg-white border rounded-xl shadow-sm p-4 space-y-3 dark:bg-zinc-800 dark:border-zinc-700">
        <p class="font-semibold text-sm text-neutral-700 dark:text-neutral-300">
            {{ $editingId ? 'Editar categoria' : 'Nova categoria' }}
        </p>
        @if($isSuperAdmin)
        <div>
            <flux:select wire:model="company_id" label="Empresa" placeholder="Selecione uma empresa...">
                @foreach($companies as $company)
                    <flux:select.option value="{{ $company->id }}">{{ $company->name }}</flux:select.option>
                @endforeach
            </flux:select>
            @error('company_id') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
        </div>
        @endif
        <div class="flex gap-2 items-end">
            <div class="flex-1">
                <label class="block text-xs font-medium text-neutral-500 mb-1 dark:text-neutral-400">Nome</label>
                <flux:input wire:model="name" placeholder="Ex: Coxinhas" />
                @error('name') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="w-24">
                <label class="block text-xs font-medium text-neutral-500 mb-1 dark:text-neutral-400">Ordem</label>
                <flux:input wire:model="sort_order" type="number" placeholder="0" min="0" />
            </div>
        </div>
        <div class="flex gap-2">
            <flux:button wire:click="save" class="!bg-amber-500 !text-white hover:!bg-amber-600 text-sm"
                wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="save">{{ $editingId ? 'Atualizar' : 'Adicionar' }}</span>
                <span wire:loading wire:target="save">Salvando...</span>
            </flux:button>
            @if ($editingId)
                <flux:button wire:click="cancelEdit" class="!bg-neutral-100 !text-neutral-700 text-sm dark:!bg-zinc-700 dark:!text-neutral-300">
                    Cancelar
                </flux:button>
            @endif
        </div>
    </div>
    @endif

    {{-- List --}}
    <div class="bg-white border rounded-xl shadow-sm overflow-hidden dark:bg-zinc-800 dark:border-zinc-700">
        {{-- Cabeçalho de colunas --}}
        <div class="flex items-center justify-between px-4 py-2 border-b bg-neutral-50 dark:bg-zinc-700/50 dark:border-zinc-700">
            <span class="text-xs font-medium text-neutral-400 uppercase tracking-wide">Categoria</span>
            <span class="text-xs font-medium text-neutral-400 uppercase tracking-wide">Ações</span>
        </div>
        <div class="divide-y dark:divide-zinc-700">
            @forelse ($categories as $cat)
                <div class="flex items-center justify-between px-4 py-3">
                    <div>
                        <p class="font-semibold text-sm text-neutral-800 dark:text-neutral-100">{{ $cat->name }}</p>
                        @if($isSuperAdmin && $cat->company)
                            <p class="text-xs font-medium text-amber-600 dark:text-amber-400">{{ $cat->company->name }}</p>
                        @endif
                        <p class="text-xs text-neutral-400 dark:text-neutral-500">Ordem: {{ $cat->sort_order }}</p>
                    </div>
                    <div class="flex items-center gap-1">
                        {{-- Editar --}}
                        @if($canUpdate)
                        <div class="relative group">
                            <button wire:click="edit({{ $cat->id }})"
                                class="inline-flex items-center justify-center p-1.5 rounded text-amber-600 hover:bg-amber-50 dark:text-amber-400 dark:hover:bg-amber-900/20 transition-colors">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                </svg>
                            </button>
                            <span class="pointer-events-none absolute bottom-full left-1/2 -translate-x-1/2 mb-1.5 px-2 py-1 rounded bg-neutral-800 text-white text-xs whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity z-10 dark:bg-zinc-600">Editar</span>
                        </div>
                        @endif

                        {{-- Excluir --}}
                        @if($canDelete)
                        <div class="relative group">
                            <button wire:click="confirmDelete({{ $cat->id }})"
                                class="inline-flex items-center justify-center p-1.5 rounded text-neutral-400 hover:text-red-600 hover:bg-red-50 dark:hover:text-red-400 dark:hover:bg-red-900/20 transition-colors">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                </svg>
                            </button>
                            <span class="pointer-events-none absolute bottom-full left-1/2 -translate-x-1/2 mb-1.5 px-2 py-1 rounded bg-neutral-800 text-white text-xs whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity z-10 dark:bg-zinc-600">Excluir</span>
                        </div>
                        @endif
                    </div>
                </div>
            @empty
                <div class="px-4 py-10 text-center">
                    <div class="text-3xl mb-2">🏷️</div>
                    <p class="text-sm text-neutral-500 dark:text-neutral-400">Nenhuma categoria cadastrada.</p>
                </div>
            @endforelse
        </div>
    </div>

    {{-- Paginação --}}
    @if ($categories->hasPages())
        <div class="mt-2">
            {{ $categories->links() }}
        </div>
    @endif

    {{-- Modal de confirmação de exclusão --}}
    <flux:modal name="confirm-delete-category" class="max-w-sm">
        <div class="space-y-5">
            <div class="flex items-start gap-4">
                <div class="w-10 h-10 rounded-full bg-red-100 dark:bg-red-900/30 flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
                    </svg>
                </div>
                <div>
                    <flux:heading size="lg">Excluir categoria?</flux:heading>
                    <flux:subheading class="mt-1">Esta ação não pode ser desfeita. <br> A categoria será removida permanentemente.</flux:subheading>
                </div>
            </div>
            <div class="flex justify-end gap-3 pt-1">
                <flux:modal.close>
                    <flux:button wire:click="cancelDelete" variant="ghost">Cancelar</flux:button>
                </flux:modal.close>
                <flux:modal.close>
                    <flux:button wire:click="delete" variant="danger">Excluir</flux:button>
                </flux:modal.close>
            </div>
        </div>
    </flux:modal>
</div>
