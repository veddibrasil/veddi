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

                    @if (!$isVariant)
                    <div>
                        <flux:input wire:model="price" type="number" step="0.01" min="0.01" label="Preço base (R$)" placeholder="0,00" />
                        @error('price') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    @endif
                </div>

                {{-- Modo variante --}}
                <div class="flex items-start gap-2">
                    <flux:checkbox wire:model.live="isVariant" id="is-variant" class="mt-0.5" />
                    <div>
                        <label for="is-variant" class="text-sm text-neutral-700 dark:text-neutral-300 cursor-pointer select-none font-medium">
                            Preço definido pelas variações
                        </label>
                        <p class="text-xs text-neutral-400 dark:text-neutral-500 mt-0.5">
                            Ative quando o produto não tem preço único — cada opção define o valor (ex: tamanhos P / M / G com preços distintos).
                        </p>
                    </div>
                </div>

                @if ($isVariant)
                <div class="rounded-lg border border-blue-200 bg-blue-50 dark:border-blue-800 dark:bg-blue-950/20 px-4 py-3 text-sm text-blue-700 dark:text-blue-300 space-y-1">
                    <p class="font-medium">Como funciona o modo variações</p>
                    <ul class="list-disc list-inside space-y-0.5 text-xs text-blue-600 dark:text-blue-400">
                        <li>O produto não tem preço base — as opções definem o valor final.</li>
                        <li>Adicione um grupo de opções com as variações (ex: Pequeno, Médio, Grande).</li>
                        <li>Informe o <strong>Preço (R$)</strong> de cada opção — esse será o preço cobrado.</li>
                        <li>Outros grupos adicionais (ex: recheios) continuam funcionando como acréscimos normais.</li>
                    </ul>
                </div>
                @endif

                @if (!$isVariant)
                <div class="space-y-3">
                    <div class="flex items-center gap-2">
                        <flux:checkbox wire:model.live="promo_price_enabled" id="promo-price-enabled" />
                        <label for="promo-price-enabled" class="text-sm text-neutral-700 dark:text-neutral-300 cursor-pointer select-none">
                            Preço promocional
                            <span class="text-xs text-neutral-400 dark:text-neutral-500 ml-1">(por valor ou %)</span>
                        </label>
                    </div>

                    @if($promo_price_enabled)
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <flux:select wire:model.live="promo_price_type" label="Tipo">
                                    <flux:select.option value="fixed">Valor fixo (R$)</flux:select.option>
                                    <flux:select.option value="percentage">Porcentagem (%)</flux:select.option>
                                </flux:select>
                                @error('promo_price_type') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <flux:input
                                    wire:model="promo_price_value"
                                    type="number"
                                    step="0.01"
                                    min="0.01"
                                    label="{{ $promo_price_type === 'percentage' ? 'Desconto (%)' : 'Preço promocional (R$)' }}"
                                    placeholder="{{ $promo_price_type === 'percentage' ? 'Ex: 20' : '0,00' }}"
                                />
                                @error('promo_price_value') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    @endif
                </div>
                @endif
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
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">Disponível nas filiais</h2>
                        <p class="text-xs text-neutral-500 dark:text-neutral-400 mt-1">
                            Define em quais filiais este produto aparece no cardápio.
                            A disponibilidade individual pode ser ajustada depois em <strong>Estoque</strong>.
                        </p>
                    </div>
                    <div class="flex gap-2 shrink-0 pt-0.5">
                        <button type="button" wire:click="selectAllBranches"
                            class="text-xs text-amber-600 hover:underline dark:text-amber-400">
                            Selecionar todas
                        </button>
                        <span class="text-neutral-300 dark:text-neutral-600">|</span>
                        <button type="button" wire:click="deselectAllBranches"
                            class="text-xs text-neutral-500 hover:underline dark:text-neutral-400">
                            Remover todas
                        </button>
                    </div>
                </div>
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
                <div class="flex items-center justify-between gap-2 flex-wrap">
                    <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">Grupos de opções</h2>
                    <div class="flex items-center gap-2">
                        @if ($availableGroups->isNotEmpty())
                            <flux:button type="button" wire:click="$set('showGroupPicker', true)" size="sm" variant="ghost">
                                Usar existente
                            </flux:button>
                        @endif
                        <flux:button type="button" wire:click="addOptionGroup" size="sm" variant="ghost">
                            + Novo grupo
                        </flux:button>
                    </div>
                </div>

                {{-- Picker de grupos existentes --}}
                @if ($showGroupPicker)
                    <div class="border border-amber-200 rounded-lg p-3 bg-amber-50 dark:bg-amber-950/20 dark:border-amber-800 space-y-2">
                        <div class="flex items-center justify-between">
                            <p class="text-xs font-medium text-amber-700 dark:text-amber-400">
                                Selecione um grupo para adicionar a este produto. Alterações no grupo afetarão todos os produtos que o utilizam.
                            </p>
                            <button type="button" wire:click="$set('showGroupPicker', false)"
                                class="text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-300 ml-2 shrink-0">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                            @foreach ($availableGroups as $ag)
                                <button type="button" wire:click="attachExistingGroup({{ $ag->id }})"
                                    class="text-left px-3 py-2 rounded-lg border border-neutral-200 bg-white hover:border-amber-400 hover:bg-amber-50 dark:bg-zinc-700 dark:border-zinc-600 dark:hover:border-amber-500 transition-colors">
                                    <p class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ $ag->name }}</p>
                                    <p class="text-xs text-neutral-400 dark:text-neutral-500">
                                        {{ $ag->options_count ?? $ag->options->count() }} opções · qtd {{ $ag->total_qty }}
                                        @if($ag->fixed) · fixo @endif
                                    </p>
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if (count($optionGroups) === 0)
                    <p class="text-sm text-neutral-400 dark:text-neutral-500">
                        @if ($isVariant)
                            Adicione um grupo com as variações do produto (ex: Pequeno, Médio, Grande) e informe o preço de cada uma.
                        @else
                            Nenhum grupo de opções. Use quando o cliente precisar distribuir quantidades entre sabores, tamanhos etc. (ex: cento de salgados).
                        @endif
                    </p>
                @endif

                @foreach ($optionGroups as $gi => $group)
                    <div class="border rounded-lg p-4 space-y-3 dark:border-zinc-600" wire:key="group-{{ $gi }}">

                        {{-- Cabeçalho do grupo --}}
                        <div class="flex items-start gap-3">
                            <div class="flex-1 grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <div class="flex items-center gap-2 mb-1">
                                        <span class="text-sm font-medium text-neutral-700 dark:text-neutral-300">Nome do grupo</span>
                                        @if (!empty($group['group_id']))
                                            <span class="inline-flex items-center gap-1 text-[10px] font-semibold px-1.5 py-0.5 rounded-full bg-blue-100 text-blue-600 dark:bg-blue-900/40 dark:text-blue-400">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-2.5 w-2.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244" />
                                                </svg>
                                                Compartilhado
                                            </span>
                                        @endif
                                    </div>
                                    <flux:input wire:model="optionGroups.{{ $gi }}.name"
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
                            <div class="mt-6 flex items-center gap-1">
                                <button type="button" wire:click="moveOptionGroupUp({{ $gi }})"
                                    title="Subir grupo"
                                    @if ($gi === 0) disabled @endif
                                    class="text-neutral-400 hover:text-neutral-700 disabled:opacity-30 disabled:hover:text-neutral-400 dark:text-neutral-500 dark:hover:text-neutral-300 transition-colors">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 15l7-7 7 7" />
                                    </svg>
                                </button>
                                <button type="button" wire:click="moveOptionGroupDown({{ $gi }})"
                                    title="Descer grupo"
                                    @if ($gi === count($optionGroups) - 1) disabled @endif
                                    class="text-neutral-400 hover:text-neutral-700 disabled:opacity-30 disabled:hover:text-neutral-400 dark:text-neutral-500 dark:hover:text-neutral-300 transition-colors">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                                    </svg>
                                </button>
                                <button type="button" wire:click="removeOptionGroup({{ $gi }})"
                                    title="Remover grupo"
                                    class="text-neutral-400 hover:text-red-500 dark:text-neutral-500 dark:hover:text-red-400 transition-colors">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        {{-- Aviso grupo compartilhado --}}
                        @if (!empty($group['group_id']))
                            <p class="text-[11px] text-blue-500 dark:text-blue-400 -mt-1">
                                Alterações neste grupo serão refletidas em todos os produtos que o utilizam.
                            </p>
                        @endif

                        {{-- Opções do grupo --}}
                        <div class="space-y-2">
                            <p class="text-xs font-medium text-neutral-500 uppercase tracking-wide dark:text-neutral-400">Opções</p>

                            @foreach ($group['options'] as $oi => $option)
                                @php $paused = empty($option['active']); @endphp
                                <div class="rounded-lg border {{ $paused ? 'border-amber-300 bg-amber-50 dark:border-amber-700 dark:bg-amber-950/20' : 'border-neutral-200 bg-neutral-50 dark:border-zinc-600 dark:bg-zinc-700/40' }} p-3 space-y-2"
                                    wire:key="group-{{ $gi }}-option-{{ $oi }}">

                                    {{-- Linha 1: nome + ações --}}
                                    <div class="flex items-center gap-2">
                                        <div class="flex-1">
                                            <flux:input wire:model="optionGroups.{{ $gi }}.options.{{ $oi }}.name"
                                                placeholder="Nome da opção (ex: Coxinha de Frango)" />
                                            @error("optionGroups.{$gi}.options.{$oi}.name")
                                                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                                            @enderror
                                        </div>
                                        <div class="flex items-center gap-1 shrink-0">
                                            {{-- Pause / Reativar --}}
                                            <button type="button"
                                                wire:click="$set('optionGroups.{{ $gi }}.options.{{ $oi }}.active', {{ $paused ? 'true' : 'false' }})"
                                                title="{{ $paused ? 'Reativar (disponível novamente)' : 'Pausar (em falta)' }}"
                                                class="inline-flex items-center gap-1 px-2 py-1 rounded text-xs font-medium transition-colors
                                                    {{ $paused
                                                        ? 'bg-amber-100 text-amber-700 hover:bg-amber-200 dark:bg-amber-900/40 dark:text-amber-400 dark:hover:bg-amber-800/60'
                                                        : 'text-neutral-400 hover:text-amber-600 hover:bg-amber-50 dark:text-neutral-500 dark:hover:text-amber-400 dark:hover:bg-amber-950/30' }}">
                                                @if ($paused)
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.347a1.125 1.125 0 0 1 0 1.972l-11.54 6.347c-.75.412-1.667-.13-1.667-.986V5.653Z" />
                                                    </svg>
                                                    Em falta
                                                @else
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25v13.5m-7.5-13.5v13.5" />
                                                    </svg>
                                                    Pausar
                                                @endif
                                            </button>
                                            <button type="button" wire:click="moveOptionUp({{ $gi }}, {{ $oi }})"
                                                title="Subir"
                                                @if ($oi === 0) disabled @endif
                                                class="p-1 text-neutral-400 hover:text-neutral-700 disabled:opacity-30 disabled:hover:text-neutral-400 dark:text-neutral-500 dark:hover:text-neutral-300 transition-colors">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 15l7-7 7 7" />
                                                </svg>
                                            </button>
                                            <button type="button" wire:click="moveOptionDown({{ $gi }}, {{ $oi }})"
                                                title="Descer"
                                                @if ($oi === count($group['options']) - 1) disabled @endif
                                                class="p-1 text-neutral-400 hover:text-neutral-700 disabled:opacity-30 disabled:hover:text-neutral-400 dark:text-neutral-500 dark:hover:text-neutral-300 transition-colors">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                                                </svg>
                                            </button>
                                            <button type="button" wire:click="removeOption({{ $gi }}, {{ $oi }})"
                                                title="Remover"
                                                class="p-1 text-neutral-400 hover:text-red-500 dark:text-neutral-500 dark:hover:text-red-400 transition-colors">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                                </svg>
                                            </button>
                                        </div>
                                    </div>

                                    {{-- Linha 2: descrição + campos numéricos --}}
                                    <div class="flex items-start gap-2">
                                        <div class="flex-1">
                                            <flux:input wire:model="optionGroups.{{ $gi }}.options.{{ $oi }}.description"
                                                placeholder="Descrição (opcional)"
                                                class="text-xs text-neutral-500" />
                                        </div>
                                        @if (($isVariant && $gi === 0) || empty($group['fixed']))
                                        <div class="w-28 shrink-0">
                                            <flux:input wire:model="optionGroups.{{ $gi }}.options.{{ $oi }}.additional_price"
                                                type="number" step="0.01" min="0"
                                                label="{{ $isVariant && $gi === 0 ? 'Preço (R$)' : 'Acréscimo (R$)' }}"
                                                placeholder="0,00" />
                                            @error("optionGroups.{$gi}.options.{$oi}.additional_price")
                                                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                                            @enderror
                                        </div>
                                        @endif
                                        @if (!empty($group['fixed']))
                                        <div class="w-20 shrink-0">
                                            <flux:input wire:model="optionGroups.{{ $gi }}.options.{{ $oi }}.default_qty"
                                                type="number" min="0"
                                                label="Qtd. fixa"
                                                placeholder="0" />
                                            @error("optionGroups.{$gi}.options.{$oi}.default_qty")
                                                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                                            @enderror
                                        </div>
                                        @endif
                                    </div>
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
