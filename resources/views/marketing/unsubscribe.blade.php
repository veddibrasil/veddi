<x-layouts::auth.simple title="Preferências de e-mail">
    {{-- O layout auth.simple já desenha o card; aqui só o conteúdo. --}}
    <div class="space-y-4">
        <div class="space-y-1 text-center">
            <h1 class="text-xl font-semibold text-zinc-900">Preferências de e-mail</h1>
            @unless($invalid)
                <p class="text-sm text-zinc-500">{{ $email }}</p>
            @endunless
        </div>

        @if($invalid)
            <p class="text-sm text-zinc-600 text-center">
                Este link não é válido ou foi alterado. Use o link “Descadastrar” do último e-mail que você recebeu.
            </p>
        @else
            @if($done === 'unsubscribed')
                <div class="rounded-lg bg-green-50 px-4 py-3 text-sm text-green-700">Pronto: você não vai mais receber nossos e-mails de novidades.</div>
            @elseif($done === 'resubscribed')
                <div class="rounded-lg bg-green-50 px-4 py-3 text-sm text-green-700">Inscrição reativada. Obrigado!</div>
            @elseif($done === 'blocked')
                <div class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-700">Este endereço foi bloqueado por falha de entrega ou reclamação. Fale com o suporte para reativar.</div>
            @endif

            <p class="text-sm text-zinc-600">
                @if($subscribed)
                    Você recebe e-mails de novidades, dicas e lançamentos do {{ config('app.name') }}. E-mails sobre pedidos, cobrança e segurança da conta continuam chegando normalmente.
                @else
                    Você não recebe e-mails de novidades do {{ config('app.name') }}. E-mails sobre pedidos, cobrança e segurança da conta continuam chegando normalmente.
                @endif
            </p>

            <form method="POST" action="{{ $actionUrl }}">
                @csrf
                @if($subscribed)
                    <input type="hidden" name="action" value="unsubscribe">
                    <button type="submit" class="w-full rounded-lg bg-zinc-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-zinc-700">
                        Não quero mais receber
                    </button>
                @else
                    <input type="hidden" name="action" value="resubscribe">
                    <button type="submit" class="w-full rounded-lg bg-[#7A00A3] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#5c0079]">
                        Quero voltar a receber
                    </button>
                @endif
            </form>
        @endif
    </div>
</x-layouts::auth.simple>
