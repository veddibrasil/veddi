{{--
    Pendências da pré-checagem fiscal (FiscalNoteService::check), agrupadas por cadastro a
    corrigir. Usado no modal do pedido e no Controle de notas.

    $issues: lista de FiscalPreflightException::issues
    $canFix: mostra "Corrigir dados" nos produtos (products.update)
    $returnTo: caminho para voltar depois de salvar o produto
    $skipGroups: grupos já explicados em outro lugar da tela (ex.: 'policy')
--}}
@php
    $skipGroups ??= [];
    $grouped = collect($issues)->reject(fn ($issue) => in_array($issue['group'], $skipGroups, true))->groupBy('group');
    $order = array_keys(\App\Exceptions\FiscalPreflightException::GROUPS);
    $grouped = $grouped->sortBy(fn ($items, $group) => array_search($group, $order, true));
@endphp
@if($grouped->isNotEmpty())
    <div class="space-y-3">
        @foreach($grouped as $group => $groupIssues)
            <section aria-label="Pendências: {{ \App\Exceptions\FiscalPreflightException::GROUPS[$group] ?? $group }}" class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 dark:border-amber-800/60 dark:bg-amber-900/20">
                <h5 class="text-sm font-semibold text-amber-900 dark:text-amber-200">{{ \App\Exceptions\FiscalPreflightException::GROUPS[$group] ?? $group }}</h5>
                @foreach($groupIssues as $issue)
                    @if($group === 'products' && ! empty($issue['products']))
                        <p class="mt-1 text-xs text-amber-900 dark:text-amber-200">{{ \Illuminate\Support\Str::before($issue['message'], ' Complete em') }} Corrija no cadastro indicado e verifique novamente.</p>
                        <ul class="mt-2 space-y-2">
                            @foreach($issue['products'] as $product)
                                <li class="flex flex-wrap items-start justify-between gap-2 rounded-md bg-white px-2.5 py-2 text-sm dark:bg-zinc-800">
                                    <div class="min-w-0">
                                        <p class="font-medium text-neutral-800 dark:text-neutral-100">{{ $product['product_name'] }}</p>
                                        <p class="text-xs text-neutral-600 dark:text-neutral-300">
                                            {{ $product['fields'] !== [] ? 'Faltam: '.implode(', ', $product['fields']) : ucfirst((string) $product['problem']) }}
                                        </p>
                                        <p class="text-xs text-neutral-500 dark:text-neutral-400">
                                            [{{ $product['product_id'] ? 'produto #'.$product['product_id'].', ' : '' }}{{ $product['source'] === 'branch' ? 'perfil fiscal da filial '.$product['branch_name'] : 'cadastro geral' }}]
                                        </p>
                                    </div>
                                    @if($canFix && ($url = \App\Support\Fiscal\FiscalCorrectionLinks::productUrl($product, $returnTo)))
                                        <a href="{{ $url }}" wire:navigate class="shrink-0 text-xs font-medium text-blue-600 hover:underline dark:text-blue-400">
                                            Corrigir dados<span class="sr-only"> de {{ $product['product_name'] }}</span>
                                        </a>
                                    @elseif(! $canFix)
                                        <span class="shrink-0 text-xs text-neutral-500 dark:text-neutral-400">Peça a correção a quem cuida dos produtos.</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                        {{-- Vários produtos com pendência: um modelo fiscal resolve todos de uma vez, no
                             mesmo cadastro que a emissão usa (perfil da filial ou cadastro geral). --}}
                        @php($batchGroups = $canFix ? collect($issue['products'])->filter(fn ($p) => ! empty($p['product_id']))->groupBy(fn ($p) => $p['source'] === 'branch' && ! empty($p['branch_id']) ? (string) $p['branch_id'] : 'general') : collect())
                        @if($batchGroups->flatten(1)->count() > 1)
                            <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1">
                                @foreach($batchGroups as $destination => $groupProducts)
                                    <a href="{{ route('admin.fiscal.templates', ['produtos' => $groupProducts->pluck('product_id')->unique()->implode(','), 'destino' => $destination, 'voltar' => $returnTo]) }}" wire:navigate class="text-xs font-semibold text-blue-600 hover:underline dark:text-blue-400">
                                        Preencher {{ $groupProducts->count() }} produto(s) de uma vez{{ $destination !== 'general' ? ' (perfil da filial '.$groupProducts->first()['branch_name'].')' : '' }}
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    @else
                        <p class="mt-1 text-sm text-amber-900 dark:text-amber-200">{{ $issue['message'] }}</p>
                    @endif
                @endforeach
            </section>
        @endforeach
    </div>
@endif
