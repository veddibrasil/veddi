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
                @elseif(($data['sub_biz_status'] ?? null) === 6)
                    <flux:badge color="red" class="shrink-0">Bloqueada pela 99Food</flux:badge>
                @elseif(($data['sub_biz_status'] ?? null) === 4)
                    <flux:badge color="yellow" class="shrink-0">App da loja offline</flux:badge>
                @elseif($data['webhook_silent'] ?? false)
                    <flux:badge color="yellow" class="shrink-0">Com atraso</flux:badge>
                @else
                    <flux:badge color="green" class="shrink-0">Recebendo pedidos</flux:badge>
                @endif
                @break
            @case('activating')
                <flux:badge color="amber" class="shrink-0">Ativação pendente</flux:badge>
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

    @if($connectionState === 'activating')
        <p class="text-sm text-neutral-600 dark:text-neutral-300">
            A loja <strong>{{ $data['shop_name'] ?: $data['shop_id'] }}</strong> foi ligada a esta filial, mas a 99Food ainda não liberou o acesso. Aguarde meio minuto e conclua a ativação.
        </p>
        <div class="flex flex-wrap gap-2">
            <flux:button wire:click="reapplySettings" wire:target="reapplySettings" variant="primary">Concluir ativação</flux:button>
            <flux:button wire:click="confirmDisconnect" variant="ghost" class="text-red-600 dark:text-red-400">Desconectar</flux:button>
        </div>
    @endif

    @if(in_array($connectionState, ['not_connected', 'error'], true))
        <p class="text-sm text-neutral-600 dark:text-neutral-300">
            Receba os pedidos da 99Food direto no painel. Quem autoriza é o usuário superadmin da loja na 99Food — nenhuma senha é digitada aqui.
        </p>
        @if($connectionState === 'not_connected')
            <div class="rounded-lg bg-neutral-50 p-4 text-sm dark:bg-zinc-900/60">
                <p class="font-medium text-neutral-700 dark:text-neutral-200">Antes de começar</p>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-neutral-600 dark:text-neutral-300">
                    <li>Tenha em mãos o acesso do usuário superadmin da loja na 99Food.</li>
                    <li>A loja precisa estar aprovada na 99Food, com o contrato assinado.</li>
                    <li>Cada loja da 99Food conecta em uma filial só. Repita o processo em cada filial.</li>
                </ul>
            </div>
        @endif
        <flux:button wire:click="connect" wire:target="connect" variant="primary">{{ $connectionState === 'error' ? 'Reconectar 99Food' : 'Conectar 99Food' }}</flux:button>
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

                @if($pendingShop)
                    <div class="space-y-3 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm dark:border-amber-800/60 dark:bg-amber-900/20" role="status">
                        <p class="text-neutral-800 dark:text-neutral-100">
                            Encontramos a loja <strong>{{ $pendingShop['shop_name'] ?? 'sem nome' }}</strong> (<span class="font-mono text-xs">{{ $pendingShop['shop_id'] }}</span>).
                            Os pedidos dela vão chegar na filial {{ $currentBranch['name'] ?? '' }}. É esta loja?
                        </p>
                        <div class="flex flex-wrap gap-2">
                            <flux:button size="sm" variant="primary" wire:click="confirmLinkShop" wire:target="confirmLinkShop">Sim, conectar esta loja</flux:button>
                            <flux:button size="sm" variant="ghost" wire:click="cancelLinkShop">Não é esta</flux:button>
                        </div>
                    </div>
                @endif
            </li>
        </ol>

        @if($connectionState === 'pending_authorization')
            <flux:button wire:click="cancelConnection" wire:target="cancelConnection" variant="ghost">Cancelar conexão</flux:button>
        @endif

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
                <dd class="font-medium text-neutral-800 dark:text-neutral-100">{{ $data['store_label'] ?? 'Aguardando status' }}{{ ($data['busy'] ?? false) ? ' · modo ocupado' : '' }}</dd>
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
        @if($data['webhook_silent'] ?? false)
            <x-admin.alert variant="warning" icon="exclamation-triangle">
                A loja está aberta, mas a 99Food não envia nenhum aviso há algumas horas. Acompanhe pelo app da 99Food; se continuar, fale com o suporte.
            </x-admin.alert>
        @endif
        @if($data['unavailable_reason'] ?? null)
            <x-admin.alert variant="warning" icon="exclamation-triangle">
                {{ $data['unavailable_reason'] }}@if($data['unavailable_until'] ?? null) Previsão de volta: {{ $data['unavailable_until'] }}.@endif
            </x-admin.alert>
        @endif
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
