@php($preview = $this->pastePreview)

<form wire:submit="importPasted" aria-labelledby="paste-import-heading" class="min-w-0 overflow-hidden rounded-xl border bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-800 xl:col-span-2">
    <div class="space-y-5 p-5 sm:p-6">
        <div class="flex items-start gap-3">
            <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">
                <flux:icon.clipboard-document-list class="size-5" />
            </div>
            <div class="min-w-0">
                <h2 id="paste-import-heading" class="font-semibold text-neutral-800 dark:text-neutral-100">Colar lista de e-mails</h2>
                <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">Cole seus contatos e confira o que será adicionado antes de importar.</p>
            </div>
        </div>

        @if($pasteNotice)
            <div role="status" class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-800 dark:bg-green-900/30 dark:text-green-300">{{ $pasteNotice }}</div>
        @endif

        <fieldset>
            <legend class="mb-2 text-sm font-medium text-neutral-700 dark:text-neutral-300">O que você vai colar?</legend>
            <div class="grid grid-cols-2 gap-2 rounded-xl bg-neutral-50 p-1.5 dark:bg-zinc-900/60">
                @foreach(['emails' => 'Só e-mails', 'table' => 'Nome e empresa'] as $value => $label)
                    <label class="relative block min-w-0 cursor-pointer">
                        <input type="radio" name="paste-format" wire:model.live="pasteFormat" value="{{ $value }}" @checked($pasteFormat === $value) class="peer absolute inset-0 z-10 h-full w-full cursor-pointer opacity-0" />
                        <span class="flex min-h-11 items-center justify-center rounded-lg border border-transparent px-2 py-2 text-center text-sm font-medium text-neutral-500 transition-colors duration-150 peer-checked:border-neutral-200 peer-checked:bg-white peer-checked:text-neutral-900 peer-checked:shadow-sm peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-amber-600 motion-reduce:transition-none dark:text-neutral-400 dark:peer-checked:border-zinc-600 dark:peer-checked:bg-zinc-700 dark:peer-checked:text-white">{{ $label }}</span>
                    </label>
                @endforeach
            </div>
        </fieldset>

        <div class="space-y-2">
            <flux:textarea wire:model.live.debounce.600ms="pasted" label="Cole os contatos" rows="8" maxlength="200000" spellcheck="false" autocapitalize="off" autocomplete="off" aria-describedby="paste-format-help" :placeholder="$pasteFormat === 'emails' ? 'ana@restaurante.com.br'.PHP_EOL.'joao@restaurante.com.br' : 'email;nome;empresa'.PHP_EOL.'ana@restaurante.com.br;Ana;Restaurante da Ana'" class="font-mono text-sm" />
            <p id="paste-format-help" class="text-xs leading-relaxed text-neutral-500 dark:text-neutral-400">
                @if($pasteFormat === 'emails')
                    Separe os e-mails por linha, vírgula, ponto e vírgula ou espaço. Maiúsculas e espaços extras são ajustados automaticamente.
                @else
                    Cole linhas de uma planilha ou use <code class="rounded bg-neutral-100 px-1 dark:bg-zinc-700">email;nome;empresa</code>. Aceita vírgula, ponto e vírgula ou tabulação. Só o e-mail é obrigatório.
                @endif
            </p>
        </div>

        @if($preview['too_long'])
            <p role="alert" class="text-sm text-red-600 dark:text-red-400">O texto ultrapassou 200 mil caracteres. Divida a lista ou importe um CSV.</p>
        @elseif($preview['total'] > 0)
            <div class="space-y-3" wire:loading.class="opacity-50" wire:target="pasted,pasteFormat,importPasted">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-sm font-semibold text-neutral-800 dark:text-neutral-100">Conferência dos contatos</h3>
                    <span class="text-xs text-neutral-500 dark:text-neutral-400">{{ number_format($preview['total'], 0, ',', '.') }} item(ns) identificado(s)</span>
                </div>
                <p class="sr-only" role="status">{{ $preview['new'] }} novos contatos, {{ $preview['existing'] }} já na lista, {{ $preview['duplicates'] }} repetidos e {{ $preview['invalid'] + $preview['skipped'] }} para revisar.</p>
                <dl class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    @foreach(['new' => 'Novos', 'existing' => 'Já na lista', 'duplicates' => 'Repetidos', 'review' => 'Revisar'] as $key => $label)
                        <div @class(['rounded-lg border p-3', 'border-emerald-200 bg-emerald-50 dark:border-emerald-800 dark:bg-emerald-900/20' => $key === 'new', 'border-neutral-200 bg-neutral-50 dark:border-zinc-700 dark:bg-zinc-900/40' => $key !== 'new'])>
                            <dt class="text-xs text-neutral-600 dark:text-neutral-400">{{ $label }}</dt>
                            <dd class="mt-1 text-xl font-semibold tabular-nums text-neutral-800 dark:text-neutral-100">{{ number_format($key === 'review' ? $preview['invalid'] + $preview['skipped'] : $preview[$key], 0, ',', '.') }}</dd>
                        </div>
                    @endforeach
                </dl>

                @if($preview['sample'])
                    <details class="rounded-lg border border-neutral-200 px-3 py-2 dark:border-zinc-700">
                        <summary class="min-h-8 cursor-pointer text-sm text-neutral-700 dark:text-neutral-300">Ver prévia dos contatos <span class="text-xs text-neutral-500 dark:text-neutral-400">(até 5)</span></summary>
                        <ul class="mt-2 divide-y divide-neutral-100 dark:divide-zinc-700">
                            @foreach($preview['sample'] as $contact)
                                <li class="flex flex-wrap items-start justify-between gap-2 py-2 text-sm">
                                    <div class="min-w-0 flex-1">
                                        <p class="break-all text-neutral-800 dark:text-neutral-100">{{ $contact['email'] }}</p>
                                        @if($contact['name'] || $contact['company_name'])
                                            <p class="break-words text-xs text-neutral-500 dark:text-neutral-400">{{ implode(' · ', array_filter([$contact['name'], $contact['company_name']])) }}</p>
                                        @endif
                                    </div>
                                    <span @class(['shrink-0 rounded-full px-2 py-0.5 text-xs', 'bg-neutral-100 text-neutral-600 dark:bg-zinc-700 dark:text-neutral-300' => $contact['existing'], 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300' => ! $contact['existing']])>{{ $contact['existing'] ? 'Já na lista' : 'Novo' }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </details>
                @endif

                @if($preview['issues'])
                    <details open class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 dark:border-amber-800 dark:bg-amber-900/20">
                        <summary class="min-h-8 cursor-pointer text-sm font-medium text-amber-900 dark:text-amber-200">Itens para revisar · {{ $preview['invalid'] + $preview['skipped'] }}</summary>
                        <p class="mt-1 text-xs text-amber-800 dark:text-amber-300">Estes itens ficam de fora da importação. Confira o endereço ou o formato escolhido.</p>
                        <ul class="mt-2 space-y-2 text-xs">
                            @foreach($preview['issues'] as $issue)
                                <li class="flex flex-wrap justify-between gap-x-3 gap-y-1">
                                    <span class="break-all font-mono text-amber-950 dark:text-amber-100">{{ $issue['email'] }}</span>
                                    <span class="text-amber-800 dark:text-amber-300">{{ $issue['reason'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                        @if($preview['invalid'] + $preview['skipped'] > 5)
                            <p class="mt-2 text-xs text-amber-800 dark:text-amber-300">Mostrando os primeiros 5 itens.</p>
                        @endif
                    </details>
                @endif
            </div>
        @endif
    </div>

    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-neutral-100 bg-neutral-50/70 px-5 py-4 dark:border-zinc-700 dark:bg-zinc-900/30 sm:px-6">
        <div class="min-w-0 text-xs text-neutral-500 dark:text-neutral-400">
            <span wire:loading.delay wire:target="pasted,pasteFormat,importPasted" role="status">Conferindo contatos…</span>
            <span wire:loading.remove wire:target="pasted,pasteFormat,importPasted">
                @if($preview['new'] > 0)
                    Novos contatos serão adicionados a esta lista.
                @elseif($preview['existing'] > 0)
                    Os e-mails válidos já estão nesta lista.
                @elseif($preview['total'] > 0 || $preview['too_long'])
                    Revise os itens acima para continuar.
                @else
                    Cole os e-mails para começar.
                @endif
            </span>
        </div>
        <div class="flex flex-wrap gap-2">
            @if($pasted !== '' || $pasteNotice)
                <flux:button type="button" variant="ghost" wire:click="clearPasted" wire:loading.attr="disabled" wire:target="pasted,pasteFormat,importPasted,clearPasted">Limpar</flux:button>
            @endif
            <flux:button type="submit" variant="primary" icon="plus" :disabled="$preview['new'] === 0" wire:loading.attr="disabled" wire:target="pasted,pasteFormat,importPasted">
                {{ $preview['new'] > 0 ? 'Adicionar '.number_format($preview['new'], 0, ',', '.').' contato'.($preview['new'] === 1 ? '' : 's') : 'Adicionar contatos' }}
            </flux:button>
        </div>
    </div>
</form>
