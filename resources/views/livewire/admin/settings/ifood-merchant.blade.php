<div class="rounded-xl border bg-white p-6 space-y-6 dark:bg-zinc-800 dark:border-zinc-700">
    <div>
        <h2 class="font-semibold">Operação da loja no iFood</h2>
        <p class="text-sm text-neutral-500">Client ID: {{ config('ifood.partner_client_id') ?: 'Não configurado' }}</p>
        <p class="text-sm text-neutral-500">Última operação: {{ $merchantCheckedAt ?? 'Ainda não consultado' }} · {{ config('ifood.timezone') }}</p>
        <p class="text-sm text-neutral-500">Pausar a integração suspende a conexão local. Para pausar a loja no iFood, cadastre uma pausa abaixo.</p>
    </div>
    @error('merchantOperation') <p role="alert" class="text-red-600">{{ $message }}</p> @enderror
    <section class="space-y-3">
        <flux:button wire:click="consultMerchant" wire:loading.attr="disabled">Consultar lojas, detalhes e disponibilidade</flux:button>
        @if($merchantStores)
            <h3 class="font-medium">Lojas vinculadas ao aplicativo</h3>
            <ul class="space-y-2">
                @foreach($merchantStores as $store)
                    <li class="text-sm">{{ $store['name'] ?? 'Loja' }} — {{ $store['id'] }}</li>
                @endforeach
            </ul>
        @endif
        @if($merchantDetails)
            <h3 class="font-medium">Detalhes completos da loja desta filial</h3>
            @include('livewire.admin.settings.ifood-data', ['data' => $merchantDetails])
        @endif
        @if($merchantStatus)
            <h3 class="font-medium">Disponibilidade e validações</h3>
            @include('livewire.admin.settings.ifood-data', ['data' => $merchantStatus])
        @endif
    </section>
    <section class="space-y-3 border-t pt-4 dark:border-zinc-700">
        <h3 class="font-semibold">Pausas da loja</h3>
        <flux:input wire:model="interruptionDescription" label="Motivo da pausa" maxlength="255" />
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <flux:input wire:model="interruptionStart" type="datetime-local" label="Início da pausa" />
            <flux:input wire:model="interruptionEnd" type="datetime-local" label="Fim da pausa" />
        </div>
        <div class="flex flex-wrap gap-3">
            <flux:button wire:click="createStoreInterruption" wire:loading.attr="disabled" variant="primary">Criar pausa no iFood</flux:button>
            <flux:button wire:click="consultInterruptions" wire:loading.attr="disabled">Consultar pausas</flux:button>
        </div>
        @forelse($interruptions as $interruption)
            <div class="rounded-lg border p-3 space-y-2 dark:border-zinc-700" wire:key="pause-{{ $interruption['id'] }}">
                <p>{{ $interruption['description'] ?? 'Pausa' }}</p>
                <p class="text-sm">{{ $interruption['start'] ?? '' }} → {{ $interruption['end'] ?? '' }}</p>
                <flux:button wire:click="removeStoreInterruption(@js($interruption['id']))" wire:loading.attr="disabled" size="sm">Remover pausa</flux:button>
            </div>
        @empty
            <p class="text-sm text-neutral-500">{{ $interruptionsLoaded ? 'Nenhuma pausa ativa ou agendada nesta loja.' : 'Consulte as pausas para obter a lista atual de interrupções ativas e agendadas.' }}</p>
        @endforelse
    </section>
    <section class="space-y-3 border-t pt-4 dark:border-zinc-700">
        <h3 class="font-semibold">Horários de funcionamento no iFood</h3>
        <flux:button wire:click="consultOpeningHours" wire:loading.attr="disabled">Consultar horários</flux:button>
        @if($hoursBranchId === $branchId)
            <p class="text-sm text-neutral-500">Salvar substitui a semana inteira. Dias sem turnos ficarão fechados. O preenchimento da homologação mantém os horários de segunda a sexta.</p>
            @error('openingShifts') <p role="alert" class="text-red-600">{{ $message }}</p> @enderror
            @foreach($openingShifts as $index => $shift)
                <div class="grid grid-cols-1 items-end gap-3 sm:grid-cols-4" wire:key="shift-{{ $index }}">
                    <flux:select wire:model="openingShifts.{{ $index }}.dayOfWeek" label="Dia">
                        @foreach($this->weekDays() as $day => $label) <option value="{{ $day }}">{{ $label }}</option> @endforeach
                    </flux:select>
                    <flux:input wire:model="openingShifts.{{ $index }}.start" type="time" label="Abertura" />
                    <flux:input wire:model="openingShifts.{{ $index }}.duration" type="number" min="1" max="1440" label="Duração (minutos)" />
                    <flux:button wire:click="removeOpeningShift({{ $index }})">Remover turno</flux:button>
                </div>
            @endforeach
            <div class="flex flex-wrap gap-3">
                <flux:button wire:click="addOpeningShift">Adicionar turno</flux:button>
                <flux:button wire:click="fillHomologationHours">Preencher sábado e domingo da homologação</flux:button>
                <flux:button wire:click="saveOpeningHours" wire:loading.attr="disabled" variant="primary">Salvar horários no iFood</flux:button>
            </div>
        @endif
    </section>
</div>
