<x-layouts::auth :title="__('Verificação de e-mail')">
    <div class="mt-4 flex flex-col gap-6">
        <flux:text class="text-center">
            {{ __('Por favor, verifique seu endereço de e-mail clicando no link que acabamos de enviar para você.') }}
        </flux:text>

        @if (session('status') == 'verification-link-sent')
            <flux:text class="text-center font-medium !dark:text-green-400 !text-green-600">
                {{ __('Um novo link de verificação foi enviado para o endereço de e-mail fornecido durante o cadastro.') }}
            </flux:text>
        @endif

        <div class="flex flex-col items-center justify-between space-y-3">
            <form method="POST" action="{{ route('verification.send') }}">
                @csrf
                <flux:button type="submit" variant="primary" class="w-full">
                    {{ __('Reenviar e-mail de verificação') }}
                </flux:button>
            </form>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <flux:button variant="ghost" type="submit" class="text-sm cursor-pointer" data-test="logout-button">
                    {{ __('Sair') }}
                </flux:button>
            </form>
        </div>
    </div>
</x-layouts::auth>
