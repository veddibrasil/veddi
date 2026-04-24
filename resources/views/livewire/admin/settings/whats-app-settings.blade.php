<div class="w-full space-y-6">
    <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100">Notificações WhatsApp</h1>

    @if(session('status'))
        <div class="bg-green-50 border border-green-200 text-green-700 rounded-lg px-4 py-3 text-sm dark:bg-green-900/30 dark:border-green-700 dark:text-green-400">
            {{ session('status') }}
        </div>
    @endif

    @if(! $hasGlobalCredentials)
        <div class="bg-yellow-50 border border-yellow-200 text-yellow-800 rounded-lg px-4 py-3 text-sm dark:bg-yellow-900/30 dark:border-yellow-700 dark:text-yellow-400">
            As credenciais Z-API (<code>ZAPI_INSTANCE_ID</code>, <code>ZAPI_TOKEN</code>, <code>ZAPI_CLIENT_TOKEN</code>) não estão configuradas no servidor. Contate o administrador do sistema.
        </div>
    @endif

    {{-- Ativar / Desativar notificações --}}
    <div class="bg-white border rounded-xl shadow-sm p-6 dark:bg-zinc-800 dark:border-zinc-700">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-neutral-700 dark:text-neutral-300">Ativar notificações automáticas</h2>
                <p class="text-sm text-neutral-500 dark:text-neutral-400 mt-1">Envie atualizações de status do pedido para os clientes via WhatsApp.</p>
            </div>
            <flux:switch wire:model="enabled" />
        </div>
    </div>

    {{-- Eventos --}}
    <div class="bg-white border rounded-xl shadow-sm p-6 space-y-4 dark:bg-zinc-800 dark:border-zinc-700">
        <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">Quando notificar</h2>

        <div class="space-y-3">
            @foreach([
                ['model' => 'notifyOnNewOrder',        'title' => 'Pedido recebido',             'desc' => 'Ao confirmar o pedido no chat'],
                ['model' => 'notifyOnAwaitingPayment', 'title' => 'Aguardando pagamento',         'desc' => 'Envia o código PIX para o cliente'],
                ['model' => 'notifyOnPaid',            'title' => 'Pagamento confirmado',         'desc' => 'Ao confirmar o recebimento do pagamento'],
                ['model' => 'notifyOnPreparing',       'title' => 'Em preparo',                  'desc' => 'Ao marcar o pedido como "Preparando"'],
                ['model' => 'notifyOnReady',           'title' => 'Pronto / Saiu para entrega',  'desc' => 'Ao marcar o pedido como "Pronto"'],
                ['model' => 'notifyOnDelivered',       'title' => 'Entregue',                    'desc' => 'Ao marcar o pedido como "Entregue"'],
                ['model' => 'notifyOnCancelled',       'title' => 'Cancelado / Reembolsado',     'desc' => 'Ao cancelar ou reembolsar o pedido'],
                ['model' => 'notifyOnAdminMessage',    'title' => 'Mensagem do atendente',       'desc' => 'Quando o atendente envia mensagem no chat do pedido'],
            ] as $item)
                <div class="flex items-center justify-between py-2 {{ !$loop->last ? 'border-b border-neutral-100 dark:border-zinc-700' : '' }}">
                    <div>
                        <p class="text-sm font-medium text-neutral-700 dark:text-neutral-300">{{ $item['title'] }}</p>
                        <p class="text-xs text-neutral-400 dark:text-neutral-500">{{ $item['desc'] }}</p>
                    </div>
                    <flux:switch wire:model="{{ $item['model'] }}" />
                </div>
            @endforeach
        </div>
    </div>

    {{-- Salvar --}}
    <div class="flex justify-end">
        <flux:button wire:click="save" variant="primary">
            Salvar configurações
        </flux:button>
    </div>
</div>
