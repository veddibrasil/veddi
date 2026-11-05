{{-- Conexão: autorização no portal da Keeta (o lojista escolhe as lojas) → escolha da loja desta filial → onboarding. --}}
<x-admin.form-card padding="p-6 space-y-5">
    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
            <h2 class="font-semibold text-neutral-800 dark:text-neutral-100">
                {{ $connectionState === 'connected' ? ($data['shop_name'] ?: 'Loja na Keeta') : 'Conexão com a Keeta' }}
            </h2>
            @if($currentBranch)
                <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">Filial {{ $currentBranch['name'] }}@if($connectionState === 'connected' && ($data['shop_address'] ?? null)) · {{ $data['shop_address'] }}@endif</p>
            @endif
        </div>
        @switch($connectionState)
            @case('connected')
                @if(($data['status'] ?? null) === 'paused')
                    <flux:badge color="yellow" class="shrink-0">Suspensa</flux:badge>
                @elseif(! ($data['store_open'] ?? true))
                    <flux:badge color="yellow" class="shrink-0">Loja fechada na Keeta</flux:badge>
                @else
                    <flux:badge color="green" class="shrink-0">Recebendo pedidos</flux:badge>
                @endif
                @break
            @case('pending_mapping')
                <flux:badge color="amber" class="shrink-0">Escolha a loja</flux:badge>
                @break
            @case('error')
                <flux:badge color="red" class="shrink-0">Reconexão necessária</flux:badge>
                @break
            @case('revoked')
                <flux:badge color="red" class="shrink-0">Autorização revogada</flux:badge>
                @break
            @case('pending_authorization')
                <flux:badge color="amber" class="shrink-0">Aguardando autorização</flux:badge>
                @break
            @default
                <flux:badge color="zinc" class="shrink-0">Não conectado</flux:badge>
        @endswitch
    </div>

    @if($connectionState === 'revoked')
        <x-admin.alert variant="danger" icon="exclamation-triangle" heading="Pedidos novos da Keeta não estão chegando" role="alert">
            A loja tirou a autorização do app VEDDI no portal da Keeta. Autorize de novo para voltar a receber pedidos.
        </x-admin.alert>
    @endif

    @if($connectionState === 'error')
        <x-admin.alert variant="danger" icon="exclamation-triangle" heading="A Keeta não confirmou o vínculo da loja" role="alert">
            Os pedidos podem não estar chegando. Tente ativar de novo; se persistir, autorize de novo no portal da Keeta.
        </x-admin.alert>
        <div class="flex flex-wrap gap-2">
            <flux:button wire:click="retryOnboarding" wire:target="retryOnboarding" variant="primary">Ativar de novo</flux:button>
            <flux:button wire:click="connect" wire:target="connect">Autorizar de novo</flux:button>
            <flux:button wire:click="confirmDisconnect" variant="ghost" class="text-red-600 dark:text-red-400">Desconectar</flux:button>
        </div>
    @endif

    @if(in_array($connectionState, ['not_connected', 'pending_authorization', 'revoked'], true))
        <p class="text-sm text-neutral-600 dark:text-neutral-300">
            Receba os pedidos da Keeta direto no painel. Quem autoriza é o responsável pela loja no portal da Keeta — nenhuma senha é digitada aqui.
        </p>
        @if($connectionState === 'not_connected')
            <div class="rounded-lg bg-neutral-50 p-4 text-sm dark:bg-zinc-900/60">
                <p class="font-medium text-neutral-700 dark:text-neutral-200">Antes de começar</p>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-neutral-600 dark:text-neutral-300">
                    <li>Tenha em mãos o acesso da marca ou da loja no portal da Keeta.</li>
                    <li>No portal, escolha a loja que corresponde a esta filial e confirme.</li>
                    <li>A Keeta exige confirmar cada pedido em até 5 minutos. Com o aceite automático (ligado por padrão), o VEDDI confirma sozinho.</li>
                </ul>
            </div>
        @endif
        <div class="flex flex-wrap gap-2">
            <flux:button wire:click="connect" wire:target="connect" variant="primary" icon-trailing="arrow-top-right-on-square">
                {{ match($connectionState) { 'revoked' => 'Autorizar de novo', 'pending_authorization' => 'Abrir autorização de novo', default => 'Conectar Keeta' } }}
            </flux:button>
            @if($connectionState === 'pending_authorization')
                <flux:button wire:click="cancelConnection" wire:target="cancelConnection" variant="ghost">Cancelar conexão</flux:button>
            @endif
        </div>
        @if($connectionState === 'pending_authorization')
            <p class="text-xs text-neutral-500 dark:text-neutral-400">Depois de autorizar, o portal da Keeta volta para esta tela com as lojas liberadas.</p>
        @endif
    @endif

    @if($connectionState === 'pending_mapping' || (in_array($connectionState, ['error', 'revoked'], true) && $availableShops !== []))
        <div class="space-y-3">
            <p class="text-sm font-medium text-neutral-700 dark:text-neutral-200">Qual loja da Keeta é a filial {{ $currentBranch['name'] ?? '' }}?</p>
            @if($availableShops === [])
                <p class="text-sm text-neutral-600 dark:text-neutral-300">A autorização não trouxe nenhuma loja. Confira no portal da Keeta se a loja foi marcada e atualize a lista.</p>
            @else
                <fieldset class="space-y-2">
                    <legend class="sr-only">Lojas autorizadas</legend>
                    @foreach($availableShops as $shop)
                        <label wire:key="keeta-shop-{{ $shop['id'] }}" class="flex cursor-pointer items-start gap-3 rounded-lg border border-neutral-200 p-3 text-sm hover:bg-neutral-50 dark:border-zinc-700 dark:hover:bg-zinc-900/40">
                            <input type="radio" wire:model="selectedShopId" value="{{ $shop['id'] }}" class="mt-1" />
                            <span>
                                <span class="font-medium text-neutral-800 dark:text-neutral-100">{{ $shop['name'] }}</span>
                                @if($shop['address'] ?? null)<span class="block text-neutral-500 dark:text-neutral-400">{{ $shop['address'] }}</span>@endif
                                <span class="block font-mono text-xs text-neutral-400">ID {{ $shop['id'] }}</span>
                            </span>
                        </label>
                    @endforeach
                </fieldset>
            @endif
            @error('selectedShopId') <p role="alert" class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            <div class="flex flex-wrap gap-2">
                @if($availableShops !== [])
                    <flux:button wire:click="linkShop" wire:target="linkShop" variant="primary">Conectar esta loja</flux:button>
                @endif
                <flux:button wire:click="refreshShops" wire:target="refreshShops" icon="arrow-path">Atualizar lista</flux:button>
                @if($connectionState === 'pending_mapping')
                    <flux:button wire:click="cancelConnection" wire:target="cancelConnection" variant="ghost">Cancelar</flux:button>
                @endif
            </div>
        </div>
    @endif

    @if($connectionState === 'connected')
        <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-3">
            <div>
                <dt class="text-xs uppercase tracking-wide text-neutral-500 dark:text-neutral-400">Aceite</dt>
                <dd class="font-medium text-neutral-800 dark:text-neutral-100">{{ ($data['auto_accept'] ?? false) ? 'Automático' : 'Manual (até 5 min)' }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-neutral-500 dark:text-neutral-400">Cardápio</dt>
                <dd class="font-medium text-neutral-800 dark:text-neutral-100">{{ $data['catalog_published'] ? 'Publicado' : 'Não publicado' }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-neutral-500 dark:text-neutral-400">Último evento</dt>
                <dd class="font-medium text-neutral-800 dark:text-neutral-100">{{ $data['last_webhook_at'] ?? 'Nenhum ainda' }}</dd>
            </div>
        </dl>
        <div class="flex flex-wrap gap-2">
            @if(($data['status'] ?? null) === 'paused')
                <flux:button wire:click="resume" wire:target="resume" variant="primary">Retomar integração</flux:button>
            @else
                <flux:button wire:click="$set('showSuspendModal', true)" variant="ghost">Suspender</flux:button>
            @endif
            <flux:button wire:click="confirmDisconnect" variant="ghost" class="text-red-600 dark:text-red-400">Desconectar</flux:button>
        </div>
    @endif
</x-admin.form-card>
