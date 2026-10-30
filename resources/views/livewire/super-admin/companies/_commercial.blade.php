@php
    $usage = $commercial['usage'];
    $plan = $company->plan;
    $nextAction = $commercial['opportunities'][0] ?? null;
@endphp

<section aria-labelledby="commercial-heading" class="space-y-4">
    <div class="flex flex-wrap items-end justify-between gap-2">
        <div>
            <h2 id="commercial-heading" class="text-lg font-semibold text-neutral-800 dark:text-neutral-100">Oportunidades comerciais</h2>
            <p class="text-sm text-neutral-500 dark:text-neutral-400">Sinais da operação para preparar uma conversa relevante com a empresa.</p>
        </div>
        <p class="text-xs text-neutral-500 dark:text-neutral-400">Uso atualizado em {{ $usage['updated_at']->format('d/m/Y H:i') }} · cache de até 5 min</p>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="min-w-0 rounded-xl border border-amber-200 bg-amber-50 p-5 dark:border-amber-800 dark:bg-amber-950/30 lg:col-span-2">
            @if($nextAction)
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-xs font-semibold uppercase tracking-wide text-amber-800 dark:text-amber-300">Próxima abordagem sugerida</span>
                    <span class="rounded-full bg-white px-2 py-1 text-xs font-medium text-amber-800 dark:bg-amber-900/50 dark:text-amber-200">{{ $nextAction['priority'] }}</span>
                </div>
                <h3 class="mt-2 text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ $nextAction['title'] }}</h3>
                <p class="mt-2 text-sm text-neutral-700 dark:text-neutral-300">{{ $nextAction['reason'] }}</p>
                @if($nextAction['monthlyIncrease'] !== null)
                    <p class="mt-3 text-sm font-medium text-amber-900 dark:text-amber-200">Adicional mensal potencial: R$ {{ number_format($nextAction['monthlyIncrease'], 2, ',', '.') }}</p>
                @endif
                <a href="#commercial-messages" class="mt-3 inline-flex min-h-11 items-center text-sm font-semibold text-amber-900 underline underline-offset-4 dark:text-amber-200">Ver mensagem sugerida</a>
            @else
                <h3 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">Acompanhar relacionamento</h3>
                <p class="mt-2 text-sm text-neutral-700 dark:text-neutral-300">A empresa está no PRO com todos os módulos ativos. Aproveite o contato para revisar resultados e necessidades da operação.</p>
            @endif
        </div>

        <aside aria-labelledby="commercial-contacts" class="min-w-0 rounded-xl border bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800">
            <h3 id="commercial-contacts" class="font-semibold text-neutral-800 dark:text-neutral-100">Contato para abordagem</h3>
            <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">Empresa cadastrada em {{ $company->created_at?->format('d/m/Y') ?? '—' }}</p>
            <div class="mt-3 space-y-3 text-sm">
                @if($company->email)
                    <div>
                        <p class="text-xs text-neutral-500 dark:text-neutral-400">E-mail da empresa</p>
                        <a href="mailto:{{ $company->email }}" class="break-all text-amber-700 underline dark:text-amber-400">{{ $company->email }}</a>
                    </div>
                @endif
                @forelse($contacts as $contact)
                    <div>
                        <p class="font-medium text-neutral-800 dark:text-neutral-100">{{ $contact->name }} <span class="text-xs font-normal text-neutral-500 dark:text-neutral-400">· Administrador</span></p>
                        <a href="mailto:{{ $contact->email }}" class="break-all text-amber-700 underline dark:text-amber-400">{{ $contact->email }}</a>
                    </div>
                @empty
                    <p class="text-neutral-500 dark:text-neutral-400">Nenhum administrador vinculado. Confirme quem decide sobre a contratação.</p>
                @endforelse
                @foreach($branches->filter(fn ($branch) => filled($branch->phone)) as $branch)
                    <p class="break-words text-neutral-600 dark:text-neutral-300">{{ $branch->name }}: {{ $branch->phone }} <span class="text-xs text-neutral-500 dark:text-neutral-400">(telefone da filial)</span></p>
                @endforeach
            </div>
        </aside>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-xl border bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <p class="text-xs text-neutral-500 dark:text-neutral-400">Mensalidade de referência</p>
            <p class="mt-1 text-2xl font-bold text-neutral-800 dark:text-neutral-100">R$ {{ number_format($commercial['monthlyTotal'], 2, ',', '.') }}</p>
            <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">{{ $plan?->label() ?? 'Sem plano' }} + módulos ativos, conforme preços atuais. Valor registrado da assinatura abaixo.</p>
        </div>
        <div class="rounded-xl border bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <p class="text-xs text-neutral-500 dark:text-neutral-400">Pedidos confirmados · últimos 30 dias</p>
            <p class="mt-1 text-2xl font-bold text-neutral-800 dark:text-neutral-100">{{ number_format($usage['recent_orders'], 0, ',', '.') }}</p>
            <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                @if($commercial['growth'] !== null)
                    {{ $commercial['growth'] > 0 ? '+' : '' }}{{ number_format($commercial['growth'], 1, ',', '.') }}% em relação aos 30 dias anteriores ({{ $usage['previous_orders'] }} pedidos)
                @else
                    Sem base de pedidos nos 30 dias anteriores para comparar.
                @endif
            </p>
        </div>
        <div class="rounded-xl border bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <p class="text-xs text-neutral-500 dark:text-neutral-400">Volume confirmado · últimos 30 dias</p>
            <p class="mt-1 text-2xl font-bold text-neutral-800 dark:text-neutral-100">R$ {{ number_format($usage['recent_revenue'], 2, ',', '.') }}</p>
            <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">Último pedido confirmado: {{ $usage['last_order_at'] ? \Carbon\Carbon::parse($usage['last_order_at'])->format('d/m/Y H:i') : 'Nenhum' }}</p>
        </div>
        <div class="rounded-xl border bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <p class="text-xs text-neutral-500 dark:text-neutral-400">Taxas de plano · mês atual</p>
            <p class="mt-1 text-2xl font-bold text-neutral-800 dark:text-neutral-100">R$ {{ number_format($usage['month_fees'], 2, ',', '.') }}</p>
            <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">Somente taxas registradas em pedidos confirmados; não inclui taxas do meio de pagamento.</p>
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <div class="min-w-0 rounded-xl border bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800">
            <h3 class="font-semibold text-neutral-800 dark:text-neutral-100">Plano, limites e momento da conta</h3>
            <dl class="mt-4 grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-neutral-500 dark:text-neutral-400">Plano atual</dt>
                    <dd class="mt-1 font-medium text-neutral-800 dark:text-neutral-100">{{ $plan?->label() ?? 'Não informado' }} · R$ {{ number_format($plan?->monthlyPrice() ?? 0, 2, ',', '.') }}/mês</dd>
                </div>
                <div>
                    <dt class="text-neutral-500 dark:text-neutral-400">Filiais utilizadas / limite</dt>
                    <dd class="mt-1 font-medium text-neutral-800 dark:text-neutral-100">{{ $usage['branches'] }} / {{ $plan?->maxBranches() ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-neutral-500 dark:text-neutral-400">Pedidos confirmados no mês</dt>
                    <dd class="mt-1 font-medium text-neutral-800 dark:text-neutral-100">{{ $usage['month_orders'] }} · {{ $commercial['limit'] !== null ? 'faixa inicial de '.$commercial['limit'].' pedidos' : 'pedidos ilimitados' }}</dd>
                    @if($commercial['limit'] !== null && $commercial['limit'] > 0)
                        <progress aria-label="Uso da faixa inicial de pedidos" class="mt-2 h-2 w-full accent-amber-600" max="{{ $commercial['limit'] }}" value="{{ min($usage['month_orders'], $commercial['limit']) }}"></progress>
                    @endif
                </div>
                <div>
                    <dt class="text-neutral-500 dark:text-neutral-400">Taxa de plano por pedido</dt>
                    <dd class="mt-1 font-medium text-neutral-800 dark:text-neutral-100">{{ number_format(($plan?->feePercentage() ?? 0) * 100, 2, ',', '.') }}%</dd>
                    @if($company->isFree())
                        <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">{{ number_format(max($plan->feePercentage(), (float) config('plans.free.fee_percentage_over_limit', 0.03)) * 100, 2, ',', '.') }}% ao atingir a faixa mensal de pedidos confirmados.</p>
                    @endif
                </div>
                <div>
                    <dt class="text-neutral-500 dark:text-neutral-400">Mudança de plano</dt>
                    <dd class="mt-1 font-medium text-neutral-800 dark:text-neutral-100">{{ $company->pending_plan ? 'Pendente para '.$company->pending_plan->label() : 'Nenhuma pendência de troca' }}</dd>
                </div>
                <div>
                    <dt class="text-neutral-500 dark:text-neutral-400">Situação de cobrança</dt>
                    <dd class="mt-1 font-medium text-neutral-800 dark:text-neutral-100">{{ $company->status }}{{ $company->overdue_since ? ' · em atraso desde '.$company->overdue_since->format('d/m/Y') : '' }}</dd>
                </div>
            </dl>
            <p class="mt-4 text-xs text-neutral-500 dark:text-neutral-400">Nos últimos 30 dias: {{ $usage['pdv_orders'] }} pedido(s) de PDV · {{ $usage['ifood_orders'] }} do iFood · {{ $usage['food99_orders'] ?? 0 }} da 99Food. Mesas ativas cadastradas: {{ $usage['tables'] }}.</p>
        </div>

        <div class="min-w-0 rounded-xl border bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800">
            <h3 class="font-semibold text-neutral-800 dark:text-neutral-100">Módulos e espaço para expansão</h3>
            <div class="mt-4 space-y-4">
                @foreach($commercial['modules'] as $module)
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-neutral-100 pb-3 last:border-0 dark:border-zinc-700">
                        <div>
                            <p class="text-sm font-medium text-neutral-800 dark:text-neutral-100">{{ $module['label'] }}</p>
                            <p class="text-xs text-neutral-500 dark:text-neutral-400">R$ {{ number_format($module['price'], 2, ',', '.') }}/mês adicional</p>
                            @if($module['key'] === 'waiter')
                                <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">Exige módulo PDV ativo.</p>
                            @endif
                        </div>
                        <span @class(['rounded-full px-2 py-1 text-xs font-medium', 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300' => $module['enabled'], 'bg-neutral-100 text-neutral-600 dark:bg-zinc-700 dark:text-neutral-300' => ! $module['enabled']])>
                            {{ $module['enabled'] ? 'Ativo' : 'Não contratado' }}
                        </span>
                    </div>
                @endforeach
            </div>
            <p class="mt-3 text-xs text-neutral-500 dark:text-neutral-400">Valores mensais de referência. A ativação pode ter cobrança proporcional conforme o ciclo da assinatura.</p>
        </div>
    </div>

    <details class="rounded-xl border bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800">
        <summary class="cursor-pointer font-semibold text-neutral-800 dark:text-neutral-100">Comparar planos para preparar a proposta</summary>
        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">Preços, limites e taxas dos planos disponíveis</caption>
                <thead class="text-xs text-neutral-500 dark:text-neutral-400">
                    <tr>
                        <th class="py-2 pr-4">Plano</th>
                        <th class="px-3 py-2">Mensalidade base</th>
                        <th class="px-3 py-2">Filiais</th>
                        <th class="px-3 py-2">Pedidos/mês</th>
                        <th class="px-3 py-2">Taxa de plano</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 text-neutral-700 dark:divide-zinc-700 dark:text-neutral-200">
                    @foreach(\App\Enums\Plan::cases() as $option)
                        <tr>
                            <th class="whitespace-nowrap py-3 pr-4 font-medium">{{ $option->label() }}{{ $option === $plan ? ' (atual)' : '' }}</th>
                            <td class="whitespace-nowrap px-3 py-3">R$ {{ number_format($option->monthlyPrice(), 2, ',', '.') }}</td>
                            <td class="px-3 py-3">{{ $option->maxBranches() }}</td>
                            <td class="px-3 py-3">{{ $option->maxOrdersPerMonth() ?? 'Ilimitados' }}</td>
                            <td class="whitespace-nowrap px-3 py-3">{{ number_format($option->feePercentage() * 100, 2, ',', '.') }}%{{ $option === \App\Enums\Plan::Free ? ' / '.number_format(max($option->feePercentage(), (float) config('plans.free.fee_percentage_over_limit', 0.03)) * 100, 2, ',', '.').'% ao atingir a faixa' : '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="mt-3 text-xs text-neutral-500 dark:text-neutral-400">Módulos e taxas dos meios de pagamento são cobrados à parte. Na mudança do gratuito para um plano pago, a taxa de ativação é de R$ {{ number_format(\App\Enums\Plan::Essencial->setupFee(), 2, ',', '.') }} no Essencial e R$ {{ number_format(\App\Enums\Plan::Pro->setupFee(), 2, ',', '.') }} no PRO.</p>
    </details>

    @if(count($commercial['opportunities']))
        <div id="commercial-messages" class="scroll-mt-6 space-y-3">
            <h3 class="font-semibold text-neutral-800 dark:text-neutral-100">Abordagens e mensagens sugeridas</h3>
            <p class="text-sm text-neutral-500 dark:text-neutral-400">Revise os dados e adapte o texto antes de entrar em contato. As sugestões não enviam mensagens automaticamente.</p>
            @foreach($commercial['opportunities'] as $opportunity)
                <details wire:key="opportunity-{{ $loop->index }}" @if($loop->first) open @endif class="rounded-xl border bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                    <summary class="cursor-pointer text-sm font-semibold text-neutral-800 dark:text-neutral-100">{{ $opportunity['title'] }} <span class="ml-2 text-xs font-normal text-amber-700 dark:text-amber-400">{{ $opportunity['priority'] }}</span></summary>
                    <p class="mt-3 text-sm text-neutral-600 dark:text-neutral-300">{{ $opportunity['reason'] }}</p>
                    @if($opportunity['monthlyIncrease'] !== null)
                        <p class="mt-2 text-xs font-medium text-neutral-600 dark:text-neutral-300">Adicional mensal: R$ {{ number_format($opportunity['monthlyIncrease'], 2, ',', '.') }}</p>
                    @endif
                    <div class="mt-3 space-y-3" x-data="{ copied: false, copyError: false }">
                        <label for="commercial-message-{{ $loop->index }}" class="block text-xs font-medium text-neutral-600 dark:text-neutral-300">Mensagem para adaptar</label>
                        <textarea id="commercial-message-{{ $loop->index }}" x-ref="message" readonly rows="5" class="w-full resize-y rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-sm leading-relaxed text-neutral-700 dark:border-zinc-600 dark:bg-zinc-900 dark:text-neutral-200">{{ $opportunity['message'] }}</textarea>
                        <div class="flex flex-wrap items-center gap-3">
                            <button type="button" x-on:click="copyError = false; try { await navigator.clipboard.writeText($refs.message.value); copied = true; setTimeout(() => copied = false, 2500) } catch (error) { copyError = true; $refs.message.focus(); $refs.message.select() }" class="inline-flex min-h-11 items-center rounded-lg bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-600">
                                <span x-text="copied ? 'Mensagem copiada' : 'Copiar mensagem'">Copiar mensagem</span>
                            </button>
                            <span x-cloak x-show="copied" role="status" class="text-xs text-emerald-700 dark:text-emerald-400">Texto copiado.</span>
                            <span x-cloak x-show="copyError" role="status" class="text-xs text-neutral-600 dark:text-neutral-300">Selecione e copie o texto no campo acima.</span>
                        </div>
                    </div>
                </details>
            @endforeach
        </div>
    @endif
</section>
