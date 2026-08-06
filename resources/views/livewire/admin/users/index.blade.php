<div class="max-w-5xl mx-auto py-8 px-4 space-y-6"
    x-init="$watch(() => $wire.removingUserId, val => val ? $flux.modal('confirm-remove-user').show() : $flux.modal('confirm-remove-user').close())">

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">Usuários</h1>
            <p class="text-sm text-neutral-500 dark:text-neutral-400 mt-1">Gerencie os usuários da sua empresa.</p>
        </div>
        <div class="flex items-center gap-2">
            <button
                wire:click="$set('showLinkForm', true)"
                class="text-sm text-neutral-600 hover:text-neutral-800 dark:text-neutral-400 dark:hover:text-neutral-200 border border-neutral-300 dark:border-zinc-600 px-3 py-2 rounded-lg"
            >
                Vincular existente
            </button>
            <button
                wire:click="$set('showCreateForm', true)"
                class="inline-flex items-center gap-1 bg-amber-500 text-white text-sm font-medium px-4 py-2 rounded-lg hover:bg-amber-600"
            >
                + Novo usuário
            </button>
        </div>
    </div>

    @if(session('status'))
        <div class="bg-green-50 border border-green-200 text-green-700 text-sm px-4 py-3 rounded-lg dark:bg-green-900/20 dark:border-green-800 dark:text-green-400">
            {{ session('status') }}
        </div>
    @endif

    {{-- Formulário: Novo usuário --}}
    @if($showCreateForm)
        <div class="bg-white border rounded-xl shadow-sm p-6 dark:bg-zinc-800 dark:border-zinc-700 space-y-4">
            <h2 class="font-medium text-neutral-800 dark:text-neutral-200">Novo Usuário</h2>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <flux:input wire:model="newName" label="Nome" placeholder="Nome completo" />
                    @error('newName') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <flux:input wire:model="newEmail" label="E-mail" type="email" placeholder="usuario@empresa.com" />
                    @error('newEmail') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <flux:input wire:model="newPassword" label="Senha" type="password" placeholder="Mínimo 8 caracteres" viewable />
                    @error('newPassword') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <flux:select wire:model.live="newRole" label="Tipo de usuário">
                        <flux:select.option value="">Selecione...</flux:select.option>
                        @foreach($roles as $role)
                            @php $roleUnavailable = ! $company->waiter_module_enabled && $role->permissions->pluck('name')->contains('pdv.waiter_operate'); @endphp
                            <flux:select.option value="{{ $role->slug }}" :disabled="$roleUnavailable">
                                {{ $role->name }}
                                @if($role->is_system) (padrão) @endif
                                @if($roleUnavailable) — indisponível (módulo Garçom) @endif
                            </flux:select.option>
                        @endforeach
                    </flux:select>
                    @error('newRole') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                @if(in_array($newRole, ['branch_manager', 'cozinha', 'caixa', 'garcom']))
                    <div>
                        <flux:select wire:model="newBranchId" label="Filial">
                            <flux:select.option value="0">Todas as filiais</flux:select.option>
                            @foreach($branches as $branch)
                                <flux:select.option value="{{ $branch->id }}">{{ $branch->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>
                @endif
            </div>

            <div class="flex items-center gap-3 pt-1">
                <button
                    wire:click="createUser"
                    wire:loading.attr="disabled"
                    class="inline-flex items-center gap-2 bg-amber-500 text-white text-sm font-medium px-4 py-2 rounded-lg hover:bg-amber-600 disabled:opacity-50"
                >
                    <span wire:loading.remove wire:target="createUser">Criar Usuário</span>
                    <span wire:loading wire:target="createUser">Criando...</span>
                </button>
                <button wire:click="$set('showCreateForm', false)" class="text-sm text-neutral-500 hover:text-neutral-700 dark:text-neutral-400">
                    Cancelar
                </button>
            </div>
        </div>
    @endif

    {{-- Formulário: Vincular existente --}}
    @if($showLinkForm)
        <div class="bg-white border rounded-xl shadow-sm p-6 dark:bg-zinc-800 dark:border-zinc-700 space-y-4">
            <h2 class="font-medium text-neutral-800 dark:text-neutral-200">Vincular Usuário Existente</h2>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <flux:input wire:model="linkEmail" label="E-mail do usuário" type="email" placeholder="usuario@empresa.com" />
                    @error('linkEmail') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <flux:select wire:model.live="linkRole" label="Tipo de usuário">
                        <flux:select.option value="">Selecione...</flux:select.option>
                        @foreach($roles as $role)
                            @php $roleUnavailable = ! $company->waiter_module_enabled && $role->permissions->pluck('name')->contains('pdv.waiter_operate'); @endphp
                            <flux:select.option value="{{ $role->slug }}" :disabled="$roleUnavailable">
                                {{ $role->name }}
                                @if($role->is_system) (padrão) @endif
                                @if($roleUnavailable) — indisponível (módulo PDV) @endif
                            </flux:select.option>
                        @endforeach
                    </flux:select>
                    @error('linkRole') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                @if(in_array($linkRole, ['branch_manager', 'cozinha', 'caixa', 'garcom']))
                    <div>
                        <flux:select wire:model="linkBranchId" label="Filial">
                            <flux:select.option value="0">Todas as filiais</flux:select.option>
                            @foreach($branches as $branch)
                                <flux:select.option value="{{ $branch->id }}">{{ $branch->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>
                @endif
            </div>

            <div class="flex items-center gap-3">
                <button
                    wire:click="linkUser"
                    wire:loading.attr="disabled"
                    class="inline-flex items-center gap-2 bg-amber-500 text-white text-sm font-medium px-4 py-2 rounded-lg hover:bg-amber-600 disabled:opacity-50"
                >
                    <span wire:loading.remove wire:target="linkUser">Vincular</span>
                    <span wire:loading wire:target="linkUser">Vinculando...</span>
                </button>
                <button wire:click="$set('showLinkForm', false)" class="text-sm text-neutral-500 hover:text-neutral-700 dark:text-neutral-400">
                    Cancelar
                </button>
            </div>
        </div>
    @endif

    {{-- Busca --}}
    <flux:input wire:model.live="search" placeholder="Buscar por nome ou e-mail..." class="max-w-sm" />

    {{-- Lista de usuários --}}
    <div class="bg-white border rounded-xl shadow-sm overflow-hidden dark:bg-zinc-800 dark:border-zinc-700">
        <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-neutral-500 text-xs uppercase tracking-wide dark:bg-zinc-700/50 dark:text-neutral-400">
                <tr>
                    <th class="px-4 py-3 text-left">Usuário</th>
                    <th class="px-4 py-3 text-left">Tipo</th>
                    <th class="px-4 py-3 text-right">Ações</th>
                </tr>
            </thead>
            <tbody class="divide-y dark:divide-zinc-700">
                @forelse($users as $user)
                    <tr class="hover:bg-neutral-50 dark:hover:bg-zinc-700/50">
                        <td class="px-4 py-3">
                            <div class="font-medium text-neutral-800 dark:text-neutral-100">{{ $user->name }}</div>
                            <div class="text-neutral-400 text-xs dark:text-neutral-500">{{ $user->email }}</div>
                        </td>
                        <td class="px-4 py-3">
                            @if($editUserId === $user->id)
                                <div class="flex items-center gap-2">
                                    <flux:select wire:model.live="editRole">
                                        @foreach($roles as $role)
                                            @php $roleUnavailable = ! $company->waiter_module_enabled && $role->permissions->pluck('name')->contains('pdv.waiter_operate'); @endphp
                                            <flux:select.option value="{{ $role->slug }}" :disabled="$roleUnavailable">
                                                {{ $role->name }}
                                                @if($roleUnavailable) — indisponível (módulo PDV) @endif
                                            </flux:select.option>
                                        @endforeach
                                    </flux:select>
                                    @error('editRole') <p class="text-red-500 text-xs">{{ $message }}</p> @enderror
                                    @if(in_array($editRole, ['branch_manager', 'cozinha', 'caixa', 'garcom']))
                                        <flux:select wire:model="editBranchId">
                                            <flux:select.option value="0">Todas</flux:select.option>
                                            @foreach($branches as $branch)
                                                <flux:select.option value="{{ $branch->id }}">{{ $branch->name }}</flux:select.option>
                                            @endforeach
                                        </flux:select>
                                    @endif
                                    <button wire:click="saveEditRole" class="text-xs text-amber-600 font-medium hover:text-amber-700">Salvar</button>
                                    <button wire:click="cancelEdit" class="text-xs text-neutral-400 hover:text-neutral-600">✕</button>
                                </div>
                            @else
                                @php $pivot = $user->companies->first()?->pivot @endphp
                                <span class="text-xs px-2 py-0.5 rounded-full bg-neutral-100 text-neutral-600 dark:bg-zinc-700 dark:text-neutral-300">
                                    {{ $pivot?->role ?? '—' }}
                                </span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-1">
                                {{-- Permissões --}}
                                <div class="relative group">
                                    <a href="{{ route('admin.users.permissions', $user) }}"
                                        class="inline-flex items-center justify-center p-1.5 rounded text-blue-600 hover:bg-blue-50 dark:text-blue-400 dark:hover:bg-blue-900/20 transition-colors"
                                        wire:navigate>
                                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                                        </svg>
                                    </a>
                                    <span class="pointer-events-none absolute bottom-full left-1/2 -translate-x-1/2 mb-1.5 px-2 py-1 rounded bg-neutral-800 text-white text-xs whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity z-10 dark:bg-zinc-600">Permissões</span>
                                </div>

                                {{-- Tipo --}}
                                <div class="relative group">
                                    <button wire:click="openEditRole({{ $user->id }})"
                                        class="inline-flex items-center justify-center p-1.5 rounded text-amber-600 hover:bg-amber-50 dark:text-amber-400 dark:hover:bg-amber-900/20 transition-colors">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-5 5a2 2 0 01-2.828 0l-7-7A2 2 0 013 8V5a2 2 0 012-2z" />
                                        </svg>
                                    </button>
                                    <span class="pointer-events-none absolute bottom-full left-1/2 -translate-x-1/2 mb-1.5 px-2 py-1 rounded bg-neutral-800 text-white text-xs whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity z-10 dark:bg-zinc-600">Alterar tipo</span>
                                </div>

                                {{-- Remover --}}
                                <div class="relative group">
                                    <button wire:click="confirmRemove({{ $user->id }})"
                                        class="inline-flex items-center justify-center p-1.5 rounded text-neutral-400 hover:text-red-600 hover:bg-red-50 dark:hover:text-red-400 dark:hover:bg-red-900/20 transition-colors">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M13 7a4 4 0 11-8 0 4 4 0 018 0zM9 14a6 6 0 00-6 6v1h12v-1a6 6 0 00-6-6zm7-4h6" />
                                        </svg>
                                    </button>
                                    <span class="pointer-events-none absolute bottom-full left-1/2 -translate-x-1/2 mb-1.5 px-2 py-1 rounded bg-neutral-800 text-white text-xs whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity z-10 dark:bg-zinc-600">Excluir</span>
                                </div>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="px-4 py-8 text-center text-neutral-400 dark:text-neutral-500">
                            Nenhum usuário encontrado.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $users->links() }}

    {{-- Modal: Confirmar remoção --}}
    <flux:modal name="confirm-remove-user" class="max-w-sm">
        <div class="space-y-5">
            <div class="flex items-start gap-4">
                <div class="w-10 h-10 rounded-full bg-red-100 dark:bg-red-900/30 flex items-center justify-center flex-shrink-0">
                    <svg class="w-5 h-5 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <div>
                    <flux:heading size="lg">Excluir usuário?</flux:heading>
                    <flux:subheading>A conta será excluída da plataforma, incluindo o acesso a todas as empresas vinculadas. Esta ação não pode ser desfeita pela tela.</flux:subheading>
                </div>
            </div>
            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button wire:click="cancelRemove" variant="ghost">Cancelar</flux:button>
                </flux:modal.close>
                <flux:modal.close>
                    <flux:button wire:click="removeUser" variant="danger">Excluir</flux:button>
                </flux:modal.close>
            </div>
        </div>
    </flux:modal>
</div>
