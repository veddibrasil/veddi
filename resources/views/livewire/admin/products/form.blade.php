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
                    <div x-data="{}"
                         x-init="$watch(() => $wire.showCategoryModal, val => val ? $flux.modal('create-category').show() : $flux.modal('create-category').close())">
                        <label class="block text-sm font-medium text-neutral-700 mb-1 dark:text-neutral-300">Categoria</label>
                        <div class="flex gap-2">
                            <select wire:model="product_category_id"
                                class="flex-1 border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-amber-400 dark:bg-zinc-700 dark:border-zinc-600 dark:text-neutral-200 dark:focus:ring-amber-500">
                                <option value="0">Selecione...</option>
                                @foreach ($categories as $cat)
                                    <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                                @endforeach
                            </select>
                            <button type="button" wire:click="openCategoryModal"
                                title="Nova categoria"
                                class="flex items-center justify-center w-9 h-9 rounded-lg border border-neutral-300 bg-white text-neutral-500 hover:bg-neutral-50 hover:text-neutral-700 dark:bg-zinc-700 dark:border-zinc-600 dark:text-neutral-300 dark:hover:bg-zinc-600 transition-colors">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                </svg>
                            </button>
                        </div>
                        @error('product_category_id') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror

                        <flux:modal name="create-category" class="w-full max-w-sm"
                            x-on:close="$wire.showCategoryModal = false">
                            <div class="space-y-4 p-1">
                                <div>
                                    <h3 class="text-base font-semibold text-neutral-800 dark:text-neutral-100">Nova categoria</h3>
                                    <p class="text-xs text-neutral-400 mt-0.5">A categoria será criada e selecionada automaticamente.</p>
                                </div>
                                <div>
                                    <flux:input wire:model="newCategoryName" label="Nome da categoria" placeholder="Ex: Salgados" autofocus />
                                    @error('newCategoryName') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                                </div>
                                <div class="flex justify-end gap-2 pt-1">
                                    <flux:button type="button" variant="ghost" x-on:click="$flux.modal('create-category').close()">
                                        Cancelar
                                    </flux:button>
                                    <flux:button type="button" wire:click="saveCategory" variant="primary">
                                        Criar categoria
                                    </flux:button>
                                </div>
                            </div>
                        </flux:modal>
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

            {{-- Grupos de Opções --}}
            <div class="bg-white border rounded-xl shadow-sm p-6 space-y-4 dark:bg-zinc-800 dark:border-zinc-700">
                <div class="flex items-center justify-between">
                    <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">Grupos de opções</h2>
                    <flux:button type="button" wire:click="addOptionGroup" size="sm" variant="ghost">
                        + Adicionar grupo
                    </flux:button>
                </div>

                @if (count($optionGroups) === 0)
                    <p class="text-sm text-neutral-400 dark:text-neutral-500">
                        Nenhum grupo de opções. Use quando o cliente precisar distribuir quantidades entre sabores, tamanhos etc. (ex: cento de salgados).
                    </p>
                @endif

                @foreach ($optionGroups as $gi => $group)
                    <div class="border rounded-lg p-4 space-y-3 dark:border-zinc-600" wire:key="group-{{ $gi }}">

                        {{-- Cabeçalho do grupo --}}
                        <div class="flex items-start gap-3">
                            <div class="flex-1 grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <flux:input wire:model="optionGroups.{{ $gi }}.name"
                                        label="Nome do grupo"
                                        placeholder="Ex: Sabores do Cento" />
                                    @error("optionGroups.{$gi}.name")
                                        <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                                    @enderror
                                </div>
                                <div>
                                    <flux:input wire:model="optionGroups.{{ $gi }}.total_qty"
                                        type="number" min="1"
                                        label="Quantidade total"
                                        placeholder="Ex: 100" />
                                    @error("optionGroups.{$gi}.total_qty")
                                        <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                                    @enderror
                                </div>
                                <div class="sm:col-span-2 flex items-center gap-2 pt-1">
                                    <flux:checkbox wire:model.live="optionGroups.{{ $gi }}.fixed"
                                        id="fixed-{{ $gi }}" />
                                    <label for="fixed-{{ $gi }}" class="text-sm text-neutral-600 dark:text-neutral-300 cursor-pointer select-none">
                                        Quantidades fixas
                                        <span class="text-xs text-neutral-400 dark:text-neutral-500 ml-1">(o cliente não pode alterar)</span>
                                    </label>
                                </div>
                            </div>
                            <button type="button" wire:click="removeOptionGroup({{ $gi }})"
                                title="Remover grupo"
                                class="mt-6 text-neutral-400 hover:text-red-500 dark:text-neutral-500 dark:hover:text-red-400 transition-colors">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        {{-- Opções do grupo --}}
                        <div class="space-y-2 pl-1">
                            <p class="text-xs font-medium text-neutral-500 uppercase tracking-wide dark:text-neutral-400">Opções</p>

                            @foreach ($group['options'] as $oi => $option)
                                <div class="flex items-start gap-2" wire:key="group-{{ $gi }}-option-{{ $oi }}">
                                    <div class="flex-1">
                                        <flux:input wire:model="optionGroups.{{ $gi }}.options.{{ $oi }}.name"
                                            placeholder="Ex: Coxinha de Frango" />
                                        @error("optionGroups.{$gi}.options.{$oi}.name")
                                            <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                                        @enderror
                                    </div>
                                    <div class="w-28">
                                        <flux:input wire:model="optionGroups.{{ $gi }}.options.{{ $oi }}.additional_price"
                                            type="number" step="0.01" min="0"
                                            placeholder="Acrésc." />
                                        @error("optionGroups.{$gi}.options.{$oi}.additional_price")
                                            <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                                        @enderror
                                    </div>
                                    @if (!empty($group['fixed']))
                                    <div class="w-20">
                                        <flux:input wire:model="optionGroups.{{ $gi }}.options.{{ $oi }}.default_qty"
                                            type="number" min="0"
                                            placeholder="Qtd." />
                                        @error("optionGroups.{$gi}.options.{$oi}.default_qty")
                                            <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                                        @enderror
                                    </div>
                                    @endif
                                    <button type="button" wire:click="removeOption({{ $gi }}, {{ $oi }})"
                                        title="Remover opção"
                                        class="mt-2 text-neutral-400 hover:text-red-500 dark:text-neutral-500 dark:hover:text-red-400 transition-colors">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                    </button>
                                </div>
                            @endforeach

                            <button type="button" wire:click="addOption({{ $gi }})"
                                class="text-xs text-amber-600 hover:text-amber-700 dark:text-amber-400 dark:hover:text-amber-300 font-medium">
                                + Adicionar opção
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Estoque inicial (apenas na criação) --}}
            @if (! $isEditing)
            <div class="bg-white border rounded-xl shadow-sm p-6 space-y-4 dark:bg-zinc-800 dark:border-zinc-700">
                <div class="flex items-center justify-between">
                    <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">Estoque</h2>
                    <flux:checkbox wire:model.live="trackStock" label="Controlar estoque" />
                </div>

                @if ($trackStock)
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <flux:input wire:model="initialQuantity" type="number" min="0" label="Quantidade inicial" placeholder="0" />
                        @error('initialQuantity') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <flux:input wire:model="minQuantity" type="number" min="0" label="Quantidade mínima (alerta)" placeholder="0" />
                        <p class="text-xs text-neutral-400 mt-1 dark:text-neutral-500">Alerta quando estoque ficar abaixo deste valor.</p>
                    </div>
                </div>
                @endif
            </div>
            @endif
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
