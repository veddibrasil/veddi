<div class="space-y-6">
    @include('livewire.super-admin.marketing.partials.header', [
        'title' => 'Contatos externos',
        'description' => 'Listas de possíveis clientes que ainda não têm conta na VEDDI. Importe um CSV ou cole os e-mails.',
    ])

    @include('livewire.super-admin.marketing.partials.notice')

    <div class="flex justify-end">
        <flux:button variant="primary" icon="plus" wire:click="create">Nova lista</flux:button>
    </div>

    <div class="overflow-x-auto rounded-xl border bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
        <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-left text-xs uppercase tracking-wide text-neutral-500 dark:bg-zinc-700/50 dark:text-neutral-400">
                <tr>
                    <th class="px-4 py-3">Lista e origem</th>
                    <th class="px-4 py-3 text-right">Contatos</th>
                    <th class="px-4 py-3">Criada em</th>
                    <th class="px-4 py-3 text-right">Ações</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100 dark:divide-zinc-700">
                @forelse($lists as $list)
                    <tr wire:key="contact-list-{{ $list->id }}">
                        <td class="px-4 py-3">
                            <a href="{{ route('superadmin.marketing.contacts.show', $list) }}" wire:navigate class="font-medium text-neutral-800 hover:underline dark:text-neutral-100">{{ $list->name }}</a>
                            <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">{{ $list->source }}</p>
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ number_format($list->contacts_count, 0, ',', '.') }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-neutral-500 dark:text-neutral-400">{{ $list->created_at->format('d/m/Y') }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right">
                            <flux:button size="sm" :href="route('superadmin.marketing.contacts.show', $list)" wire:navigate>Gerenciar contatos</flux:button>
                            <flux:button size="sm" :href="route('superadmin.marketing.campaigns.create', ['lista' => $list->id])" wire:navigate>Criar campanha</flux:button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-10 text-center text-neutral-500 dark:text-neutral-400">Crie uma lista para importar os e-mails dos possíveis clientes e preparar sua primeira campanha externa.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $lists->links() }}

    <flux:modal wire:model.self="showForm" class="w-full max-w-lg">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg" level="2">Nova lista de contatos externos</flux:heading>
            <flux:input wire:model="name" label="Nome da lista" placeholder="Ex.: Interessados no PDV" />
            <flux:textarea wire:model="source" label="Origem dos contatos" placeholder="Ex.: Formulário de interesse do site" rows="3" />
            <flux:checkbox wire:model="consent" label="Confirmo que estes contatos autorizaram receber e-mails da VEDDI." />
            @error('consent') <p class="text-xs text-red-500">{{ $message }}</p> @enderror
            <div class="flex flex-wrap justify-end gap-3">
                <flux:modal.close><flux:button variant="ghost">Cancelar</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save">Criar lista</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
