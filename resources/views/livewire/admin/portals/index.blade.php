<div class="w-full space-y-6">
    <x-admin.page-header :back-route="route('admin.dashboard')" title="Portais" />

    @if (session('status'))
        <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg text-sm dark:bg-green-900/30 dark:border-green-700 dark:text-green-400">
            {{ session('status') }}
        </div>
    @endif

    @unless($portalsEnabled)
        <x-admin.form-card title="Portais">
            <p class="text-sm text-neutral-500 dark:text-neutral-400">
                Módulo Portais (integração com iFood) não está contratado para esta empresa.
                Ative em <a href="{{ route('admin.billing') }}" wire:navigate class="text-amber-600 hover:underline">Configurações &gt; Assinatura</a>.
            </p>
        </x-admin.form-card>
    @else
        <x-admin.form-card title="Conexão iFood">
            @if($portal && $portal->status === 'connected')
                <div class="flex items-center justify-between flex-wrap gap-3">
                    <div class="text-sm text-neutral-700 dark:text-neutral-300">
                        Conectado — loja iFood <span class="font-mono">{{ $portal->external_merchant_id }}</span>
                        (filial: {{ $portal->branch?->name }})
                        @if($portal->paused_until)
                            <span class="block text-xs text-amber-600 dark:text-amber-400 font-medium">
                                Pausado até {{ $portal->paused_until->format('d/m H:i') }}
                            </span>
                        @endif
                    </div>
                    <div class="flex items-center gap-2">
                        @if($portal->paused_until)
                            <flux:button wire:click="resumeOrders" class="text-sm">Retomar recebimento</flux:button>
                        @else
                            <flux:button wire:click="pauseOrders(30)" class="text-sm">Pausar 30min</flux:button>
                            <flux:button wire:click="pauseOrders(60)" class="text-sm">Pausar 1h</flux:button>
                        @endif
                        <flux:button wire:click="disconnect" wire:confirm="Desconectar o iFood desta empresa?" class="text-sm">
                            Desconectar
                        </flux:button>
                    </div>
                </div>
                @error('pause') <p class="text-red-500 text-xs mt-2">{{ $message }}</p> @enderror
            @elseif($pendingUserCode)
                <div class="space-y-4">
                    <p class="text-sm text-neutral-700 dark:text-neutral-300">
                        1. Acesse o link abaixo e informe o código <span class="font-mono font-bold">{{ $pendingUserCode }}</span> no Portal do Parceiro iFood para autorizar.
                    </p>
                    @if($pendingVerificationUrl)
                        <a href="{{ $pendingVerificationUrl }}" target="_blank" class="text-amber-600 underline text-sm break-all">{{ $pendingVerificationUrl }}</a>
                    @endif

                    <p class="text-sm text-neutral-700 dark:text-neutral-300">
                        2. Depois de autorizar, cole aqui o código de autorização exibido e o ID da loja (Portal do Parceiro → Configurações).
                    </p>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <flux:input wire:model="authorizationCode" label="Código de autorização" />
                            @error('authorizationCode') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <flux:input wire:model="externalMerchantId" label="ID da loja no iFood" />
                            @error('externalMerchantId') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <flux:button wire:click="confirmConnect" class="!bg-amber-500 !text-white hover:!bg-amber-600 text-sm">
                        Confirmar conexão
                    </flux:button>
                </div>
            @else
                <div class="space-y-3">
                    <p class="text-sm text-neutral-500 dark:text-neutral-400">Escolha a filial que vai receber pedidos do iFood:</p>
                    @foreach($branches as $branch)
                        <div class="flex items-center justify-between border rounded-lg p-3 dark:border-zinc-700">
                            <span class="text-sm">{{ $branch->name }}</span>
                            <flux:button wire:click="startConnect({{ $branch->id }})" class="text-sm">
                                Conectar iFood
                            </flux:button>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-admin.form-card>

        @if($portal && $portal->status === 'connected')
            <x-admin.form-card title="Mapeamento de produtos">
                <p class="text-sm text-neutral-500 dark:text-neutral-400">
                    Associe cada produto do seu cardápio ao ID do item correspondente no catálogo iFood.
                    Pedidos com item sem mapeamento não são criados automaticamente.
                </p>

                <flux:input wire:model.live.debounce.400ms="search" placeholder="Buscar produto…" class="max-w-sm" />

                <div class="bg-white border rounded-xl shadow-sm overflow-hidden dark:bg-zinc-800 dark:border-zinc-700">
                    <table class="w-full text-sm">
                        <thead class="bg-neutral-50 dark:bg-zinc-900 text-left text-neutral-500 dark:text-neutral-400">
                            <tr>
                                <th class="px-4 py-2">Produto</th>
                                <th class="px-4 py-2">ID do item no iFood</th>
                                <th class="px-4 py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($products as $product)
                                <tr class="border-t dark:border-zinc-700">
                                    <td class="px-4 py-2">{{ $product->name }}</td>
                                    <td class="px-4 py-2">
                                        <flux:input wire:model="mappingInputs.{{ $product->id }}" size="sm" />
                                        @error("mappingInputs.{$product->id}") <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                                    </td>
                                    <td class="px-4 py-2">
                                        <flux:button wire:click="saveMapping({{ $product->id }})" size="sm">Salvar</flux:button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{ $products->links() }}
            </x-admin.form-card>
        @endif
    @endunless
</div>
