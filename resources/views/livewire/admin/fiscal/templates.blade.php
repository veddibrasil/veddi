<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:heading size="xl">Tributação em lote</flux:heading>
            <flux:text class="mt-1 max-w-3xl">Cadastre modelos com os valores aprovados pela contabilidade e aplique nos produtos com prévia. A aplicação copia os valores para o produto; editar o modelo depois não muda produto nenhum. Nada aqui emite nota.</flux:text>
        </div>
        @unless($lockedBranchId)
            <flux:button wire:click="newTemplate" variant="primary" icon="plus">Novo modelo</flux:button>
        @endunless
    </div>

    @if($returnTo)
        @php
            $backLabel = str_starts_with($returnTo, '/admin/fiscal/controle') ? 'Voltar ao Controle de notas' : (str_starts_with($returnTo, '/admin/orders/') ? 'Voltar ao pedido' : 'Voltar');
        @endphp
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-sm text-blue-900 dark:border-blue-800/60 dark:bg-blue-900/20 dark:text-blue-200">
            <p>Os produtos com pendência já vêm selecionados. Escolha o modelo, gere a prévia e aplique; depois volte para emitir.</p>
            <flux:button size="sm" icon="arrow-left" :href="$returnTo" wire:navigate>{{ $backLabel }}</flux:button>
        </div>
    @endif

    @if(session('status'))
        <flux:callout variant="success" icon="check-circle" :text="session('status')" />
    @endif
    @if(session('error'))
        <flux:callout variant="danger" icon="exclamation-triangle" :text="session('error')" />
    @endif

    {{-- Modelos --}}
    <section class="rounded-xl border bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800" aria-labelledby="templates-title">
        <h2 id="templates-title" class="text-sm font-semibold uppercase tracking-wide text-neutral-700 dark:text-neutral-300">Modelos fiscais</h2>
        @if($templates->isEmpty())
            <p class="mt-2 text-sm text-neutral-500 dark:text-neutral-400">Nenhum modelo ainda. {{ $suggestions !== [] ? 'Comece por uma sugestão abaixo ou crie' : 'Crie' }} um por grupo de produtos com a mesma classificação (ex.: "Salgados fritos – Simples PR"). O nome da categoria não define a tributação.</p>
        @else
            <ul class="mt-3 divide-y dark:divide-zinc-700">
                @foreach($templates as $template)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2" wire:key="template-{{ $template->id }}">
                        <div class="min-w-0">
                            <p class="font-medium text-neutral-800 dark:text-neutral-100">{{ $template->name }}</p>
                            <p class="text-xs text-neutral-500 dark:text-neutral-400">
                                {{ $template->crt ? 'CRT '.$template->crt : 'Qualquer regime' }}
                                · {{ $template->state ?: 'Qualquer UF' }}
                                @if($template->operation) · {{ $template->operation }} @endif
                                @if($template->valid_from || $template->valid_until)
                                    · vigência {{ $template->valid_from?->format('d/m/Y') ?? '…' }} a {{ $template->valid_until?->format('d/m/Y') ?? '…' }}
                                @endif
                                @unless($template->isValidOn(now())) · <span class="font-medium text-red-600 dark:text-red-400">fora da vigência</span> @endunless
                            </p>
                            <p class="text-xs text-neutral-500 dark:text-neutral-400">
                                @foreach($template->filledValues() as $field => $value)
                                    @continue($field === 'taxes')
                                    {{ $fieldLabels[$field] }}: {{ $value }}@if(! $loop->last) · @endif
                                @endforeach
                                @if(! empty($template->data['taxes'])) · {{ count($template->data['taxes']) }} campo(s) adicional(is) @endif
                            </p>
                        </div>
                        <div class="flex gap-2">
                            <flux:button size="sm" variant="{{ (string) $template->id === $applyTemplateId ? 'filled' : 'ghost' }}" wire:click="useTemplate({{ $template->id }})" :disabled="! $template->isValidOn(now())">
                                {{ (string) $template->id === $applyTemplateId ? 'Selecionado' : 'Aplicar' }}<span class="sr-only"> {{ $template->name }}</span>
                            </flux:button>
                        @unless($lockedBranchId)
                                <flux:button size="sm" variant="ghost" wire:click="editTemplate({{ $template->id }})">Editar</flux:button>
                                <flux:button size="sm" variant="ghost" wire:click="deleteTemplate({{ $template->id }})" wire:confirm="Excluir o modelo {{ $template->name }}? Produtos já alterados continuam como estão.">Excluir</flux:button>
                        @endunless
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif

        @if($suggestions !== [])
            <div class="mt-4 border-t pt-4 dark:border-zinc-700">
                <h3 class="text-sm font-medium text-neutral-800 dark:text-neutral-100">Sugestões para começar</h3>
                <p class="text-xs text-neutral-500 dark:text-neutral-400">Montadas com os dados fiscais já cadastrados nos produtos. "Usar" abre o formulário preenchido; só vira modelo depois de conferir e salvar.</p>
                <ul class="mt-2 divide-y dark:divide-zinc-700">
                    @foreach($suggestions as $suggestion)
                        <li class="flex flex-wrap items-center justify-between gap-2 py-2" wire:key="suggestion-{{ $suggestion['key'] }}">
                            <div class="min-w-0">
                                <p class="font-medium text-neutral-800 dark:text-neutral-100">{{ $suggestion['name'] }}</p>
                                <p class="text-xs text-neutral-500 dark:text-neutral-400">
                                    @foreach($suggestion['data'] as $field => $value)
                                        @continue($field === 'taxes')
                                        {{ $fieldLabels[$field] }}: {{ $value }}@if(! $loop->last) · @endif
                                    @endforeach
                                    @if(! empty($suggestion['data']['taxes'])) · {{ count($suggestion['data']['taxes']) }} campo(s) adicional(is) @endif
                                </p>
                                @if($suggestion['generic'])
                                    <p class="text-xs text-amber-700 dark:text-amber-400">Padrão usado hoje na NFC-e ao consumidor quando o produto não tem dados fiscais. Códigos genéricos: confirme com a contabilidade.</p>
                                @else
                                    <p class="text-xs text-neutral-500 dark:text-neutral-400">
                                        Igual a {{ $suggestion['count'] }} produto(s): {{ implode(', ', $suggestion['products']) }}@if($suggestion['count'] > count($suggestion['products'])) e mais {{ $suggestion['count'] - count($suggestion['products']) }}@endif
                                    </p>
                                @endif
                            </div>
                            <flux:button size="sm" variant="ghost" icon="document-duplicate" wire:click="useSuggestion('{{ $suggestion['key'] }}')">
                                Usar<span class="sr-only"> sugestão {{ $suggestion['name'] }}</span>
                            </flux:button>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </section>

    {{-- Formulário do modelo --}}
    <flux:modal wire:model.self="showForm" class="w-full max-w-3xl">
        <form wire:submit="saveTemplate" class="space-y-4">
            <flux:heading size="lg">{{ $editingId ? 'Editar modelo fiscal' : 'Novo modelo fiscal' }}</flux:heading>
            <flux:text>Preencha só o que a contabilidade aprovou. Campo vazio no modelo nunca apaga dado do produto.</flux:text>
            {{-- 12 colunas: datas precisam de ~150px; min-w-0 impede que um campo estoure o modal. --}}
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-12">
                <div class="col-span-2 min-w-0 sm:col-span-8"><flux:input wire:model="name" label="Nome do modelo" required /></div>
                <div class="col-span-2 min-w-0 sm:col-span-4">
                    <flux:select wire:model="crt" label="Regime (CRT)">
                        <option value="">Qualquer</option>
                        <option value="1">1 – Simples Nacional</option>
                        <option value="2">2 – Simples, excesso de sublimite</option>
                        <option value="3">3 – Regime normal</option>
                    </flux:select>
                </div>
                <div class="col-span-2 min-w-0 sm:col-span-2"><flux:input wire:model="state" label="UF" maxlength="2" placeholder="Qualquer" /></div>
                <div class="col-span-2 min-w-0 sm:col-span-4"><flux:input wire:model="operation" label="Operação" /></div>
                <div class="col-span-2 min-w-0 sm:col-span-3"><flux:input wire:model="validFrom" type="date" label="Início da vigência" /></div>
                <div class="col-span-2 min-w-0 sm:col-span-3"><flux:input wire:model="validUntil" type="date" label="Fim da vigência" /></div>
            </div>
            @error('fields') <p role="alert" class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                @foreach($fieldLabels as $field => $label)
                    <div class="min-w-0">
                        <flux:input wire:model="fields.{{ $field }}" :label="$label" />
                    </div>
                @endforeach
            </div>
            <details class="rounded-lg border p-3 dark:border-zinc-700">
                <summary class="cursor-pointer text-sm font-medium">Campos adicionais (avançado)</summary>
                <flux:textarea wire:model="taxes" rows="3" class="mt-2" label="JSON da Focus NFe" placeholder='{"icms_aliquota": 18}' />
                @error('taxes') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </details>
            <flux:textarea wire:model="notes" rows="3" label="Observações (fonte, quem aprovou)" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Cancelar</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">Salvar modelo</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Aplicação --}}
    <section class="space-y-4 rounded-xl border bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800" aria-labelledby="apply-title">
        <h2 id="apply-title" class="text-sm font-semibold uppercase tracking-wide text-neutral-700 dark:text-neutral-300">Aplicar modelo</h2>
        <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
            <flux:select wire:model.live="applyTemplateId" label="Modelo">
                <option value="">Selecione</option>
                @foreach($templates as $template)
                    <option value="{{ $template->id }}">{{ $template->name }}</option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="target" label="Onde gravar">
                @unless($lockedBranchId)
                    <option value="general">Cadastro geral (vale para todas as filiais)</option>
                @endunless
                @foreach($branches as $branch)
                    <option value="{{ $branch->id }}">Só exceções da filial {{ $branch->name }}</option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="mode" label="Como aplicar">
                <option value="fill">Só preencher campos vazios</option>
                <option value="replace">Substituir valores existentes</option>
            </flux:select>
        </div>
        @if($applyTemplate)
            <div class="rounded-lg bg-neutral-50 px-3 py-2 text-sm dark:bg-zinc-900/40" aria-live="polite">
                <p class="font-medium text-neutral-800 dark:text-neutral-100">Valores do modelo {{ $applyTemplate->name }}</p>
                <dl class="mt-1 grid grid-cols-2 gap-x-4 gap-y-0.5 text-xs sm:grid-cols-4">
                    @foreach($applyTemplate->filledValues() as $field => $value)
                        @continue($field === 'taxes')
                        <div><dt class="inline text-neutral-500 dark:text-neutral-400">{{ $fieldLabels[$field] }}:</dt> <dd class="inline font-medium text-neutral-800 dark:text-neutral-100">{{ $value }}</dd></div>
                    @endforeach
                </dl>
                @if(! empty($applyTemplate->data['taxes']))
                    <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">Campos adicionais: {{ json_encode($applyTemplate->data['taxes'], JSON_UNESCAPED_UNICODE) }}</p>
                @endif
                <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">Só estes campos são gravados; campos vazios no modelo não mudam o produto.</p>
            </div>
        @elseif($templates->isNotEmpty())
            <p class="text-xs text-amber-700 dark:text-amber-400">Escolha o modelo acima ou clique em "Aplicar" na lista de modelos.</p>
        @endif
        <p class="text-xs text-neutral-500 dark:text-neutral-400">
            O cadastro geral vale para todas as filiais. A exceção de uma filial guarda só o que muda nela; o resto vem do cadastro geral.
            Gravando na filial, só produtos que já têm exceção nela são alterados (para criar uma exceção, abra o produto).
        </p>
        @if($mode === 'replace')
            <flux:checkbox wire:model.live="confirmReplace" label="Entendo que valores já preenchidos serão substituídos pelos do modelo" />
            @error('confirmReplace') <p role="alert" class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        @endif
        @error('target') <p role="alert" class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror

        <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
            <flux:select wire:model.live="categoryFilter" label="Filtrar por categoria">
                <option value="">Todas</option>
                @foreach($categories as $category)
                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                @endforeach
            </flux:select>
            <div class="md:col-span-2"><flux:input wire:model.live.debounce.300ms="search" label="Buscar produto" icon="magnifying-glass" /></div>
        </div>
        <flux:checkbox wire:model.live="onlyPending" label="Só produtos com campo da NF-e vazio (no que a nota usa no destino escolhido)" />

        <div class="flex flex-wrap items-center gap-3 text-sm">
            <span>{{ count($selected) }} selecionado(s)</span>
            <flux:button size="sm" variant="ghost" wire:click="selectFiltered">Selecionar os filtrados</flux:button>
            <flux:button size="sm" variant="ghost" wire:click="clearSelection">Limpar seleção</flux:button>
        </div>
        @error('selected') <p role="alert" class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror

        <div class="max-h-80 overflow-y-auto rounded-lg border dark:border-zinc-700">
            <ul class="divide-y dark:divide-zinc-700">
                @forelse($products as $product)
                    @php
                        $status = $productStatus[$product->id] ?? ['missing' => [], 'editable' => true, 'exceptions' => []];
                        $missing = count($status['missing']);
                    @endphp
                    <li wire:key="tpl-product-{{ $product->id }}">
                        <label class="flex cursor-pointer items-center gap-3 px-3 py-2 text-sm">
                            <input type="checkbox" value="{{ $product->id }}" wire:model.live="selected" class="rounded">
                            <span class="min-w-0 flex-1">
                                <span class="font-medium text-neutral-800 dark:text-neutral-100">{{ $product->name }}</span>
                                <span class="text-xs text-neutral-500 dark:text-neutral-400"> · #{{ $product->id }} · {{ $product->category?->name ?? 'Sem categoria' }}</span>
                            </span>
                            <span class="text-right text-xs">
                                @if($target !== 'general' && ! $status['editable'])
                                    <span class="text-neutral-500 dark:text-neutral-400">sem exceção nesta filial (usa o cadastro geral)</span>
                                @else
                                    <span class="{{ $missing ? 'text-amber-700 dark:text-amber-400' : 'text-green-700 dark:text-green-400' }}">
                                        @if($target === 'general')
                                            {{ $missing ? $missing.' campo(s) NF-e vazio(s) no cadastro geral' : 'cadastro geral completo' }}
                                        @else
                                            {{ $missing ? $missing.' campo(s) NF-e vazio(s) nesta filial' : 'completo nesta filial' }}
                                        @endif
                                    </span>
                                @endif
                                @if($target === 'general' && $status['exceptions'] !== [])
                                    <span class="block text-neutral-500 dark:text-neutral-400">exceção em {{ implode(', ', $status['exceptions']) }}</span>
                                @endif
                            </span>
                        </label>
                    </li>
                @empty
                    <li class="px-3 py-4 text-sm text-neutral-500">Nenhum produto com esse filtro.</li>
                @endforelse
            </ul>
        </div>

        <div class="flex justify-end">
            <flux:button wire:click="generatePreview" variant="primary" wire:loading.attr="disabled" wire:target="generatePreview">Gerar prévia</flux:button>
        </div>
    </section>

    {{-- Prévia --}}
    @if($preview)
        <section class="space-y-3 rounded-xl border bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800" aria-labelledby="preview-title">
            <h2 id="preview-title" class="text-sm font-semibold uppercase tracking-wide text-neutral-700 dark:text-neutral-300">Prévia</h2>
            @if($preview['blocked'])
                <flux:callout variant="danger" icon="exclamation-triangle" :text="$preview['blocked']" />
            @else
                <p class="text-sm text-neutral-700 dark:text-neutral-200">
                    {{ $preview['summary']['changed'] }} produto(s) alterado(s) · {{ $preview['summary']['unchanged'] }} sem mudança · {{ $preview['summary']['skipped'] }} ignorado(s).
                    Pendências da NF-e: {{ $preview['summary']['resolved'] }} resolvida(s), {{ $preview['summary']['remaining'] }} permanecem.
                </p>
                @foreach($preview['warnings'] as $warning)
                    <p class="text-xs text-amber-700 dark:text-amber-400">{{ $warning }}</p>
                @endforeach
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase text-neutral-500">
                                <th class="px-2 py-2">Produto</th>
                                <th class="px-2 py-2">Alterações (atual → proposto)</th>
                                <th class="px-2 py-2">Pendências NF-e depois</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y dark:divide-zinc-700">
                            @foreach($preview['rows'] as $row)
                                <tr wire:key="preview-{{ $row['product_id'] }}" class="align-top">
                                    <td class="px-2 py-2">
                                        <p class="font-medium">{{ $row['name'] }}</p>
                                        <p class="text-xs text-neutral-500">#{{ $row['product_id'] }}</p>
                                    </td>
                                    <td class="px-2 py-2">
                                        @if($row['skipped'])
                                            <span class="text-xs text-neutral-500">{{ $row['skipped'] }}</span>
                                        @elseif($row['changed'] === [])
                                            <span class="text-xs text-neutral-500">Sem mudança</span>
                                        @else
                                            <ul class="space-y-0.5 text-xs">
                                                @foreach($row['changed'] as $field)
                                                    <li>
                                                        <span class="font-medium">{{ $fieldLabels[$field] ?? ($field === 'taxes' ? 'Campos adicionais' : $field) }}:</span>
                                                        <span class="text-neutral-500 line-through">{{ is_array($row['before'][$field] ?? null) ? json_encode($row['before'][$field]) : (($row['before'][$field] ?? '') === '' ? 'vazio' : $row['before'][$field]) }}</span>
                                                        → @if(array_key_exists($field, $row['after']))<span class="text-green-700 dark:text-green-400">{{ is_array($row['after'][$field]) ? json_encode($row['after'][$field]) : $row['after'][$field] }}</span>@else<span class="text-green-700 dark:text-green-400">usa o cadastro geral</span>@endif
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @endif
                                        @foreach($row['warnings'] as $warning)
                                            <p class="mt-1 text-xs text-amber-700 dark:text-amber-400">{{ $warning }}</p>
                                        @endforeach
                                    </td>
                                    <td class="px-2 py-2 text-xs">
                                        @if($row['skipped'])
                                            —
                                        @elseif($row['missing_after'] === [])
                                            <span class="text-green-700 dark:text-green-400">Nenhuma</span>
                                        @else
                                            <span class="text-amber-700 dark:text-amber-400">{{ implode(', ', $row['missing_after']) }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @error('apply') <p role="alert" class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                <div class="flex justify-end gap-2">
                    <flux:button wire:click="discardPreview" variant="ghost">Descartar prévia</flux:button>
                    @if($preview['summary']['changed'] > 0)
                        <flux:button wire:click="applyPreview" variant="primary" wire:loading.attr="disabled" wire:target="applyPreview">Aplicar em {{ $preview['summary']['changed'] }} produto(s)</flux:button>
                    @endif
                </div>
            @endif
        </section>
    @endif

    {{-- Histórico --}}
    <section class="rounded-xl border bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800" aria-labelledby="history-title">
        <h2 id="history-title" class="text-sm font-semibold uppercase tracking-wide text-neutral-700 dark:text-neutral-300">Histórico de aplicações</h2>
        @if($applications->isEmpty())
            <p class="mt-2 text-sm text-neutral-500 dark:text-neutral-400">Nenhuma aplicação ainda.</p>
        @else
            <ul class="mt-3 divide-y dark:divide-zinc-700">
                @foreach($applications as $application)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm" wire:key="application-{{ $application->id }}">
                        <div>
                            <p class="font-medium text-neutral-800 dark:text-neutral-100">{{ $application->template_name }} · {{ $application->products_count }} produto(s)</p>
                            <p class="text-xs text-neutral-500 dark:text-neutral-400">
                                {{ $application->created_at->format('d/m/Y H:i') }} · {{ $application->user?->name ?? 'usuário removido' }}
                                · {{ $application->target === 'branch' ? 'exceções da filial '.($application->branch?->name ?? '#'.$application->branch_id) : 'cadastro geral (todas as filiais)' }}
                                · {{ $application->mode === 'replace' ? 'substituição' : 'preenchimento de vazios' }}
                                @if($application->reverted_at) · <span class="font-medium">desfeita em {{ $application->reverted_at->format('d/m/Y H:i') }}</span> @endif
                            </p>
                        </div>
                        @unless($application->reverted_at)
                            <flux:button size="sm" variant="ghost" wire:click="revert({{ $application->id }})" wire:confirm="Desfazer esta aplicação? Produtos alterados depois dela ficam como estão.">Desfazer</flux:button>
                        @endunless
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
