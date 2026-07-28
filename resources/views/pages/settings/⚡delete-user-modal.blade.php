<?php

use App\Concerns\PasswordValidationRules;
use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component {
    use PasswordValidationRules;

    public string $password = '';

    /**
     * Delete the currently authenticated user.
     */
    public function deleteUser(Logout $logout): void
    {
        $this->validate([
            'password' => $this->currentPasswordRules(),
        ]);

        tap(Auth::user(), $logout(...))->forceDelete();

        $this->redirect('/', navigate: true);
    }
}; ?>

<flux:modal name="confirm-user-deletion" :show="$errors->isNotEmpty()" focusable class="max-w-lg">
    <form method="POST" wire:submit="deleteUser" class="space-y-6">
        <div>
            <flux:heading size="lg">{{ __('Tem certeza que deseja excluir sua conta?') }}</flux:heading>

            <flux:subheading>
                {{ __('Ao excluir sua conta, todos os seus recursos e dados serão permanentemente removidos. Digite sua senha para confirmar que deseja excluir permanentemente sua conta.') }}
            </flux:subheading>
        </div>

        <flux:input wire:model="password" :label="__('Senha')" type="password" />

        <div class="flex justify-end space-x-2 rtl:space-x-reverse">
            <flux:modal.close>
                <flux:button variant="filled">{{ __('Cancelar') }}</flux:button>
            </flux:modal.close>

            <flux:button variant="danger" type="submit" data-test="confirm-delete-user-button">
                {{ __('Excluir conta') }}
            </flux:button>
        </div>
    </form>
</flux:modal>
