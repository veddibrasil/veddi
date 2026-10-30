{{-- Cardápio: prévia com pendências, publicação opt-in (substitui o cardápio da loja na 99) e resultado do último envio. --}}
@php $preview = $this->menuPreview; @endphp
<div @if($data['catalog_busy']) wire:poll.5s="refreshCatalogStatus" @endif>
<x-admin.form-card padding="p-6 space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h3 class="font-semibold text-neutral-800 dark:text-neutral-100">Cardápio na 99Food</h3>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">
                @if($data['catalog_published'])
                    Publicado. Alterações nos produtos vão sozinhas (o cardápio inteiro é reenviado, até 1 vez por minuto).
                @else
                    Ainda não publicado. Ao publicar, o cardápio desta filial substitui o cardápio atual da loja na 99Food.
                @endif
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            @if($data['catalog_published'])
                <flux:button wire:click="syncCatalogNow" wire:target="syncCatalogNow" icon="arrow-path" :disabled="$data['catalog_busy']">Enviar agora</flux:button>
            @else
                <flux:button wire:click="publishCatalog" wire:target="publishCatalog" variant="primary" :disabled="$data['catalog_busy']">Publicar cardápio na 99Food</flux:button>
            @endif
            @if($data['catalog_task_id'])
                <flux:button wire:click="checkCatalogTask" wire:target="checkCatalogTask" variant="ghost">Consultar último envio</flux:button>
            @endif
        </div>
    </div>

    @if($data['catalog_sync_status'])
        <x-admin.alert :variant="match($data['catalog_sync_status']) { 'success' => 'success', 'failed' => 'danger', 'partial' => 'warning', default => 'info' }" icon="information-circle">
            Último envio: {{ match($data['catalog_sync_status']) { 'queued' => 'na fila', 'running' => 'processando na 99Food', 'success' => 'aceito', 'partial' => 'aceito com pendências', 'failed' => 'recusado', default => $data['catalog_sync_status'] } }}
            @if($data['catalog_synced_at']) · {{ $data['catalog_synced_at'] }} @endif
            @if($data['catalog_sync_error']) <br>{{ $data['catalog_sync_error'] }} @endif
        </x-admin.alert>
    @endif

    @if($preview)
        <p class="text-sm text-neutral-700 dark:text-neutral-200">
            Vai para a 99Food: <strong>{{ $preview['items'] }}</strong> {{ $preview['items'] === 1 ? 'produto' : 'produtos' }} em {{ $preview['categories'] }} {{ $preview['categories'] === 1 ? 'categoria' : 'categorias' }}, {{ $preview['groups'] }} {{ $preview['groups'] === 1 ? 'grupo' : 'grupos' }} de complemento.
        </p>
        @if($preview['issues'] !== [])
            <div class="space-y-1">
                <p class="text-sm font-medium text-red-700 dark:text-red-400">Ficam de fora até corrigir:</p>
                <ul class="list-disc pl-5 text-sm text-neutral-700 dark:text-neutral-300">
                    @foreach($preview['issues'] as $issue)
                        <li><strong>{{ $issue['name'] }}</strong>: {{ $issue['reason'] }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
        @if($preview['warnings'] !== [])
            <div class="space-y-1">
                <p class="text-sm font-medium text-amber-700 dark:text-amber-400">Vão com ressalva:</p>
                <ul class="list-disc pl-5 text-sm text-neutral-700 dark:text-neutral-300">
                    @foreach($preview['warnings'] as $warning)
                        <li><strong>{{ $warning['name'] }}</strong>: {{ $warning['reason'] }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
    @endif
    <p class="text-xs text-neutral-500 dark:text-neutral-400">Só produtos ativos, desta filial e marcados como "Disponível na 99Food" entram. Recomendado publicar primeiro numa loja de teste.</p>
</x-admin.form-card>
</div>
