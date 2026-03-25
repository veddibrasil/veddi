<div class="w-full space-y-6">
    <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100">Configurações da Empresa</h1>

    @if(session('status'))
        <div class="bg-green-50 border border-green-200 text-green-700 rounded-lg px-4 py-3 text-sm dark:bg-green-900/30 dark:border-green-700 dark:text-green-400">
            {{ session('status') }}
        </div>
    @endif

    {{-- Identidade --}}
    <div class="bg-white border rounded-xl shadow-sm p-6 space-y-4 dark:bg-zinc-800 dark:border-zinc-700">
        <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">Identidade</h2>

        <div class="grid grid-cols-1 sm:grid-cols-1 gap-4">
              <div>
                <x-image-upload
                    model="logo"
                    :current-url="$currentCompany->logo_url"
                    label="Logo"
                />
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <flux:input wire:model="name" label="Nome da empresa" placeholder="Ex: Minha Lanchonete" />
                @error('name') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <flux:input wire:model="slug" label="Slug (URL)" placeholder="minha-lanchonete" />
                @error('slug') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <flux:input wire:model="tagline" label="Slogan" placeholder="O melhor da cidade!" />
                @error('tagline') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <flux:input wire:model="footer_text" label="Texto do rodapé" placeholder="© 2026 Minha Empresa" />
                @error('footer_text') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 items-start">
            <div>
                <flux:input wire:model="order_prefix" label="Prefixo dos pedidos" placeholder="ORD" />
                <p class="text-xs text-neutral-400 mt-1 dark:text-neutral-500">Ex: MXC → pedido MXC-2026-00001. Somente letras e números maiúsculos.</p>
                @error('order_prefix') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
          
        </div>
    </div>

    {{-- Cores da marca --}}
    <div class="bg-white border rounded-xl shadow-sm p-6 space-y-4 dark:bg-zinc-800 dark:border-zinc-700">
        <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">Cores da Marca</h2>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1 dark:text-neutral-300">Cor primária</label>
                <div class="flex items-center gap-2">
                    <input type="color" wire:model="primary_color" class="h-9 w-14 cursor-pointer rounded border border-neutral-200 dark:border-zinc-600">
                    <flux:input wire:model="primary_color" placeholder="#7A00A3" class="font-mono text-sm" />
                </div>
                @error('primary_color') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1 dark:text-neutral-300">Cor primária escura</label>
                <div class="flex items-center gap-2">
                    <input type="color" wire:model="primary_color_dark" class="h-9 w-14 cursor-pointer rounded border border-neutral-200 dark:border-zinc-600">
                    <flux:input wire:model="primary_color_dark" placeholder="#5c0079" class="font-mono text-sm" />
                </div>
                @error('primary_color_dark') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1 dark:text-neutral-300">Cor primária clara</label>
                <div class="flex items-center gap-2">
                    <input type="color" wire:model="primary_color_light" class="h-9 w-14 cursor-pointer rounded border border-neutral-200 dark:border-zinc-600">
                    <flux:input wire:model="primary_color_light" placeholder="#9B10C8" class="font-mono text-sm" />
                </div>
                @error('primary_color_light') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1 dark:text-neutral-300">Cor secundária</label>
                <div class="flex items-center gap-2">
                    <input type="color" wire:model="secondary_color" class="h-9 w-14 cursor-pointer rounded border border-neutral-200 dark:border-zinc-600">
                    <flux:input wire:model="secondary_color" placeholder="#1E2F4F" class="font-mono text-sm" />
                </div>
                @error('secondary_color') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1 dark:text-neutral-300">Cor secundária clara</label>
                <div class="flex items-center gap-2">
                    <input type="color" wire:model="secondary_color_light" class="h-9 w-14 cursor-pointer rounded border border-neutral-200 dark:border-zinc-600">
                    <flux:input wire:model="secondary_color_light" placeholder="#D8DEE9" class="font-mono text-sm" />
                </div>
                @error('secondary_color_light') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1 dark:text-neutral-300">Cor de destaque (fundo)</label>
                <div class="flex items-center gap-2">
                    <input type="color" wire:model="accent_color" class="h-9 w-14 cursor-pointer rounded border border-neutral-200 dark:border-zinc-600">
                    <flux:input wire:model="accent_color" placeholder="#f9f0fd" class="font-mono text-sm" />
                </div>
                @error('accent_color') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
        </div>

        {{-- Preview dinâmico --}}
        <div class="mt-2 p-4 rounded-xl border border-dashed border-neutral-200 text-center text-sm text-neutral-500 dark:border-zinc-600 dark:text-neutral-400">
            Prévia: <span style="color: {{ $primary_color }}; font-weight: 700;">{{ $name ?: 'Nome da empresa' }}</span>
            <span class="inline-block ml-2 px-3 py-1 rounded text-white text-xs" style="background: {{ $primary_color }}">Botão</span>
        </div>
    </div>

    {{-- Diferenciais do Chat --}}
    <div class="bg-white border rounded-xl shadow-sm p-6 space-y-4 dark:bg-zinc-800 dark:border-zinc-700">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">Diferenciais do Chat</h2>
                <p class="text-xs text-neutral-400 mt-0.5 dark:text-neutral-500">Exibidos no painel esquerdo do chat (desktop). Máximo 6 itens.</p>
            </div>
            @if(count($chat_highlights) < 6)
                <flux:button wire:click="addHighlight" size="sm" variant="ghost">
                    + Adicionar
                </flux:button>
            @endif
        </div>

        <div class="space-y-3">
            @foreach($chat_highlights as $i => $highlight)
                <div class="flex items-start gap-3 p-3 bg-neutral-50 rounded-lg border border-neutral-100 dark:bg-zinc-700/50 dark:border-zinc-600">
                    <div class="w-20 shrink-0">
                        <flux:input wire:model="chat_highlights.{{ $i }}.icon" placeholder="🥟" />
                        @error("chat_highlights.{$i}.icon") <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex-1 grid grid-cols-1 sm:grid-cols-2 gap-2">
                        <div>
                            <flux:input wire:model="chat_highlights.{{ $i }}.title" placeholder="Título" />
                            @error("chat_highlights.{$i}.title") <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <flux:input wire:model="chat_highlights.{{ $i }}.description" placeholder="Descrição curta" />
                            @error("chat_highlights.{$i}.description") <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <button wire:click="removeHighlight({{ $i }})" class="text-neutral-400 hover:text-red-500 transition-colors mt-1.5 shrink-0" title="Remover">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
                        </svg>
                    </button>
                </div>
            @endforeach

            @if(count($chat_highlights) === 0)
                <p class="text-sm text-neutral-400 text-center py-4 dark:text-neutral-500">Nenhum diferencial cadastrado. Clique em "+ Adicionar" para começar.</p>
            @endif
        </div>
    </div>

    {{-- Pagamento --}}
    <div class="bg-white border rounded-xl shadow-sm p-6 space-y-4 dark:bg-zinc-800 dark:border-zinc-700">
        <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">Pagamento (AbacatePay)</h2>

        <div class="max-w-md">
            <flux:input wire:model="abacatepay_token" label="Token da API" type="password" placeholder="••••••••" />
            @error('abacatepay_token') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
        </div>
    </div>

    <div class="flex gap-3 pb-8">
        <flux:button wire:click="save" class="bg-amber-500! text-white! hover:bg-amber-600!"
            wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="save">Salvar configurações</span>
            <span wire:loading wire:target="save">Salvando...</span>
        </flux:button>
    </div>
</div>
