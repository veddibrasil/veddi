@php
    $preview = $this->catalogPreview;
    $syncing = in_array($catalogSyncStatus, ['queued', 'running'], true);
    [$badgeColor, $badgeLabel] = match (true) {
        $syncing => ['amber', 'Enviando…'],
        $catalogSyncStatus === 'failed' => ['red', 'Envio falhou'],
        $catalogSyncStatus === 'partial' => ['yellow', 'Publicado com pendências'],
        $catalogPublished => ['green', 'Publicado'],
        default => ['zinc', 'Não publicado'],
    };
@endphp

<x-admin.form-card padding="p-6 space-y-5">
    <div @if($syncing) wire:poll.3s="refreshCatalogStatus" @endif class="space-y-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0 flex-1 basis-64">
                <h2 class="font-semibold text-neutral-800 dark:text-neutral-100">Cardápio no iFood</h2>
                <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
                    @if($catalogPublished)
                        Preço e disponibilidade são atualizados sozinhos quando você muda o produto ou o estoque; o cardápio completo é reenviado todo dia às 05:00.
                        @if($lastSyncedAt) Último envio completo {{ $lastSyncedAt }}. @endif
                    @else
                        O cardápio desta filial ainda não foi enviado ao iFood. Confira a prévia e publique: a partir daí ele segue sincronizado sozinho.
                    @endif
                </p>
            </div>
            <flux:badge :color="$badgeColor" class="shrink-0">{{ $badgeLabel }}</flux:badge>
        </div>

        @if($catalogSyncError && $catalogSyncStatus === 'failed')
            <x-admin.alert variant="danger" icon="exclamation-triangle" role="alert">
                {{ $catalogSyncError }}
            </x-admin.alert>
        @elseif($catalogSyncError)
            <x-admin.alert variant="warning" icon="exclamation-triangle" role="alert">
                {{ $catalogSyncError }}
            </x-admin.alert>
        @endif

        <dl class="grid grid-cols-3 gap-3 text-center">
            <div class="rounded-lg bg-neutral-50 p-3 dark:bg-zinc-900/60">
                <dt class="text-xs text-neutral-600 dark:text-neutral-400">Produtos para o iFood</dt>
                <dd class="text-xl font-bold tabular-nums text-neutral-800 dark:text-neutral-100">{{ $preview['total'] }}</dd>
            </div>
            <div class="rounded-lg bg-neutral-50 p-3 dark:bg-zinc-900/60">
                <dt class="text-xs text-neutral-600 dark:text-neutral-400">Categorias</dt>
                <dd class="text-xl font-bold tabular-nums text-neutral-800 dark:text-neutral-100">{{ $preview['categories'] }}</dd>
            </div>
            <div class="rounded-lg bg-neutral-50 p-3 dark:bg-zinc-900/60">
                <dt class="text-xs text-neutral-600 dark:text-neutral-400">Já no iFood</dt>
                <dd class="text-xl font-bold tabular-nums text-neutral-800 dark:text-neutral-100">{{ $preview['published'] }}</dd>
            </div>
        </dl>

        <p class="text-sm text-neutral-500 dark:text-neutral-400">
            Entram os produtos ativos, disponíveis nesta filial e com “Disponível no iFood” marcado no cadastro.
        </p>

        @if($preview['issues'])
            <div class="space-y-2">
                <h3 class="text-sm font-semibold text-neutral-800 dark:text-neutral-100">Produtos que precisam de ajuste ({{ count($preview['issues']) }})</h3>
                <ul class="divide-y divide-neutral-100 rounded-lg border border-neutral-200 dark:divide-zinc-700 dark:border-zinc-700">
                    @foreach(array_slice($preview['issues'], 0, 20) as $issue)
                        <li class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm" wire:key="catalog-issue-{{ $issue['id'] }}">
                            <span class="min-w-0">
                                <span class="font-medium text-neutral-800 dark:text-neutral-100">{{ $issue['name'] }}</span>
                                <span class="block text-yellow-800 dark:text-yellow-300">{{ $issue['problem'] }}</span>
                            </span>
                            <a href="{{ route('admin.products.edit', $issue['id']) }}" wire:navigate class="text-sm font-medium text-amber-700 underline-offset-2 hover:underline dark:text-amber-400">Editar produto</a>
                        </li>
                    @endforeach
                </ul>
                @if(count($preview['issues']) > 20)
                    <p class="text-xs text-neutral-500 dark:text-neutral-400">E mais {{ count($preview['issues']) - 20 }} produto(s).</p>
                @endif
            </div>
        @endif

        <div class="flex flex-wrap gap-3">
            @if($status !== 'active')
                <p class="text-sm text-neutral-500 dark:text-neutral-400">Retome a integração para enviar o cardápio.</p>
            @elseif($catalogPublished)
                <flux:button wire:click="syncCatalogNow" wire:target="syncCatalogNow" :disabled="$syncing" icon="arrow-path">Sincronizar cardápio agora</flux:button>
            @else
                <flux:button wire:click="syncCatalogNow" wire:target="syncCatalogNow" :disabled="$syncing || $preview['total'] === 0" variant="primary">Publicar cardápio no iFood</flux:button>
            @endif
        </div>
    </div>
</x-admin.form-card>
