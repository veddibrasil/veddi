<?php

namespace App\Livewire\Admin\Pdv\Concerns;

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
        $value = (float) str_replace(',', '.', $this->manualDiscountInput ?: '0');

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

        if (! $this->manualDiscountAllowed) {
            $this->addError('manual_discount', 'Desconto manual não está habilitado para esta empresa.');

            return;
        }

        $value = (float) str_replace(',', '.', $this->manualDiscountInput ?: '0');

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

        $this->audit('discount_applied', [
            'amount' => $this->manualDiscountAmount,
            'metadata' => [
                'type' => $this->manualDiscountType,
                'input' => $this->manualDiscountInput,
                'cart_total' => $this->cartTotal,
            ],
        ]);
    }

    public function removeManualDiscount(): void
    {
        $this->manualDiscountAmount = 0.0;
        $this->manualDiscountInput = '';
        $this->resetValidation('manual_discount');
    }
}
