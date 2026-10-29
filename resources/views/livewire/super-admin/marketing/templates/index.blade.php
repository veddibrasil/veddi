<div class="space-y-6">
    @include('livewire.super-admin.marketing.partials.header', [
        'title' => 'Templates',
        'description' => 'Layouts reutilizáveis com blocos e variáveis. A campanha copia o conteúdo ao usar.',
        'actionRoute' => 'superadmin.marketing.templates.create',
        'actionLabel' => 'Novo template',
    ])

    @include('livewire.super-admin.marketing.partials.notice')

    <div class="flex flex-wrap items-center justify-between gap-3">
        <flux:input wire:model.live.debounce.300ms="search" placeholder="Buscar template..." class="max-w-sm" icon="magnifying-glass" />
        <flux:button icon="sparkles" wire:click="installDefaults">Adicionar modelos padrão</flux:button>
    </div>

    <div class="bg-white border rounded-xl shadow-sm overflow-x-auto dark:bg-zinc-800 dark:border-zinc-700">
        <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-neutral-500 text-xs uppercase tracking-wide dark:bg-zinc-700/50 dark:text-neutral-400">
                <tr>
                    <th class="px-4 py-3 text-left">Template</th>
                    <th class="px-4 py-3 text-right">Blocos</th>
                    <th class="px-4 py-3 text-right">Campanhas</th>
                    <th class="px-4 py-3 text-left">Atualizado</th>
                    <th class="px-4 py-3 text-right">Ações</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100 dark:divide-zinc-700">
                @forelse($templates as $template)
                    <tr wire:key="template-{{ $template->id }}" class="hover:bg-neutral-50 transition dark:hover:bg-zinc-700/50">
                        <td class="px-4 py-3">
                            <a href="{{ route('superadmin.marketing.templates.edit', $template) }}" wire:navigate class="font-medium text-neutral-800 hover:text-amber-600 dark:text-neutral-100 dark:hover:text-amber-400">{{ $template->name }}</a>
                            @if($template->subject_default)
                                <p class="text-xs text-neutral-400 dark:text-neutral-500">{{ $template->subject_default }}</p>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ count($template->blocks ?? []) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ $template->campaigns_count }}</td>
                        <td class="px-4 py-3 text-xs text-neutral-500 dark:text-neutral-400">{{ $template->updated_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}</td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <flux:button size="sm" variant="ghost" icon="pencil-square" :href="route('superadmin.marketing.templates.edit', $template)" wire:navigate aria-label="Editar" />
                            <flux:button size="sm" variant="ghost" icon="document-duplicate" wire:click="duplicate({{ $template->id }})" aria-label="Duplicar" />
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="confirmDelete({{ $template->id }})" aria-label="Excluir" />
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-10 text-center text-neutral-400 dark:text-neutral-500">Nenhum template ainda. Use “Adicionar modelos padrão” para começar com modelos prontos.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $templates->links() }}

    <flux:modal wire:model.self="confirmingDelete" class="max-w-sm">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">Excluir template?</flux:heading>
                <flux:subheading class="mt-1">Campanhas já criadas mantêm o conteúdo delas.</flux:subheading>
            </div>
            <div class="flex justify-end gap-3">
                <flux:modal.close><flux:button variant="ghost">Cancelar</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="delete">Excluir</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
