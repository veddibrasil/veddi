<x-admin.form-card title="Recebimento de pedidos">
    <dl class="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-3">
        <div>
            <dt class="text-xs text-neutral-600 dark:text-neutral-400">Avisos do iFood (webhook)</dt>
            <dd class="font-medium text-neutral-800 dark:text-neutral-100">
                @if($webhookStatus === 'healthy')
                    <span class="inline-flex items-center gap-1.5"><flux:icon.check-circle variant="mini" class="size-4 text-green-600 dark:text-green-400" /> Chegando normalmente</span>
                @elseif($webhookStatus === 'degraded')
                    <span class="inline-flex items-center gap-1.5"><flux:icon.exclamation-triangle variant="mini" class="size-4 text-yellow-600 dark:text-yellow-400" /> Com atraso — conferindo o iFood a cada 30 segundos</span>
                @else
                    <span class="inline-flex items-center gap-1.5"><flux:icon.clock variant="mini" class="size-4 text-neutral-500" /> Aguardando o primeiro aviso</span>
                @endif
            </dd>
        </div>
        <div>
            <dt class="text-xs text-neutral-600 dark:text-neutral-400">Último aviso recebido</dt>
            <dd class="font-medium text-neutral-800 dark:text-neutral-100">{{ $lastWebhookReceivedAt ?? '—' }}</dd>
        </div>
        <div>
            <dt class="text-xs text-neutral-600 dark:text-neutral-400">Última consulta ao iFood</dt>
            <dd class="font-medium text-neutral-800 dark:text-neutral-100">{{ $lastPolledAt ?? '—' }}</dd>
        </div>
    </dl>
    <p class="text-xs text-neutral-500 dark:text-neutral-400">
        O painel também consulta o iFood a cada 30 segundos, então os pedidos chegam mesmo quando os avisos atrasam.
    </p>
</x-admin.form-card>

<x-admin.form-card title="Dados para o suporte">
    <dl class="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
        <div>
            <dt class="text-xs text-neutral-600 dark:text-neutral-400">ID da loja no iFood (Merchant ID)</dt>
            <dd class="flex items-center gap-2 font-mono text-neutral-800 dark:text-neutral-100" x-data="{ copied: false }">
                <span class="break-all">{{ $merchantId }}</span>
                <flux:button size="xs" variant="ghost" icon="document-duplicate" aria-label="Copiar ID da loja"
                    x-on:click="navigator.clipboard.writeText(@js($merchantId)); copied = true; setTimeout(() => copied = false, 2000)" />
                <span x-show="copied" x-cloak class="font-sans text-xs text-green-600 dark:text-green-400">Copiado</span>
            </dd>
        </div>
        <div>
            <dt class="text-xs text-neutral-600 dark:text-neutral-400">Nome da loja</dt>
            <dd class="font-medium text-neutral-800 dark:text-neutral-100">{{ $merchantName ?? '—' }}</dd>
        </div>
    </dl>
    <p class="text-xs text-neutral-500 dark:text-neutral-400">Informe o ID da loja ao falar com o suporte do iFood ou da Veddi.</p>
</x-admin.form-card>

<x-admin.form-card title="Conexão">
    <div class="flex flex-wrap gap-3">
        @if($status === 'active')
            <flux:button wire:click="confirmSuspend" icon="pause-circle">Suspender integração</flux:button>
        @elseif($status === 'paused')
            <flux:button wire:click="resume" wire:target="resume" variant="primary">Retomar integração</flux:button>
        @endif
        <flux:button wire:click="confirmDisconnect" variant="danger" icon="link-slash">Desconectar</flux:button>
    </div>
    <p class="text-xs text-neutral-500 dark:text-neutral-400">
        Suspender fecha a loja no iFood sem desfazer a conexão. Desconectar exige aprovar o acesso de novo no iFood para voltar.
    </p>
</x-admin.form-card>
