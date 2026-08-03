<div class="w-full space-y-6">
    <x-admin.page-header
        :back-route="route('admin.branches.index')"
        :title="'Configurar Impressoras — ' . $branch->name"
    />

    @if (session('status'))
        <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg text-sm dark:bg-green-900/30 dark:border-green-700 dark:text-green-400">
            {{ session('status') }}
        </div>
    @endif

    <x-admin.form-card title="Impressoras desta filial">
        @if ($this->printers->isEmpty())
            <p class="text-sm text-neutral-500 dark:text-neutral-400">Nenhuma impressora configurada ainda.</p>
        @else
            <div class="space-y-2">
                @foreach ($this->printers as $printer)
                    <div wire:key="printer-{{ $printer->id }}"
                        class="flex flex-wrap items-center justify-between gap-3 border rounded-lg px-4 py-3 dark:border-zinc-700 {{ $editingId === $printer->id ? 'border-amber-400 bg-amber-50/50 dark:bg-amber-900/10' : '' }}">
                        <div class="text-sm">
                            <div class="font-medium text-neutral-800 dark:text-neutral-200">
                                {{ ucfirst($printer->station) }}
                                @if ($printer->name) — {{ $printer->name }} @endif
                                @unless ($printer->active)
                                    <span class="text-xs text-neutral-400">(inativa)</span>
                                @endunless
                            </div>
                            <div class="text-xs text-neutral-500 dark:text-neutral-400">
                                {{ $printer->ip_address }}:{{ $printer->port }} · {{ $printer->paper_width }}mm
                                @if ($printer->auto_print) · cupom automático @endif
                                @if ($printer->print_fiscal_note) · nota fiscal automática @endif
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <flux:button wire:click="edit({{ $printer->id }})" variant="outline" size="sm">Editar</flux:button>
                            <flux:button wire:click="delete({{ $printer->id }})" wire:confirm="Remover esta impressora?"
                                variant="outline" size="sm" class="!text-red-600">Remover</flux:button>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-admin.form-card>

    <x-admin.form-card :title="$editingId ? 'Editar impressora' : 'Adicionar impressora'">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">Estação</label>
                <flux:select wire:model="station">
                    @if ($editingId)
                        <option value="{{ $station }}">{{ ucfirst($station) }}</option>
                    @endif
                    @foreach ($this->availableStations as $availableStation)
                        <option value="{{ $availableStation }}">{{ ucfirst($availableStation) }}</option>
                    @endforeach
                </flux:select>
                @error('station') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <flux:input wire:model="name" label="Nome (opcional)" placeholder="Ex: Impressora cozinha" />
                @error('name') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <flux:input wire:model="ipAddress" label="Endereço IP" placeholder="Ex: 192.168.0.50" />
                @error('ipAddress') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <flux:input wire:model="port" type="number" min="1" max="65535"
                    label="Porta" placeholder="9100" />
                @error('port') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">Largura do papel</label>
                <flux:select wire:model="paperWidth">
                    <option value="58">58mm</option>
                    <option value="80">80mm</option>
                </flux:select>
                @error('paperWidth') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="pb-1 flex items-end">
                <flux:checkbox wire:model="active" label="Impressora ativa" />
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <flux:checkbox wire:model="autoPrint" label="Imprimir cupom automaticamente ao finalizar pagamento" />
            <flux:checkbox wire:model="printFiscalNote" label="Imprimir nota fiscal (NFC-e) automaticamente ao autorizar" />
        </div>

        <div class="flex items-center gap-3 flex-wrap pt-2">
            <button wire:click="testConnection" type="button"
                wire:loading.attr="disabled" wire:target="testConnection"
                class="inline-flex items-center gap-1.5 text-xs bg-blue-50 text-blue-700 border border-blue-200 px-3 py-1.5 rounded-lg hover:bg-blue-100 transition-colors disabled:opacity-60 dark:bg-blue-900/30 dark:text-blue-300 dark:border-blue-700">
                <span wire:loading.remove wire:target="testConnection">Testar conexão</span>
                <span wire:loading wire:target="testConnection">Testando...</span>
            </button>

            @if ($testResult === true)
                <span class="text-xs text-green-600 dark:text-green-400">✓ Impressora respondeu no IP:porta informados.</span>
            @elseif ($testResult === false)
                <span class="text-xs text-red-500">✗ Não foi possível conectar. Verifique IP, porta e rede.</span>
            @endif
        </div>

        <div class="flex gap-3 pt-2">
            <flux:button wire:click="save" class="!bg-amber-500 !text-white hover:!bg-amber-600" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="save">{{ $editingId ? 'Salvar alterações' : 'Adicionar impressora' }}</span>
                <span wire:loading wire:target="save">Salvando...</span>
            </flux:button>

            @if ($editingId)
                <flux:button wire:click="resetForm" variant="outline">Cancelar edição</flux:button>
            @endif
        </div>
    </x-admin.form-card>

    <div class="pb-8">
        <a href="{{ route('admin.branches.index') }}"
            class="inline-flex items-center px-4 py-2 text-sm text-neutral-600 hover:text-neutral-800 dark:text-neutral-400 dark:hover:text-neutral-200">
            Voltar
        </a>
    </div>
</div>
