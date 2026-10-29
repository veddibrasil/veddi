<div class="space-y-6">
    @include('livewire.super-admin.marketing.partials.header', [
        'title' => 'Segmentos',
        'description' => 'Filtros de público salvos. A contagem já desconta supressões e descadastros.',
    ])

    @include('livewire.super-admin.marketing.partials.notice')

    <div class="flex flex-wrap justify-end gap-3">
        <flux:button icon="sparkles" wire:click="installDefaults">Adicionar segmentos padrão</flux:button>
        <flux:button variant="primary" icon="plus" wire:click="create">Novo segmento</flux:button>
    </div>

    <div class="bg-white border rounded-xl shadow-sm overflow-x-auto dark:bg-zinc-800 dark:border-zinc-700">
        <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-neutral-500 text-xs uppercase tracking-wide dark:bg-zinc-700/50 dark:text-neutral-400">
                <tr>
                    <th class="px-4 py-3 text-left">Segmento</th>
                    <th class="px-4 py-3 text-right">Pessoas</th>
                    <th class="px-4 py-3 text-right">Campanhas</th>
                    <th class="px-4 py-3 text-right">Ações</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100 dark:divide-zinc-700">
                @forelse($segments as $segment)
                    <tr wire:key="segment-{{ $segment->id }}">
                        <td class="px-4 py-3">
                            <p class="font-medium text-neutral-800 dark:text-neutral-100">{{ $segment->name }}</p>
                            <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ implode(' · ', $segment->filterData()->describe()) }}</p>
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            {{ $segment->last_count !== null ? number_format($segment->last_count, 0, ',', '.') : '—' }}
                            @if($segment->last_counted_at)
                                <p class="text-[11px] text-neutral-400">{{ $segment->last_counted_at->diffForHumans() }}</p>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ $segment->campaigns_count }}</td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <flux:button size="sm" variant="ghost" icon="arrow-path" wire:click="recount({{ $segment->id }})" aria-label="Recontar" />
                            <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $segment->id }})" aria-label="Editar" />
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="confirmDelete({{ $segment->id }})" aria-label="Excluir" />
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-10 text-center text-neutral-400 dark:text-neutral-500">Nenhum segmento ainda. Use “Adicionar segmentos padrão” para começar com públicos prontos (Grátis, sem PDV, sem pedidos e outros).</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <flux:modal wire:model.self="showForm" class="w-full max-w-3xl">
        <div class="space-y-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <flux:heading size="lg">{{ $editingId ? 'Editar segmento' : 'Novo segmento' }}</flux:heading>
                <div class="rounded-lg bg-amber-50 px-3 py-1.5 text-right dark:bg-amber-900/20" wire:loading.class="opacity-50">
                    <p class="text-[11px] text-amber-700 dark:text-amber-300">Pessoas agora</p>
                    <p class="text-lg font-bold tabular-nums text-neutral-900 dark:text-neutral-100" data-test="segment-count">{{ number_format($this->liveCount, 0, ',', '.') }}</p>
                </div>
            </div>
            <div>
                <flux:input wire:model="name" label="Nome" placeholder="Ex.: PRO ativos com PDV" />
                @error('name') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            @include('livewire.super-admin.marketing.partials.audience-filters')
            <div class="flex justify-end gap-3">
                <flux:modal.close><flux:button variant="ghost">Cancelar</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="save">Salvar</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal wire:model.self="confirmingDelete" class="max-w-sm">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">Excluir segmento?</flux:heading>
                <flux:subheading class="mt-1">Campanhas que usam este segmento ficam sem público até escolher outro.</flux:subheading>
            </div>
            <div class="flex justify-end gap-3">
                <flux:modal.close><flux:button variant="ghost">Cancelar</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="delete">Excluir</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
