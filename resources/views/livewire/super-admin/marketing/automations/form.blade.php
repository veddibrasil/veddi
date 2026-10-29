<div class="w-full space-y-6 max-w-4xl">
    <div class="flex items-center gap-3">
        <a href="{{ route('superadmin.marketing.automations.index') }}" wire:navigate class="text-neutral-400 hover:text-neutral-600 dark:text-neutral-500 dark:hover:text-neutral-300" aria-label="Voltar para automações">←</a>
        <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100">{{ $automationId ? 'Editar automação' : 'Nova automação' }}</h1>
        @if($automation)
            <flux:badge size="sm" :color="$automation->active ? 'green' : 'zinc'">{{ $automation->active ? 'Ativa' : 'Pausada' }}</flux:badge>
        @endif
    </div>

    <div class="bg-white border rounded-xl shadow-sm p-6 space-y-4 dark:bg-zinc-800 dark:border-zinc-700">
        <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">Gatilho</h2>
        <div>
            <flux:input wire:model="name" label="Nome" placeholder="Ex.: Recuperar cadastro" />
            @error('name') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <flux:select wire:model.live="trigger" label="Quando entrar">
                    @foreach($triggers as $option)
                        <flux:select.option value="{{ $option->value }}">{{ $option->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                @if($currentTrigger)
                    <p class="text-xs text-neutral-500 dark:text-neutral-400 mt-1">{{ $currentTrigger->description() }}</p>
                @endif
            </div>
            <div>
                <flux:input type="number" min="1" wire:model="threshold" :label="$currentTrigger?->thresholdLabel() ?? 'Limite'" />
                @error('threshold') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
                <flux:input wire:model="fromName" label="Nome do remetente" />
                @error('fromName') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <flux:input wire:model="fromEmail" type="email" label="E-mail do remetente" />
                @error('fromEmail') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <flux:input wire:model="replyTo" type="email" label="Responder para" />
                @error('replyTo') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
        </div>
    </div>

    <div class="bg-white border rounded-xl shadow-sm p-6 space-y-4 dark:bg-zinc-800 dark:border-zinc-700">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">Sequência</h2>
            <flux:button size="sm" icon="plus" wire:click="addStep">Adicionar e-mail</flux:button>
        </div>
        @error('steps') <p class="text-red-500 text-xs">{{ $message }}</p> @enderror
        @if($templates->isEmpty())
            <p class="text-sm text-amber-700 dark:text-amber-300">Crie um template antes: cada e-mail da sequência usa um. <a href="{{ route('superadmin.marketing.templates.create') }}" wire:navigate class="underline">Novo template</a>.</p>
        @endif

        @foreach($steps as $i => $step)
            @php($stepCampaign = $automation?->steps->firstWhere('id', $step['id'])?->campaign)
            <div wire:key="step-{{ $step['id'] ?? 'new-'.$i }}" class="rounded-lg border border-neutral-200 p-4 space-y-3 dark:border-zinc-700">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-semibold text-neutral-700 dark:text-neutral-200">E-mail {{ $i + 1 }}</span>
                    <div class="flex items-center gap-2">
                        @if($stepCampaign)
                            <a href="{{ route('superadmin.marketing.campaigns.show', $stepCampaign) }}" wire:navigate class="text-xs text-neutral-500 underline dark:text-neutral-400">
                                {{ $stepCampaign->sent_count }} enviados · {{ number_format($stepCampaign->rate('opened_count'), 1, ',', '.') }}% abertura
                            </a>
                        @endif
                        <flux:button size="xs" variant="ghost" icon="trash" wire:click="removeStep({{ $i }})" aria-label="Remover e-mail {{ $i + 1 }}" />
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <flux:input type="number" min="0" wire:model="steps.{{ $i }}.delay_hours" :label="$i === 0 ? 'Esperar após entrar (horas)' : 'Esperar após o anterior (horas)'" />
                        @error('steps.'.$i.'.delay_hours') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <flux:select wire:model="steps.{{ $i }}.template_id" label="Template">
                            <flux:select.option value="">Selecione…</flux:select.option>
                            @foreach($templates as $template)
                                <flux:select.option value="{{ $template->id }}">{{ $template->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        @error('steps.'.$i.'.template_id') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <flux:input wire:model="steps.{{ $i }}.subject" label="Assunto" />
                        @error('steps.'.$i.'.subject') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <flux:input wire:model="steps.{{ $i }}.preheader" label="Pré-cabeçalho (opcional)" />
                </div>
            </div>
        @endforeach
        <p class="text-xs text-neutral-400 dark:text-neutral-500">Salvar copia o conteúdo atual do template para a sequência. Editou o template? Salve a automação de novo.</p>
    </div>

    <div class="flex justify-end gap-2">
        <flux:button :href="route('superadmin.marketing.automations.index')" wire:navigate variant="ghost">Cancelar</flux:button>
        <flux:button variant="primary" wire:click="save" wire:loading.attr="disabled">Salvar automação</flux:button>
    </div>
</div>
