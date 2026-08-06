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

    <x-admin.form-card title="Como instalar e configurar o QZ Tray">
        <p class="text-sm text-neutral-600 dark:text-neutral-300">
            O QZ Tray é o programa que roda no computador do caixa e conecta o navegador
            à impressora física — sem ele instalado e aberto, nenhuma impressão (rede ou
            USB) funciona, mesmo com a impressora corretamente cadastrada abaixo.
        </p>

        <ol class="list-decimal list-inside text-sm text-neutral-600 dark:text-neutral-300 space-y-2 pt-1">
            <li>
                No computador do caixa, baixe e instale o QZ Tray em
                <a href="https://qz.io/download/" target="_blank" rel="noopener"
                    class="text-blue-600 hover:underline dark:text-blue-400">qz.io/download</a>.
            </li>
            <li>
                Abra o QZ Tray (ícone fica na bandeja do sistema, perto do relógio) antes de
                usar o PDV. Ele precisa continuar aberto durante todo o turno de caixa.
            </li>
            <li>
                Se a impressora for <strong>USB</strong>, instale o driver dela no Windows/Mac
                normalmente e depois cadastre abaixo usando exatamente o nome que aparece na
                lista de impressoras do sistema operacional.
            </li>
            <li>
                Sem configurar mais nada, o QZ Tray vai exibir um popup de "Action Required"
                pedindo permissão a cada impressão. Para eliminar esse popup nessa estação:
                <ol class="list-[lower-alpha] list-inside pl-4 pt-1 space-y-1">
                    <li>
                        Baixe o certificado desta empresa clicando em
                        <a href="{{ route('admin.pdv.qz-certificate') }}" target="_blank"
                            class="text-blue-600 hover:underline dark:text-blue-400">baixar certificado</a>
                        e salve o arquivo com o nome exato <code class="px-1 py-0.5 rounded bg-neutral-100 dark:bg-zinc-700">override.crt</code>.
                    </li>
                    <li>Feche o QZ Tray (ícone na bandeja &gt; Exit).</li>
                    <li>
                        Copie o <code class="px-1 py-0.5 rounded bg-neutral-100 dark:bg-zinc-700">override.crt</code>
                        para a pasta de instalação do QZ Tray:
                        Windows <code class="px-1 py-0.5 rounded bg-neutral-100 dark:bg-zinc-700">C:\Program Files\QZ Tray\</code>,
                        macOS <code class="px-1 py-0.5 rounded bg-neutral-100 dark:bg-zinc-700">/Applications/QZ Tray.app/Contents/Resources/</code>
                        (confirme o caminho em QZ Tray &gt; Advanced &gt; About se a versão instalada usar outro local).
                    </li>
                    <li>Abra o QZ Tray de novo. A partir daí a impressão não mostra mais o popup nessa máquina.</li>
                </ol>
            </li>
        </ol>

        <p class="text-xs text-neutral-500 dark:text-neutral-400 pt-1">
            Esse passo a passo é feito uma vez por computador de caixa, não a cada impressão.
            Se o certificado da empresa for trocado, repita o passo 4 em cada estação.
        </p>
    </x-admin.form-card>

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
                                @if ($printer->connection_type === 'usb')
                                    USB · {{ $printer->printer_name }}
                                @else
                                    {{ $printer->ip_address }}:{{ $printer->port }}
                                @endif
                                · {{ $printer->paper_width }}mm
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

        <div>
            <label class="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">Tipo de conexão</label>
            <flux:select wire:model.live="connectionType">
                <option value="network">Rede (IP)</option>
                <option value="usb">USB (impressora instalada no computador do caixa)</option>
            </flux:select>
            @error('connectionType') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
        </div>

        @if ($connectionType === 'usb')
            <div>
                <flux:input wire:model="printerName" label="Nome da impressora" placeholder="Ex: EPSON TM-T20" />
                <p class="text-xs text-neutral-500 dark:text-neutral-400 mt-1">
                    Use exatamente o nome como aparece na lista de impressoras do Windows/Mac do computador do caixa
                    (o QZ Tray precisa estar instalado e a impressora, com driver instalado nesse computador).
                </p>
                @error('printerName') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
        @else
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
        @endif

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

        @if ($connectionType === 'usb')
            <p class="text-xs text-neutral-500 dark:text-neutral-400 pt-2">
                Não dá pra testar impressora USB por aqui — o teste depende do computador do caixa, não do servidor.
                Confira se o QZ Tray está aberto e se o nome digitado acima bate com a lista de impressoras do sistema.
            </p>
        @else
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
        @endif

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
