{{-- Card de conexão: um status e uma ação principal por estado (mesmo padrão da tela de WhatsApp). --}}
@php
    $branchLabel = $currentBranch['name'] ?? null;
@endphp

<x-admin.form-card padding="p-6 space-y-5">
    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
            <h2 class="font-semibold text-neutral-800 dark:text-neutral-100">
                @if($connectionState === 'connected')
                    {{ $merchantName ?: 'Loja no iFood' }}
                @else
                    Conexão com o iFood
                @endif
            </h2>
            @if($branchLabel)
                <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">Filial {{ $branchLabel }}</p>
            @endif
        </div>

        @switch($connectionState)
            @case('connected')
                @if($status === 'paused')
                    <flux:badge color="yellow" class="shrink-0">Suspensa</flux:badge>
                @elseif($pollingStalled)
                    <flux:badge color="yellow" class="shrink-0">Com atraso</flux:badge>
                @else
                    <flux:badge color="green" class="shrink-0">Recebendo pedidos</flux:badge>
                @endif
                @break
            @case('reauthorization_required')
                <flux:badge color="red" class="shrink-0">Reconexão necessária</flux:badge>
                @break
            @case('pending_authorization')
                <flux:badge color="amber" class="shrink-0">Aguardando aprovação</flux:badge>
                @break
            @case('pending_merchant_selection')
                <flux:badge color="amber" class="shrink-0">Escolha a loja</flux:badge>
                @break
            @default
                <flux:badge color="zinc" class="shrink-0">Não conectado</flux:badge>
        @endswitch
    </div>

    @switch($connectionState)
        @case('reauthorization_required')
            <x-admin.alert variant="danger" icon="exclamation-triangle" heading="Pedidos novos do iFood não estão chegando" role="alert">
                A autorização do iFood expirou ou foi revogada. Reconecte esta loja para voltar a receber pedidos no painel.
            </x-admin.alert>
            <flux:button wire:click="connect" wire:target="connect" variant="primary">Reconectar iFood</flux:button>
            @break

        @case('not_connected')
            <p class="text-sm text-neutral-600 dark:text-neutral-300">
                Receba os pedidos do iFood direto no painel. Você entra na sua conta do iFood e aprova o acesso por lá — nenhuma senha ou credencial técnica é digitada aqui.
            </p>
            <div class="rounded-lg bg-neutral-50 p-4 text-sm dark:bg-zinc-900/60">
                <p class="font-medium text-neutral-700 dark:text-neutral-200">Antes de começar</p>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-neutral-600 dark:text-neutral-300">
                    <li>Tenha acesso de administrador à loja no Portal do Parceiro iFood.</li>
                    <li>Deixe o cardápio desta filial cadastrado no painel. Você revisa o que vai para o iFood antes de publicar.</li>
                </ul>
            </div>
            <flux:button wire:click="connect" wire:target="connect" variant="primary">Conectar iFood</flux:button>
            @break

        @case('pending_authorization')
            <ol class="space-y-5">
                <li class="space-y-2">
                    <p class="text-sm font-medium text-neutral-700 dark:text-neutral-200">1. Copie o código de conexão</p>
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="rounded-lg border border-neutral-200 bg-neutral-50 px-4 py-2 font-mono text-2xl font-bold tracking-widest text-neutral-800 dark:border-zinc-700 dark:bg-zinc-900 dark:text-neutral-100">{{ $userCode }}</span>
                        <div x-data="{ copied: false }">
                            <flux:button size="sm" icon="document-duplicate"
                                x-on:click="navigator.clipboard.writeText(@js($userCode)); copied = true; setTimeout(() => copied = false, 2000)">
                                <span x-text="copied ? 'Copiado' : 'Copiar código'">Copiar código</span>
                            </flux:button>
                        </div>
                    </div>
                    @if($userCodeExpiresAt)
                        <p class="text-xs text-neutral-600 dark:text-neutral-400">O código expira {{ $userCodeExpiresAt }}.</p>
                    @endif
                </li>

                <li class="space-y-2">
                    <p class="text-sm font-medium text-neutral-700 dark:text-neutral-200">2. Abra o iFood e aprove o acesso</p>
                    <flux:button :href="$verificationUrl" target="_blank" rel="noopener" icon:trailing="arrow-top-right-on-square" variant="primary">
                        Abrir iFood e aprovar <span class="sr-only">(abre em nova aba)</span>
                    </flux:button>
                </li>

                <li class="space-y-2">
                    <p class="text-sm font-medium text-neutral-700 dark:text-neutral-200">3. Cole o código de autorização</p>
                    <p class="text-sm text-neutral-500 dark:text-neutral-400">Depois de aprovar, o iFood mostra um código de autorização na tela. Cole aqui para concluir.</p>
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                        <div class="sm:w-64">
                            <flux:input wire:model="authorizationCode" wire:keydown.enter="confirmAuthorization" label="Código de autorização" placeholder="Ex: HTLM-KWVR" autocomplete="off" autocapitalize="characters" spellcheck="false" />
                        </div>
                        <flux:button wire:click="confirmAuthorization" wire:target="confirmAuthorization" variant="primary">Confirmar conexão</flux:button>
                    </div>
                </li>
            </ol>

            <flux:button wire:click="disconnect" wire:target="disconnect" variant="ghost" size="sm">Cancelar conexão</flux:button>
            @break

        @case('pending_merchant_selection')
            @if(empty($availableMerchants))
                <p class="text-sm text-neutral-600 dark:text-neutral-300">Autorização salva. Consulte as lojas autorizadas no iFood para concluir a conexão.</p>
            @else
                <flux:radio.group wire:model="selectedMerchantId" variant="cards" label="Qual loja do iFood corresponde a esta filial?" class="flex-col">
                    @foreach($availableMerchants as $merchant)
                        <flux:radio
                            value="{{ $merchant['id'] }}"
                            :label="($merchant['name'] ?? null) && $merchant['name'] !== $merchant['id'] ? $merchant['name'] : 'Loja sem nome'"
                            :description="$merchant['id']"
                        />
                    @endforeach
                </flux:radio.group>
            @endif

            <div class="flex flex-wrap items-center gap-3">
                @if(! empty($availableMerchants))
                    <flux:button wire:click="selectMerchant" wire:target="selectMerchant" variant="primary">Confirmar loja</flux:button>
                @endif
                <flux:button wire:click="confirmAuthorization" wire:target="confirmAuthorization" :variant="empty($availableMerchants) ? 'primary' : 'ghost'">Consultar lojas novamente</flux:button>
                <flux:button wire:click="disconnect" wire:target="disconnect" variant="ghost" size="sm">Cancelar conexão</flux:button>
            </div>
            @break

        @case('connected')
            <dl class="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-3">
                <div>
                    <dt class="text-xs text-neutral-600 dark:text-neutral-400">Pedidos</dt>
                    <dd class="font-medium text-neutral-800 dark:text-neutral-100">
                        @if($status === 'paused')
                            Não recebe pedidos novos
                        @elseif($pollingStalled)
                            Sem consultar o iFood há alguns minutos
                        @else
                            Chegando no painel
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-neutral-600 dark:text-neutral-400">Última consulta ao iFood</dt>
                    <dd class="font-medium text-neutral-800 dark:text-neutral-100">{{ $lastPolledAt ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-neutral-600 dark:text-neutral-400">Cardápio enviado ao iFood</dt>
                    {{-- Fora da aba Cardápio (que tem o próprio poll), acompanha o envio até terminar. --}}
                    <dd class="font-medium text-neutral-800 dark:text-neutral-100"
                        @if(in_array($catalogSyncStatus, ['queued', 'running'], true) && $tab !== 'cardapio') wire:poll.5s="refreshCatalogStatus" @endif>
                        {{ match (true) {
                            ! $catalogPublished => 'Ainda não publicado',
                            in_array($catalogSyncStatus, ['queued', 'running'], true) => 'Enviando agora…',
                            $catalogSyncStatus === 'failed' => 'Último envio falhou',
                            default => $lastSyncedAt ?? '—',
                        } }}
                    </dd>
                </div>
            </dl>

            @if($status === 'paused')
                <x-admin.alert variant="warning" icon="pause-circle" heading="Integração suspensa">
                    A loja fica fechada no iFood e pedidos novos não chegam ao painel. Pedidos já recebidos podem ser concluídos normalmente.
                </x-admin.alert>
            @elseif($pollingStalled)
                <x-admin.alert variant="warning" icon="exclamation-triangle" role="alert">
                    Não conseguimos consultar o iFood nos últimos minutos. Acompanhe os pedidos pelo Gestor de Pedidos do iFood enquanto isso; se continuar, fale com o suporte.
                </x-admin.alert>
            @endif

            <div class="flex flex-wrap items-center gap-3">
                @if($status === 'paused')
                    <flux:button wire:click="resume" wire:target="resume" variant="primary">Retomar integração</flux:button>
                @elseif($catalogPublished)
                    <flux:button wire:click="syncCatalogNow" wire:target="syncCatalogNow" :disabled="in_array($catalogSyncStatus, ['queued', 'running'], true)">Sincronizar cardápio agora</flux:button>
                @else
                    <flux:button wire:click="$set('tab', 'cardapio')" variant="primary">Revisar e publicar cardápio</flux:button>
                @endif

                <flux:dropdown position="bottom" align="end">
                    <flux:button icon:trailing="chevron-down" variant="ghost">Mais opções</flux:button>
                    <flux:menu>
                        @if($status === 'active')
                            <flux:menu.item icon="pause-circle" wire:click="confirmSuspend">Suspender integração</flux:menu.item>
                        @endif
                        <flux:menu.item icon="wrench-screwdriver" wire:click="$set('tab', 'diagnostico')">Diagnóstico da conexão</flux:menu.item>
                        <flux:menu.separator />
                        <flux:menu.item icon="link-slash" variant="danger" wire:click="confirmDisconnect">Desconectar…</flux:menu.item>
                    </flux:menu>
                </flux:dropdown>
            </div>
            @break
    @endswitch
</x-admin.form-card>
