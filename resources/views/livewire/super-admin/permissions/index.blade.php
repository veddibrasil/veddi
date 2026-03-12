<div class="max-w-5xl mx-auto py-8 px-4 space-y-6">

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">Permissões por Tipo de Usuário</h1>
            <p class="text-sm text-neutral-500 dark:text-neutral-400 mt-1">Gerencie quais permissões cada tipo de usuário possui por padrão.</p>
        </div>
    </div>

    @if(session('status'))
        <div class="bg-green-50 border border-green-200 text-green-700 text-sm px-4 py-3 rounded-lg dark:bg-green-900/20 dark:border-green-800 dark:text-green-400">
            {{ session('status') }}
        </div>
    @endif

    <div class="bg-white border rounded-xl shadow-sm dark:bg-zinc-800 dark:border-zinc-700 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-neutral-50 dark:bg-zinc-700/50">
                <tr>
                    <th class="text-left px-6 py-3 font-medium text-neutral-600 dark:text-neutral-300 w-1/2">Permissão</th>
                    @foreach($systemRoles as $role)
                        <th class="text-center px-4 py-3 font-medium text-neutral-600 dark:text-neutral-300">
                            {{ $role->name }}
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y dark:divide-zinc-700">
                @foreach($permissions as $group => $groupPermissions)
                    <tr class="bg-neutral-50/60 dark:bg-zinc-700/20">
                        <td colspan="{{ $systemRoles->count() + 1 }}" class="px-6 py-2">
                            <span class="text-xs font-semibold uppercase tracking-wide text-neutral-400 dark:text-neutral-500">
                                {{ ucfirst($group) }}
                            </span>
                        </td>
                    </tr>
                    @foreach($groupPermissions as $permission)
                        <tr class="hover:bg-neutral-50 dark:hover:bg-zinc-700/30">
                            <td class="px-6 py-3 text-neutral-700 dark:text-neutral-300">
                                {{ $permission->label }}
                                <span class="ml-2 text-xs text-neutral-400 dark:text-neutral-500 font-mono">{{ $permission->name }}</span>
                            </td>
                            @foreach($systemRoles as $role)
                                <td class="px-4 py-3 text-center">
                                    @php
                                        $hasPermission = in_array($permission->name, $rolePermissions[$role->slug] ?? []);
                                    @endphp
                                    <button
                                        wire:click="toggle('{{ $role->slug }}', '{{ $permission->name }}')"
                                        wire:loading.attr="disabled"
                                        class="w-6 h-6 rounded flex items-center justify-center mx-auto transition-colors
                                            {{ $hasPermission
                                                ? 'bg-amber-500 text-white hover:bg-amber-600'
                                                : 'bg-neutral-200 text-neutral-400 hover:bg-neutral-300 dark:bg-zinc-600 dark:hover:bg-zinc-500' }}"
                                    >
                                        @if($hasPermission)
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/>
                                            </svg>
                                        @endif
                                    </button>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
    </div>
</div>
