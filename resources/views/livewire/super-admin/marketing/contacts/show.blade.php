<div class="space-y-6">
    @include('livewire.super-admin.marketing.partials.header', [
        'title' => $list->name,
        'description' => 'Contatos externos · '.$list->source,
    ])

    @include('livewire.super-admin.marketing.partials.notice')

    <div class="flex flex-wrap justify-between gap-3">
        <flux:button :href="route('superadmin.marketing.contacts.index')" wire:navigate icon="arrow-left">Todas as listas</flux:button>
        <div class="flex flex-wrap gap-3">
            <flux:button :href="route('superadmin.marketing.campaigns.create', ['lista' => $list->id])" wire:navigate variant="primary" icon="envelope">Criar campanha para esta lista</flux:button>
            <flux:button wire:click="$set('confirmingDelete', true)" variant="danger" icon="trash">Excluir lista</flux:button>
        </div>
    </div>

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach(['total' => 'Contatos na lista', 'eligible' => 'E-mails externos aptos', 'suppressed' => 'Descadastros e bloqueios', 'users' => 'Já têm conta na VEDDI'] as $key => $label)
            <div class="rounded-xl border bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
                <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ $label }}</p>
                <p class="mt-1 text-2xl font-bold tabular-nums text-neutral-800 dark:text-neutral-100">{{ number_format($this->numbers[$key], 0, ',', '.') }}</p>
            </div>
        @endforeach
    </div>
    <p class="text-xs text-neutral-500 dark:text-neutral-400">Quem já tem conta recebe pelo público de usuários, conforme suas preferências. Para somar os donos, marque essa opção no público da campanha.</p>

    <div class="grid items-start gap-4 xl:grid-cols-3">
        @include('livewire.super-admin.marketing.contacts._paste-import')
        <form wire:submit="import" class="min-w-0 space-y-4 rounded-xl border bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
            <h2 class="font-semibold text-neutral-800 dark:text-neutral-100">Importar arquivo CSV</h2>
            <p class="text-sm text-neutral-500 dark:text-neutral-400">Até 5 MB. Use uma linha por contato, com as colunas e-mail, nome e empresa. Nome e empresa são opcionais.</p>
            <flux:input type="file" wire:model="csv" label="Arquivo de contatos" accept=".csv,.txt,text/csv,text/plain" />
            <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="csv,import">Importar CSV</flux:button>
            <p wire:loading wire:target="csv,import" role="status" class="text-xs text-neutral-500 dark:text-neutral-400">Carregando contatos…</p>
        </form>
    </div>
    <p class="text-xs text-neutral-500 dark:text-neutral-400">Duplicados na mesma lista são ignorados. A importação informa endereços inválidos ou ignorados; descadastros e bloqueios são descontados antes do disparo.</p>

    <flux:input wire:model.live.debounce.300ms="search" label="Buscar contato" placeholder="E-mail, nome ou empresa" class="max-w-md" />

    <div class="overflow-x-auto rounded-xl border bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
        <table class="w-full text-left text-sm">
            <thead class="bg-neutral-50 text-xs uppercase tracking-wide text-neutral-500 dark:bg-zinc-700/50 dark:text-neutral-400">
                <tr>
                    <th class="px-4 py-3">E-mail</th>
                    <th class="px-4 py-3">Nome / empresa</th>
                    <th class="px-4 py-3">Situação para envio externo</th>
                    <th class="px-4 py-3 text-right">Ações</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100 dark:divide-zinc-700">
                @forelse($contacts as $contact)
                    <tr wire:key="contact-{{ $contact->id }}">
                        <td class="px-4 py-3 text-neutral-800 dark:text-neutral-100">{{ $contact->email }}</td>
                        <td class="px-4 py-3">
                            <p class="text-neutral-700 dark:text-neutral-200">{{ $contact->name ?? '—' }}</p>
                            <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ $contact->company_name }}</p>
                        </td>
                        <td class="px-4 py-3 text-xs">
                            @if(isset($suppressed[$contact->email]))
                                <span class="text-red-600 dark:text-red-400">Descadastrado ou bloqueado</span>
                            @elseif(isset($registered[$contact->email]))
                                <span class="text-neutral-500 dark:text-neutral-400">Já tem conta na VEDDI</span>
                            @else
                                <span class="text-emerald-700 dark:text-emerald-400">Apto para envio</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <flux:button size="sm" variant="ghost" wire:click="removeContact({{ $contact->id }})" wire:confirm="Remover este contato da lista e dos envios associados?">Remover</flux:button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-10 text-center text-neutral-500 dark:text-neutral-400">{{ $search !== '' ? 'Nenhum contato encontrado para esta busca.' : 'Importe um CSV ou cole os e-mails para começar.' }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $contacts->links() }}

    <flux:modal wire:model.self="confirmingDelete" class="max-w-sm">
        <div class="space-y-5">
            <flux:heading size="lg" level="2">Excluir esta lista?</flux:heading>
            <flux:subheading>Os contatos desta lista e seus registros de destinatário serão removidos. Envios pendentes para esses contatos serão interrompidos.</flux:subheading>
            <div class="flex flex-wrap justify-end gap-3">
                <flux:modal.close><flux:button variant="ghost">Cancelar</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="deleteList">Excluir lista</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
