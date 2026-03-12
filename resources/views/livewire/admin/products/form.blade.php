<div class="w-full space-y-6">
    <div class="flex items-center gap-3">
        <a href="{{ route('admin.products.index') }}" class="text-neutral-400 hover:text-neutral-600 dark:text-neutral-500 dark:hover:text-neutral-300">←</a>
        <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100">
            {{ $isEditing ? 'Editar Produto' : 'Novo Produto' }}
        </h1>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">

        {{-- Coluna principal --}}
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white border rounded-xl shadow-sm p-6 space-y-4 dark:bg-zinc-800 dark:border-zinc-700">
                <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">Informações do produto</h2>

                @if($isSuperAdmin)
                <div>
                    <flux:select wire:model.live="company_id" label="Empresa" placeholder="Selecione uma empresa...">
                        @foreach($companies as $company)
                            <flux:select.option value="{{ $company->id }}">{{ $company->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    @error('company_id') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                @endif

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <flux:input wire:model="name" label="Nome do produto" placeholder="Ex: Coxinha de Frango" />
                        @error('name') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    
                      <div>
                        <flux:input wire:model="price" type="number" step="0.01" min="0.01" label="Preço (R$)" placeholder="0,00" />
                        @error('price') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
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
                    <div>
                        <flux:input wire:model="sort_order" type="number" min="0" label="Ordem de exibição" />
                        <p class="text-xs text-neutral-400 mt-1 dark:text-neutral-500">Menor número aparece primeiro no cardápio.</p>
                    </div>
                </div>
                <div>
                    <flux:textarea wire:model="description" label="Descrição (opcional)" placeholder="Breve descrição..." rows="3" />
                    @error('description') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>

                 <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 items-end">
                  
                    <div class="pb-1">
                        <flux:checkbox wire:model="active" label="Produto ativo" />
                    </div>
                </div>
            </div>

            {{-- Filiais --}}
            <div class="bg-white border rounded-xl shadow-sm p-6 space-y-3 dark:bg-zinc-800 dark:border-zinc-700">
                <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">Disponível nas filiais</h2>
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                    @foreach ($branches as $branch)
                        <label class="flex items-center gap-2 text-sm cursor-pointer dark:text-neutral-200 p-2 rounded-lg hover:bg-neutral-50 dark:hover:bg-zinc-700">
                            <input type="checkbox" wire:model="selectedBranches" value="{{ $branch->id }}"
                                class="rounded border-neutral-300 text-amber-500 focus:ring-amber-400 dark:border-zinc-600 dark:bg-zinc-700" />
                            {{ $branch->name }}
                        </label>
                    @endforeach
                </div>
                @error('selectedBranches') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
        </div>

        {{-- Coluna lateral - Imagem --}}
        <div class="bg-white border rounded-xl shadow-sm p-6 space-y-3 dark:bg-zinc-800 dark:border-zinc-700">
            <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">Imagem</h2>
            <x-image-upload
                model="image"
                :current-url="$isEditing && $product->image_path ? $product->image_url : null"
                label=""
            />
        </div>
    </div>

    <div class="flex gap-3 pb-8">
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
