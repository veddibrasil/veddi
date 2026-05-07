<flux:modal name="profile-modal" class="max-w-lg" focusable>
    <div class="space-y-6">
        <div>
            <flux:heading size="lg">{{ __('Meu perfil') }}</flux:heading>
            <flux:subheading>{{ __('Atualize seus dados pessoais e senha') }}</flux:subheading>
        </div>

        <flux:separator />

        <div class="space-y-4">
            <flux:heading size="sm">{{ __('Informações pessoais') }}</flux:heading>

            <form wire:submit="updateProfileInformation" class="space-y-4">
                <flux:input
                    wire:model="name"
                    :label="__('Nome')"
                    type="text"
                    required
                    autofocus
                    autocomplete="name"
                />

                <flux:input
                    wire:model="email"
                    :label="__('E-mail')"
                    type="email"
                    required
                    autocomplete="email"
                />

                <div class="flex items-center gap-4">
                    <flux:button variant="primary" type="submit">
                        {{ __('Salvar') }}
                    </flux:button>

                    <x-action-message on="profile-updated">
                        {{ __('Salvo.') }}
                    </x-action-message>
                </div>
            </form>
        </div>

        <flux:separator />

        <div class="space-y-4">
            <flux:heading size="sm">{{ __('Alterar senha') }}</flux:heading>

            <form wire:submit="updatePassword" class="space-y-4">
                <flux:input
                    wire:model="current_password"
                    :label="__('Senha atual')"
                    type="password"
                    required
                    autocomplete="current-password"
                />

                <flux:input
                    wire:model="password"
                    :label="__('Nova senha')"
                    type="password"
                    required
                    autocomplete="new-password"
                />

                <flux:input
                    wire:model="password_confirmation"
                    :label="__('Confirmar nova senha')"
                    type="password"
                    required
                    autocomplete="new-password"
                />

                <div class="flex items-center gap-4">
                    <flux:button variant="primary" type="submit">
                        {{ __('Alterar senha') }}
                    </flux:button>

                    <x-action-message on="password-updated">
                        {{ __('Salvo.') }}
                    </x-action-message>
                </div>
            </form>
        </div>
    </div>
</flux:modal>
