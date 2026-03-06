<div class="max-w-lg space-y-6">
    <div class="flex items-center gap-3">
        <a href="{{ route('admin.products.index') }}" class="text-neutral-400 hover:text-neutral-600 dark:text-neutral-500 dark:hover:text-neutral-300">←</a>
        <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100">
            {{ $isEditing ? 'Editar Produto' : 'Novo Produto' }}
        </h1>
    </div>

    <div class="bg-white border rounded-xl shadow-sm p-6 space-y-4 dark:bg-zinc-800 dark:border-zinc-700">
        <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1 dark:text-neutral-300">Categoria</label>
            <select wire:model="product_category_id"
                class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-amber-400 dark:bg-zinc-700 dark:border-zinc-600 dark:text-neutral-200 dark:focus:ring-amber-500">
                <option value="0">Selecione...</option>
                @foreach ($categories as $cat)
                    <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                @endforeach
            </select>
            @error('product_category_id') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
        </div>

        <flux:input wire:model="name" label="Nome do produto" placeholder="Ex: Coxinha de Frango" />
        @error('name') <p class="text-red-500 text-xs -mt-2">{{ $message }}</p> @enderror

        <flux:textarea wire:model="description" label="Descrição (opcional)" placeholder="Breve descrição..." rows="2" />
        @error('description') <p class="text-red-500 text-xs -mt-2">{{ $message }}</p> @enderror

        <flux:input wire:model="price" type="number" step="0.01" min="0.01" label="Preço (R$)" placeholder="0,00" />
        @error('price') <p class="text-red-500 text-xs -mt-2">{{ $message }}</p> @enderror

        <flux:input wire:model="sort_order" type="number" min="0" label="Ordem de exibição" />

        <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1 dark:text-neutral-300">Imagem (opcional)</label>
            <input wire:model="image" type="file" accept="image/*"
                class="block w-full text-sm text-neutral-500 dark:text-neutral-400 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:bg-amber-50 file:text-amber-700 hover:file:bg-amber-100 dark:file:bg-zinc-700 dark:file:text-amber-400" />
            @error('image') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            @if ($isEditing && $product->image_path && !$image)
                <img src="{{ asset('storage/'.$product->image_path) }}" class="mt-2 h-20 w-20 object-cover rounded-lg" />
            @endif
            @if ($image)
                <img src="{{ $image->temporaryUrl() }}" class="mt-2 h-20 w-20 object-cover rounded-lg" />
            @endif
        </div>

        <div>
            <label class="block text-sm font-medium text-neutral-700 mb-2 dark:text-neutral-300">Disponível nas filiais</label>
            <div class="space-y-1">
                @foreach ($branches as $branch)
                    <label class="flex items-center gap-2 text-sm cursor-pointer dark:text-neutral-200">
                        <input type="checkbox" wire:model="selectedBranches" value="{{ $branch->id }}"
                            class="rounded border-neutral-300 text-amber-500 focus:ring-amber-400 dark:border-zinc-600 dark:bg-zinc-700" />
                        {{ $branch->name }}
                    </label>
                @endforeach
            </div>
            @error('selectedBranches') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
        </div>

        <flux:checkbox wire:model="active" label="Produto ativo" />

        <div class="flex gap-3 pt-2">
            <flux:button wire:click="save" class="!bg-amber-500 !text-white hover:!bg-amber-600"
                wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="save">
                    {{ $isEditing ? 'Salvar alterações' : 'Criar produto' }}
                </span>
                <span wire:loading wire:target="save">Salvando...</span>
            </flux:button>
            <a href="{{ route('admin.products.index') }}"
                class="inline-flex items-center px-4 py-2 text-sm text-neutral-600 hover:text-neutral-800 dark:text-neutral-400 dark:hover:text-neutral-200">
                Cancelar
            </a>
        </div>
    </div>
</div>
