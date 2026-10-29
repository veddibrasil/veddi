<?php

use App\Concerns\ProfileValidationRules;
use App\Services\Marketing\SuppressionService;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Configurações de perfil')] class extends Component {
    use ProfileValidationRules;

    public string $name = '';
    public string $email = '';
    public bool $marketingOptIn = false;

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
        // Com legítimo interesse (require_opt_in=false) recebe quem não saiu, mesmo sem aceite gravado.
        $this->marketingOptIn = config('marketing.require_opt_in')
            ? Auth::user()->wantsMarketingEmails()
            : Auth::user()->marketing_opt_out_at === null;
    }

    /**
     * Aceite de e-mail marketing (LGPD): grava a data do consentimento ou da saída. Voltar a
     * aceitar também tira o e-mail da supressão por descadastro (bounce/spam continuam valendo).
     */
    public function updatedMarketingOptIn(bool $value): void
    {
        $user = Auth::user();

        if ($value) {
            app(SuppressionService::class)->resubscribe($user->email);
            $user->forceFill(['marketing_opt_in_at' => now(), 'marketing_opt_out_at' => null])->save();
        } else {
            $user->forceFill(['marketing_opt_out_at' => now()])->save();
        }

        $this->dispatch('marketing-preference-updated');
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $validated = $this->validate($this->profileRules($user->id));

        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        $this->dispatch('profile-updated', name: $user->name);
    }

    /**
     * Send an email verification notification to the current user.
     */
    public function resendVerificationNotification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));

            return;
        }

        $user->sendEmailVerificationNotification();

        Session::flash('status', 'verification-link-sent');
    }

    #[Computed]
    public function hasUnverifiedEmail(): bool
    {
        return Auth::user() instanceof MustVerifyEmail && ! Auth::user()->hasVerifiedEmail();
    }

    #[Computed]
    public function showDeleteUser(): bool
    {
        return ! Auth::user() instanceof MustVerifyEmail
            || (Auth::user() instanceof MustVerifyEmail && Auth::user()->hasVerifiedEmail());
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Configurações de perfil') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Perfil')" :subheading="__('Atualize seu nome e endereço de e-mail')">
        <form wire:submit="updateProfileInformation" class="my-6 w-full space-y-6">
            <flux:input wire:model="name" :label="__('Nome')" type="text" required autofocus autocomplete="name" />

            <div>
                <flux:input wire:model="email" :label="__('E-mail')" type="email" required autocomplete="email" />

                @if ($this->hasUnverifiedEmail)
                    <div>
                        <flux:text class="mt-4">
                            {{ __('Seu endereço de e-mail não foi verificado.') }}

                            <flux:link class="text-sm cursor-pointer" wire:click.prevent="resendVerificationNotification">
                                {{ __('Clique aqui para reenviar o e-mail de verificação.') }}
                            </flux:link>
                        </flux:text>

                        @if (session('status') === 'verification-link-sent')
                            <flux:text class="mt-2 font-medium !dark:text-green-400 !text-green-600">
                                {{ __('Um novo link de verificação foi enviado para seu endereço de e-mail.') }}
                            </flux:text>
                        @endif
                    </div>
                @endif
            </div>

            <div class="flex items-center gap-4">
                <div class="flex items-center justify-end">
                    <flux:button variant="primary" type="submit" class="w-full" data-test="update-profile-button">
                        {{ __('Salvar') }}
                    </flux:button>
                </div>

                <x-action-message class="me-3" on="profile-updated">
                    {{ __('Salvo.') }}
                </x-action-message>
            </div>
        </form>

        <div class="my-6 w-full space-y-3 border-t border-zinc-200 pt-6 dark:border-zinc-700">
            <flux:heading size="sm">{{ __('Comunicações') }}</flux:heading>
            <flux:switch wire:model.live="marketingOptIn" :label="__('Receber novidades, dicas e lançamentos da Veddi por e-mail')" :description="__('E-mails sobre pedidos, cobrança e segurança da conta continuam chegando normalmente.')" />
            <x-action-message on="marketing-preference-updated">{{ __('Preferência salva.') }}</x-action-message>
        </div>

        {{-- @if ($this->showDeleteUser)
            <livewire:pages::settings.delete-user-form />
        @endif --}}
    </x-pages::settings.layout>
</section>
