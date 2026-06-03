<div class="max-w-4xl mx-auto py-8 px-4 space-y-6">

    <div class="flex items-center gap-3">
        <a href="{{ route('admin.users.index') }}" class="text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-200">
            ← Voltar
        </a>
        <div>
            <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">Permissões de {{ $user->name }}</h1>
            <p class="text-sm text-neutral-500 dark:text-neutral-400 mt-0.5">Overrides individuais sobrepõem os padrões do tipo de usuário.</p>
        </div>
    </div>

    @if(session('status'))
        <div class="bg-green-50 border border-green-200 text-green-700 text-sm px-4 py-3 rounded-lg dark:bg-green-900/20 dark:border-green-800 dark:text-green-400">
            {{ session('status') }}
        </div>
    @endif

    <div class="bg-white border rounded-xl shadow-sm p-6 dark:bg-zinc-800 dark:border-zinc-700 space-y-4">
        <table class="w-full text-sm">
            <thead class="border-b dark:border-zinc-700">
                <tr>
                    <th class="text-left py-2 font-medium text-neutral-600 dark:text-neutral-300 w-1/2">Permissão</th>
                    <th class="text-center py-2 font-medium text-neutral-600 dark:text-neutral-300 w-1/4">Padrão do Tipo</th>
                    <th class="text-center py-2 font-medium text-neutral-600 dark:text-neutral-300 w-1/4">Override</th>
                </tr>
            </thead>
            <tbody class="divide-y dark:divide-zinc-700">
                @foreach($permissions as $group => $groupPermissions)
                    <tr class="bg-neutral-50/60 dark:bg-zinc-700/20">
                        <td colspan="3" class="py-1.5 px-2">
                            <span class="text-xs font-semibold uppercase tracking-wide text-neutral-400 dark:text-neutral-500">
                                {{ ucfirst($group) }}
                            </span>
                        </td>
                    </tr>
                    @foreach($groupPermissions as $permission)
                        @php
                            $isDefault = in_array($permission->name, $roleDefaults);
                            $override  = $overrides[str_replace('.', '__', $permission->name)] ?? 'default';
                        @endphp
                        <tr class="hover:bg-neutral-50 dark:hover:bg-zinc-700/30">
                            <td class="py-2.5 text-neutral-700 dark:text-neutral-300">
                                {{ $permission->label }}
                                <span class="ml-1 text-xs text-neutral-400 font-mono">{{ $permission->name }}</span>
                            </td>
                            <td class="py-2.5 text-center">
                                <span class="text-xs px-2 py-0.5 rounded-full {{ $isDefault ? 'bg-green-100 text-green-700 dark:bg-green-900/50 dark:text-green-400' : 'bg-neutral-100 text-neutral-500 dark:bg-zinc-700 dark:text-neutral-400' }}">
                                    {{ $isDefault ? 'Sim' : 'Não' }}
                                </span>
                            </td>
                            <td class="py-2.5 text-center">
                                <flux:select
                                    wire:model="overrides.{{ str_replace('.', '__', $permission->name) }}"
                                >
                                    <flux:select.option value="default">Padrão</flux:select.option>
                                    <flux:select.option value="grant">Conceder</flux:select.option>
                                    <flux:select.option value="deny">Negar</flux:select.option>
                                </flux:select>
                            </td>
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>

        <div class="flex justify-end pt-2">
            <button
                wire:click="save"
                wire:loading.attr="disabled"
                class="inline-flex items-center gap-2 bg-amber-500 text-white text-sm font-medium px-4 py-2 rounded-lg hover:bg-amber-600 disabled:opacity-50"
            >
                <span wire:loading.remove wire:target="save">Salvar Permissões</span>
                <span wire:loading wire:target="save">Salvando...</span>
            </button>
        </div>
    </div>
</div>
