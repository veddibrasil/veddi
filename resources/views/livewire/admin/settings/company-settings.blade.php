<div class="max-w-2xl space-y-6">
    <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100">Configurações da Empresa</h1>

    @if(session('status'))
        <div class="bg-green-50 border border-green-200 text-green-700 rounded-lg px-4 py-3 text-sm dark:bg-green-900/30 dark:border-green-700 dark:text-green-400">
            {{ session('status') }}
        </div>
    @endif

    {{-- Identidade --}}
    <div class="bg-white border rounded-xl shadow-sm p-6 space-y-4 dark:bg-zinc-800 dark:border-zinc-700">
        <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">Identidade</h2>

        <flux:input wire:model="name" label="Nome da empresa" placeholder="Ex: Minha Lanchonete" />
        @error('name') <p class="text-red-500 text-xs -mt-2">{{ $message }}</p> @enderror

        <flux:input wire:model="slug" label="Slug (URL)" placeholder="minha-lanchonete" />
        @error('slug') <p class="text-red-500 text-xs -mt-2">{{ $message }}</p> @enderror

        <flux:input wire:model="tagline" label="Slogan" placeholder="O melhor da cidade!" />
        @error('tagline') <p class="text-red-500 text-xs -mt-2">{{ $message }}</p> @enderror

        <flux:input wire:model="footer_text" label="Texto do rodapé" placeholder="© 2026 Minha Empresa" />
        @error('footer_text') <p class="text-red-500 text-xs -mt-2">{{ $message }}</p> @enderror

        <flux:input wire:model="order_prefix" label="Prefixo dos pedidos" placeholder="ORD" />
        <p class="text-xs text-neutral-400 -mt-2 dark:text-neutral-500">Ex: MXC → pedido MXC-2026-00001. Somente letras e números maiúsculos.</p>
        @error('order_prefix') <p class="text-red-500 text-xs -mt-2">{{ $message }}</p> @enderror

        <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1 dark:text-neutral-300">Logo</label>
            <input type="file" wire:model="logo" accept="image/*" class="block text-sm text-neutral-600 dark:text-neutral-400">
            @if($currentCompany->logo_path)
                <img src="{{ asset('storage/' . $currentCompany->logo_path) }}" alt="Logo atual" class="mt-2 h-16 object-contain rounded">
            @endif
            @error('logo') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
        </div>
    </div>

    {{-- Cores da marca --}}
    <div class="bg-white border rounded-xl shadow-sm p-6 space-y-4 dark:bg-zinc-800 dark:border-zinc-700">
        <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">Cores da Marca</h2>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1 dark:text-neutral-300">Cor primária</label>
                <div class="flex items-center gap-2">
                    <input type="color" wire:model="primary_color" class="h-9 w-14 cursor-pointer rounded border border-neutral-200 dark:border-zinc-600">
                    <flux:input wire:model="primary_color" placeholder="#B91C1C" class="font-mono text-sm" />
                </div>
                @error('primary_color') <p class="text-red-500 text-xs">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1 dark:text-neutral-300">Cor primária escura</label>
                <div class="flex items-center gap-2">
                    <input type="color" wire:model="primary_color_dark" class="h-9 w-14 cursor-pointer rounded border border-neutral-200 dark:border-zinc-600">
                    <flux:input wire:model="primary_color_dark" placeholder="#7F1D1D" class="font-mono text-sm" />
                </div>
                @error('primary_color_dark') <p class="text-red-500 text-xs">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1 dark:text-neutral-300">Cor primária clara</label>
                <div class="flex items-center gap-2">
                    <input type="color" wire:model="primary_color_light" class="h-9 w-14 cursor-pointer rounded border border-neutral-200 dark:border-zinc-600">
                    <flux:input wire:model="primary_color_light" placeholder="#DC2626" class="font-mono text-sm" />
                </div>
                @error('primary_color_light') <p class="text-red-500 text-xs">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1 dark:text-neutral-300">Cor secundária</label>
                <div class="flex items-center gap-2">
                    <input type="color" wire:model="secondary_color" class="h-9 w-14 cursor-pointer rounded border border-neutral-200 dark:border-zinc-600">
                    <flux:input wire:model="secondary_color" placeholder="#B45309" class="font-mono text-sm" />
                </div>
                @error('secondary_color') <p class="text-red-500 text-xs">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1 dark:text-neutral-300">Cor secundária clara</label>
                <div class="flex items-center gap-2">
                    <input type="color" wire:model="secondary_color_light" class="h-9 w-14 cursor-pointer rounded border border-neutral-200 dark:border-zinc-600">
                    <flux:input wire:model="secondary_color_light" placeholder="#D97706" class="font-mono text-sm" />
                </div>
                @error('secondary_color_light') <p class="text-red-500 text-xs">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1 dark:text-neutral-300">Cor de destaque (fundo)</label>
                <div class="flex items-center gap-2">
                    <input type="color" wire:model="accent_color" class="h-9 w-14 cursor-pointer rounded border border-neutral-200 dark:border-zinc-600">
                    <flux:input wire:model="accent_color" placeholder="#FEF3C7" class="font-mono text-sm" />
                </div>
                @error('accent_color') <p class="text-red-500 text-xs">{{ $message }}</p> @enderror
            </div>
        </div>

        {{-- Preview dinâmico --}}
        <div class="mt-2 p-4 rounded-xl border border-dashed border-neutral-200 text-center text-sm text-neutral-500 dark:border-zinc-600 dark:text-neutral-400">
            Prévia: <span style="color: {{ $primary_color }}; font-weight: 700;">{{ $name ?: 'Nome da empresa' }}</span>
            <span class="inline-block ml-2 px-3 py-1 rounded text-white text-xs" style="background: {{ $primary_color }}">Botão</span>
        </div>
    </div>

    {{-- Pagamento --}}
    <div class="bg-white border rounded-xl shadow-sm p-6 space-y-4 dark:bg-zinc-800 dark:border-zinc-700">
        <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">Pagamento (AbacatePay)</h2>

        <flux:input wire:model="abacatepay_token" label="Token da API" type="password" placeholder="••••••••" />
        @error('abacatepay_token') <p class="text-red-500 text-xs -mt-2">{{ $message }}</p> @enderror
    </div>

    <div class="flex gap-3 pb-8">
        <flux:button wire:click="save" class="!bg-amber-500 !text-white hover:!bg-amber-600">
            Salvar configurações
        </flux:button>
    </div>
</div>
