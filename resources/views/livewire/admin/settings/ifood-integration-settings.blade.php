<div class="w-full space-y-6">
    <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100">Integração iFood</h1>

    @if(session('status'))
        <div class="bg-green-50 border border-green-200 text-green-700 rounded-lg px-4 py-3 text-sm dark:bg-green-900/30 dark:border-green-700 dark:text-green-400">
            {{ session('status') }}
        </div>
    @endif

    @if(session('error'))
        <div class="bg-red-50 border border-red-200 text-red-700 rounded-lg px-4 py-3 text-sm dark:bg-red-900/30 dark:border-red-700 dark:text-red-400">
            {{ session('error') }}
        </div>
    @endif

    @if(empty($branchOptions))
        <div class="bg-yellow-50 border border-yellow-200 text-yellow-700 rounded-lg px-4 py-3 text-sm dark:bg-yellow-900/30 dark:border-yellow-700 dark:text-yellow-400">
            Nenhuma filial cadastrada — cadastre uma em <a href="{{ route('admin.branches.index') }}" class="underline">Filiais</a> antes de conectar o iFood.
        </div>
    @else
        <div class="bg-white border rounded-xl shadow-sm p-6 space-y-4 dark:bg-zinc-800 dark:border-zinc-700">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    @if(count($branchOptions) > 1)
                        <flux:select wire:model.live="branchId" label="Filial">
                            @foreach($branchOptions as $option)
                                <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                            @endforeach
                        </flux:select>
                    @else
                        <flux:input value="{{ $branchOptions[0]['name'] }}" label="Filial" disabled />
                    @endif
                </div>
            </div>
            @error('branchId') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror

            @if($connectionState === 'not_connected')
                <div class="space-y-3">
                    <p class="text-sm text-neutral-500 dark:text-neutral-400">
                        Conecte esta filial à sua conta iFood — você será direcionado pra fazer login e aprovar o acesso diretamente no site do iFood, sem precisar digitar nenhuma credencial técnica aqui.
                    </p>
                    <flux:button wire:click="connect" variant="primary">Conectar iFood</flux:button>
                </div>
            @elseif($connectionState === 'pending_authorization')
                <div class="space-y-4">
                    <div class="bg-neutral-50 border border-neutral-200 rounded-lg p-4 text-center space-y-2 dark:bg-zinc-900 dark:border-zinc-700">
                        <p class="text-xs text-neutral-500 dark:text-neutral-400">Passo 1 — Código de conexão</p>
                        <p class="text-2xl font-mono font-bold tracking-widest text-neutral-800 dark:text-neutral-100">{{ $userCode }}</p>
                        @if($userCodeExpiresAt)
                            <p class="text-xs text-neutral-400 dark:text-neutral-500">Expira {{ $userCodeExpiresAt }}</p>
                        @endif
                    </div>

                    <flux:button href="{{ $verificationUrl }}" target="_blank" variant="primary">Abrir iFood e aprovar</flux:button>

                    <div class="border-t pt-4 space-y-2 dark:border-zinc-700">
                        <p class="text-sm text-neutral-500 dark:text-neutral-400">
                            Passo 2 — Depois de aprovar, o iFood mostra um código de autorização na tela. Cole ele aqui pra concluir a conexão.
                        </p>
                        <flux:input wire:model="authorizationCode" label="Código de autorização" placeholder="Ex: HTLM-KWVR" />
                        @error('authorizationCode') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        <flux:button wire:click="confirmAuthorization" variant="primary">Confirmar conexão</flux:button>
                    </div>

                    <flux:button wire:click="disconnect" variant="ghost" size="sm">Cancelar</flux:button>
                </div>
            @elseif($connectionState === 'pending_merchant_selection')
                <div class="space-y-4">
                    <p class="text-sm text-neutral-500 dark:text-neutral-400">
                        Essa autorização cobre mais de uma loja no iFood. Escolha qual delas corresponde a esta filial.
                    </p>

                    <div class="space-y-2">
                        @foreach($availableMerchants as $merchant)
                            <label class="flex items-center gap-3 rounded-lg border border-neutral-200 dark:border-zinc-700 px-4 py-3 cursor-pointer hover:bg-neutral-50 dark:hover:bg-zinc-900">
                                <input type="radio" wire:model="selectedMerchantId" value="{{ $merchant['id'] }}" class="shrink-0">
                                <span>
                                    <span class="block font-medium text-neutral-800 dark:text-neutral-100">{{ $merchant['name'] ?? $merchant['id'] }}</span>
                                    <span class="block text-xs text-neutral-400 dark:text-neutral-500">{{ $merchant['id'] }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                    @error('selectedMerchantId') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror

                    <div class="flex items-center gap-3">
                        <flux:button wire:click="selectMerchant" variant="primary">Confirmar loja</flux:button>
                        <flux:button wire:click="disconnect" variant="ghost" size="sm">Cancelar</flux:button>
                    </div>
                </div>
            @elseif($connectionState === 'connected')
                <div class="space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                        <div>
                            <p class="text-neutral-500 dark:text-neutral-400">Merchant ID</p>
                            <p class="font-medium">{{ $merchantId }}</p>
                        </div>
                        <div>
                            <p class="text-neutral-500 dark:text-neutral-400">Status</p>
                            <p class="font-medium">
                                @if($status === 'active')
                                    <span class="text-green-600 dark:text-green-400">Ativo</span>
                                @else
                                    <span class="text-yellow-600 dark:text-yellow-400">Pausado</span>
                                @endif
                            </p>
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
                        @if($status === 'active')
                            <flux:button wire:click="pause" variant="ghost" size="sm">Pausar</flux:button>
                        @else
                            <flux:button wire:click="resume" variant="ghost" size="sm">Retomar</flux:button>
                        @endif
                        <flux:button wire:click="syncCatalogNow" wire:loading.attr="disabled" variant="ghost" size="sm">Sincronizar cardápio agora</flux:button>
                        <flux:button wire:click="disconnect" variant="danger" size="sm">Desconectar</flux:button>
                    </div>
                </div>
            @endif
        </div>

        @if($connectionState === 'connected')
            <div class="bg-white border rounded-xl shadow-sm p-6 space-y-3 dark:bg-zinc-800 dark:border-zinc-700">
                <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">Saúde da conexão</h2>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm">
                    <div>
                        <p class="text-neutral-500 dark:text-neutral-400">Webhook</p>
                        <p class="font-medium">
                            @if($webhookStatus === 'healthy')
                                <span class="text-green-600 dark:text-green-400">Saudável</span>
                            @elseif($webhookStatus === 'degraded')
                                <span class="text-yellow-600 dark:text-yellow-400">Instável (usando polling)</span>
                            @else
                                <span class="text-neutral-500 dark:text-neutral-400">Aguardando primeiro evento</span>
                            @endif
                        </p>
                    </div>
                    <div>
                        <p class="text-neutral-500 dark:text-neutral-400">Último webhook recebido</p>
                        <p class="font-medium">{{ $lastWebhookReceivedAt ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-neutral-500 dark:text-neutral-400">Último sync de catálogo</p>
                        <p class="font-medium">{{ $lastSyncedAt ?? '—' }}</p>
                    </div>
                </div>
            </div>
        @endif
    @endif
</div>
