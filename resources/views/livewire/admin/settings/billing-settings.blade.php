<div class="w-full space-y-6"
     x-init="
         $watch(() => $wire.confirmingPlanChange, val => val ? $flux.modal('confirm-plan-change').show() : $flux.modal('confirm-plan-change').close());
         $watch(() => $wire.cardSuccess, val => { if (val) setTimeout(() => window.location.reload(), 2000); });
     "
     x-on:open-plan-card-modal.window="$flux.modal('plan-change-card').show()">

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
                                                Pagar
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

    {{-- Modal de cartão de crédito para troca de plano --}}
    <flux:modal name="plan-change-card" class="max-w-lg">
        <div
            x-data="{
                maskCardNumber(e) {
                    let v = e.target.value.replace(/\D/g, '').substring(0, 16);
                    v = v.replace(/(\d{4})(?=\d)/g, '$1 ');
                    e.target.value = v;
                    $wire.set('cardNumber', v);
                },
                maskExpiry(e) {
                    let v = e.target.value.replace(/\D/g, '').substring(0, 4);
                    if (v.length >= 3) v = v.substring(0, 2) + '/' + v.substring(2);
                    e.target.value = v;
                    $wire.set('cardExpiry', v);
                },
                maskCvv(e) {
                    let v = e.target.value.replace(/\D/g, '').substring(0, 4);
                    e.target.value = v;
                    $wire.set('cardCvv', v);
                },
                maskCpfCnpj(e) {
                    let v = e.target.value.replace(/\D/g, '');
                    if (v.length <= 11) {
                        v = v.substring(0, 11)
                             .replace(/(\d{3})(\d)/, '$1.$2')
                             .replace(/(\d{3})(\d)/, '$1.$2')
                             .replace(/(\d{3})(\d{1,2})$/, '$1-$2');
                    } else {
                        v = v.substring(0, 14)
                             .replace(/(\d{2})(\d)/, '$1.$2')
                             .replace(/(\d{3})(\d)/, '$1.$2')
                             .replace(/(\d{3})(\d)/, '$1/$2')
                             .replace(/(\d{4})(\d{1,2})$/, '$1-$2');
                    }
                    e.target.value = v;
                    $wire.set('cardCpfCnpj', v);
                },
                maskCep(e) {
                    let v = e.target.value.replace(/\D/g, '').substring(0, 8);
                    if (v.length > 5) v = v.substring(0, 5) + '-' + v.substring(5);
                    e.target.value = v;
                    $wire.set('cardPostalCode', v);
                },
                maskHolderName(e) {
                    e.target.value = e.target.value.toUpperCase();
                    $wire.set('cardHolderName', e.target.value);
                },
            }"
            class="space-y-5"
        >
            <div>
                <flux:heading size="lg">Dados do cartão de crédito</flux:heading>
                <flux:subheading class="mt-1">Pagamento processado com segurança via Asaas</flux:subheading>
            </div>

            @if($cardSuccess)
                <div class="flex flex-col items-center gap-3 py-6 text-center">
                    <div class="flex h-14 w-14 items-center justify-center rounded-full bg-green-100 dark:bg-green-900/30">
                        <svg class="h-7 w-7 text-green-600 dark:text-green-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                        </svg>
                    </div>
                    <p class="font-semibold text-neutral-800 dark:text-neutral-100">Pagamento confirmado!</p>
                    <p class="text-sm text-neutral-500 dark:text-neutral-400">Plano ativado. Recarregando...</p>
                </div>
            @else

                @if($cardError)
                    <div class="flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 dark:bg-red-900/20 dark:border-red-700/50 p-4 text-sm text-red-700 dark:text-red-400">
                        <svg class="h-5 w-5 shrink-0 mt-0.5 text-red-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                        </svg>
                        <span>{{ $cardError }}</span>
                    </div>
                @endif

                <div>
                    <flux:input
                        wire:model="cardNumber"
                        x-on:input="maskCardNumber($event)"
                        label="Número do cartão"
                        placeholder="0000 0000 0000 0000"
                        maxlength="19"
                        inputmode="numeric"
                        autocomplete="cc-number"
                    />
                    @error('cardNumber') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <flux:input
                            wire:model="cardExpiry"
                            x-on:input="maskExpiry($event)"
                            label="Validade"
                            placeholder="MM/AA"
                            maxlength="5"
                            inputmode="numeric"
                            autocomplete="cc-exp"
                        />
                        @error('cardExpiry') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <flux:input
                            wire:model="cardCvv"
                            x-on:input="maskCvv($event)"
                            label="CVV"
                            placeholder="123"
                            maxlength="4"
                            inputmode="numeric"
                            autocomplete="cc-csc"
                            type="password"
                            viewable
                        />
                        @error('cardCvv') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div>
                    <flux:input
                        wire:model="cardHolderName"
                        x-on:input="maskHolderName($event)"
                        label="Nome conforme no cartão"
                        placeholder="NOME SOBRENOME"
                        autocomplete="cc-name"
                    />
                    @error('cardHolderName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <hr class="border-zinc-100 dark:border-zinc-700">
                <p class="text-xs text-neutral-400 dark:text-neutral-500 -mt-2">Dados do titular para cobrança</p>

                <div>
                    <flux:input
                        wire:model="cardCpfCnpj"
                        x-on:input="maskCpfCnpj($event)"
                        label="CPF ou CNPJ do titular"
                        placeholder="000.000.000-00"
                        maxlength="18"
                        inputmode="numeric"
                    />
                    @error('cardCpfCnpj') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <flux:input
                            wire:model="cardPostalCode"
                            x-on:input="maskCep($event)"
                            label="CEP"
                            placeholder="00000-000"
                            maxlength="9"
                            inputmode="numeric"
                        />
                        @error('cardPostalCode') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <flux:input
                            wire:model="cardAddressNumber"
                            label="Número"
                            placeholder="123"
                        />
                        @error('cardAddressNumber') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                <button
                    wire:click="submitCardForSubscription"
                    wire:loading.attr="disabled"
                    wire:loading.class="opacity-75 cursor-not-allowed"
                    @if($cardProcessing) disabled @endif
                    class="w-full flex items-center justify-center gap-2 rounded-xl px-6 py-3 text-sm font-bold text-white shadow-sm transition-all duration-200 disabled:opacity-75"
                    style="background: linear-gradient(135deg, #5c0079, #7A00A3);"
                >
                    <span wire:loading.remove wire:target="submitCardForSubscription">
                        <svg class="h-4 w-4 inline mr-1" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z" />
                        </svg>
                        Pagar e ativar plano
                    </span>
                    <span wire:loading wire:target="submitCardForSubscription" class="flex items-center gap-2">
                        <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z"/>
                        </svg>
                        Processando...
                    </span>
                </button>

                <p class="text-center text-xs text-neutral-400 dark:text-neutral-500 flex items-center justify-center gap-1">
                    <svg class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                    </svg>
                    Pagamento processado com segurança via Asaas
                </p>

            @endif
        </div>
    </flux:modal>

    {{-- Modal de confirmação de troca de plano --}}
    <flux:modal name="confirm-plan-change" class="max-w-sm">
        {{-- x-data aqui: estado do método de pagamento gerenciado pelo Alpine,
             sem round-trips ao Livewire quando o usuário troca de opção. --}}
        <div class="space-y-5"
             x-data="{ method: 'pix', termsAccepted: false, syncToWire() { $wire.paymentMethod = this.method; $wire.acceptedTerms = this.termsAccepted; } }"
             x-init="$watch(() => $wire.confirmingPlanChange, val => { if (val) { method = 'pix'; termsAccepted = false; } })">
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
                            @if($plan === 'free')
                                <span x-show="method === 'credit_card'" style="display:none">
                                    Cobrança única de <strong>R$ {{ number_format(config('plans.essencial.setup_fee', 99.00) + config('plans.essencial.monthly_price', 59.00), 2, ',', '.') }}</strong> no cartão (taxa de ativação R$ {{ number_format(config('plans.essencial.setup_fee', 99.00), 2, ',', '.') }} + 1º mês R$ {{ number_format(config('plans.essencial.monthly_price', 59.00), 2, ',', '.') }}). A partir do 2º mês: R$ {{ number_format(config('plans.essencial.monthly_price', 59.00), 2, ',', '.') }}/mês.
                                </span>
                                <span x-show="method === 'boleto'" style="display:none">
                                    Você receberá um boleto de <strong>R$ {{ number_format(config('plans.essencial.setup_fee', 99.00) + config('plans.essencial.monthly_price', 59.00), 2, ',', '.') }}</strong> (taxa de ativação + 1º mês). A partir do 2º mês: R$ {{ number_format(config('plans.essencial.monthly_price', 59.00), 2, ',', '.') }}/mês.
                                </span>
                                <span x-show="method === 'pix'">
                                    Você receberá um PIX de <strong>R$ {{ number_format(config('plans.essencial.setup_fee', 99.00) + config('plans.essencial.monthly_price', 59.00), 2, ',', '.') }}</strong> (taxa de ativação + 1º mês). A partir do 2º mês: R$ {{ number_format(config('plans.essencial.monthly_price', 59.00), 2, ',', '.') }}/mês.
                                </span>
                            @else
                                <span x-show="method === 'credit_card'" style="display:none">
                                    Você será cobrado <strong>R$ {{ number_format(config('plans.essencial.monthly_price', 59.00), 2, ',', '.') }}/mês</strong> no cartão de crédito. O primeiro mês é cobrado imediatamente.
                                </span>
                                <span x-show="method === 'boleto'" style="display:none">
                                    Você será cobrado <strong>R$ {{ number_format(config('plans.essencial.monthly_price', 59.00), 2, ',', '.') }}/mês</strong> via boleto. O link será enviado por e-mail.
                                </span>
                                <span x-show="method === 'pix'">
                                    Você será cobrado <strong>R$ {{ number_format(config('plans.essencial.monthly_price', 59.00), 2, ',', '.') }}/mês</strong> via PIX. O link de pagamento será enviado por e-mail.
                                </span>
                            @endif
                        </flux:subheading>
                    </div>
                </div>
                @include('livewire.admin.settings._payment-method-selector')
                <div class="border rounded-lg px-4 py-3 bg-neutral-50 dark:bg-zinc-700/50 dark:border-zinc-600">
                    <label class="flex items-start gap-3 cursor-pointer">
                        <input type="checkbox" x-model="termsAccepted" class="mt-0.5 accent-[#7A00A3] shrink-0">
                        <span class="text-sm text-neutral-700 dark:text-neutral-300">
                            Li e aceito os
                            <button type="button" x-on:click="$flux.modal('terms-modal').show()"
                                    class="text-[#7A00A3] hover:underline font-medium">
                                Termos de Responsabilidade
                            </button>
                        </span>
                    </label>
                </div>
                <div class="flex justify-end gap-3 pt-1">
                    <flux:modal.close>
                        <flux:button variant="ghost" wire:click="cancelPlanChange">Cancelar</flux:button>
                    </flux:modal.close>
                    <span :class="{ 'opacity-50 pointer-events-none cursor-not-allowed': !termsAccepted }">
                        <flux:button
                            x-on:click="syncToWire()"
                            wire:click="changePlan"
                            variant="primary">
                            <span x-text="method === 'credit_card' ? 'Continuar para pagamento' : 'Confirmar'"></span>
                        </flux:button>
                    </span>
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
                            @if($plan === 'free')
                                <span x-show="method === 'credit_card'" style="display:none">
                                    Cobrança única de <strong>R$ {{ number_format(config('plans.pro.setup_fee', 99.00) + config('plans.pro.monthly_price', 119.00), 2, ',', '.') }}</strong> no cartão (taxa de ativação R$ {{ number_format(config('plans.pro.setup_fee', 99.00), 2, ',', '.') }} + 1º mês R$ {{ number_format(config('plans.pro.monthly_price', 119.00), 2, ',', '.') }}). A partir do 2º mês: R$ {{ number_format(config('plans.pro.monthly_price', 119.00), 2, ',', '.') }}/mês.
                                </span>
                                <span x-show="method === 'boleto'" style="display:none">
                                    Você receberá um boleto de <strong>R$ {{ number_format(config('plans.pro.setup_fee', 99.00) + config('plans.pro.monthly_price', 119.00), 2, ',', '.') }}</strong> (taxa de ativação + 1º mês). A partir do 2º mês: R$ {{ number_format(config('plans.pro.monthly_price', 119.00), 2, ',', '.') }}/mês.
                                </span>
                                <span x-show="method === 'pix'">
                                    Você receberá um PIX de <strong>R$ {{ number_format(config('plans.pro.setup_fee', 99.00) + config('plans.pro.monthly_price', 119.00), 2, ',', '.') }}</strong> (taxa de ativação + 1º mês). A partir do 2º mês: R$ {{ number_format(config('plans.pro.monthly_price', 119.00), 2, ',', '.') }}/mês.
                                </span>
                            @else
                                <span x-show="method === 'credit_card'" style="display:none">
                                    Você será cobrado <strong>R$ {{ number_format(config('plans.pro.monthly_price', 119.00), 2, ',', '.') }}/mês</strong> no cartão de crédito. O primeiro mês é cobrado imediatamente.
                                </span>
                                <span x-show="method === 'boleto'" style="display:none">
                                    Você será cobrado <strong>R$ {{ number_format(config('plans.pro.monthly_price', 119.00), 2, ',', '.') }}/mês</strong> via boleto. O link será enviado por e-mail.
                                </span>
                                <span x-show="method === 'pix'">
                                    Você será cobrado <strong>R$ {{ number_format(config('plans.pro.monthly_price', 119.00), 2, ',', '.') }}/mês</strong> via PIX. O link de pagamento será enviado por e-mail.
                                </span>
                            @endif
                        </flux:subheading>
                    </div>
                </div>
                @include('livewire.admin.settings._payment-method-selector')
                <div class="border rounded-lg px-4 py-3 bg-neutral-50 dark:bg-zinc-700/50 dark:border-zinc-600">
                    <label class="flex items-start gap-3 cursor-pointer">
                        <input type="checkbox" x-model="termsAccepted" class="mt-0.5 accent-[#7A00A3] shrink-0">
                        <span class="text-sm text-neutral-700 dark:text-neutral-300">
                            Li e aceito os
                            <button type="button" x-on:click="$flux.modal('terms-modal').show()"
                                    class="text-[#7A00A3] hover:underline font-medium">
                                Termos de Responsabilidade
                            </button>
                        </span>
                    </label>
                </div>
                <div class="flex justify-end gap-3 pt-1">
                    <flux:modal.close>
                        <flux:button variant="ghost" wire:click="cancelPlanChange">Cancelar</flux:button>
                    </flux:modal.close>
                    <span :class="{ 'opacity-50 pointer-events-none cursor-not-allowed': !termsAccepted }">
                        <flux:button
                            x-on:click="syncToWire()"
                            wire:click="changePlan"
                            variant="primary">
                            <span x-text="method === 'credit_card' ? 'Continuar para pagamento' : 'Confirmar upgrade'"></span>
                        </flux:button>
                    </span>
                </div>
            @endif
        </div>
    </flux:modal>

    {{-- Modal de Termos de Responsabilidade --}}
    <flux:modal name="terms-modal" class="max-w-2xl">
        <div class="space-y-4">
            <flux:heading size="lg">Termos de Responsabilidade</flux:heading>
            <div class="max-h-[60vh] overflow-y-auto space-y-4 text-sm text-neutral-700 dark:text-neutral-300 pr-2">
                <p><strong>Última atualização:</strong> 30/03/2026</p>

                <p>Ao assinar um plano pago na plataforma <strong>Veddi</strong>, você declara que leu, compreendeu e concorda com os termos e condições descritos abaixo.</p>

                <div>
                    <h3 class="font-semibold text-neutral-800 dark:text-neutral-200 mb-1">1. Contratação e Pagamento</h3>
                    <p>A assinatura é cobrada mensalmente de forma recorrente, na data de vencimento estabelecida no momento da contratação. O não pagamento dentro do prazo resultará no bloqueio temporário do acesso à plataforma até a regularização do débito. Em caso de inadimplência superior a 30 dias, a conta poderá ser suspensa definitivamente.</p>
                </div>

                <div>
                    <h3 class="font-semibold text-neutral-800 dark:text-neutral-200 mb-1">2. Dados de Pagamento</h3>
                    <p>Os dados do cartão de crédito são transmitidos diretamente à operadora de pagamentos (<strong>Asaas</strong>) via checkout transparente e não são armazenados em nossos servidores. Para pagamentos via PIX ou boleto, os dados bancários são gerados pela Asaas e utilizados exclusivamente para a liquidação da cobrança. A Veddi não tem acesso aos dados sensíveis do seu instrumento de pagamento.</p>
                </div>

                <div>
                    <h3 class="font-semibold text-neutral-800 dark:text-neutral-200 mb-1">3. Limites de Uso</h3>
                    <p>Cada plano possui limites específicos de uso, incluindo número de pedidos mensais, quantidade de filiais e funcionalidades disponíveis. O uso além dos limites do plano contratado pode resultar no bloqueio de funcionalidades ou na necessidade de upgrade para um plano superior. Pedidos que excedam 50 por mês estão sujeitos a uma taxa adicional de <strong>3%</strong> sobre o excedente, conforme política vigente.</p>
                </div>

                <div>
                    <h3 class="font-semibold text-neutral-800 dark:text-neutral-200 mb-1">4. Cancelamento</h3>
                    <p>O cancelamento pode ser solicitado a qualquer momento diretamente pelo painel, na seção de <strong>Assinatura</strong>. O acesso permanece ativo até o fim do período já pago. Não há reembolso proporcional de períodos parciais utilizados.</p>
                </div>

                <div>
                    <h3 class="font-semibold text-neutral-800 dark:text-neutral-200 mb-1">5. Responsabilidades do Contratante</h3>
                    <p>É de responsabilidade exclusiva do contratante: manter os dados cadastrais e de pagamento sempre atualizados; garantir o uso adequado da plataforma dentro dos limites do plano contratado; zelar pela segurança e confidencialidade de suas credenciais de acesso; garantir que os pedidos registrados estejam em conformidade com as leis aplicáveis ao seu negócio; e não utilizar a plataforma para fins ilícitos ou que violem direitos de terceiros.</p>
                </div>

                <div>
                    <h3 class="font-semibold text-neutral-800 dark:text-neutral-200 mb-1">6. Responsabilidades da Plataforma</h3>
                    <p>A Veddi se compromete a manter a plataforma disponível e funcional, podendo realizar manutenções programadas com aviso prévio. A plataforma não se responsabiliza por interrupções decorrentes de força maior ou caso fortuito, falhas em serviços de terceiros (operadoras de pagamento, provedores de internet), uso indevido por parte do contratante ou de seus colaboradores, ou perdas financeiras decorrentes de erros operacionais do contratante.</p>
                </div>

                <div>
                    <h3 class="font-semibold text-neutral-800 dark:text-neutral-200 mb-1">7. Proteção de Dados (LGPD)</h3>
                    <p>Ao contratar o plano, você consente com o tratamento dos seus dados pessoais — incluindo nome, e-mail, CPF/CNPJ e dados de faturamento — para fins de cobrança, suporte e melhoria do serviço, conforme a <strong>Lei Geral de Proteção de Dados (Lei nº 13.709/2018)</strong>. Os dados não serão compartilhados com terceiros, salvo com parceiros essenciais à prestação do serviço (como a Asaas). Você pode solicitar a exclusão dos seus dados mediante cancelamento da conta, respeitados os prazos legais de retenção.</p>
                </div>

                <div>
                    <h3 class="font-semibold text-neutral-800 dark:text-neutral-200 mb-1">8. Alterações nos Termos</h3>
                    <p>Estes termos podem ser atualizados periodicamente. Em caso de alterações relevantes, notificaremos por e-mail com antecedência mínima de <strong>15 dias</strong>. A continuidade do uso da plataforma após o prazo de notificação implica a aceitação automática dos novos termos.</p>
                </div>

                <p class="text-xs text-neutral-400 dark:text-neutral-500 pt-2">Em caso de dúvidas, entre em contato com nosso suporte.</p>
            </div>
            <div class="flex justify-end pt-4 border-t dark:border-zinc-700">
                <flux:button x-on:click="$flux.modal('terms-modal').close()">Fechar</flux:button>
            </div>
        </div>
    </flux:modal>

</div>
