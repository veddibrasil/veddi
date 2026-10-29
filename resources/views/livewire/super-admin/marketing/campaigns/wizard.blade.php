<div class="w-full space-y-6">
    <div class="flex items-center gap-3">
        <a href="{{ route('superadmin.marketing.campaigns.index') }}" wire:navigate class="text-neutral-400 hover:text-neutral-600 dark:text-neutral-500 dark:hover:text-neutral-300" aria-label="Voltar para campanhas">←</a>
        <div>
            <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100">{{ $campaignId ? 'Editar campanha' : 'Nova campanha' }}</h1>
            @if($campaign?->last_error)
                <p class="text-sm text-amber-600 dark:text-amber-400 mt-1">{{ $campaign->last_error }}</p>
            @endif
        </div>
    </div>

    {{-- Passos --}}
    <ol class="grid grid-cols-2 sm:grid-cols-4 gap-2" aria-label="Passos da campanha">
        @foreach(\App\Livewire\SuperAdmin\Marketing\Campaigns\Wizard::STEPS as $number => $label)
            <li>
                <button type="button" wire:click="goToStep({{ $number }})"
                    @class([
                        'w-full rounded-lg border px-3 py-2 text-left text-sm transition',
                        'border-amber-500 bg-amber-50 text-neutral-900 dark:bg-amber-900/20 dark:text-neutral-100' => $step === $number,
                        'border-neutral-200 bg-white text-neutral-600 hover:border-neutral-300 dark:border-zinc-700 dark:bg-zinc-800 dark:text-neutral-300' => $step !== $number,
                    ])
                    @if($step === $number) aria-current="step" @endif>
                    <span class="block text-xs text-neutral-400 dark:text-neutral-500">Passo {{ $number }}</span>
                    <span class="font-medium">{{ $label }}</span>
                </button>
            </li>
        @endforeach
    </ol>

    @include('livewire.super-admin.marketing.partials.notice')

    {{-- 1. Conteúdo --}}
    @if($step === 1)
        <div class="grid grid-cols-1 xl:grid-cols-2 gap-6 items-start">
            <div class="space-y-6">
                <div class="bg-white border rounded-xl shadow-sm p-6 space-y-4 dark:bg-zinc-800 dark:border-zinc-700">
                    <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">Campanha</h2>
                    <div>
                        <flux:input wire:model="name" label="Nome interno" placeholder="Ex.: Lançamento PDV — outubro" />
                        @error('name') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <flux:input wire:model.live.debounce.600ms="subject" label="Assunto" />
                        @error('subject') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <flux:input wire:model.live.debounce.600ms="preheader" label="Pré-cabeçalho (opcional)" description="Texto curto que aparece ao lado do assunto na caixa de entrada." />
                        @error('preheader') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <flux:input wire:model="fromName" label="Nome do remetente" />
                            @error('fromName') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <flux:input wire:model="fromEmail" type="email" label="E-mail do remetente" />
                            @error('fromEmail') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div>
                        <flux:input wire:model="replyTo" type="email" label="Responder para (opcional)" />
                        @error('replyTo') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="bg-white border rounded-xl shadow-sm p-6 space-y-4 dark:bg-zinc-800 dark:border-zinc-700">
                    <div class="flex flex-wrap items-end gap-3">
                        <div class="flex-1 min-w-48">
                            <flux:select wire:model="templateId" label="Começar de um template">
                                <flux:select.option value="">Selecione…</flux:select.option>
                                @foreach($templates as $template)
                                    <flux:select.option value="{{ $template->id }}">{{ $template->name }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        </div>
                        <flux:button wire:click="loadTemplate" :disabled="$templates->isEmpty()">Carregar</flux:button>
                    </div>
                    <p class="text-xs text-neutral-400 dark:text-neutral-500">Carregar substitui os blocos abaixo pelos do template.</p>

                    @include('livewire.super-admin.marketing.partials.block-editor')
                </div>
            </div>

            <div class="bg-white border rounded-xl shadow-sm p-6 space-y-3 dark:bg-zinc-800 dark:border-zinc-700 xl:sticky xl:top-4">
                <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">Preview</h2>
                @include('livewire.super-admin.marketing.partials.preview', ['previewCompanies' => $this->previewCompanies])
            </div>
        </div>
    @endif

    {{-- 2. Público --}}
    @if($step === 2)
        <div class="bg-white border rounded-xl shadow-sm p-6 space-y-5 dark:bg-zinc-800 dark:border-zinc-700">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">Público</h2>
                    <p class="text-sm text-neutral-500 dark:text-neutral-400 mt-1">
                        Usuários das empresas (dono e gerentes).
                        @if(config('marketing.require_opt_in'))
                            Só quem aceitou receber comunicações.
                        @endif
                        Supressões e descadastros já estão descontados.
                    </p>
                </div>
                <div class="rounded-lg bg-amber-50 px-4 py-2 text-right dark:bg-amber-900/20" wire:loading.class="opacity-50">
                    <p class="text-xs text-amber-700 dark:text-amber-300">Destinatários agora</p>
                    <p class="text-2xl font-bold text-neutral-900 dark:text-neutral-100" data-test="audience-count">{{ number_format($this->audienceCount, 0, ',', '.') }}</p>
                </div>
            </div>

            <flux:radio.group wire:model.live="audienceMode" variant="segmented" label="Origem do público">
                <flux:radio value="filters" label="Filtros desta campanha" />
                <flux:radio value="segment" label="Segmento salvo" />
            </flux:radio.group>

            @if($audienceMode === 'segment')
                <div class="max-w-md">
                    <flux:select wire:model.live="segmentId" label="Segmento">
                        <flux:select.option value="">Selecione…</flux:select.option>
                        @foreach($segments as $segment)
                            <flux:select.option value="{{ $segment->id }}">{{ $segment->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    @error('segmentId') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                    @if($segments->isEmpty())
                        <p class="text-xs text-neutral-400 mt-1">Nenhum segmento ainda. <a href="{{ route('superadmin.marketing.segments.index') }}" class="underline" wire:navigate>Criar segmento</a>.</p>
                    @endif
                </div>
            @else
                @include('livewire.super-admin.marketing.partials.audience-filters')
            @endif

            <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ implode(' · ', $this->audienceSummary) }}</p>
        </div>

        <div class="bg-white border rounded-xl shadow-sm p-6 space-y-4 dark:bg-zinc-800 dark:border-zinc-700">
            <flux:switch wire:model.live="abEnabled" label="Teste A/B de assunto" description="Uma amostra recebe o assunto A ou B; depois da espera, a variação com mais aberturas vai para o restante." />
            @if($abEnabled)
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div class="sm:col-span-3">
                        <flux:input wire:model="abSubjectB" label="Assunto B" />
                        @error('abSubjectB') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <flux:input type="number" min="10" max="50" wire:model="abSamplePercent" label="Amostra (%)" description="Dividida entre A e B." />
                        @error('abSamplePercent') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <flux:input type="number" min="1" max="72" wire:model="abWaitHours" label="Esperar (horas)" />
                        @error('abWaitHours') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>
            @endif
        </div>
    @endif

    {{-- 3. Revisão --}}
    @if($step === 3)
        <div class="grid grid-cols-1 xl:grid-cols-2 gap-6 items-start">
            <div class="space-y-6">
                <div class="bg-white border rounded-xl shadow-sm p-6 space-y-3 dark:bg-zinc-800 dark:border-zinc-700">
                    <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">Checklist</h2>
                    <ul class="space-y-2" data-test="checklist">
                        @foreach($this->checklist as $item)
                            <li class="flex items-start gap-2 text-sm">
                                @if($item['ok'])
                                    <flux:icon.check-circle variant="solid" class="size-5 shrink-0 text-green-600 dark:text-green-400" />
                                @else
                                    <flux:icon.x-circle variant="solid" class="size-5 shrink-0 text-red-500" />
                                @endif
                                <div>
                                    <p class="text-neutral-800 dark:text-neutral-100">{{ $item['label'] }}</p>
                                    @if($item['hint'])
                                        <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ $item['hint'] }}</p>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>

                <div class="bg-white border rounded-xl shadow-sm p-6 space-y-3 dark:bg-zinc-800 dark:border-zinc-700">
                    <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">Envio de teste</h2>
                    <p class="text-sm text-neutral-500 dark:text-neutral-400">
                        Até {{ config('marketing.sending.test_max_recipients') }} e-mails, separados por vírgula. Usa os dados da empresa escolhida no preview.
                        @if($abEnabled) Cada endereço recebe as duas variações (A e B). @endif
                    </p>
                    <div class="flex flex-wrap items-start gap-3">
                        <div class="flex-1 min-w-56">
                            <flux:input wire:model="testEmails" placeholder="voce@veddi.com.br, socio@veddi.com.br" aria-label="E-mails de teste" />
                            @error('testEmails') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        <flux:button wire:click="sendTest" icon="paper-airplane" wire:loading.attr="disabled" wire:target="sendTest">Enviar teste</flux:button>
                    </div>
                    @if($campaign?->test_sent_at)
                        <p class="text-xs text-neutral-400 dark:text-neutral-500">Último teste: {{ $campaign->test_sent_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}</p>
                    @endif
                </div>

                <div class="bg-white border rounded-xl shadow-sm p-6 space-y-2 text-sm dark:bg-zinc-800 dark:border-zinc-700">
                    <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">Resumo</h2>
                    <p><span class="text-neutral-500 dark:text-neutral-400">Assunto:</span> {{ $subject }}</p>
                    @if($abEnabled)
                        <p><span class="text-neutral-500 dark:text-neutral-400">Assunto B:</span> {{ $abSubjectB }} ({{ $abSamplePercent }}% de amostra, {{ $abWaitHours }}h)</p>
                    @endif
                    <p><span class="text-neutral-500 dark:text-neutral-400">Remetente:</span> {{ $fromName }} &lt;{{ $fromEmail }}&gt;</p>
                    <p><span class="text-neutral-500 dark:text-neutral-400">Público:</span> {{ number_format($this->audienceCount, 0, ',', '.') }} destinatário(s) — {{ implode(' · ', $this->audienceSummary) }}</p>
                </div>
            </div>

            <div class="bg-white border rounded-xl shadow-sm p-6 space-y-3 dark:bg-zinc-800 dark:border-zinc-700">
                <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">Preview</h2>
                @include('livewire.super-admin.marketing.partials.preview', ['previewCompanies' => $this->previewCompanies])
            </div>
        </div>
    @endif

    {{-- 4. Envio --}}
    @if($step === 4)
        <div class="bg-white border rounded-xl shadow-sm p-6 space-y-5 dark:bg-zinc-800 dark:border-zinc-700 max-w-2xl">
            <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">Quando enviar</h2>
            <flux:radio.group wire:model.live="sendMode">
                <flux:radio value="now" label="Enviar agora" description="O público é recalculado no momento do disparo." />
                <flux:radio value="schedule" label="Agendar" description="Horário de Brasília (America/Sao_Paulo)." />
            </flux:radio.group>

            @if($sendMode === 'schedule')
                <div class="max-w-xs">
                    <flux:input type="datetime-local" wire:model="scheduledAt" label="Data e hora" />
                    @error('scheduledAt') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
            @endif

            @php
                $limits = ['lotes de até '.config('marketing.sending.batch_size')];
                if (config('marketing.sending.rate_per_second') > 0) {
                    $limits[] = 'no máximo '.config('marketing.sending.rate_per_second').' por segundo';
                }
                if (config('marketing.sending.daily_limit') > 0) {
                    $limits[] = number_format(config('marketing.sending.daily_limit'), 0, ',', '.').' por dia (aquecimento do domínio)';
                }
            @endphp
            <p class="text-sm text-neutral-500 dark:text-neutral-400">Envio em {{ implode(', ', $limits) }}.</p>

            <flux:button variant="primary" icon="paper-airplane" wire:click="confirmDispatch">
                {{ $sendMode === 'schedule' ? 'Agendar campanha' : 'Disparar campanha' }}
            </flux:button>
        </div>
    @endif

    {{-- Navegação --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            @if($step > 1)
                <flux:button variant="ghost" icon="arrow-left" wire:click="back">Voltar</flux:button>
            @endif
        </div>
        <div class="flex gap-2">
            @if($step <= 2)
                <flux:button wire:click="save" wire:loading.attr="disabled">Salvar rascunho</flux:button>
            @endif
            @if($step < 4)
                <flux:button variant="primary" icon:trailing="arrow-right" wire:click="next" wire:loading.attr="disabled">Continuar</flux:button>
            @endif
        </div>
    </div>

    <flux:modal wire:model.self="confirmingDispatch" class="max-w-md">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">{{ $sendMode === 'schedule' ? 'Agendar esta campanha?' : 'Disparar esta campanha?' }}</flux:heading>
                <flux:subheading class="mt-2">Depois de começar, o conteúdo não pode mais ser alterado.</flux:subheading>
            </div>
            <dl class="space-y-1 text-sm">
                <div class="flex gap-2"><dt class="text-neutral-500 dark:text-neutral-400">Assunto:</dt><dd class="font-medium text-neutral-900 dark:text-neutral-100">{{ $subject }}</dd></div>
                <div class="flex gap-2"><dt class="text-neutral-500 dark:text-neutral-400">Destinatários:</dt><dd class="font-medium text-neutral-900 dark:text-neutral-100" data-test="confirm-count">{{ number_format($this->audienceCount, 0, ',', '.') }}</dd></div>
                @if($sendMode === 'schedule' && $scheduledAt)
                    <div class="flex gap-2"><dt class="text-neutral-500 dark:text-neutral-400">Quando:</dt><dd class="font-medium text-neutral-900 dark:text-neutral-100">{{ \Illuminate\Support\Carbon::parse($scheduledAt)->format('d/m/Y H:i') }}</dd></div>
                @endif
            </dl>
            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancelar</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" wire:click="dispatchCampaign" wire:loading.attr="disabled">
                    {{ $sendMode === 'schedule' ? 'Agendar' : 'Disparar agora' }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
