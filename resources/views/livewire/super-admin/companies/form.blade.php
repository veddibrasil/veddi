<div class="w-full space-y-6">
    <div class="flex items-center gap-3">
        <a href="{{ route('superadmin.companies.index') }}" class="text-neutral-400 hover:text-neutral-600 dark:text-neutral-500 dark:hover:text-neutral-300">←</a>
        <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100">
            {{ $isEditing ? 'Editar Empresa' : 'Nova Empresa' }}
        </h1>
    </div>

    {{-- Identidade --}}
    <div class="bg-white border rounded-xl shadow-sm p-6 space-y-4 dark:bg-zinc-800 dark:border-zinc-700">
        <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">Identidade</h2>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <flux:input wire:model="name" label="Nome da empresa" placeholder="Ex: Minha Lanchonete" />
                @error('name') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <flux:input wire:model="slug" label="Slug" placeholder="minha-lanchonete" />
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

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 items-start">
            <div>
                <flux:input wire:model="subdomain" label="Subdomínio (opcional)" placeholder="minha-lanchonete" />
                <p class="text-xs text-neutral-400 mt-1 dark:text-neutral-500">Ex: empresa.seudominio.com.br</p>
                @error('subdomain') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <flux:input wire:model="order_prefix" label="Prefixo dos pedidos" placeholder="ORD" />
                @error('order_prefix') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="flex items-end gap-4 pb-1">
                <flux:checkbox wire:model="active" label="Empresa ativa" />
            </div>
        </div>

        <div>
            <x-image-upload
                model="logo"
                :current-url="$isEditing && $company->logo_path ? $company->logo_url : null"
                label="Logo"
            />
        </div>
    </div>

    {{-- Cores --}}
    <div class="bg-white border rounded-xl shadow-sm p-6 space-y-4 dark:bg-zinc-800 dark:border-zinc-700">
        <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">Cores da Marca</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach([
                ['primary_color', 'Cor primária', '#7A00A3'],
                ['primary_color_dark', 'Primária escura', '#5c0079'],
                ['primary_color_light', 'Primária clara', '#9B10C8'],
                ['secondary_color', 'Secundária', '#1E2F4F'],
                ['secondary_color_light', 'Secundária clara', '#D8DEE9'],
                ['accent_color', 'Destaque (fundo)', '#f9f0fd'],
            ] as [$field, $label, $placeholder])
                <div>
                    <label class="block text-sm font-medium text-neutral-700 mb-1 dark:text-neutral-300">{{ $label }}</label>
                    <div class="flex items-center gap-2">
                        <input type="color" wire:model="{{ $field }}" class="h-9 w-14 cursor-pointer rounded border border-neutral-200 dark:border-zinc-600">
                        <flux:input wire:model="{{ $field }}" placeholder="{{ $placeholder }}" class="font-mono text-sm" />
                    </div>
                    @error($field) <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
            @endforeach
        </div>
    </div>

    {{-- Pagamento --}}
    <div class="bg-white border rounded-xl shadow-sm p-6 space-y-4 dark:bg-zinc-800 dark:border-zinc-700">
        <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">Pagamento (AbacatePay)</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <flux:input wire:model="abacatepay_token" label="Token da API" type="password" placeholder="••••••••" />
                @error('abacatepay_token') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <flux:input wire:model="abacatepay_webhook_secret" label="Webhook Secret" type="password" placeholder="••••••••" />
                @error('abacatepay_webhook_secret') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
        </div>
    </div>

    <div class="flex gap-3 pb-8">
        <flux:button wire:click="save" class="bg-amber-500! text-white! hover:bg-amber-600!">
            {{ $isEditing ? 'Salvar alterações' : 'Criar empresa' }}
        </flux:button>
        <a href="{{ route('superadmin.companies.index') }}"
            class="inline-flex items-center px-4 py-2 text-sm text-neutral-600 hover:text-neutral-800 dark:text-neutral-400 dark:hover:text-neutral-200">
            Cancelar
        </a>
    </div>
</div>
