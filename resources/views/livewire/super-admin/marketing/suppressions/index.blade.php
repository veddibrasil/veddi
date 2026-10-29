<div class="space-y-6">
    @include('livewire.super-admin.marketing.partials.header', [
        'title' => 'Supressões',
        'description' => 'Quem está aqui nunca recebe e-mail marketing. Bounces permanentes, spam e descadastros entram sozinhos.',
    ])

    @include('livewire.super-admin.marketing.partials.notice')

    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        @foreach($reasons as $value => $label)
            <div class="rounded-lg border border-neutral-200 bg-white px-4 py-3 dark:border-zinc-700 dark:bg-zinc-800">
                <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ $label }}</p>
                <p class="text-lg font-semibold tabular-nums text-neutral-800 dark:text-neutral-100">{{ number_format($totals[$value] ?? 0, 0, ',', '.') }}</p>
            </div>
        @endforeach
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="bg-white border rounded-xl shadow-sm p-5 space-y-3 dark:bg-zinc-800 dark:border-zinc-700">
            <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">Incluir manualmente</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <flux:input wire:model="newEmail" type="email" placeholder="email@empresa.com" aria-label="E-mail" />
                    @error('newEmail') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <flux:input wire:model="newNote" placeholder="Observação (opcional)" aria-label="Observação" />
            </div>
            <flux:button wire:click="add" icon="no-symbol">Suprimir</flux:button>
        </div>

        <div class="bg-white border rounded-xl shadow-sm p-5 space-y-3 dark:bg-zinc-800 dark:border-zinc-700">
            <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">Importar CSV</h2>
            <p class="text-xs text-neutral-500 dark:text-neutral-400">Um e-mail por linha (ou na primeira coluna). Até 5 MB.</p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <input type="file" wire:model="csv" accept=".csv,.txt,text/csv,text/plain" class="block w-full text-sm text-neutral-600 file:mr-3 file:rounded-lg file:border-0 file:bg-neutral-100 file:px-3 file:py-2 file:text-sm file:font-medium hover:file:bg-neutral-200 dark:text-neutral-300 dark:file:bg-zinc-700 dark:file:text-neutral-200" aria-label="Arquivo CSV" />
                    @error('csv') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <flux:input wire:model="importNote" placeholder="Origem (opcional)" aria-label="Origem da lista" />
            </div>
            <flux:button wire:click="import" icon="arrow-up-tray" wire:loading.attr="disabled" wire:target="csv,import">Importar</flux:button>
        </div>
    </div>

    <div class="bg-white border rounded-xl shadow-sm p-5 space-y-4 dark:bg-zinc-800 dark:border-zinc-700">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">Limpeza da lista</h2>
                <p class="text-xs text-neutral-500 dark:text-neutral-400 mt-1">Procura, entre os usuários das empresas, endereços que só geram bounce ou que ninguém lê. Limpar antes do primeiro disparo protege a reputação do domínio.</p>
            </div>
            <flux:button icon="magnifying-glass" wire:click="scanCleanup" wire:loading.attr="disabled" wire:target="scanCleanup">{{ $cleanupScanned ? 'Verificar de novo' : 'Verificar lista' }}</flux:button>
        </div>

        @if($cleanupScanned)
            <div class="divide-y divide-neutral-100 dark:divide-zinc-700" data-test="cleanup-results">
                @foreach($this->cleanup as $key => $rule)
                    @php($found = count($rule['emails']))
                    <div wire:key="cleanup-{{ $key }}" class="flex flex-wrap items-center justify-between gap-3 py-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <p class="text-sm font-medium text-neutral-800 dark:text-neutral-100">{{ $rule['label'] }}</p>
                                <flux:badge size="sm" :color="$found ? 'amber' : 'zinc'">{{ number_format($found, 0, ',', '.') }}</flux:badge>
                            </div>
                            <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ $rule['description'] }}</p>
                            @if($found)
                                <p class="text-xs text-neutral-400 dark:text-neutral-500 mt-1 truncate">{{ implode(', ', array_slice($rule['emails'], 0, 5)) }}{{ $found > 5 ? ' e mais '.($found - 5) : '' }}</p>
                            @endif
                        </div>
                        @if($found)
                            <flux:button size="sm" icon="no-symbol" wire:click="confirmCleanup('{{ $key }}')">Suprimir {{ number_format($found, 0, ',', '.') }}</flux:button>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <div class="flex flex-wrap gap-3">
        <flux:input wire:model.live.debounce.300ms="search" placeholder="Buscar e-mail..." class="max-w-sm" icon="magnifying-glass" />
        <flux:select wire:model.live="reason" class="max-w-52" aria-label="Filtrar por motivo">
            <flux:select.option value="">Todos os motivos</flux:select.option>
            @foreach($reasons as $value => $label)
                <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <div class="bg-white border rounded-xl shadow-sm overflow-x-auto dark:bg-zinc-800 dark:border-zinc-700">
        <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-neutral-500 text-xs uppercase tracking-wide dark:bg-zinc-700/50 dark:text-neutral-400">
                <tr>
                    <th class="px-4 py-3 text-left">E-mail</th>
                    <th class="px-4 py-3 text-center">Motivo</th>
                    <th class="px-4 py-3 text-left">Origem</th>
                    <th class="px-4 py-3 text-left">Desde</th>
                    <th class="px-4 py-3 text-right">Ações</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100 dark:divide-zinc-700">
                @forelse($suppressions as $suppression)
                    <tr wire:key="suppression-{{ $suppression->id }}">
                        <td class="px-4 py-3 text-neutral-800 dark:text-neutral-100">{{ $suppression->email }}</td>
                        <td class="px-4 py-3 text-center"><flux:badge size="sm" :color="$suppression->reason->color()">{{ $suppression->reason->label() }}</flux:badge></td>
                        <td class="px-4 py-3 text-xs text-neutral-500 dark:text-neutral-400">
                            {{ $suppression->sourceCampaign?->name ?? $suppression->note ?? ($suppression->creator ? 'por '.$suppression->creator->name : '—') }}
                        </td>
                        <td class="px-4 py-3 text-xs text-neutral-500 dark:text-neutral-400">{{ $suppression->created_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}</td>
                        <td class="px-4 py-3 text-right">
                            <flux:button size="sm" variant="ghost" wire:click="confirmRemove({{ $suppression->id }})">Remover</flux:button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-10 text-center text-neutral-400 dark:text-neutral-500">Nenhum e-mail suprimido.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $suppressions->links() }}

    <flux:modal wire:model.self="confirmingCleanup" class="max-w-md">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">Suprimir {{ $cleanupRule ? mb_strtolower($this->cleanup[$cleanupRule]['label'] ?? '') : '' }}?</flux:heading>
                <flux:subheading class="mt-1">
                    {{ number_format(count($this->cleanup[$cleanupRule]['emails'] ?? []), 0, ',', '.') }} e-mail(s) entram como supressão manual e deixam de receber e-mail marketing. Dá para remover depois, um a um, com motivo.
                </flux:subheading>
            </div>
            <div class="flex justify-end gap-3">
                <flux:modal.close><flux:button variant="ghost">Cancelar</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="runCleanup">Suprimir</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal wire:model.self="confirmingRemove" class="max-w-md">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">Remover da supressão?</flux:heading>
                <flux:subheading class="mt-1">O e-mail volta a poder receber campanhas. Remover quem reclamou de spam prejudica a reputação do domínio.</flux:subheading>
            </div>
            <div>
                <flux:textarea wire:model="removalReason" label="Motivo (fica registrado)" rows="2" />
                @error('removalReason') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="flex justify-end gap-3">
                <flux:modal.close><flux:button variant="ghost">Cancelar</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="remove">Remover</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
