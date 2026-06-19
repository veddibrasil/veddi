<div class="w-full space-y-6">
    <x-admin.page-header
        :back-route="route('admin.branches.index')"
        :title="'Configurar Impressora — ' . $branch->name"
    />

    @if (session('status'))
        <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg text-sm dark:bg-green-900/30 dark:border-green-700 dark:text-green-400">
            {{ session('status') }}
        </div>
    @endif

    <x-admin.form-card title="Dados da impressora">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <flux:input wire:model="name" label="Nome (opcional)" placeholder="Ex: Impressora cozinha" />
                @error('name') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="pb-1 flex items-end">
                <flux:checkbox wire:model="active" label="Impressora ativa para esta filial" />
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
                <flux:checkbox wire:model="autoPrint" label="Imprimir automaticamente ao receber novo pedido" />
            </div>
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
    </x-admin.form-card>

    <x-admin.form-actions
        save-label="Salvar configurações"
        :cancel-route="route('admin.branches.index')"
    />
</div>
