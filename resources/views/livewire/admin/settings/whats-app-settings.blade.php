@php
    $connection = $this->connection;
    $status = $connection?->status;
    $canConfigure = $this->canConfigure;

    // A tela acompanha sozinha a montagem da conexão: rápido enquanto conecta, devagar enquanto a Meta aprova os modelos.
    $poll = match ($status) {
        \App\Models\WhatsAppConnection::STATUS_PENDING, \App\Models\WhatsAppConnection::STATUS_PROVISIONING => '5s',
        \App\Models\WhatsAppConnection::STATUS_TEMPLATES_PENDING => '30s',
        default => null,
    };

    $qualityLabels = ['GREEN' => 'Alta', 'YELLOW' => 'Média', 'RED' => 'Baixa'];
    $qualityColors = ['GREEN' => 'green', 'YELLOW' => 'amber', 'RED' => 'red'];
    $tierLabels = ['TIER_50' => '50', 'TIER_250' => '250', 'TIER_1K' => '1.000', 'TIER_10K' => '10.000', 'TIER_100K' => '100.000', 'TIER_UNLIMITED' => 'Ilimitados'];
    $templateColors = ['APPROVED' => 'green', 'PENDING' => 'amber', 'REJECTED' => 'red', 'PAUSED' => 'red', 'DISABLED' => 'zinc'];

    $signupConfigured = $meta['appId'] !== '' && $meta['configId'] !== '';
    $showConnect = $connection === null || in_array($status, [\App\Models\WhatsAppConnection::STATUS_DISCONNECTED, \App\Models\WhatsAppConnection::STATUS_ERROR], true);
    $isReconnect = $connection !== null && $showConnect;
@endphp

<div class="w-full space-y-6">
    <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100">Notificações WhatsApp</h1>

    @if(session('status'))
        <div class="bg-green-50 border border-green-200 text-green-700 rounded-lg px-4 py-3 text-sm dark:bg-green-900/30 dark:border-green-700 dark:text-green-400">
            {{ session('status') }}
        </div>
    @endif

    @if($notice)
        <div class="bg-green-50 border border-green-200 text-green-700 rounded-lg px-4 py-3 text-sm dark:bg-green-900/30 dark:border-green-700 dark:text-green-400">
            {{ $notice }}
        </div>
    @endif

    {{-- Conexão --}}
    <div @if($poll) wire:poll.{{ $poll }} @endif class="bg-white border rounded-xl shadow-sm p-6 space-y-5 dark:bg-zinc-800 dark:border-zinc-700">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 class="font-semibold text-neutral-700 dark:text-neutral-300">Número de WhatsApp da empresa</h2>
                <p class="text-sm text-neutral-500 dark:text-neutral-400 mt-1">
                    As atualizações do pedido saem do próprio número do seu restaurante, com mensagens aprovadas pela Meta.
                </p>
            </div>

            @if($status === \App\Models\WhatsAppConnection::STATUS_ACTIVE)
                <flux:badge color="green" class="shrink-0">Conectado</flux:badge>
            @elseif(in_array($status, [\App\Models\WhatsAppConnection::STATUS_PENDING, \App\Models\WhatsAppConnection::STATUS_PROVISIONING, \App\Models\WhatsAppConnection::STATUS_TEMPLATES_PENDING], true))
                <flux:badge color="amber" class="shrink-0">Conectando</flux:badge>
            @elseif($status === \App\Models\WhatsAppConnection::STATUS_ERROR)
                <flux:badge color="red" class="shrink-0">Com problema</flux:badge>
            @else
                <flux:badge color="zinc" class="shrink-0">Não conectado</flux:badge>
            @endif
        </div>

        {{-- ── Ativo ── --}}
        @if($status === \App\Models\WhatsAppConnection::STATUS_ACTIVE)
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 text-sm">
                <div>
                    <dt class="text-xs text-neutral-400 dark:text-neutral-500">Nome verificado</dt>
                    <dd class="font-medium text-neutral-800 dark:text-neutral-100">{{ $connection->verified_name ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-neutral-400 dark:text-neutral-500">Número</dt>
                    <dd class="font-medium text-neutral-800 dark:text-neutral-100">{{ $connection->display_phone_number ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-neutral-400 dark:text-neutral-500">Tipo de conexão</dt>
                    <dd class="font-medium text-neutral-800 dark:text-neutral-100">
                        {{ $connection->isCoexistence() ? 'Coexistência (com o app WhatsApp Business)' : 'Número novo (API oficial)' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-neutral-400 dark:text-neutral-500">Qualidade do número</dt>
                    <dd>
                        @if($connection->quality_rating)
                            <flux:badge size="sm" :color="$qualityColors[$connection->quality_rating] ?? 'zinc'">
                                {{ $qualityLabels[$connection->quality_rating] ?? $connection->quality_rating }}
                            </flux:badge>
                        @else
                            <span class="text-neutral-800 dark:text-neutral-100">—</span>
                        @endif
                    </dd>
                </div>
                @if($connection->messaging_limit_tier)
                    <div>
                        <dt class="text-xs text-neutral-400 dark:text-neutral-500">Limite diário da Meta</dt>
                        <dd class="font-medium text-neutral-800 dark:text-neutral-100">
                            {{ $tierLabels[$connection->messaging_limit_tier] ?? $connection->messaging_limit_tier }} clientes por dia
                        </dd>
                    </div>
                @endif
            </dl>

            @if($connection->last_error)
                <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-lg px-4 py-3 text-sm dark:bg-amber-900/30 dark:border-amber-700 dark:text-amber-300">
                    {{ $connection->last_error }}
                </div>
            @endif

            @if($connection->isCoexistence())
                <p class="text-xs text-neutral-500 dark:text-neutral-400">
                    Mantenha o app WhatsApp Business aberto no celular: a Meta desconecta a integração se o app ficar cerca de 14 dias sem uso.
                </p>
            @endif

            @if($canManage)
                <div class="flex flex-wrap gap-3 pt-1">
                    <flux:button wire:click="openTestModal" icon="paper-airplane">Enviar teste</flux:button>
                    <flux:button wire:click="confirmDisconnect" variant="danger">Desconectar</flux:button>
                </div>
            @endif

        {{-- ── Em andamento ── --}}
        @elseif($poll)
            <div class="space-y-4">
                <div class="flex items-center gap-3 text-sm text-neutral-700 dark:text-neutral-300">
                    <svg class="w-5 h-5 animate-spin text-amber-500 shrink-0" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                    </svg>
                    @if($status === \App\Models\WhatsAppConnection::STATUS_PENDING)
                        <span>Conectando com a Meta… isso leva alguns segundos.</span>
                    @elseif($status === \App\Models\WhatsAppConnection::STATUS_PROVISIONING)
                        <span>Número conectado. Criando as mensagens do WhatsApp…</span>
                    @else
                        <span>Aguardando a Meta aprovar as mensagens. Costuma levar de alguns minutos a poucas horas — você pode sair desta tela, a conexão ativa sozinha.</span>
                    @endif
                </div>

                @if($connection->last_error)
                    <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-lg px-4 py-3 text-sm dark:bg-amber-900/30 dark:border-amber-700 dark:text-amber-300">
                        {{ $connection->last_error }}
                    </div>
                @endif

                @if($status !== \App\Models\WhatsAppConnection::STATUS_PENDING)
                    <ul class="divide-y divide-neutral-100 border rounded-lg dark:divide-zinc-700 dark:border-zinc-700">
                        @foreach($this->templateProgress as $template)
                            <li class="flex items-center justify-between gap-3 px-4 py-2.5 text-sm">
                                <div class="min-w-0">
                                    <p class="text-neutral-700 dark:text-neutral-300">{{ $template['label'] }}</p>
                                    @if($template['reason'] && $template['reason'] !== 'NONE')
                                        <p class="text-xs text-red-600 dark:text-red-400 truncate">Motivo: {{ $template['reason'] }}</p>
                                    @endif
                                </div>
                                @if($template['status'])
                                    <flux:badge size="sm" :color="$templateColors[$template['status']] ?? 'zinc'">
                                        {{ $templateStatusLabels[$template['status']] ?? $template['status'] }}
                                    </flux:badge>
                                @else
                                    <flux:badge size="sm" color="zinc">Aguardando envio</flux:badge>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if($canManage)
                    <button type="button" wire:click="confirmDisconnect" class="text-xs text-neutral-500 underline hover:text-red-600 dark:text-neutral-400">
                        Cancelar conexão
                    </button>
                @endif
            </div>

        {{-- ── Não conectado / com erro / desconectado ── --}}
        @else
            <div class="space-y-4">
                @if($status === \App\Models\WhatsAppConnection::STATUS_ERROR)
                    <div class="bg-red-50 border border-red-200 text-red-700 rounded-lg px-4 py-3 text-sm dark:bg-red-900/30 dark:border-red-700 dark:text-red-400">
                        {{ $connection->last_error ?: 'Não foi possível concluir a conexão do WhatsApp. Tente conectar novamente.' }}
                    </div>
                @elseif($status === \App\Models\WhatsAppConnection::STATUS_DISCONNECTED)
                    <p class="text-sm text-neutral-600 dark:text-neutral-400">
                        O WhatsApp foi desconectado{{ $connection->disconnected_at ? ' em '.$connection->disconnected_at->format('d/m/Y H:i') : '' }}. Reconecte para voltar a notificar os clientes.
                    </p>
                @else
                    <div class="text-sm text-neutral-600 space-y-2 dark:text-neutral-400">
                        <p>Conecte o número de WhatsApp do restaurante para avisar o cliente a cada etapa do pedido. Você precisa de:</p>
                        <ul class="list-disc pl-5 space-y-1">
                            <li>uma conta do Facebook para autorizar a conexão;</li>
                            <li>um cartão cadastrado no WhatsApp Manager — a Meta cobra pelas mensagens enviadas;</li>
                            <li>se o número já usa o app WhatsApp Business, o app atualizado no celular (ele continua funcionando).</li>
                        </ul>
                    </div>
                @endif

                @if($canManage)
                    @if($signupConfigured)
                        <div x-data="whatsappSignup(@js($meta))" class="space-y-3">
                            <flux:button variant="primary" icon="chat-bubble-left-ellipsis" x-on:click="connect()" x-bind:disabled="busy">
                                <span x-show="!busy">{{ $isReconnect ? 'Reconectar WhatsApp' : 'Conectar WhatsApp' }}</span>
                                <span x-show="busy" style="display: none">Conectando…</span>
                            </flux:button>

                            <p x-show="error" x-text="error" style="display: none" class="text-sm text-red-600 dark:text-red-400"></p>
                        </div>
                    @else
                        <p class="text-sm text-amber-700 dark:text-amber-400">A conexão com a Meta ainda não foi configurada na plataforma. Fale com o suporte.</p>
                    @endif

                    @if($signupError)
                        <p class="text-sm text-red-600 dark:text-red-400">{{ $signupError }}</p>
                    @endif
                @else
                    <p class="text-sm text-neutral-500 dark:text-neutral-400">Somente o administrador da empresa pode conectar o WhatsApp.</p>
                @endif
            </div>
        @endif

        @if($signupError && ! $showConnect)
            <p class="text-sm text-red-600 dark:text-red-400">{{ $signupError }}</p>
        @endif
    </div>

    {{-- Ativar / Desativar notificações --}}
    <div class="bg-white border rounded-xl shadow-sm p-6 space-y-4 dark:bg-zinc-800 dark:border-zinc-700">
        @unless($canConfigure)
            <div class="bg-neutral-50 border border-neutral-200 text-neutral-600 rounded-lg px-4 py-3 text-sm dark:bg-zinc-700/40 dark:border-zinc-600 dark:text-neutral-300">
                As notificações só podem ser ligadas depois que o WhatsApp estiver conectado e ativo. Quando a conexão ficar ativa, volte aqui e ligue as notificações.
            </div>
        @endunless

        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-neutral-700 dark:text-neutral-300">Ativar notificações automáticas</h2>
                <p class="text-sm text-neutral-500 dark:text-neutral-400 mt-1">Envie atualizações de status do pedido para os clientes via WhatsApp. Só recebe quem aceitar no chat do pedido.</p>
            </div>
            <flux:switch wire:model="enabled" :disabled="! $canConfigure" />
        </div>
    </div>

    {{-- Eventos --}}
    <div class="bg-white border rounded-xl shadow-sm p-6 space-y-4 dark:bg-zinc-800 dark:border-zinc-700">
        <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">Quando notificar</h2>

        <div class="space-y-3">
            @foreach([
                ['model' => 'notifyOnNewOrder',        'title' => 'Pedido recebido',             'desc' => 'Ao confirmar o pedido no chat'],
                ['model' => 'notifyOnAwaitingPayment', 'title' => 'Aguardando pagamento',        'desc' => 'Envio do código PIX por WhatsApp — em breve', 'disabled' => true],
                ['model' => 'notifyOnPaid',            'title' => 'Pagamento confirmado',        'desc' => 'Ao confirmar o recebimento do pagamento online'],
                ['model' => 'notifyOnScheduled',       'title' => 'Pedido agendado',             'desc' => 'Ao confirmar um pedido agendado'],
                ['model' => 'notifyOnPreparing',       'title' => 'Em preparo',                  'desc' => 'Ao marcar o pedido como "Preparando"'],
                ['model' => 'notifyOnReady',           'title' => 'Pronto',                      'desc' => 'Ao marcar o pedido como "Pronto" (retirada ou entrega)'],
                ['model' => 'notifyOnOutForDelivery',  'title' => 'Saiu para entrega',           'desc' => 'Ao marcar o pedido como "A caminho"'],
                ['model' => 'notifyOnDelivered',       'title' => 'Entregue',                    'desc' => 'Ao marcar o pedido como "Entregue"'],
                ['model' => 'notifyOnCancelled',       'title' => 'Cancelado / Reembolsado',     'desc' => 'Ao cancelar ou reembolsar o pedido'],
                ['model' => 'notifyOnAdminMessage',    'title' => 'Mensagem do atendente',       'desc' => 'Quando o atendente envia mensagem no chat do pedido'],
            ] as $item)
                <div class="flex items-center justify-between py-2 {{ !$loop->last ? 'border-b border-neutral-100 dark:border-zinc-700' : '' }}">
                    <div>
                        <p class="text-sm font-medium text-neutral-700 dark:text-neutral-300">{{ $item['title'] }}</p>
                        <p class="text-xs text-neutral-400 dark:text-neutral-500">{{ $item['desc'] }}</p>
                    </div>
                    <flux:switch wire:model="{{ $item['model'] }}" :disabled="($item['disabled'] ?? false) || ! $canConfigure" />
                </div>
            @endforeach
        </div>
    </div>

    {{-- Salvar --}}
    <div class="flex justify-end">
        <flux:button wire:click="save" variant="primary" :disabled="! $canConfigure">
            Salvar configurações
        </flux:button>
    </div>

    {{-- Modal: desconectar (conexão ativa ou em andamento) --}}
    @if($canManage && ($status === \App\Models\WhatsAppConnection::STATUS_ACTIVE || $poll))
    <flux:modal wire:model.self="showDisconnectModal" class="max-w-sm">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">Desconectar o WhatsApp?</flux:heading>
                <flux:subheading class="mt-1">
                    Os clientes deixam de receber as notificações por este número. Você pode reconectar quando quiser.
                    @if($connection?->isCoexistence())
                        O número continua funcionando normalmente no app WhatsApp Business.
                    @endif
                </flux:subheading>
            </div>
            <div class="flex justify-end gap-3">
                <flux:button wire:click="$set('showDisconnectModal', false)" variant="ghost">Cancelar</flux:button>
                <flux:button wire:click="disconnect" variant="danger" wire:loading.attr="disabled" wire:target="disconnect">Desconectar</flux:button>
            </div>
        </div>
    </flux:modal>
    @endif

    {{-- Modal: enviar teste (só com a conexão ativa) --}}
    @if($canManage && $status === \App\Models\WhatsAppConnection::STATUS_ACTIVE)
    <flux:modal wire:model.self="showTestModal" class="max-w-md">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">Enviar mensagem de teste</flux:heading>
                <flux:subheading class="mt-1">
                    Enviamos o aviso "Em preparo" com dados fictícios (pedido #1042) para o celular abaixo, pelo número conectado.
                </flux:subheading>
            </div>

            <flux:input wire:model="testPhone" wire:keydown.enter="sendTest" type="tel" inputmode="numeric" label="Celular com DDD" placeholder="(44) 99999-9999" />

            @if($testResult)
                <p class="text-sm {{ $testFailed ? 'text-red-600 dark:text-red-400' : 'text-green-700 dark:text-green-400' }}">{{ $testResult }}</p>
            @endif

            <div class="flex justify-end gap-3">
                <flux:button wire:click="$set('showTestModal', false)" variant="ghost">Fechar</flux:button>
                <flux:button wire:click="sendTest" variant="primary" wire:loading.attr="disabled" wire:target="sendTest">
                    <span wire:loading.remove wire:target="sendTest">Enviar teste</span>
                    <span wire:loading wire:target="sendTest">Enviando…</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>
    @endif
</div>
