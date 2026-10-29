<div class="space-y-6" @if($live) wire:poll.10s @endif>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-start gap-3">
            <a href="{{ $campaign->isAutomation() && $campaign->automationStep ? route('superadmin.marketing.automations.edit', $campaign->automationStep->automation_id) : route('superadmin.marketing.campaigns.index') }}" wire:navigate class="mt-1 text-neutral-400 hover:text-neutral-600 dark:text-neutral-500 dark:hover:text-neutral-300" aria-label="Voltar">←</a>
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100">{{ $campaign->name }}</h1>
                    <flux:badge size="sm" :color="$campaign->status->color()" data-test="campaign-status">{{ $campaign->status->label() }}</flux:badge>
                </div>
                <p class="text-sm text-neutral-500 dark:text-neutral-400 mt-1">
                    {{ $campaign->subject }}
                    @if($campaign->started_at) · início {{ $campaign->started_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }} @endif
                    @if($campaign->finished_at) · fim {{ $campaign->finished_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }} @endif
                    @if($campaign->scheduled_at && $campaign->status === \App\Enums\EmailCampaignStatus::Scheduled) · agendada para {{ $campaign->scheduled_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }} @endif
                </p>
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            @if(! $campaign->isAutomation())
                @if($campaign->status->canPause())
                    <flux:button icon="pause" wire:click="pause">Pausar</flux:button>
                @endif
                @if($campaign->status === \App\Enums\EmailCampaignStatus::Paused)
                    <flux:button icon="play" variant="primary" wire:click="resume">Retomar</flux:button>
                @endif
                @if($campaign->status->canCancel())
                    <flux:button icon="x-circle" variant="danger" wire:click="$set('confirmingCancel', true)">Cancelar</flux:button>
                @endif
                <flux:button icon="document-duplicate" wire:click="duplicate">Duplicar</flux:button>
            @endif
            <flux:button icon="arrow-path" wire:click="refreshStats" aria-label="Atualizar números" />
            <flux:button icon="arrow-down-tray" :href="route('superadmin.marketing.campaigns.export', $campaign)">CSV</flux:button>
        </div>
    </div>

    @include('livewire.super-admin.marketing.partials.notice')

    @if($campaign->last_error)
        <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-lg px-4 py-3 text-sm dark:bg-amber-900/20 dark:border-amber-800 dark:text-amber-300" role="alert">
            {{ $campaign->last_error }}
        </div>
    @endif

    @if($live || $campaign->status === \App\Enums\EmailCampaignStatus::Paused)
        <div class="bg-white border rounded-xl shadow-sm p-5 space-y-2 dark:bg-zinc-800 dark:border-zinc-700">
            <div class="flex items-center justify-between text-sm">
                <span class="font-medium text-neutral-700 dark:text-neutral-200">
                    {{ $campaign->status === \App\Enums\EmailCampaignStatus::Building ? 'Montando o público…' : 'Progresso do envio' }}
                </span>
                <span class="tabular-nums text-neutral-500 dark:text-neutral-400" data-test="progress">{{ $progress }}%</span>
            </div>
            <div class="h-2 w-full rounded-full bg-neutral-100 dark:bg-zinc-700" role="progressbar" aria-valuenow="{{ $progress }}" aria-valuemin="0" aria-valuemax="100">
                <div class="h-2 rounded-full bg-amber-500 transition-all" style="width: {{ $progress }}%"></div>
            </div>
            <p class="text-xs text-neutral-400 dark:text-neutral-500">Atualiza sozinho durante o envio.</p>
        </div>
    @endif

    {{-- Funil --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        @foreach($funnel as $step)
            <div class="bg-white border rounded-xl shadow-sm p-4 dark:bg-zinc-800 dark:border-zinc-700">
                <p class="text-xs uppercase tracking-wide text-neutral-500 dark:text-neutral-400">{{ $step['label'] }}</p>
                <p class="mt-1 text-2xl font-bold tabular-nums text-neutral-900 dark:text-neutral-100">{{ number_format($step['value'], 0, ',', '.') }}</p>
                @unless($loop->first)
                    <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ number_format($step['percent'], 1, ',', '.') }}% dos enviados</p>
                @else
                    <p class="text-xs text-neutral-500 dark:text-neutral-400">de {{ number_format($campaign->recipients_count, 0, ',', '.') }} destinatários</p>
                @endunless
            </div>
        @endforeach
    </div>

    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm">
        @foreach([
            ['Bounces', $campaign->bounced_count],
            ['Spam', $campaign->complained_count],
            ['Descadastros', $campaign->unsubscribed_count],
            ['Falhas', $campaign->failed_count],
        ] as [$label, $value])
            <div class="rounded-lg border border-neutral-200 px-4 py-3 dark:border-zinc-700">
                <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ $label }}</p>
                <p class="font-semibold tabular-nums text-neutral-800 dark:text-neutral-100">{{ number_format($value, 0, ',', '.') }}</p>
            </div>
        @endforeach
    </div>
    <p class="text-xs text-neutral-400 dark:text-neutral-500">Aberturas são estimativa: o Apple Mail (Proteção de Privacidade) abre os e-mails sozinho, e alguns clientes bloqueiam imagens.</p>

    @if($ab)
        <div class="bg-white border rounded-xl shadow-sm p-5 space-y-3 dark:bg-zinc-800 dark:border-zinc-700">
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">Teste A/B</h2>
                @if($campaign->ab_winner)
                    <flux:badge color="green" size="sm">Vencedora: {{ strtoupper($campaign->ab_winner) }}</flux:badge>
                @elseif($campaign->started_at)
                    <span class="text-xs text-neutral-500 dark:text-neutral-400">Decide em {{ $campaign->started_at->copy()->addHours($campaign->ab_wait_hours)->timezone('America/Sao_Paulo')->format('d/m H:i') }}</span>
                @endif
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                @foreach($ab as $variant => $result)
                    <div @class(['rounded-lg border p-4', 'border-green-400 dark:border-green-600' => $campaign->ab_winner === $variant, 'border-neutral-200 dark:border-zinc-700' => $campaign->ab_winner !== $variant])>
                        <p class="text-xs font-semibold text-neutral-500 dark:text-neutral-400">Variação {{ strtoupper($variant) }}</p>
                        <p class="font-medium text-neutral-800 dark:text-neutral-100">{{ $result['subject'] }}</p>
                        <p class="text-sm text-neutral-600 dark:text-neutral-300 mt-1">{{ $result['sent'] }} enviados · {{ number_format($result['open_rate'], 1, ',', '.') }}% abertura · {{ $result['clicked'] }} cliques</p>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Cliques por link --}}
    <div class="bg-white border rounded-xl shadow-sm overflow-x-auto dark:bg-zinc-800 dark:border-zinc-700">
        <div class="px-4 py-3 border-b border-neutral-100 dark:border-zinc-700">
            <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">Cliques por link</h2>
        </div>
        <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-neutral-500 text-xs uppercase tracking-wide dark:bg-zinc-700/50 dark:text-neutral-400">
                <tr>
                    <th class="px-4 py-2 text-left">Link</th>
                    <th class="px-4 py-2 text-right">Únicos</th>
                    <th class="px-4 py-2 text-right">Total</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100 dark:divide-zinc-700">
                @forelse($links as $link)
                    <tr>
                        <td class="px-4 py-2 text-neutral-700 dark:text-neutral-200 break-all">{{ $link->url }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $link->unique_clicks }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $link->clicks }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-4 py-6 text-center text-neutral-400 dark:text-neutral-500">Nenhum clique ainda.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Destinatários --}}
    <div class="space-y-3">
        <div class="flex flex-wrap gap-3">
            <flux:input wire:model.live.debounce.300ms="search" placeholder="Buscar e-mail..." class="max-w-xs" icon="magnifying-glass" />
            <flux:select wire:model.live="recipientStatus" class="max-w-56" aria-label="Filtrar destinatários por status">
                <flux:select.option value="">Todos ({{ number_format($campaign->recipients_count, 0, ',', '.') }})</flux:select.option>
                @foreach($recipientStatuses as $status)
                    @if($statusCounts->has($status->value))
                        <flux:select.option value="{{ $status->value }}">{{ $status->label() }} ({{ $statusCounts[$status->value] }})</flux:select.option>
                    @endif
                @endforeach
            </flux:select>
        </div>
        <div class="bg-white border rounded-xl shadow-sm overflow-x-auto dark:bg-zinc-800 dark:border-zinc-700">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 text-neutral-500 text-xs uppercase tracking-wide dark:bg-zinc-700/50 dark:text-neutral-400">
                    <tr>
                        <th class="px-4 py-2 text-left">Destinatário</th>
                        <th class="px-4 py-2 text-left">Empresa</th>
                        <th class="px-4 py-2 text-center">Status</th>
                        <th class="px-4 py-2 text-left">Enviado</th>
                        <th class="px-4 py-2 text-left">Abriu / clicou</th>
                        <th class="px-4 py-2 text-left">Erro</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-zinc-700">
                    @forelse($recipients as $recipient)
                        <tr wire:key="recipient-{{ $recipient->id }}">
                            <td class="px-4 py-2">
                                <p class="text-neutral-800 dark:text-neutral-100">{{ $recipient->email }}</p>
                                <p class="text-xs text-neutral-400 dark:text-neutral-500">{{ $recipient->name }}@if($recipient->variant) · variação {{ strtoupper($recipient->variant) }}@endif</p>
                            </td>
                            <td class="px-4 py-2 text-neutral-600 dark:text-neutral-300">{{ $recipient->company?->name ?? '—' }}</td>
                            <td class="px-4 py-2 text-center"><flux:badge size="sm" :color="$recipient->status->color()">{{ $recipient->status->label() }}</flux:badge></td>
                            <td class="px-4 py-2 text-xs text-neutral-600 dark:text-neutral-300 whitespace-nowrap">{{ $recipient->sent_at?->timezone('America/Sao_Paulo')->format('d/m H:i') ?? '—' }}</td>
                            <td class="px-4 py-2 text-xs text-neutral-600 dark:text-neutral-300 whitespace-nowrap">
                                {{ $recipient->first_opened_at?->timezone('America/Sao_Paulo')->format('d/m H:i') ?? '—' }}
                                /
                                {{ $recipient->first_clicked_at?->timezone('America/Sao_Paulo')->format('d/m H:i') ?? '—' }}
                            </td>
                            <td class="px-4 py-2 text-xs text-red-600 dark:text-red-400 max-w-xs"><span class="line-clamp-2" title="{{ $recipient->error }}">{{ $recipient->error }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-6 text-center text-neutral-400 dark:text-neutral-500">
                            {{ $campaign->status->isEditable() ? 'Os destinatários são montados no disparo.' : 'Nenhum destinatário com esse filtro.' }}
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $recipients->links() }}
    </div>

    <flux:modal wire:model.self="confirmingCancel" class="max-w-sm">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">Cancelar o envio?</flux:heading>
                <flux:subheading class="mt-1">Quem ainda não recebeu não vai receber. Não dá para desfazer.</flux:subheading>
            </div>
            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button variant="ghost">Voltar</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="cancel">Cancelar envio</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
