<div class="max-w-5xl mx-auto py-8 px-4 space-y-6">

    <div>
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">Tipos de Usuário</h1>
        <p class="text-sm text-neutral-500 dark:text-neutral-400 mt-1">Crie tipos customizados com permissões específicas para sua equipe.</p>
    </div>

    @if(session('status'))
        <div class="bg-green-50 border border-green-200 text-green-700 text-sm px-4 py-3 rounded-lg dark:bg-green-900/20 dark:border-green-800 dark:text-green-400">
            {{ session('status') }}
        </div>
    @endif

    {{-- Tipos de sistema --}}
    <div class="bg-white border rounded-xl shadow-sm dark:bg-zinc-800 dark:border-zinc-700">
        <div class="px-6 py-4 border-b dark:border-zinc-700">
            <h2 class="font-medium text-neutral-800 dark:text-neutral-200">Tipos Padrão do Sistema</h2>
            <p class="text-xs text-neutral-500 dark:text-neutral-400 mt-0.5">Não podem ser editados aqui. Permissões gerenciadas pelo super admin.</p>
        </div>
        <ul class="divide-y dark:divide-zinc-700">
            @foreach($systemRoles as $role)
                <li class="px-6 py-4 flex items-center justify-between">
                    <div>
                        <span class="font-medium text-neutral-800 dark:text-neutral-200">{{ $role->name }}</span>
                        <span class="ml-2 text-xs text-neutral-400 font-mono">{{ $role->slug }}</span>
                        <span class="ml-2 text-xs px-2 py-0.5 rounded-full bg-neutral-100 text-neutral-500 dark:bg-zinc-700 dark:text-neutral-400">Sistema</span>
                    </div>
                    <div class="relative group">
                        <button wire:click="openAssign({{ $role->id }})"
                            class="inline-flex items-center justify-center p-1.5 rounded text-amber-600 hover:bg-amber-50 dark:text-amber-400 dark:hover:bg-amber-900/20 transition-colors">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
                            </svg>
                        </button>
                        <span class="pointer-events-none absolute bottom-full left-1/2 -translate-x-1/2 mb-1.5 px-2 py-1 rounded bg-neutral-800 text-white text-xs whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity z-10 dark:bg-zinc-600">Atribuir usuário</span>
                    </div>
                </li>
            @endforeach
        </ul>
    </div>

    {{-- Formulário novo/editar tipo --}}
    <div class="bg-white border rounded-xl shadow-sm p-6 dark:bg-zinc-800 dark:border-zinc-700 space-y-5">
        <h2 class="font-medium text-neutral-800 dark:text-neutral-200">
            {{ $editingId ? 'Editar Tipo' : 'Novo Tipo de Usuário' }}
        </h2>

        <div>
            <flux:input wire:model="name" label="Nome do tipo" placeholder="Ex: Atendente, Cozinheiro..." />
            @error('name')
                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-3">Permissões</label>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-y-4 gap-x-8">
                @foreach($permissions as $group => $groupPermissions)
                    <div class="space-y-1.5">
                        <p class="text-xs font-semibold uppercase tracking-wide text-neutral-400 dark:text-neutral-500">{{ ucfirst($group) }}</p>
                        @foreach($groupPermissions as $perm)
                            <label class="flex items-center gap-2 text-sm cursor-pointer text-neutral-700 dark:text-neutral-300 hover:text-neutral-900 dark:hover:text-neutral-100">
                                <input
                                    type="checkbox"
                                    wire:model="selectedPermissions"
                                    value="{{ $perm->name }}"
                                    class="rounded border-neutral-300 text-amber-500 focus:ring-amber-400 dark:border-zinc-600 dark:bg-zinc-700"
                                />
                                {{ $perm->label }}
                            </label>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>

        <div class="flex items-center gap-3 pt-1">
            <button
                wire:click="save"
                wire:loading.attr="disabled"
                class="inline-flex items-center gap-2 bg-amber-500 text-white text-sm font-medium px-4 py-2 rounded-lg hover:bg-amber-600 disabled:opacity-50"
            >
                <span wire:loading.remove wire:target="save">{{ $editingId ? 'Salvar Alterações' : 'Criar Tipo' }}</span>
                <span wire:loading wire:target="save">Salvando...</span>
            </button>
            @if($editingId)
                <button wire:click="cancelEdit" class="text-sm text-neutral-500 hover:text-neutral-700 dark:text-neutral-400">
                    Cancelar
                </button>
            @endif
        </div>
    </div>

    {{-- Tipos customizados --}}
    @if($customRoles->isNotEmpty())
        <div class="bg-white border rounded-xl shadow-sm dark:bg-zinc-800 dark:border-zinc-700">
            <div class="px-6 py-4 border-b dark:border-zinc-700">
                <h2 class="font-medium text-neutral-800 dark:text-neutral-200">Tipos Criados por Você</h2>
            </div>
            <ul class="divide-y dark:divide-zinc-700">
                @foreach($customRoles as $role)
                    <li class="px-6 py-4 space-y-1.5">
                        <div class="flex items-center justify-between">
                            <div>
                                <span class="font-medium text-neutral-800 dark:text-neutral-200">{{ $role->name }}</span>
                                <span class="ml-2 text-xs text-neutral-400 font-mono">{{ $role->slug }}</span>
                            </div>
                            <div class="flex items-center gap-1">
                                {{-- Atribuir usuário --}}
                                <div class="relative group">
                                    <button wire:click="openAssign({{ $role->id }})"
                                        class="inline-flex items-center justify-center p-1.5 rounded text-amber-600 hover:bg-amber-50 dark:text-amber-400 dark:hover:bg-amber-900/20 transition-colors">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
                                        </svg>
                                    </button>
                                    <span class="pointer-events-none absolute bottom-full left-1/2 -translate-x-1/2 mb-1.5 px-2 py-1 rounded bg-neutral-800 text-white text-xs whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity z-10 dark:bg-zinc-600">Atribuir usuário</span>
                                </div>

                                {{-- Editar --}}
                                <div class="relative group">
                                    <button wire:click="edit({{ $role->id }})"
                                        class="inline-flex items-center justify-center p-1.5 rounded text-neutral-500 hover:bg-neutral-100 dark:text-neutral-400 dark:hover:bg-zinc-700 transition-colors">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                        </svg>
                                    </button>
                                    <span class="pointer-events-none absolute bottom-full left-1/2 -translate-x-1/2 mb-1.5 px-2 py-1 rounded bg-neutral-800 text-white text-xs whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity z-10 dark:bg-zinc-600">Editar</span>
                                </div>

                                {{-- Remover --}}
                                <div class="relative group">
                                    <button wire:click="confirmDelete({{ $role->id }})"
                                        class="inline-flex items-center justify-center p-1.5 rounded text-neutral-400 hover:text-red-600 hover:bg-red-50 dark:hover:text-red-400 dark:hover:bg-red-900/20 transition-colors">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                        </svg>
                                    </button>
                                    <span class="pointer-events-none absolute bottom-full left-1/2 -translate-x-1/2 mb-1.5 px-2 py-1 rounded bg-neutral-800 text-white text-xs whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity z-10 dark:bg-zinc-600">Remover</span>
                                </div>
                            </div>
                        </div>
                        @if($role->permissions->isNotEmpty())
                            <div class="flex flex-wrap gap-1.5">
                                @foreach($role->permissions as $perm)
                                    <span class="text-xs px-2 py-0.5 rounded-full bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400 font-mono">
                                        {{ $perm->name }}
                                    </span>
                                @endforeach
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Modal: Atribuir usuário --}}
    @if($assignRoleId)
        <flux:modal name="assign-user-modal" class="max-w-sm">
            <div class="space-y-5">
                <div>
                    <flux:heading size="lg">Atribuir Usuário</flux:heading>
                    <flux:subheading>Informe o e-mail do usuário para atribuí-lo a este tipo.</flux:subheading>
                </div>
                <div>
                    <flux:input wire:model="assignUserEmail" label="E-mail do usuário" type="email" placeholder="usuario@exemplo.com" />
                    @error('assignUserEmail')
                        <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>
                <div class="flex justify-end gap-3">
                    <flux:modal.close>
                        <flux:button wire:click="cancelAssign" variant="ghost">Cancelar</flux:button>
                    </flux:modal.close>
                    <flux:button wire:click="assignUser" variant="primary">Atribuir</flux:button>
                </div>
            </div>
        </flux:modal>
    @endif

    {{-- Modal: Confirmar exclusão --}}
    <flux:modal name="confirm-delete-role" class="max-w-sm">
        <div class="space-y-5">
            <div class="flex items-start gap-4">
                <div class="w-10 h-10 rounded-full bg-red-100 dark:bg-red-900/30 flex items-center justify-center flex-shrink-0">
                    <svg class="w-5 h-5 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <div>
                    <flux:heading size="lg">Remover tipo?</flux:heading>
                    <flux:subheading>Esta ação não pode ser desfeita. Usuários com este tipo perderão o acesso.</flux:subheading>
                </div>
            </div>
            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button wire:click="cancelDelete" variant="ghost">Cancelar</flux:button>
                </flux:modal.close>
                <flux:modal.close>
                    <flux:button wire:click="delete" variant="danger">Remover</flux:button>
                </flux:modal.close>
            </div>
        </div>
    </flux:modal>
</div>
