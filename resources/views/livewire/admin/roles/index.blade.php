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
                    <button
                        wire:click="openAssign({{ $role->id }})"
                        class="text-xs text-amber-600 hover:text-amber-700 dark:text-amber-400 font-medium"
                    >
                        Atribuir usuário →
                    </button>
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
                            <div class="flex items-center gap-4 text-sm">
                                <button wire:click="openAssign({{ $role->id }})" class="text-amber-600 hover:text-amber-700 dark:text-amber-400 font-medium">
                                    Atribuir usuário
                                </button>
                                <button wire:click="edit({{ $role->id }})" class="text-neutral-500 hover:text-neutral-700 dark:text-neutral-400">
                                    Editar
                                </button>
                                <button wire:click="confirmDelete({{ $role->id }})" class="text-red-500 hover:text-red-700">
                                    Remover
                                </button>
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
