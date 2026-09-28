<?php

namespace App\Livewire\Admin\Pdv\Concerns;

use App\Models\Company;
use App\Support\MoneyInput;

trait HasManualDiscount
{
    public function updatedManualDiscountInput(): void
    {
        $this->applyOrRemoveManualDiscount();
    }

    public function updatedManualDiscountType(): void
    {
        $this->applyOrRemoveManualDiscount();
    }

    private function applyOrRemoveManualDiscount(): void
    {
        $value = MoneyInput::toFloat($this->manualDiscountInput);

        if (blank($this->manualDiscountInput) || $value <= 0) {
            $this->manualDiscountAmount = 0.0;
            $this->resetValidation('manual_discount');

            return;
        }

        $this->applyManualDiscount();
    }

    public function applyManualDiscount(): void
    {
        abort_unless(! $this->isWaiter, 403);

        $this->resetValidation('manual_discount');

        // Entrada inválida zera o desconto: senão o valor válido anterior seguiria valendo
        // enquanto o campo mostra outro número (e a mensagem de erro).
        $this->manualDiscountAmount = 0.0;

        if (! $this->manualDiscountAllowed) {
            $this->addError('manual_discount', 'Desconto manual não está habilitado para esta empresa.');

            return;
        }

        $value = MoneyInput::toFloat($this->manualDiscountInput);

        if ($value <= 0) {
            $this->addError('manual_discount', 'Informe um valor maior que zero.');

            return;
        }

        $base = $this->cartTotal;

        if ($this->manualDiscountType === 'percent') {
            if ($value > 100) {
                $this->addError('manual_discount', 'Percentual não pode exceder 100%.');

                return;
            }
            $this->manualDiscountAmount = round($base * ($value / 100), 2);
        } else {
            if ($value > $base) {
                $this->addError('manual_discount', 'Desconto não pode exceder o total restante.');

                return;
            }
            $this->manualDiscountAmount = $value;
        }
    }

    /**
     * Revalidação no servidor na hora de confirmar. `manualDiscountAmount` é gravado só por este
     * trait (a propriedade é `#[Locked]`), mas a permissão e o teto são conferidos de novo contra a
     * empresa e o carrinho atuais — a feature pode ter sido desligada com a tela aberta.
     * O registro de auditoria do desconto fica no `order_created` (metadata.manual_discount), não a
     * cada digitação.
     */
    private function manualDiscountError(Company $company): ?string
    {
        if ($this->manualDiscountAmount <= 0) {
            return null;
        }

        if (! $company->pdv_manual_discount_enabled) {
            return 'Desconto manual não está habilitado para esta empresa.';
        }

        if ($this->manualDiscountAmount > $this->cartTotal + 0.001) {
            return 'Desconto não pode exceder o total restante.';
        }

        return null;
    }

    public function removeManualDiscount(): void
    {
        $this->manualDiscountAmount = 0.0;
        $this->manualDiscountInput = '';
        $this->resetValidation('manual_discount');
    }
}
