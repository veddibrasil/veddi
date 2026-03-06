<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100">Empresas</h1>
        <a href="{{ route('superadmin.companies.create') }}"
            class="inline-flex items-center px-4 py-2 bg-amber-500 text-white text-sm font-medium rounded-lg hover:bg-amber-600 transition">
            + Nova empresa
        </a>
    </div>

    @if(session('status'))
        <div class="bg-green-50 border border-green-200 text-green-700 rounded-lg px-4 py-3 text-sm dark:bg-green-900/30 dark:border-green-700 dark:text-green-400">
            {{ session('status') }}
        </div>
    @endif

    <div class="flex gap-3">
        <flux:input wire:model.live="search" placeholder="Buscar empresa..." class="max-w-sm" />
    </div>

    <div class="bg-white border rounded-xl shadow-sm overflow-hidden dark:bg-zinc-800 dark:border-zinc-700">
        <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-neutral-500 text-xs uppercase tracking-wide dark:bg-zinc-700/50 dark:text-neutral-400">
                <tr>
                    <th class="px-4 py-3 text-left">Empresa</th>
                    <th class="px-4 py-3 text-left">Slug</th>
                    <th class="px-4 py-3 text-center">Filiais</th>
                    <th class="px-4 py-3 text-center">Pedidos</th>
                    <th class="px-4 py-3 text-center">Status</th>
                    <th class="px-4 py-3 text-right">Ações</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100 dark:divide-zinc-700">
                @forelse($companies as $company)
                    <tr class="hover:bg-neutral-50 transition dark:hover:bg-zinc-700/50">
                        <td class="px-4 py-3 font-medium text-neutral-800 dark:text-neutral-100">
                            <div class="flex items-center gap-2">
                                @if($company->logo_path)
                                    <img src="{{ asset('storage/' . $company->logo_path) }}" class="w-7 h-7 rounded-full object-cover">
                                @else
                                    <div class="w-7 h-7 rounded-full flex items-center justify-center text-white text-xs font-bold"
                                         style="background: {{ $company->primary_color }}">
                                        {{ mb_substr($company->name, 0, 1) }}
                                    </div>
                                @endif
                                {{ $company->name }}
                            </div>
                        </td>
                        <td class="px-4 py-3 text-neutral-500 font-mono text-xs dark:text-neutral-400">{{ $company->slug }}</td>
                        <td class="px-4 py-3 text-center text-neutral-700 dark:text-neutral-300">{{ $company->branches_count }}</td>
                        <td class="px-4 py-3 text-center text-neutral-700 dark:text-neutral-300">{{ $company->orders_count }}</td>
                        <td class="px-4 py-3 text-center">
                            <button wire:click="toggleActive({{ $company->id }})"
                                class="px-2 py-0.5 rounded-full text-xs font-medium {{ $company->active ? 'bg-green-100 text-green-700 dark:bg-green-900/50 dark:text-green-400' : 'bg-red-100 text-red-700 dark:bg-red-900/50 dark:text-red-400' }}">
                                {{ $company->active ? 'Ativa' : 'Inativa' }}
                            </button>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('superadmin.companies.edit', $company) }}"
                                class="text-amber-600 hover:text-amber-800 text-xs font-medium mr-3 dark:text-amber-400 dark:hover:text-amber-300">Editar</a>
                            <button wire:click="delete({{ $company->id }})"
                                wire:confirm="Excluir esta empresa? Todos os dados relacionados serão apagados."
                                class="text-red-400 hover:text-red-600 text-xs dark:hover:text-red-300">Excluir</button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-neutral-400 dark:text-neutral-500">Nenhuma empresa encontrada.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $companies->links() }}
</div>
