<section class="space-y-4 rounded-xl border border-neutral-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800" aria-labelledby="monthly-export-title">
    <div>
        <h2 id="monthly-export-title" class="font-semibold text-neutral-800 dark:text-neutral-100">Lote mensal para o escritório</h2>
        <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">Baixe um ZIP com os XMLs de NF-e e NFC-e e um relatório CSV das notas autorizadas e canceladas, pela data de emissão.</p>
        <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">XMLs de eventos de cancelamento, carta de correção e inutilização devem ser obtidos separadamente no provedor.</p>
    </div>
    <form wire:submit="generate" class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end">
        <flux:input type="month" label="Competência" wire:model="month" max="{{ now()->format('Y-m') }}" class="sm:max-w-48" />
        <flux:select label="Filial" wire:model="branchId" :disabled="$lockedBranchId !== null" class="sm:max-w-xs">
            @unless($lockedBranchId)<option value="">Todas as filiais</option>@endunless
            @foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach
        </flux:select>
        <flux:select label="Ambiente" wire:model="environment" class="sm:max-w-48">
            <option value="producao">Produção</option>
            <option value="homologacao">Homologação (teste)</option>
        </flux:select>
        <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="generate">Gerar lote mensal</flux:button>
    </form>
    @if($exports->isNotEmpty())
        <div @if($exports->contains('status', 'processing')) wire:poll.5s @endif class="space-y-2" aria-live="polite">
            @foreach($exports as $export)
                <div wire:key="fiscal-export-{{ $export->id }}" class="flex flex-col gap-2 rounded-lg bg-neutral-50 p-3 text-sm dark:bg-zinc-900 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <span class="font-medium">{{ \Carbon\Carbon::createFromFormat('!Y-m', $export->month)->format('m/Y') }} · {{ $export->environment === 'producao' ? 'Produção' : 'Homologação' }} · {{ $export->branch_id ? ($branches->firstWhere('id', $export->branch_id)?->name ?? 'Filial') : 'Todas as filiais' }}</span>
                        <p class="text-neutral-600 dark:text-neutral-400">
                            @if($export->status === 'processing') Preparando {{ count($export->note_ids) }} nota(s)…
                            @elseif($export->status === 'failed') Não foi possível gerar o lote. Gere novamente.
                            @else {{ $export->documents_count }} XML(s) incluído(s) · {{ $export->errors_count }} pendência(s).
                            @endif
                        </p>
                        @if($export->status === 'partial')<p class="text-amber-700 dark:text-amber-400">Lote incompleto. Confira as pendências no relatório antes de enviar.</p>@endif
                    </div>
                    @if(in_array($export->status, ['ready', 'partial']))
                        <flux:button href="{{ route('admin.fiscal.export', ['export' => $export->id]) }}" size="sm">{{ $export->status === 'partial' ? 'Baixar lote com pendências' : 'Baixar ZIP' }}</flux:button>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</section>
