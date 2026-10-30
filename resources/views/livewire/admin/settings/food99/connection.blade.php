{{-- Conexão: link de autorização self-service (o superadmin da loja na 99 aprova) + ID da loja. --}}
<x-admin.form-card padding="p-6 space-y-5">
    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
            <h2 class="font-semibold text-neutral-800 dark:text-neutral-100">
                {{ $connectionState === 'connected' ? ($data['shop_name'] ?: 'Loja na 99Food') : 'Conexão com a 99Food' }}
            </h2>
            @if($currentBranch)
                <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">Filial {{ $currentBranch['name'] }}</p>
            @endif
        </div>
        @switch($connectionState)
            @case('connected')
                @if(($data['status'] ?? null) === 'paused')
                    <flux:badge color="yellow" class="shrink-0">Suspensa</flux:badge>
                @else
                    <flux:badge color="green" class="shrink-0">Recebendo pedidos</flux:badge>
                @endif
                @break
            @case('error')
                <flux:badge color="red" class="shrink-0">Reconexão necessária</flux:badge>
                @break
            @case('pending_authorization')
                <flux:badge color="amber" class="shrink-0">Aguardando autorização</flux:badge>
                @break
            @default
                <flux:badge color="zinc" class="shrink-0">Não conectado</flux:badge>
        @endswitch
    </div>

    @if($connectionState === 'error')
        <x-admin.alert variant="danger" icon="exclamation-triangle" heading="Pedidos novos da 99Food não estão chegando" role="alert">
            A 99Food desfez o vínculo da loja ou o acesso não pôde ser renovado. Autorize de novo e informe o ID da loja.
        </x-admin.alert>
    @endif

    @if(in_array($connectionState, ['not_connected', 'error'], true))
        <p class="text-sm text-neutral-600 dark:text-neutral-300">
            Receba os pedidos da 99Food direto no painel. Quem autoriza é o usuário superadmin da loja na 99Food — nenhuma senha é digitada aqui.
        </p>
        <flux:button wire:click="connect" wire:target="connect" variant="primary">Conectar 99Food</flux:button>
    @endif

    @if($connectionState === 'pending_authorization' || ($connectionState === 'error' && $authorizationUrl))
        <ol class="space-y-5">
            <li class="space-y-2">
                <p class="text-sm font-medium text-neutral-700 dark:text-neutral-200">1. Abra o link de autorização com o superadmin da loja na 99Food</p>
                <flux:button :href="$authorizationUrl" target="_blank" rel="noopener noreferrer" icon-trailing="arrow-top-right-on-square">Abrir autorização da 99Food</flux:button>
                <p class="text-xs text-neutral-500 dark:text-neutral-400">
                    Escolha a loja certa e autorize o app VEDDI.@if($authorizationUrlExpiresAt) O link vale até {{ $authorizationUrlExpiresAt }}.@endif
                </p>
            </li>
            <li class="space-y-2">
                <p class="text-sm font-medium text-neutral-700 dark:text-neutral-200">2. Informe o ID da loja na 99Food</p>
                <div class="flex flex-wrap items-end gap-2">
                    <div class="w-full sm:w-72">
                        <flux:input wire:model="shopIdInput" label="ID da loja" inputmode="numeric" placeholder="Ex.: 5763000000000012345" />
                    </div>
                    <flux:button wire:click="linkShop" wire:target="linkShop" variant="primary">Já autorizei, conectar</flux:button>
                </div>
                @error('shopIdInput') <p role="alert" class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                <p class="text-xs text-neutral-500 dark:text-neutral-400">O ID aparece no app 99Food Loja e no portal da 99Food, nos dados da loja.</p>
            </li>
        </ol>

        @if($homologationMode || auth()->user()->isSuperAdmin())
            <div class="rounded-lg border border-dashed border-neutral-300 p-3 text-sm dark:border-zinc-600">
                <div class="flex items-center justify-between gap-2">
                    <p class="font-medium text-neutral-700 dark:text-neutral-200">Lojas que autorizaram o app (homologação)</p>
                    <flux:button size="sm" wire:click="loadAuthorizedShops" wire:target="loadAuthorizedShops">Consultar</flux:button>
                </div>
                @foreach($availableShops as $shop)
                    <div class="mt-2 flex items-center justify-between gap-2">
                        <span>{{ $shop['shop_name'] ?? 'Loja' }} · <span class="font-mono text-xs">{{ $shop['shop_id'] }}</span></span>
                        <flux:button size="sm" variant="ghost" wire:click="chooseShop('{{ $shop['shop_id'] }}')">Usar esta</flux:button>
                    </div>
                @endforeach
            </div>
        @endif
    @endif

    @if($connectionState === 'connected')
        <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-3">
            <div>
                <dt class="text-xs uppercase tracking-wide text-neutral-500 dark:text-neutral-400">Loja na 99Food</dt>
                <dd class="font-medium text-neutral-800 dark:text-neutral-100">{{ $data['store_label'] ?? 'Aguardando status' }}</dd>
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
    @elseif($connectionState === 'error')
        <flux:button wire:click="confirmDisconnect" variant="ghost" class="text-red-600 dark:text-red-400">Desconectar</flux:button>
    @endif
</x-admin.form-card>
