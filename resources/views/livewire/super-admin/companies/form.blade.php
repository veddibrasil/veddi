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

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <flux:select wire:model.live="plan" label="Plano">
                    <flux:select.option value="free">Grátis</flux:select.option>
                    <flux:select.option value="essencial">Essencial</flux:select.option>
                    <flux:select.option value="pro">PRO</flux:select.option>
                    <flux:select.option value="pdv">PDV</flux:select.option>
                </flux:select>
                @error('plan') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            @if($isEditing)
            <div>
                <flux:select wire:model="status" label="Status">
                    <flux:select.option value="ACTIVE">Ativo</flux:select.option>
                    <flux:select.option value="PENDING_PAYMENT">Aguardando pagamento</flux:select.option>
                    <flux:select.option value="BLOCKED">Bloqueado</flux:select.option>
                </flux:select>
                @error('status') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            @endif
        </div>

        @if($isEditing && $plan !== $originalPlan)
        <div class="rounded-lg border border-amber-200 bg-amber-50 dark:bg-amber-950/30 dark:border-amber-800 px-4 py-3 space-y-2">
            <p class="text-sm font-medium text-amber-800 dark:text-amber-300">
                Alteração de plano detectada:
                <span class="font-semibold">{{ ucfirst($originalPlan) }}</span> →
                <span class="font-semibold">{{ ucfirst($plan) }}</span>
            </p>

            @if($plan === 'free')
                <p class="text-xs text-amber-700 dark:text-amber-400">
                    A assinatura Asaas será cancelada e a empresa ficará <strong>Ativa</strong> sem cobrança mensal.
                </p>
            @else
                <div class="space-y-1">
                    <p class="text-xs text-amber-700 dark:text-amber-400">
                        Sem bypass: a assinatura Asaas antiga será cancelada e uma nova será criada. A empresa ficará <strong>Aguardando pagamento</strong> até a confirmação.
                    </p>
                    <label class="flex items-center gap-2 cursor-pointer mt-2">
                        <input type="checkbox" wire:model.live="bypassPayment" class="rounded border-neutral-300 dark:border-zinc-600">
                        <span class="text-sm font-medium text-amber-800 dark:text-amber-300">Forçar ativação imediata (sem aguardar pagamento)</span>
                    </label>
                    @if($bypassPayment)
                    <p class="text-xs text-amber-600 dark:text-amber-500 pl-6">
                        Nenhuma assinatura Asaas será criada. A empresa será ativada diretamente no plano {{ ucfirst($plan) }}.
                    </p>
                    @endif
                </div>
            @endif
        </div>
        @endif

        <div>
            <x-image-upload
                model="logo"
                :current-url="$isEditing && $company->logo_path ? $company->logo_url : null"
                label="Logo"
            />
        </div>
    </div>

    {{-- Módulos / Add-ons --}}
    <div class="bg-white border rounded-xl shadow-sm p-6 space-y-4 dark:bg-zinc-800 dark:border-zinc-700">
        <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">Módulos / Add-ons</h2>

        <div class="flex items-start gap-4 rounded-lg border border-neutral-100 dark:border-zinc-700 bg-neutral-50 dark:bg-zinc-700/40 px-4 py-3">
            <div class="pt-0.5">
                <flux:checkbox wire:model="fiscal_notes_enabled" id="fiscal_notes_enabled" />
            </div>
            <div>
                <label for="fiscal_notes_enabled" class="text-sm font-medium text-neutral-800 dark:text-neutral-100 cursor-pointer">
                    Nota Fiscal Eletrônica
                </label>
                <p class="text-xs text-neutral-500 dark:text-neutral-400 mt-0.5">
                    Libera acesso ao módulo de NF-e/NFS-e para esta empresa. A empresa precisará configurar os dados fiscais no painel admin.
                </p>
            </div>
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

    {{-- Gerente (apenas na criação) --}}
    @if(! $isEditing)
    <div class="bg-white border rounded-xl shadow-sm p-6 space-y-4 dark:bg-zinc-800 dark:border-zinc-700">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">Gerente da Empresa</h2>
            <flux:checkbox wire:model.live="create_manager" label="Cadastrar gerente agora" />
        </div>

        @if($create_manager)
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-2">
            <div class="sm:col-span-2">
                <flux:input wire:model="manager_name" label="Nome completo" placeholder="João Silva" />
                @error('manager_name') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <flux:input wire:model="manager_email" label="E-mail" type="email" placeholder="joao@empresa.com" />
                @error('manager_email') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div></div>
            <div class="sm:col-span-2">
                <p class="text-xs text-neutral-500 dark:text-neutral-400 bg-neutral-50 dark:bg-zinc-700 border border-neutral-200 dark:border-zinc-600 rounded-lg px-4 py-3">
                    🔐 Uma senha segura será gerada automaticamente e enviada ao gerente por e-mail.
                </p>
            </div>
        </div>
        <p class="text-xs text-neutral-400 dark:text-neutral-500">O usuário será criado com o papel de <strong>Administrador da Empresa</strong> e vinculado automaticamente.</p>
        @endif
    </div>
    @endif

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
