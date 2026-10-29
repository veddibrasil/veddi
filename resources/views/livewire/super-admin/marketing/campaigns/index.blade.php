<div class="space-y-6">
    @include('livewire.super-admin.marketing.partials.header', [
        'title' => 'E-mail marketing',
        'description' => 'Campanhas para empresas cadastradas ou possíveis clientes de listas externas: teste, disparo e relatório.',
        'actionRoute' => 'superadmin.marketing.campaigns.create',
        'actionLabel' => 'Nova campanha',
    ])

    @include('livewire.super-admin.marketing.partials.notice')

    @if($needsStarterKit)
        <div class="rounded-xl border border-amber-200 bg-amber-50 px-5 py-4 flex flex-wrap items-center justify-between gap-3 dark:border-amber-800 dark:bg-amber-900/20">
            <div class="text-sm">
                <p class="font-semibold text-neutral-800 dark:text-neutral-100">Primeiros passos: comece com os modelos prontos</p>
                <p class="text-neutral-600 dark:text-neutral-300 mt-0.5">Instala templates, segmentos e automações (pausadas) de uma vez. O que já existe com o mesmo nome não muda.</p>
            </div>
            <flux:button variant="primary" icon="sparkles" wire:click="installStarterKit">Instalar modelos prontos</flux:button>
        </div>
    @endif

    @foreach($recentlyFinished as $finished)
        <div wire:key="finished-{{ $finished->id }}" @class([
            'rounded-lg border px-4 py-3 text-sm flex flex-wrap items-center justify-between gap-2',
            'bg-sky-50 border-sky-200 text-sky-800 dark:bg-sky-900/20 dark:border-sky-800 dark:text-sky-300' => $finished->status === \App\Enums\EmailCampaignStatus::Sent,
            'bg-red-50 border-red-200 text-red-700 dark:bg-red-900/20 dark:border-red-800 dark:text-red-300' => $finished->status !== \App\Enums\EmailCampaignStatus::Sent,
        ])>
            <span>
                @if($finished->status === \App\Enums\EmailCampaignStatus::Sent)
                    Campanha <strong>{{ $finished->name }}</strong> concluída {{ $finished->finished_at->diffForHumans() }}: {{ number_format($finished->sent_count, 0, ',', '.') }} enviados{{ $finished->failed_count ? ', '.$finished->failed_count.' falhas' : '' }}.
                @else
                    Campanha <strong>{{ $finished->name }}</strong> falhou: {{ $finished->last_error }}
                @endif
            </span>
            <a href="{{ route('superadmin.marketing.campaigns.show', $finished->id) }}" wire:navigate class="font-medium underline">Ver relatório</a>
        </div>
    @endforeach

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-1 flex-wrap gap-3">
            <flux:input wire:model.live.debounce.300ms="search" placeholder="Buscar por nome ou assunto..." class="max-w-sm" icon="magnifying-glass" />
            <flux:select wire:model.live="status" class="max-w-52" aria-label="Filtrar por status">
                <flux:select.option value="">Todos os status</flux:select.option>
                @foreach($statuses as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
        <flux:button icon="sparkles" wire:click="$set('choosingPreset', true)">Começar de um modelo</flux:button>
    </div>

    <div class="bg-white border rounded-xl shadow-sm overflow-x-auto dark:bg-zinc-800 dark:border-zinc-700">
        <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-neutral-500 text-xs uppercase tracking-wide dark:bg-zinc-700/50 dark:text-neutral-400">
                <tr>
                    <th class="px-4 py-3 text-left">Campanha</th>
                    <th class="px-4 py-3 text-center">Status</th>
                    <th class="px-4 py-3 text-left">Público</th>
                    <th class="px-4 py-3 text-left">Quando</th>
                    <th class="px-4 py-3 text-right">Enviados</th>
                    <th class="px-4 py-3 text-right">Abertura</th>
                    <th class="px-4 py-3 text-right">Clique</th>
                    <th class="px-4 py-3 text-right">Ações</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100 dark:divide-zinc-700">
                @forelse($campaigns as $campaign)
                    <tr wire:key="campaign-{{ $campaign->id }}" class="hover:bg-neutral-50 transition dark:hover:bg-zinc-700/50">
                        <td class="px-4 py-3">
                            <a href="{{ $campaign->status->isEditable() ? route('superadmin.marketing.campaigns.edit', $campaign) : route('superadmin.marketing.campaigns.show', $campaign) }}" wire:navigate class="font-medium text-neutral-800 hover:text-amber-600 dark:text-neutral-100 dark:hover:text-amber-400">
                                {{ $campaign->name }}
                            </a>
                            <p class="text-xs text-neutral-400 dark:text-neutral-500 truncate max-w-xs">{{ $campaign->subject ?: 'Sem assunto' }}</p>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <flux:badge size="sm" :color="$campaign->status->color()">{{ $campaign->status->label() }}</flux:badge>
                        </td>
                        <td class="px-4 py-3 text-neutral-600 dark:text-neutral-300 max-w-56">
                            <span class="line-clamp-2 text-xs">{{ $campaign->segment?->name ?? implode(' · ', $campaign->audienceFilter()->describe()) }}</span>
                        </td>
                        <td class="px-4 py-3 text-xs text-neutral-600 dark:text-neutral-300 whitespace-nowrap">
                            @if($campaign->finished_at)
                                {{ $campaign->finished_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}
                            @elseif($campaign->scheduled_at && $campaign->status === \App\Enums\EmailCampaignStatus::Scheduled)
                                Agendada: {{ $campaign->scheduled_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}
                            @elseif($campaign->started_at)
                                Início: {{ $campaign->started_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums text-neutral-700 dark:text-neutral-200">
                            {{ number_format($campaign->sent_count, 0, ',', '.') }}@if($campaign->recipients_count)<span class="text-neutral-400">/{{ number_format($campaign->recipients_count, 0, ',', '.') }}</span>@endif
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums text-neutral-700 dark:text-neutral-200">{{ $campaign->sent_count ? number_format($campaign->rate('opened_count'), 1, ',', '.').'%' : '—' }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-neutral-700 dark:text-neutral-200">{{ $campaign->sent_count ? number_format($campaign->rate('clicked_count'), 1, ',', '.').'%' : '—' }}</td>
                        <td class="px-4 py-3 text-right">
                            <flux:dropdown position="bottom" align="end">
                                <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" aria-label="Ações da campanha" />
                                <flux:menu>
                                    @if($campaign->status->isEditable())
                                        <flux:menu.item icon="pencil-square" :href="route('superadmin.marketing.campaigns.edit', $campaign)" wire:navigate>Editar</flux:menu.item>
                                    @endif
                                    @if(! $campaign->status->isEditable() || $campaign->status === \App\Enums\EmailCampaignStatus::Scheduled)
                                        <flux:menu.item icon="chart-bar" :href="route('superadmin.marketing.campaigns.show', $campaign)" wire:navigate>Relatório</flux:menu.item>
                                    @endif
                                    <flux:menu.item icon="document-duplicate" wire:click="duplicate({{ $campaign->id }})">Duplicar</flux:menu.item>
                                    @if($campaign->status === \App\Enums\EmailCampaignStatus::Scheduled)
                                        <flux:menu.item icon="calendar" wire:click="unschedule({{ $campaign->id }})">Remover agendamento</flux:menu.item>
                                    @endif
                                    @if($campaign->status->canPause())
                                        <flux:menu.item icon="pause" wire:click="pause({{ $campaign->id }})">Pausar</flux:menu.item>
                                    @endif
                                    @if($campaign->status === \App\Enums\EmailCampaignStatus::Paused)
                                        <flux:menu.item icon="play" wire:click="resume({{ $campaign->id }})">Retomar</flux:menu.item>
                                    @endif
                                    @if($campaign->status->canCancel())
                                        <flux:menu.item icon="x-circle" variant="danger" wire:click="confirmCancel({{ $campaign->id }})">Cancelar envio</flux:menu.item>
                                    @endif
                                    @if($campaign->status === \App\Enums\EmailCampaignStatus::Draft)
                                        <flux:menu.item icon="trash" variant="danger" wire:click="delete({{ $campaign->id }})">Excluir rascunho</flux:menu.item>
                                    @endif
                                </flux:menu>
                            </flux:dropdown>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-10 text-center text-neutral-400 dark:text-neutral-500">
                            Nenhuma campanha ainda. <button type="button" wire:click="$set('choosingPreset', true)" class="underline">Começar de um modelo</button> ou <a href="{{ route('superadmin.marketing.campaigns.create') }}" wire:navigate class="underline">criar do zero</a>.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $campaigns->links() }}

    <flux:modal wire:model.self="choosingPreset" class="w-full max-w-4xl">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">Começar de um modelo</flux:heading>
                <flux:subheading class="mt-1">Cria um rascunho com conteúdo e público prontos. Nada é enviado: você revisa, envia um teste e dispara pelo passo a passo.</flux:subheading>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                @foreach($presets as $key => $preset)
                    <div wire:key="preset-{{ $key }}" class="flex flex-col justify-between gap-3 rounded-lg border border-neutral-200 p-4 dark:border-zinc-700">
                        <div class="space-y-1">
                            <p class="font-semibold text-sm text-neutral-800 dark:text-neutral-100">{{ $preset['name'] }}</p>
                            <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ $preset['description'] }}</p>
                            <p class="text-[11px] text-neutral-400 dark:text-neutral-500 pt-1">Público: {{ $preset['segment'] }}</p>
                        </div>
                        <flux:button size="sm" wire:click="createFromPreset('{{ $key }}')" wire:loading.attr="disabled">Usar este modelo</flux:button>
                    </div>
                @endforeach
            </div>
        </div>
    </flux:modal>

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
