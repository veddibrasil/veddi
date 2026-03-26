<div class="w-full space-y-6"
     x-init="$watch(() => $wire.confirmingPlanChange, val => val ? $flux.modal('confirm-plan-change').show() : $flux.modal('confirm-plan-change').close())">

    <h1 class="text-2xl font-bold text-neutral-800 dark:text-neutral-100">Assinatura</h1>

    {{-- Plano Atual --}}
    <div class="bg-white border rounded-xl shadow-sm p-6 space-y-5 dark:bg-zinc-800 dark:border-zinc-700">
        <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">Plano Atual</h2>

        <div class="flex flex-wrap items-center gap-3">
            {{-- Badge do plano --}}
            @if($plan === 'pro')
                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-sm font-semibold bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">
                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                    PRO
                </span>
            @elseif($plan === 'essencial')
                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-sm font-semibold bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300">
                    📦 Essencial
                </span>
            @else
                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-semibold bg-neutral-100 text-neutral-600 dark:bg-zinc-700 dark:text-neutral-300">
                    Grátis
                </span>
            @endif

            {{-- Badge do status --}}
            @if($status === 'ACTIVE')
                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-sm font-semibold bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400">
                    <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span>
                    Ativa
                </span>
            @elseif($status === 'PENDING_PAYMENT')
                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-sm font-semibold bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-400">
                    <span class="w-1.5 h-1.5 rounded-full bg-yellow-500"></span>
                    Aguardando pagamento
                </span>
            @elseif($status === 'BLOCKED')
                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-sm font-semibold bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-400">
                    <span class="w-1.5 h-1.5 rounded-full bg-red-500"></span>
                    Bloqueada
                </span>
            @endif
        </div>

        @if($status === 'PENDING_PAYMENT')
            <div class="bg-yellow-50 border border-yellow-200 rounded-lg px-4 py-3 text-sm text-yellow-800 dark:bg-yellow-900/20 dark:border-yellow-700/50 dark:text-yellow-300">
                @if($setupFeePaidAt)
                    Sua assinatura mensal está pendente de pagamento. Você receberá o link PIX por e-mail em instantes. Após o pagamento, sua conta será reativada automaticamente.
                @else
                    Sua taxa de ativação está pendente. Você receberá o link PIX por e-mail em instantes. Após o pagamento, sua conta será ativada automaticamente.
                @endif
            </div>
        @endif

        @if($status === 'BLOCKED')
            <div class="bg-red-50 border border-red-200 rounded-lg px-4 py-3 text-sm text-red-800 dark:bg-red-900/20 dark:border-red-700/50 dark:text-red-300">
                Sua conta está bloqueada por falta de pagamento. Efetue o pagamento da cobrança em aberto para reativar o acesso.
            </div>
        @endif

        @if($setupFeePaidAt)
            <p class="text-xs text-neutral-400 dark:text-neutral-500">Taxa de ativação paga em {{ $setupFeePaidAt }}</p>
        @endif

        @if(in_array($plan, ['essencial', 'pro']) && $amount)
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 pt-1">
                <div class="space-y-0.5">
                    <p class="text-xs text-neutral-500 dark:text-neutral-400 uppercase tracking-wide">Valor mensal</p>
                    <p class="text-lg font-semibold text-neutral-800 dark:text-neutral-100">
                        R$ {{ number_format($amount, 2, ',', '.') }}
                    </p>
                </div>
                @if($nextDueDate)
                    <div class="space-y-0.5">
                        <p class="text-xs text-neutral-500 dark:text-neutral-400 uppercase tracking-wide">Próximo vencimento</p>
                        <p class="text-lg font-semibold text-neutral-800 dark:text-neutral-100">{{ $nextDueDate }}</p>
                    </div>
                @endif
                @if($lastPaymentAt)
                    <div class="space-y-0.5">
                        <p class="text-xs text-neutral-500 dark:text-neutral-400 uppercase tracking-wide">Último pagamento</p>
                        <p class="text-lg font-semibold text-neutral-800 dark:text-neutral-100">{{ $lastPaymentAt }}</p>
                    </div>
                @endif
            </div>
        @endif

        @if(in_array($plan, ['essencial', 'pro']) && !$asaasSubscriptionId && $status === 'ACTIVE')
            <p class="text-sm text-neutral-500 dark:text-neutral-400">
                A assinatura está sendo gerada. Aguarde alguns instantes e recarregue a página.
            </p>
        @endif

        @if($plan === 'free')
            <p class="text-sm text-neutral-500 dark:text-neutral-400">
                Você está no plano gratuito com taxa de 1% por pedido e limite de 50 pedidos/mês. Faça upgrade para eliminar as taxas.
            </p>
        @endif

        {{-- Downgrade to free link --}}
        @if(in_array($plan, ['essencial', 'pro']))
            <div class="pt-1 border-t dark:border-zinc-700">
                <button wire:click="confirmChangePlan('free')"
                        class="text-sm text-neutral-400 hover:text-red-500 dark:text-neutral-500 dark:hover:text-red-400 transition-colors">
                    Voltar ao plano gratuito
                </button>
            </div>
        @endif
    </div>

    {{-- Upgrade cards (shown when not on the target plan) --}}
    @php
        $upgradePlans = [
            'essencial' => ['label' => 'Essencial', 'price' => config('plans.essencial.monthly_price', 59.00), 'icon' => '📦', 'features' => ['Pedidos ilimitados', 'Zero taxas por pedido', '1 filial']],
            'pro'       => ['label' => 'PRO',       'price' => config('plans.pro.monthly_price', 119.00),      'icon' => '🚀', 'features' => ['Pedidos ilimitados', 'Zero taxas por pedido', 'Até 3 filiais']],
        ];
        $availableUpgrades = collect($upgradePlans)->filter(fn($p, $key) => $key !== $plan);
    @endphp

    @foreach($availableUpgrades as $planKey => $planData)
        <div class="bg-white border rounded-xl shadow-sm p-6 space-y-4 dark:bg-zinc-800 dark:border-zinc-700">
            <div class="flex items-center justify-between flex-wrap gap-4">
                <div class="space-y-1">
                    <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">
                        {{ $planData['icon'] }} Plano {{ $planData['label'] }}
                    </h2>
                    <p class="text-sm text-neutral-500 dark:text-neutral-400">
                        @foreach($planData['features'] as $feature)
                            {{ $feature }}@if(!$loop->last) · @endif
                        @endforeach
                    </p>
                </div>
                <div class="flex items-center gap-4">
                    <div class="text-right">
                        <span class="text-xl font-bold text-neutral-800 dark:text-neutral-100">
                            R$ {{ number_format($planData['price'], 2, ',', '.') }}
                        </span>
                        <span class="text-sm text-neutral-500 dark:text-neutral-400">/mês</span>
                    </div>
                    <button wire:click="confirmChangePlan('{{ $planKey }}')"
                            class="inline-flex items-center gap-1.5 bg-[#7A00A3] text-white text-sm font-medium px-4 py-2 rounded-lg hover:bg-[#5c0079] transition-colors">
                        {{ $plan === 'free' ? 'Fazer upgrade' : 'Trocar plano' }}
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 pt-1 border-t dark:border-zinc-700">
                @foreach($planData['features'] as $feature)
                    <div class="flex items-center gap-2 text-sm text-neutral-600 dark:text-neutral-400">
                        <svg class="w-4 h-4 text-green-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        {{ $feature }}
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach

    {{-- Cobranças Recentes --}}
    @if(in_array($plan, ['essencial', 'pro']) && $asaasSubscriptionId)
        <div class="bg-white border rounded-xl shadow-sm p-6 space-y-4 dark:bg-zinc-800 dark:border-zinc-700">
            <h2 class="font-semibold text-neutral-700 text-sm uppercase tracking-wide dark:text-neutral-300">Cobranças Recentes</h2>

            @if(count($payments) > 0)
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b dark:border-zinc-700">
                                <th class="text-left py-2 pr-4 font-medium text-neutral-500 dark:text-neutral-400">Vencimento</th>
                                <th class="text-left py-2 pr-4 font-medium text-neutral-500 dark:text-neutral-400">Valor</th>
                                <th class="text-left py-2 pr-4 font-medium text-neutral-500 dark:text-neutral-400">Status</th>
                                <th class="text-left py-2 font-medium text-neutral-500 dark:text-neutral-400">Ação</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y dark:divide-zinc-700">
                            @foreach($payments as $payment)
                                <tr>
                                    <td class="py-3 pr-4 text-neutral-700 dark:text-neutral-300">
                                        {{ \Carbon\Carbon::parse($payment['dueDate'])->format('d/m/Y') }}
                                    </td>
                                    <td class="py-3 pr-4 text-neutral-700 dark:text-neutral-300">
                                        R$ {{ number_format($payment['value'], 2, ',', '.') }}
                                    </td>
                                    <td class="py-3 pr-4">
                                        @php
                                            $statusMap = [
                                                'PENDING'   => ['label' => 'Pendente',   'class' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-400'],
                                                'CONFIRMED' => ['label' => 'Confirmado', 'class' => 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400'],
                                                'RECEIVED'  => ['label' => 'Recebido',   'class' => 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400'],
                                                'OVERDUE'   => ['label' => 'Vencido',    'class' => 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-400'],
                                            ];
                                            $s = $statusMap[$payment['status']] ?? ['label' => $payment['status'], 'class' => 'bg-neutral-100 text-neutral-600 dark:bg-zinc-700 dark:text-neutral-300'];
                                        @endphp
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold {{ $s['class'] }}">
                                            {{ $s['label'] }}
                                        </span>
                                    </td>
                                    <td class="py-3">
                                        @if(in_array($payment['status'], ['PENDING', 'OVERDUE']) && !empty($payment['invoiceUrl']))
                                            <a href="{{ $payment['invoiceUrl'] }}" target="_blank" rel="noopener"
                                               class="text-xs font-medium text-amber-600 hover:text-amber-700 dark:text-amber-400 dark:hover:text-amber-300 underline underline-offset-2">
                                                Pagar via PIX
                                            </a>
                                        @else
                                            <span class="text-xs text-neutral-400 dark:text-neutral-500">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="text-sm text-neutral-500 dark:text-neutral-400">Nenhuma cobrança encontrada ainda.</p>
            @endif
        </div>
    @endif

    {{-- Modal de confirmação de troca de plano --}}
    <flux:modal name="confirm-plan-change" class="max-w-sm">
        <div class="space-y-5">
            @if($targetPlan === 'free')
                <div class="flex items-start gap-4">
                    <div class="w-10 h-10 rounded-full bg-red-100 dark:bg-red-900/30 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                        </svg>
                    </div>
                    <div>
                        <flux:heading size="lg">Voltar ao plano gratuito?</flux:heading>
                        <flux:subheading class="mt-1">
                            Sua assinatura será <strong>cancelada imediatamente</strong> e a taxa de <strong>1% por pedido</strong> voltará a ser cobrada (máx. 50 pedidos/mês). Você manterá acesso pois a taxa de ativação já foi paga.
                        </flux:subheading>
                    </div>
                </div>
                <div class="flex justify-end gap-3 pt-1">
                    <flux:modal.close>
                        <flux:button variant="ghost" wire:click="cancelPlanChange">Manter plano atual</flux:button>
                    </flux:modal.close>
                    <flux:button wire:click="changePlan" variant="danger">Confirmar downgrade</flux:button>
                </div>
            @elseif($targetPlan === 'essencial')
                <div class="flex items-start gap-4">
                    <div class="w-10 h-10 rounded-full bg-purple-100 dark:bg-purple-900/30 flex items-center justify-center shrink-0">
                        <span class="text-xl">📦</span>
                    </div>
                    <div>
                        <flux:heading size="lg">Mudar para o plano Essencial?</flux:heading>
                        <flux:subheading class="mt-1">
                            Você será cobrado <strong>R$ {{ number_format(config('plans.essencial.monthly_price', 59.00), 2, ',', '.') }}/mês</strong> via PIX.
                            O link de pagamento será enviado por e-mail. Sua conta é ativada automaticamente após a confirmação.
                        </flux:subheading>
                    </div>
                </div>
                <div class="flex justify-end gap-3 pt-1">
                    <flux:modal.close>
                        <flux:button variant="ghost" wire:click="cancelPlanChange">Cancelar</flux:button>
                    </flux:modal.close>
                    <flux:button wire:click="changePlan" variant="primary">Confirmar</flux:button>
                </div>
            @else
                <div class="flex items-start gap-4">
                    <div class="w-10 h-10 rounded-full bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5 text-amber-600 dark:text-amber-400" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                        </svg>
                    </div>
                    <div>
                        <flux:heading size="lg">Fazer upgrade para PRO?</flux:heading>
                        <flux:subheading class="mt-1">
                            Você será cobrado <strong>R$ {{ number_format(config('plans.pro.monthly_price', 119.00), 2, ',', '.') }}/mês</strong> via PIX.
                            O link de pagamento será enviado por e-mail. Sua conta é ativada automaticamente após a confirmação.
                        </flux:subheading>
                    </div>
                </div>
                <div class="flex justify-end gap-3 pt-1">
                    <flux:modal.close>
                        <flux:button variant="ghost" wire:click="cancelPlanChange">Cancelar</flux:button>
                    </flux:modal.close>
                    <flux:button wire:click="changePlan" variant="primary">Confirmar upgrade</flux:button>
                </div>
            @endif
        </div>
    </flux:modal>

</div>
