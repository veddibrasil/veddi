<div class="space-y-4"
    x-data="{}"
    x-init="$watch(() => $wire.deletingId, val => val ? $flux.modal('confirm-delete-branch').show() : $flux.modal('confirm-delete-branch').close())">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100">Filiais</h1>
        <a href="{{ route('admin.branches.create') }}"
            class="inline-flex items-center gap-1 bg-amber-500 text-white text-sm font-medium px-4 py-2 rounded-lg hover:bg-amber-600 transition-colors">
            + Nova filial
        </a>
    </div>

    @if (session('status'))
        <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg text-sm dark:bg-green-900/30 dark:border-green-700 dark:text-green-400">
            {{ session('status') }}
        </div>
    @endif

    <div class="bg-white border rounded-xl shadow-sm overflow-hidden dark:bg-zinc-800 dark:border-zinc-700">
        {{-- Cabeçalho de colunas --}}
        <div class="flex items-center justify-between px-4 py-2 border-b bg-neutral-50 dark:bg-zinc-700/50 dark:border-zinc-700">
            <span class="text-xs font-medium text-neutral-400 uppercase tracking-wide">Filial / Endereço</span>
            <span class="text-xs font-medium text-neutral-400 uppercase tracking-wide">Ações</span>
        </div>
        <div class="divide-y dark:divide-zinc-700">
            @forelse ($branches as $branch)
                <div class="flex items-center justify-between px-4 py-4">
                    <div>
                        <p class="font-semibold text-neutral-800 dark:text-neutral-100">{{ $branch->name }}</p>
                        <p class="text-sm text-neutral-500 dark:text-neutral-400">{{ $branch->address }}, {{ $branch->city }}</p>
                        <p class="text-xs text-neutral-400 dark:text-neutral-500">{{ $branch->opens_at }} – {{ $branch->closes_at }}
                            @if ($branch->phone) · {{ $branch->phone }} @endif
                        </p>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="text-xs px-2 py-0.5 rounded-full {{ $branch->active ? 'bg-green-100 text-green-700 dark:bg-green-900/50 dark:text-green-400' : 'bg-neutral-100 text-neutral-500 dark:bg-zinc-700 dark:text-neutral-400' }}">
                            {{ $branch->active ? 'Ativa' : 'Inativa' }}
                        </span>
                        <a href="{{ route('admin.branches.edit', $branch) }}"
                            class="text-xs text-amber-600 hover:underline dark:text-amber-400">Editar</a>
                        <a href="{{ route('admin.branches.delivery', $branch) }}"
                            class="text-xs text-blue-600 hover:underline dark:text-blue-400">Entrega</a>

                        <button wire:click="confirmDelete({{ $branch->id }})"
                            class="text-xs text-neutral-400 hover:text-red-600 dark:hover:text-red-300">Excluir</button>
                    </div>
                </div>
            @empty
                <div class="px-4 py-12 text-center">
                    <div class="text-3xl mb-2">🏪</div>
                    <p class="text-sm text-neutral-500 dark:text-neutral-400">Nenhuma filial cadastrada.</p>
                </div>
            @endforelse
        </div>
    </div>

    {{-- Modal de confirmação de exclusão --}}
    <flux:modal name="confirm-delete-branch" class="max-w-sm">
        <div class="space-y-5">
            <div class="flex items-start gap-4">
                <div class="w-10 h-10 rounded-full bg-red-100 dark:bg-red-900/30 flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
                    </svg>
                </div>
                <div>
                    <flux:heading size="lg">Excluir filial?</flux:heading>
                    <flux:subheading class="mt-1">Esta ação não pode ser desfeita. A filial será removida permanentemente.</flux:subheading>
                </div>
            </div>
            <div class="flex justify-end gap-3 pt-1">
                <flux:modal.close>
                    <flux:button wire:click="cancelDelete" variant="ghost">Cancelar</flux:button>
                </flux:modal.close>
                <flux:modal.close>
                    <flux:button wire:click="delete" variant="danger">Excluir</flux:button>
                </flux:modal.close>
            </div>
        </div>
    </flux:modal>
</div>
