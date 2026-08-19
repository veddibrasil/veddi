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
        {{-- Cadastro completo exigido pela Focus NFe --}}
        <div class="bg-white border rounded-xl shadow-sm p-6 space-y-4 dark:bg-zinc-800 dark:border-zinc-700">
            <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">Cadastro para emissão fiscal</h2>
            <p class="text-xs text-neutral-400 dark:text-neutral-500">
                Dados exigidos pela Focus NFe para registrar o emissor. Campos já preenchidos em outras telas aparecem bloqueados aqui — edite-os em
                <a href="{{ route('admin.settings') }}" class="underline">Configurações da Empresa</a> ou
                <a href="{{ route('admin.branches.index') }}" class="underline">Filiais</a>.
            </p>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <flux:input value="{{ $companyName }}" label="Nome da empresa" disabled />
                </div>
                <div>
                    <flux:input value="{{ $companyEmail ?: '—' }}" label="E-mail" disabled />
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    @if($hasBranchCnpj)
                        <flux:input value="{{ $branchCnpj }}" label="CNPJ da filial" disabled />
                        <p class="text-xs text-green-600 dark:text-green-400 mt-1">CNPJ já cadastrado.</p>
                    @else
                        <flux:input wire:model="branchCnpj" label="CNPJ da filial" placeholder="Só números" :disabled="!$canManage" />
                        <p class="text-xs text-yellow-600 dark:text-yellow-400 mt-1">Campo obrigatório e ainda não cadastrado — necessário para habilitar a emissão fiscal desta filial.</p>
                    @endif
                    @error('branchCnpj') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    @if(count($branchOptions) > 1)
                        <flux:select wire:model.live="branchId" label="Filial" :disabled="!$canManage">
                            @foreach($branchOptions as $option)
                                <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                            @endforeach
                        </flux:select>
                        <p class="text-xs text-neutral-400 dark:text-neutral-500 mt-1">Cada filial tem sua própria configuração fiscal — trocar aqui troca todo o formulário abaixo.</p>
                    @endif
                </div>
            </div>

            @if(count($branchOptions) > 1)
                <div class="flex items-center gap-3">
                    <flux:switch wire:model="isDefault" :disabled="!$canManage" />
                    <span class="text-sm text-neutral-600 dark:text-neutral-300">Usar como configuração padrão da empresa (fallback para filiais sem config própria)</span>
                </div>
                @error('isDefault') <p class="text-red-500 text-xs">{{ $message }}</p> @enderror
            @endif

            @if(!$hasBranch)
                <p class="text-xs text-yellow-600 dark:text-yellow-400">
                    Nenhuma filial cadastrada — cadastre uma em <a href="{{ route('admin.branches.index') }}" class="underline">Filiais</a> antes de habilitar a emissão fiscal.
                </p>
            @else
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <flux:input value="{{ $branchAddress ?: '—' }}" label="Endereço" disabled />
                    </div>
                    <div>
                        <flux:input value="{{ $branchNumber ?: '—' }}" label="Número" disabled />
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <flux:input value="{{ $branchComplement ?: '—' }}" label="Complemento" disabled />
                    </div>
                    <div>
                        <flux:input value="{{ $branchNeighborhood ?: '—' }}" label="Bairro" disabled />
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <flux:input value="{{ $branchCity ?: '—' }}" label="Município" disabled />
                    </div>
                    <div>
                        <flux:input value="{{ $branchState ?: '—' }}" label="UF" disabled />
                    </div>
                    <div>
                        <flux:input value="{{ $branchCep ?: '—' }}" label="CEP" disabled />
                    </div>
                </div>
            @endif

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <flux:input value="{{ $branchPhone ?: '—' }}" label="Telefone da filial" disabled />
                </div>
                <div>
                    <flux:input wire:model="inscricaoMunicipal" label="Inscrição Municipal" placeholder="Deixe em branco se não possuir" :disabled="!$canManage" />
                    @error('inscricaoMunicipal') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <flux:input wire:model="cscNfceProducao" label="CSC de Produção" type="password"
                        placeholder="{{ $hasCscNfceProducao ? 'CSC já configurado — deixe em branco para manter' : 'Código de Segurança do Contribuinte (obtido no portal da SEFAZ)' }}"
                        :disabled="!$canManage" />
                    @error('cscNfceProducao') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <flux:input wire:model="idTokenNfceProducao" label="ID do Token CSC" placeholder="Ex: 000001" :disabled="!$canManage" />
                    @error('idTokenNfceProducao') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
            <p class="text-xs text-neutral-400 dark:text-neutral-500">
                CSC e ID do Token são exigidos pela SEFAZ apenas para emissão de NFC-e em produção — gere-os no portal da Fazenda do seu estado.
            </p>
        </div>

        {{-- Habilitação --}}
        <div class="bg-white border rounded-xl shadow-sm p-6 space-y-4 dark:bg-zinc-800 dark:border-zinc-700">
            <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">Emissão de NFC-e</h2>

            <div class="flex items-center gap-3">
                <flux:switch wire:model="enabled" :disabled="!$canManage" />
                <span class="text-sm text-neutral-600 dark:text-neutral-300">Habilitar emissão de NFC-e para esta filial</span>
            </div>
            @error('enabled') <p class="text-red-500 text-xs">{{ $message }}</p> @enderror

            @if($focusNfeCompanyId)
                <p class="text-xs text-green-600 dark:text-green-400">
                    Empresa registrada na Focus NFe (ID: {{ $focusNfeCompanyId }}).
                </p>
            @elseif($enabled)
                <p class="text-xs text-yellow-600 dark:text-yellow-400">
                    Empresa ainda não registrada na Focus NFe — salve com o CNPJ e o certificado preenchidos para ativar a emissão real.
                </p>
            @endif

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
            <p class="text-xs text-neutral-400 dark:text-neutral-500">
                O registro da empresa e os tokens de emissão são obtidos automaticamente ao salvar com CNPJ e certificado preenchidos.
            </p>

            <div>
                <flux:input wire:model="providerToken" label="Token manual (avançado)" type="password"
                    placeholder="{{ $hasProviderToken ? 'Token já configurado — deixe em branco para manter' : 'Só preencha se precisar sobrescrever o token obtido automaticamente' }}"
                    :disabled="!$canManage" />
                @error('providerToken') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
        </div>

        {{-- Certificado --}}
        <div class="bg-white border rounded-xl shadow-sm p-6 space-y-4 dark:bg-zinc-800 dark:border-zinc-700">
            <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">Certificado Digital A1</h2>
            <p class="text-xs text-neutral-400 dark:text-neutral-500">
                Envie apenas para atualizar o certificado. Arquivos aceitos: .pfx, .p12
                @if($hasCertificate) <span class="text-green-600 dark:text-green-400">Certificado já configurado.</span> @endif
            </p>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">Arquivo do Certificado</label>
                    <input type="file" wire:model="certificateFile" accept=".pfx,.p12" @disabled(!$canManage)
                        class="block w-full text-sm text-neutral-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-purple-50 file:text-purple-700 hover:file:bg-purple-100 dark:text-neutral-400">
                    @error('certificateFile') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <flux:input wire:model="certificatePassword" label="Senha do Certificado" type="password"
                        placeholder="{{ $hasCertificate ? 'Senha já configurada — deixe em branco para manter' : 'Senha do arquivo .pfx' }}"
                        :disabled="!$canManage" />
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
