{{-- Editor de blocos (templates e campanhas). Campos por tipo; o HTML é gerado no servidor. --}}
@php($blockTypes = \App\Services\Marketing\TemplateRenderer::BLOCK_TYPES)
@php($variables = \App\Services\Marketing\TemplateRenderer::VARIABLES)

<div class="space-y-3">
    <div class="rounded-lg bg-neutral-50 px-3 py-2 text-xs text-neutral-600 dark:bg-zinc-900/60 dark:text-neutral-400">
        Variáveis:
        @foreach($variables as $name => $label)
            <code class="rounded bg-white px-1 py-0.5 text-[11px] text-neutral-800 ring-1 ring-neutral-200 dark:bg-zinc-800 dark:text-neutral-200 dark:ring-zinc-700" title="{{ $label }}">&#123;&#123;{{ $name }}&#125;&#125;</code>
        @endforeach
    </div>

    @forelse($blocks as $i => $block)
        <div wire:key="block-{{ $block['key'] ?? $i }}" class="rounded-lg border border-neutral-200 bg-white p-3 space-y-3 dark:border-zinc-700 dark:bg-zinc-800">
            <div class="flex items-center justify-between gap-2">
                <span class="text-xs font-semibold uppercase tracking-wide text-neutral-500 dark:text-neutral-400">{{ $blockTypes[$block['type']] ?? $block['type'] }}</span>
                <div class="flex items-center gap-1">
                    <flux:button size="xs" variant="ghost" icon="chevron-up" wire:click="moveBlock({{ $i }}, -1)" :disabled="$loop->first" aria-label="Mover para cima" />
                    <flux:button size="xs" variant="ghost" icon="chevron-down" wire:click="moveBlock({{ $i }}, 1)" :disabled="$loop->last" aria-label="Mover para baixo" />
                    <flux:button size="xs" variant="ghost" icon="trash" wire:click="removeBlock({{ $i }})" aria-label="Remover bloco" />
                </div>
            </div>

            @switch($block['type'])
                @case('header')
                    <flux:input wire:model.live.debounce.600ms="blocks.{{ $i }}.title" label="Título" placeholder="Ex.: Novidade no painel!" />
                    <flux:input wire:model.live.debounce.600ms="blocks.{{ $i }}.logo_url" label="Logo (URL https, opcional)" placeholder="https://..." />
                    @break
                @case('text')
                    <flux:textarea wire:model.live.debounce.600ms="blocks.{{ $i }}.html" label="Texto" rows="5"
                        placeholder="Texto simples ou HTML com strong, em, a href, ul, h2." />
                    <p class="text-xs text-neutral-400 dark:text-neutral-500">Tags fora da lista e atributos (onclick, style...) são removidos ao salvar. Links precisam começar com https://.</p>
                    @break
                @case('image')
                    <flux:input wire:model.live.debounce.600ms="blocks.{{ $i }}.url" label="Imagem (URL https)" placeholder="https://..." />
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <flux:input wire:model.live.debounce.600ms="blocks.{{ $i }}.alt" label="Texto alternativo" />
                        <flux:input wire:model.live.debounce.600ms="blocks.{{ $i }}.link" label="Link ao clicar (opcional)" placeholder="https://..." />
                    </div>
                    @break
                @case('button')
                    <div class="grid grid-cols-1 sm:grid-cols-[1fr_1fr_auto] gap-3 items-end">
                        <flux:input wire:model.live.debounce.600ms="blocks.{{ $i }}.label" label="Texto do botão" />
                        <flux:input wire:model.live.debounce.600ms="blocks.{{ $i }}.url" label="Link" placeholder="https://... ou a variável link_painel" />
                        <label class="space-y-1">
                            <span class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Cor</span>
                            <input type="color" wire:model.live.debounce.600ms="blocks.{{ $i }}.color" class="h-10 w-14 cursor-pointer rounded-lg border border-neutral-200 bg-white p-1 dark:border-zinc-600 dark:bg-zinc-800" aria-label="Cor do botão" />
                        </label>
                    </div>
                    @break
                @case('divider')
                    <p class="text-xs text-neutral-400 dark:text-neutral-500">Linha separadora.</p>
                    @break
                @case('footer')
                    <flux:textarea wire:model.live.debounce.600ms="blocks.{{ $i }}.text" label="Texto do rodapé" rows="2"
                        placeholder="Dúvidas? Responda este e-mail." />
                    <p class="text-xs text-neutral-400 dark:text-neutral-500">Descadastro e dados da empresa entram sempre no fim do e-mail.</p>
                    @break
            @endswitch
        </div>
    @empty
        <div class="rounded-lg border border-dashed border-neutral-300 p-6 text-center text-sm text-neutral-500 dark:border-zinc-600 dark:text-neutral-400">
            Adicione blocos para montar o e-mail.
        </div>
    @endforelse

    @error('blocks') <p class="text-red-500 text-xs">{{ $message }}</p> @enderror

    <div class="flex flex-wrap gap-2">
        @foreach($blockTypes as $type => $label)
            <flux:button size="sm" icon="plus" wire:click="addBlock('{{ $type }}')">{{ $label }}</flux:button>
        @endforeach
    </div>
</div>
