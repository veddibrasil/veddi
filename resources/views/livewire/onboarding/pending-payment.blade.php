<div class="flex flex-col items-center gap-6 w-full max-w-md">

    {{-- Header --}}
    <div class="text-center">
        <p class="text-xs font-semibold uppercase tracking-widest text-[#7A00A3] mb-2">Taxa de ativação</p>
        <h1 class="text-2xl font-extrabold text-zinc-900" style="font-family: 'Montserrat', sans-serif;">
            @if($company?->subscription_payment_method === 'BOLETO')
                Seu boleto está pronto
            @else
                Pague via PIX
            @endif
        </h1>
        <p class="mt-1 text-sm text-zinc-500">
            @if($this->planHasMonthly)
                R$ {{ number_format($this->firstPaymentTotal, 2, ',', '.') }} (ativação + 1º mês)
            @else
                Taxa única de ativação — R$ {{ number_format($this->firstPaymentTotal, 2, ',', '.') }}
            @endif
        </p>
    </div>

    {{-- Breakdown do pagamento --}}
    @if($this->planHasMonthly)
        <div class="w-full rounded-xl bg-zinc-50 border border-zinc-200 p-4 text-sm space-y-1.5">
            <div class="flex justify-between text-zinc-600">
                <span>Taxa de ativação (única)</span>
                <span>R$ {{ number_format($company->plan?->setupFee() ?? 99, 2, ',', '.') }}</span>
            </div>
            <div class="flex justify-between text-zinc-600">
                <span>1º mês — {{ $company->plan?->label() }}</span>
                <span>R$ {{ number_format($this->planMonthlyPrice, 2, ',', '.') }}</span>
            </div>
            <div class="flex justify-between font-bold text-zinc-900 border-t border-zinc-200 pt-1.5">
                <span>Total</span>
                <span>R$ {{ number_format($this->firstPaymentTotal, 2, ',', '.') }}</span>
            </div>
            <p class="text-xs text-zinc-400 pt-0.5">Próximas cobranças: R$ {{ number_format($this->planMonthlyPrice, 2, ',', '.') }}/mês</p>
        </div>
    @endif

    {{-- ════════════════════════
         PIX
    ════════════════════════ --}}
    @if($company?->subscription_payment_method === 'PIX')

        @if(! $ready)
            {{-- Aguardando o job criar o charge --}}
            <div wire:poll.2500ms="checkPaymentStatus"
                 class="flex flex-col items-center gap-4 rounded-2xl border border-zinc-200 bg-zinc-50 p-8 w-full text-center">
                <svg class="h-8 w-8 animate-spin text-[#7A00A3]" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z"/>
                </svg>
                <p class="text-sm text-zinc-500">Gerando seu QR code PIX...</p>
            </div>
        @else
            {{-- QR code disponível --}}
            <div wire:poll.2500ms="checkPaymentStatus"
                 class="flex flex-col items-center gap-5 rounded-2xl border border-zinc-200 bg-white p-6 w-full">

                @if($company->asaas_setup_pix_qr_code)
                    <img
                        src="data:image/png;base64,{{ $company->asaas_setup_pix_qr_code }}"
                        alt="QR Code PIX"
                        class="w-52 h-52 rounded-xl border border-zinc-100"
                    />
                @endif

                @if($company->asaas_setup_pix_copy_paste)
                    <div class="w-full">
                        <p class="text-xs text-zinc-500 mb-1.5 text-center">Ou copie o código:</p>
                        <div class="flex gap-2">
                            <input
                                type="text"
                                readonly
                                value="{{ $company->asaas_setup_pix_copy_paste }}"
                                class="flex-1 rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-xs text-zinc-600 font-mono truncate"
                            />
                            <button
                                wire:click="copyPix"
                                class="flex items-center gap-1.5 rounded-xl border px-3 py-2 text-xs font-medium transition-all
                                    {{ $copied ? 'border-green-300 bg-green-50 text-green-700' : 'border-zinc-200 bg-white text-zinc-600 hover:bg-zinc-50' }}"
                            >
                                @if($copied)
                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                    </svg>
                                    Copiado!
                                @else
                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.666 3.888A2.25 2.25 0 0 0 13.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 0 1-.75.75H9a.75.75 0 0 1-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 0 1-2.25 2.25H6.75A2.25 2.25 0 0 1 4.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 0 1 1.927-.184" />
                                    </svg>
                                    Copiar
                                @endif
                            </button>
                        </div>
                    </div>
                @endif

                <div class="flex items-center gap-2 text-xs text-zinc-400">
                    <svg class="h-3.5 w-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z"/>
                    </svg>
                    Aguardando confirmação do pagamento...
                </div>
            </div>
        @endif

    {{-- ════════════════════════
         BOLETO
    ════════════════════════ --}}
    @elseif($company?->subscription_payment_method === 'BOLETO')

        @if(! $ready)
            <div wire:poll.2500ms="checkPaymentStatus"
                 class="flex flex-col items-center gap-4 rounded-2xl border border-zinc-200 bg-zinc-50 p-8 w-full text-center">
                <svg class="h-8 w-8 animate-spin text-[#7A00A3]" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z"/>
                </svg>
                <p class="text-sm text-zinc-500">Gerando seu boleto...</p>
            </div>
        @else
            <div wire:poll.2500ms="checkPaymentStatus"
                 class="flex flex-col items-center gap-5 rounded-2xl border border-zinc-200 bg-white p-6 w-full">

                <div class="flex h-14 w-14 items-center justify-center rounded-full bg-purple-100">
                    <svg class="h-7 w-7 text-[#7A00A3]" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25H12" />
                    </svg>
                </div>

                <div class="text-center space-y-1">
                    <p class="font-semibold text-zinc-800">Boleto gerado com sucesso!</p>
                    <p class="text-sm text-zinc-500">Prazo de compensação: até 3 dias úteis.</p>
                </div>

                @if($company->asaas_setup_bank_slip_url)
                    <a
                        href="{{ $company->asaas_setup_bank_slip_url }}"
                        target="_blank"
                        rel="noopener"
                        class="w-full flex items-center justify-center gap-2 rounded-xl px-6 py-3 text-sm font-bold text-white shadow-sm transition-all duration-200"
                        style="background: linear-gradient(135deg, #5c0079, #7A00A3);"
                    >
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                        </svg>
                        Abrir boleto
                    </a>
                @endif

                <div class="flex items-center gap-2 text-xs text-zinc-400">
                    <svg class="h-3.5 w-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z"/>
                    </svg>
                    Aguardando compensação do boleto...
                </div>
            </div>
        @endif

    @endif

    {{-- Link login --}}
    <p class="text-sm text-zinc-500">
        Já realizou o pagamento?
        <flux:link href="{{ route('login') }}" wire:navigate class="text-[#7A00A3] hover:text-[#5c0079]">
            Faça login aqui
        </flux:link>
    </p>


</div>

@script
<script>
    $wire.on('copy-to-clipboard', ({ text }) => {
        navigator.clipboard.writeText(text).catch(() => {
            const el = document.createElement('textarea');
            el.value = text;
            document.body.appendChild(el);
            el.select();
            document.execCommand('copy');
            document.body.removeChild(el);
        });
    });
</script>
@endscript
