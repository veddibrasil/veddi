<div class="space-y-4"
    x-data="{}"
    x-init="
        $watch(() => $wire.deletingId, val => val ? $flux.modal('confirm-delete-coupon').show() : $flux.modal('confirm-delete-coupon').close());
        $watch(() => $wire.showModal, val => val ? $flux.modal('coupon-form').show() : $flux.modal('coupon-form').close());
    ">

    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100">Cupons de Desconto</h1>
        @if($canCreate)
        <flux:button wire:click="openModal" class="!bg-amber-500 !text-white hover:!bg-amber-600 text-sm">
            + Novo Cupom
        </flux:button>
        @endif
    </div>

    @if (session('status'))
        <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg text-sm dark:bg-green-900/30 dark:border-green-700 dark:text-green-400">
            {{ session('status') }}
        </div>
    @endif

    {{-- Filters --}}
    <div class="flex flex-wrap gap-3">
        <flux:select wire:model.live="filterStatus" placeholder="Todos os status" class="w-40">
            <flux:select.option value="">Todos os status</flux:select.option>
            <flux:select.option value="active">Ativo</flux:select.option>
            <flux:select.option value="inactive">Inativo</flux:select.option>
        </flux:select>
        <flux:select wire:model.live="filterType" placeholder="Todos os tipos" class="w-48">
            <flux:select.option value="">Todos os tipos</flux:select.option>
            <flux:select.option value="percentage">Percentual (%)</flux:select.option>
            <flux:select.option value="fixed">Valor Fixo (R$)</flux:select.option>
            <flux:select.option value="free_delivery">Frete Grátis</flux:select.option>
            <flux:select.option value="free_product">Produto Grátis</flux:select.option>
        </flux:select>
    </div>

    {{-- List --}}
    <div class="bg-white border rounded-xl shadow-sm overflow-hidden dark:bg-zinc-800 dark:border-zinc-700">
        <div class="grid grid-cols-[2fr_1fr_1fr_1fr_1fr_auto] items-center px-4 py-2 border-b bg-neutral-50 dark:bg-zinc-700/50 dark:border-zinc-700 gap-6">
            <span class="text-xs font-medium text-neutral-400 uppercase tracking-wide">Cupom</span>
            <span class="text-xs font-medium text-neutral-400 uppercase tracking-wide">Desconto</span>
            <span class="text-xs font-medium text-neutral-400 uppercase tracking-wide text-center">Usos</span>
            <span class="text-xs font-medium text-neutral-400 uppercase tracking-wide">Validade</span>
            <span class="text-xs font-medium text-neutral-400 uppercase tracking-wide text-center">Status</span>
            <span class="text-xs font-medium text-neutral-400 uppercase tracking-wide">Ações</span>
        </div>

        <div class="divide-y dark:divide-zinc-700">
            @forelse ($coupons as $coupon)
                <div class="grid grid-cols-[2fr_1fr_1fr_1fr_1fr_auto] items-center px-4 py-3.5 gap-6">
                    {{-- Código + Nome --}}
                    <div>
                        <div class="flex items-center gap-2 flex-wrap">
                            <code class="font-bold text-sm text-neutral-800 dark:text-neutral-100 bg-neutral-100 dark:bg-zinc-700 px-2 py-0.5 rounded tracking-wide">{{ $coupon->code }}</code>
                            <span class="text-xs px-1.5 py-0.5 rounded font-medium
                                {{ $coupon->type === 'percentage' ? 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400' : '' }}
                                {{ $coupon->type === 'fixed' ? 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400' : '' }}
                                {{ $coupon->type === 'free_delivery' ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : '' }}
                                {{ $coupon->type === 'free_product' ? 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400' : '' }}
                            ">
                                {{ $coupon->getTypeLabel() }}
                            </span>
                        </div>
                        <p class="text-xs text-neutral-500 dark:text-neutral-400 mt-0.5">{{ $coupon->name }}</p>
                    </div>

                    {{-- Desconto --}}
                    <div>
                        @if ($coupon->type === 'percentage')
                            <p class="text-sm font-semibold text-neutral-700 dark:text-neutral-200">{{ $coupon->discount_value }}%</p>
                        @elseif ($coupon->type === 'fixed')
                            <p class="text-sm font-semibold text-neutral-700 dark:text-neutral-200">R$ {{ number_format($coupon->discount_value, 2, ',', '.') }}</p>
                        @elseif ($coupon->type === 'free_delivery')
                            <p class="text-sm font-semibold text-green-600 dark:text-green-400">Frete 0</p>
                        @elseif ($coupon->type === 'free_product')
                            <p class="text-sm font-semibold text-orange-600 dark:text-orange-400">1 brinde</p>
                        @endif
                        @if ($coupon->minimum_order_value)
                            <p class="text-xs text-neutral-400 mt-0.5">Mín R$ {{ number_format($coupon->minimum_order_value, 2, ',', '.') }}</p>
                        @endif
                    </div>

                    {{-- Usos --}}
                    <div class="text-center">
                        <p class="text-sm font-bold text-neutral-700 dark:text-neutral-200">
                            {{ $coupon->usages_count }}
                            <span class="text-xs font-normal text-neutral-400">/ {{ $coupon->max_uses ?? '∞' }}</span>
                        </p>
                        @if ($coupon->max_uses_per_customer)
                            <p class="text-xs text-neutral-400">{{ $coupon->max_uses_per_customer }}x/cliente</p>
                        @endif
                    </div>

                    {{-- Validade --}}
                    <div>
                        @if ($coupon->starts_at)
                            <p class="text-xs text-neutral-500 dark:text-neutral-400">De {{ $coupon->starts_at->format('d/m/Y') }}</p>
                        @endif
                        @if ($coupon->expires_at)
                            <p class="text-xs {{ $coupon->isExpired() ? 'text-red-500 font-semibold' : 'text-neutral-500 dark:text-neutral-400' }}">
                                Até {{ $coupon->expires_at->format('d/m/Y') }}
                            </p>
                        @else
                            <span class="text-xs text-neutral-300 dark:text-neutral-600">Sem validade</span>
                        @endif
                    </div>

                    {{-- Status toggle --}}
                    <div class="flex justify-center">
                        @if($canUpdate)
                        <button wire:click="toggleActive({{ $coupon->id }})"
                            class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors {{ $coupon->active ? 'bg-green-500' : 'bg-gray-200 dark:bg-zinc-600' }}">
                            <span class="inline-block h-3.5 w-3.5 transform rounded-full bg-white shadow transition-transform {{ $coupon->active ? 'translate-x-4' : 'translate-x-1' }}"></span>
                        </button>
                        @else
                        <span class="text-xs {{ $coupon->active ? 'text-green-600 dark:text-green-400' : 'text-neutral-400 dark:text-neutral-500' }}">
                            {{ $coupon->active ? 'Ativo' : 'Inativo' }}
                        </span>
                        @endif
                    </div>

                    {{-- Actions --}}
                    <div class="flex items-center gap-1">
                        {{-- Editar --}}
                        @if($canUpdate)
                        <div class="relative group">
                            <button wire:click="edit({{ $coupon->id }})"
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
                            <button wire:click="confirmDelete({{ $coupon->id }})"
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
                    <div class="text-3xl mb-2">🎟️</div>
                    <p class="text-sm text-neutral-500 dark:text-neutral-400">Nenhum cupom cadastrado.</p>
                    <p class="text-xs text-neutral-400 mt-1">Clique em "Novo Cupom" para criar o primeiro.</p>
                </div>
            @endforelse
        </div>
    </div>

    @if ($coupons->hasPages())
        <div class="mt-2">{{ $coupons->links() }}</div>
    @endif

    {{-- ── Modal: Criar/Editar Cupom ── --}}
    <flux:modal name="coupon-form" class="max-w-2xl w-full">
        <div class="space-y-5 p-1">
            <flux:heading size="lg">{{ $editingId ? 'Editar Cupom' : 'Novo Cupom' }}</flux:heading>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                {{-- Código --}}
                <div>
                    <flux:input wire:model="code" label="Código do cupom" placeholder="Ex: DESCONTO10" class="uppercase" />
                    @error('code') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>

                {{-- Nome --}}
                <div>
                    <flux:input wire:model="name" label="Nome (interno)" placeholder="Ex: Desconto de Lançamento" />
                    @error('name') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>

                {{-- Tipo --}}
                <div>
                    <flux:select wire:model.live="type" label="Tipo de desconto">
                        <flux:select.option value="percentage">Percentual (%)</flux:select.option>
                        <flux:select.option value="fixed">Valor Fixo (R$)</flux:select.option>
                        <flux:select.option value="free_delivery">Frete Grátis</flux:select.option>
                        <flux:select.option value="free_product">Produto Grátis</flux:select.option>
                    </flux:select>
                    @error('type') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>

                {{-- Valor do desconto (só para percentage/fixed) --}}
                @if (in_array($type, ['percentage', 'fixed']))
                    <div>
                        <flux:input
                            wire:model="discount_value"
                            type="number"
                            step="0.01"
                            min="0.01"
                            label="{{ $type === 'percentage' ? 'Desconto (%)' : 'Valor do desconto (R$)' }}"
                            placeholder="{{ $type === 'percentage' ? 'Ex: 10' : 'Ex: 5.00' }}"
                        />
                        @error('discount_value') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                @endif

                {{-- Produto grátis (só para free_product) --}}
                @if ($type === 'free_product')
                    <div>
                        <flux:select wire:model="free_product_id" label="Produto grátis" placeholder="Selecione o produto...">
                            @foreach ($products as $product)
                                <flux:select.option value="{{ $product->id }}">{{ $product->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        @error('free_product_id') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                @endif

                {{-- Escopo (só para percentage/fixed) --}}
                @if (in_array($type, ['percentage', 'fixed']))
                    <div>
                        <flux:select wire:model.live="scope" label="Aplicar sobre">
                            <flux:select.option value="order">Pedido inteiro</flux:select.option>
                            <flux:select.option value="category">Categorias específicas</flux:select.option>
                            <flux:select.option value="product">Produtos específicos</flux:select.option>
                        </flux:select>
                    </div>

                    @if ($scope === 'category')
                        <div class="md:col-span-2">
                            <p class="text-xs font-medium text-neutral-500 mb-1 dark:text-neutral-400">Categorias elegíveis</p>
                            <div class="grid grid-cols-2 gap-1.5 max-h-36 overflow-y-auto border rounded-lg p-2 dark:border-zinc-600">
                                @foreach ($categories as $cat)
                                    <label class="flex items-center gap-2 text-sm cursor-pointer">
                                        <input type="checkbox"
                                            wire:model="scope_ids"
                                            value="{{ $cat->id }}"
                                            class="rounded border-gray-300 text-amber-500 focus:ring-amber-400"
                                        />
                                        {{ $cat->name }}
                                    </label>
                                @endforeach
                            </div>
                            @error('scope_ids') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                    @elseif ($scope === 'product')
                        <div class="md:col-span-2">
                            <p class="text-xs font-medium text-neutral-500 mb-1 dark:text-neutral-400">Produtos elegíveis</p>
                            <div class="grid grid-cols-2 gap-1.5 max-h-36 overflow-y-auto border rounded-lg p-2 dark:border-zinc-600">
                                @foreach ($products as $product)
                                    <label class="flex items-center gap-2 text-sm cursor-pointer">
                                        <input type="checkbox"
                                            wire:model="scope_ids"
                                            value="{{ $product->id }}"
                                            class="rounded border-gray-300 text-amber-500 focus:ring-amber-400"
                                        />
                                        {{ $product->name }}
                                    </label>
                                @endforeach
                            </div>
                            @error('scope_ids') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                    @endif
                @endif

                {{-- Valor mínimo --}}
                <div>
                    <flux:input wire:model="minimum_order_value" type="number" step="0.01" min="0" label="Pedido mínimo (R$)" placeholder="Opcional" />
                    @error('minimum_order_value') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>

                {{-- Max usos total --}}
                <div>
                    <flux:input wire:model="max_uses" type="number" min="1" label="Limite de usos totais" placeholder="Ilimitado" />
                    @error('max_uses') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>

                {{-- Max usos por cliente --}}
                <div>
                    <flux:input wire:model="max_uses_per_customer" type="number" min="1" label="Usos por cliente" placeholder="1" />
                    @error('max_uses_per_customer') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>

                {{-- Início --}}
                <div>
                    <flux:input wire:model="starts_at" type="datetime-local" label="Válido a partir de" />
                    @error('starts_at') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>

                {{-- Expiração --}}
                <div>
                    <flux:input wire:model="expires_at" type="datetime-local" label="Expira em" />
                    @error('expires_at') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>

                {{-- Descrição --}}
                <div class="md:col-span-2">
                    <label class="block text-xs font-medium text-neutral-500 mb-1 dark:text-neutral-400">Descrição (opcional)</label>
                    <textarea wire:model="description" rows="2"
                        class="w-full border border-gray-200 dark:border-zinc-600 rounded-lg px-3 py-2 text-sm bg-white dark:bg-zinc-700 focus:outline-none focus:ring-2 focus:ring-amber-400"
                        placeholder="Descrição interna do cupom..."></textarea>
                    @error('description') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>

                {{-- Ativo --}}
                <div class="flex items-center gap-3">
                    <label class="text-sm font-medium text-neutral-700 dark:text-neutral-300">Ativo</label>
                    <button type="button" wire:click="$toggle('active')"
                        class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors {{ $active ? 'bg-green-500' : 'bg-gray-200 dark:bg-zinc-600' }}">
                        <span class="inline-block h-3.5 w-3.5 transform rounded-full bg-white shadow transition-transform {{ $active ? 'translate-x-4' : 'translate-x-1' }}"></span>
                    </button>
                </div>
            </div>

            <div class="flex justify-end gap-3 pt-2 border-t dark:border-zinc-700">
                <flux:modal.close>
                    <flux:button wire:click="closeModal" variant="ghost">Cancelar</flux:button>
                </flux:modal.close>
                <flux:button wire:click="save" class="!bg-amber-500 !text-white hover:!bg-amber-600"
                    wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">{{ $editingId ? 'Atualizar' : 'Criar Cupom' }}</span>
                    <span wire:loading wire:target="save">Salvando...</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- ── Modal: Confirmar Exclusão ── --}}
    <flux:modal name="confirm-delete-coupon" class="max-w-sm">
        <div class="space-y-5">
            <div class="flex items-start gap-4">
                <div class="w-10 h-10 rounded-full bg-red-100 dark:bg-red-900/30 flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
                    </svg>
                </div>
                <div>
                    <flux:heading size="lg">Excluir cupom?</flux:heading>
                    <flux:subheading class="mt-1">Esta ação não pode ser desfeita. O histórico de usos também será removido.</flux:subheading>
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
