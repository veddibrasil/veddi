<div class="w-full space-y-6">
    <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100">Configurações Fiscais</h1>

    @if(session('status'))
        <div class="bg-green-50 border border-green-200 text-green-700 rounded-lg px-4 py-3 text-sm dark:bg-green-900/30 dark:border-green-700 dark:text-green-400">
            {{ session('status') }}
        </div>
    @endif

    @unless($canManage)
        <div class="bg-yellow-50 border border-yellow-200 text-yellow-700 rounded-lg px-4 py-3 text-sm dark:bg-yellow-900/30 dark:border-yellow-700 dark:text-yellow-400">
            Sem permissão para editar configurações fiscais.
        </div>
    @endunless

    <form wire:submit="save" class="space-y-6">
        {{-- Habilitação --}}
        <div class="bg-white border rounded-xl shadow-sm p-6 space-y-4 dark:bg-zinc-800 dark:border-zinc-700">
            <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">Emissão de NFC-e</h2>

            <div class="flex items-center gap-3">
                <flux:switch wire:model="enabled" :disabled="!$canManage" />
                <span class="text-sm text-neutral-600 dark:text-neutral-300">Habilitar emissão de NFC-e para esta empresa</span>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <flux:select wire:model="crt" label="Regime Tributário (CRT)" :disabled="!$canManage">
                        <option value="1">1 - Simples Nacional</option>
                        <option value="2">2 - Simples Nacional — Excesso de Sublimite</option>
                        <option value="3">3 - Regime Normal</option>
                    </flux:select>
                    @error('crt') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <flux:input wire:model="inscricaoEstadual" label="Inscrição Estadual" placeholder="Deixe em branco se isento" :disabled="!$canManage" />
                    @error('inscricaoEstadual') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <flux:select wire:model="environment" label="Ambiente" :disabled="!$canManage">
                        <option value="homologacao">Homologação (testes)</option>
                        <option value="producao">Produção</option>
                    </flux:select>
                    @error('environment') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <flux:input wire:model="nfceSerie" label="Série NFC-e" type="number" min="1" :disabled="!$canManage" />
                    @error('nfceSerie') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        {{-- Provider --}}
        <div class="bg-white border rounded-xl shadow-sm p-6 space-y-4 dark:bg-zinc-800 dark:border-zinc-700">
            <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">Integração Focus NFe</h2>

            <div>
                <flux:input wire:model="providerToken" label="Token Focus NFe" type="password" placeholder="Token da API" :disabled="!$canManage" />
                @error('providerToken') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
        </div>

        {{-- Certificado --}}
        <div class="bg-white border rounded-xl shadow-sm p-6 space-y-4 dark:bg-zinc-800 dark:border-zinc-700">
            <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">Certificado Digital A1</h2>
            <p class="text-xs text-neutral-400 dark:text-neutral-500">Envie apenas para atualizar o certificado. Arquivos aceitos: .pfx, .p12</p>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">Arquivo do Certificado</label>
                    <input type="file" wire:model="certificateFile" accept=".pfx,.p12" @disabled(!$canManage)
                        class="block w-full text-sm text-neutral-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-purple-50 file:text-purple-700 hover:file:bg-purple-100 dark:text-neutral-400">
                    @error('certificateFile') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <flux:input wire:model="certificatePassword" label="Senha do Certificado" type="password" placeholder="Senha do arquivo .pfx" :disabled="!$canManage" />
                    @error('certificatePassword') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        @if($canManage)
            <div class="flex justify-end">
                <flux:button type="submit" variant="primary">Salvar Configurações</flux:button>
            </div>
        @endif
    </form>
</div>
