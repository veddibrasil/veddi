{{-- Cardápio: a Keeta busca no nosso endpoint (pull); publicação é opt-in e bloqueia a edição manual no portal da Keeta. --}}
@php $preview = $this->menuPreview; @endphp
<x-admin.form-card padding="p-6 space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h3 class="font-semibold text-neutral-800 dark:text-neutral-100">Cardápio na Keeta</h3>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">
                @if($data['catalog_published'])
                    Publicado. A Keeta busca o cardápio desta filial no VEDDI e é avisada a cada alteração nos produtos.
                @else
                    Ainda não publicado. Ao publicar, o cardápio desta filial passa a valer na Keeta e a edição manual no portal da Keeta fica bloqueada.
                @endif
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            @if($data['catalog_published'])
                <flux:button wire:click="syncCatalogNow" wire:target="syncCatalogNow" icon="arrow-path">Atualizar agora</flux:button>
            @else
                <flux:button wire:click="publishCatalog" wire:target="publishCatalog" variant="primary">Publicar cardápio na Keeta</flux:button>
            @endif
        </div>
    </div>

    @if($data['catalog_sync_status'])
        <x-admin.alert :variant="match($data['catalog_sync_status']) { 'synced' => 'success', 'failed' => 'danger', default => 'info' }" icon="information-circle">
            Último aviso: {{ match($data['catalog_sync_status']) { 'queued' => 'na fila', 'running' => 'enviando', 'synced' => 'aceito pela Keeta', 'failed' => 'recusado', default => $data['catalog_sync_status'] } }}
            @if($data['catalog_synced_at']) · {{ $data['catalog_synced_at'] }} @endif
            @if($data['catalog_sync_error']) <br>{{ $data['catalog_sync_error'] }} @endif
        </x-admin.alert>
    @endif

    @if($data['last_menu_fetched_at'] ?? null)
        <p class="text-sm text-neutral-600 dark:text-neutral-300">Última busca do cardápio pela Keeta: {{ $data['last_menu_fetched_at'] }}</p>
    @endif

    @if($preview)
        <p class="text-sm text-neutral-700 dark:text-neutral-200">
            Vai para a Keeta: <strong>{{ $preview['items'] }}</strong> {{ $preview['items'] === 1 ? 'produto' : 'produtos' }} em {{ $preview['categories'] }} {{ $preview['categories'] === 1 ? 'categoria' : 'categorias' }}, {{ $preview['groups'] }} {{ $preview['groups'] === 1 ? 'grupo' : 'grupos' }} de complemento.
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
        @if($preview['hours'] === [])
            <x-admin.alert variant="warning" icon="exclamation-triangle">
                A filial não tem horário de funcionamento: sem horário, a loja não abre na Keeta. Ajuste em Filiais.
            </x-admin.alert>
        @endif
    @endif
    <p class="text-xs text-neutral-500 dark:text-neutral-400">Só produtos ativos, desta filial e marcados como "Disponível na Keeta" entram. Horários vêm do cadastro da filial.</p>
</x-admin.form-card>
