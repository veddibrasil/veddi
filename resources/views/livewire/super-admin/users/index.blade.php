<div class="space-y-6">
    <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100">Usuários</h1>

    @if(session('status'))
        <div class="bg-green-50 border border-green-200 text-green-700 rounded-lg px-4 py-3 text-sm dark:bg-green-900/30 dark:border-green-700 dark:text-green-400">
            {{ session('status') }}
        </div>
    @endif

    <flux:input wire:model.live="search" placeholder="Buscar por nome ou e-mail..." class="max-w-sm" />

    <div class="bg-white border rounded-xl shadow-sm overflow-hidden dark:bg-zinc-800 dark:border-zinc-700">
        <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-neutral-500 text-xs uppercase tracking-wide dark:bg-zinc-700/50 dark:text-neutral-400">
                <tr>
                    <th class="px-4 py-3 text-left">Usuário</th>
                    <th class="px-4 py-3 text-left">Empresas</th>
                    <th class="px-4 py-3 text-center">Super Admin</th>
                    <th class="px-4 py-3 text-right">Ações</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100 dark:divide-zinc-700">
                @forelse($users as $user)
                    <tr class="hover:bg-neutral-50 transition dark:hover:bg-zinc-700/50">
                        <td class="px-4 py-3">
                            <div class="font-medium text-neutral-800 dark:text-neutral-100">{{ $user->name }}</div>
                            <div class="text-neutral-400 text-xs dark:text-neutral-500">{{ $user->email }}</div>
                        </td>
                        <td class="px-4 py-3">
                            @forelse($user->companies as $company)
                                <span class="inline-block text-xs px-2 py-0.5 bg-neutral-100 rounded-full mr-1 dark:bg-zinc-700 dark:text-neutral-300">
                                    {{ $company->name }} ({{ $company->pivot->role }})
                                </span>
                            @empty
                                <span class="text-neutral-400 text-xs dark:text-neutral-500">Nenhuma</span>
                            @endforelse
                        </td>
                        <td class="px-4 py-3 text-center">
                            <button wire:click="toggleSuperAdmin({{ $user->id }})"
                                class="px-2 py-0.5 rounded-full text-xs font-medium {{ $user->is_super_admin ? 'bg-purple-100 text-purple-700 dark:bg-purple-900/50 dark:text-purple-400' : 'bg-neutral-100 text-neutral-500 dark:bg-zinc-700 dark:text-neutral-400' }}">
                                {{ $user->is_super_admin ? 'Sim' : 'Não' }}
                            </button>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <button wire:click="openAssign({{ $user->id }})"
                                class="text-amber-600 hover:text-amber-800 text-xs font-medium dark:text-amber-400 dark:hover:text-amber-300">
                                Vincular empresa
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-8 text-center text-neutral-400 dark:text-neutral-500">Nenhum usuário encontrado.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $users->links() }}

    {{-- Modal de vinculação --}}
    @if($showModal)
        <div class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center">
            <div class="bg-white rounded-xl shadow-xl w-full max-w-md p-6 space-y-4 dark:bg-zinc-800 dark:border dark:border-zinc-700">
                <h3 class="font-bold text-neutral-800 dark:text-neutral-100">Vincular usuário à empresa</h3>

                <div>
                    <label class="block text-sm font-medium text-neutral-700 mb-1 dark:text-neutral-300">Empresa</label>
                    <select wire:model="assignCompanyId" class="w-full border rounded-lg px-3 py-2 text-sm dark:bg-zinc-700 dark:border-zinc-600 dark:text-neutral-200">
                        <option value="0">Selecione...</option>
                        @foreach($companies as $company)
                            <option value="{{ $company->id }}">{{ $company->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-neutral-700 mb-1 dark:text-neutral-300">Role</label>
                    <select wire:model="assignRole" class="w-full border rounded-lg px-3 py-2 text-sm dark:bg-zinc-700 dark:border-zinc-600 dark:text-neutral-200">
                        <option value="company_admin">Administrador da empresa</option>
                        <option value="branch_manager">Gerente de filial</option>
                    </select>
                </div>

                <div class="flex gap-3 pt-2">
                    <flux:button wire:click="saveAssign" class="!bg-amber-500 !text-white hover:!bg-amber-600">
                        Salvar
                    </flux:button>
                    <button wire:click="$set('showModal', false)"
                        class="px-4 py-2 text-sm text-neutral-600 hover:text-neutral-800 dark:text-neutral-400 dark:hover:text-neutral-200">
                        Cancelar
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
