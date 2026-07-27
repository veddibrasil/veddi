<?php

namespace App\Livewire\Admin\Pdv\Concerns;

use App\Models\BranchServiceCharge;
use Livewire\Attributes\Computed;

/**
 * Totais e taxas exibidos no wizard de pagamento — compartilhado entre Terminal (venda direta,
 * onde o total nasce do $cart pendente) e TabTerminal (mesa/comanda, onde $cart fica sempre vazio
 * e o total vem da order já existente via closingTabOrder, de HasOpenTabs).
 */
trait HasOrderTotals
{
    #[Computed]
    public function cartTotal(): float
    {
        if ($this->closingTabOrderId) {
            return (float) ($this->closingTabOrder?->subtotal ?? 0.0);
        }

        return round(array_sum(array_map(function ($item) {
            $optionsExtra = 0.0;
            foreach ($item['options'] ?? [] as $group) {
                foreach ($group['selections'] ?? [] as $sel) {
                    $optionsExtra += ($sel['qty'] ?? 0) * ($sel['additional_price'] ?? 0);
                }
            }

            return $item['qty'] * ((float) $item['price'] + $optionsExtra);
        }, $this->cart)), 2);
    }

    #[Computed]
    public function cartTotalAfterDiscount(): float
    {
        if ($this->closingTabOrderId) {
            return max(0.0, round($this->cartTotal + $this->serviceFeeAmount + $this->couvertFeeAmount - $this->manualDiscountAmount, 2));
        }

        return max(0.0, round($this->cartTotal + $this->deliveryFeeAmount + $this->serviceFeeAmount + $this->couvertFeeAmount - $this->manualDiscountAmount, 2));
    }

    #[Computed]
    public function branchServiceCharge(): ?BranchServiceCharge
    {
        if (! $this->selectedBranchId) {
            return null;
        }

        return BranchServiceCharge::where('branch_id', $this->selectedBranchId)->first();
    }

    #[Computed]
    public function rawServiceFeeAmount(): float
    {
        if ($this->closingTabOrderId) {
            return (float) ($this->closingTabOrder?->service_fee ?? 0.0);
        }

        if ($this->deliveryType === 'entrega') {
            return 0.0;
        }

        return $this->branchServiceCharge?->calculateServiceFee($this->cartTotal) ?? 0.0;
    }

    #[Computed]
    public function rawCouvertFeeAmount(): float
    {
        if ($this->closingTabOrderId) {
            return (float) ($this->closingTabOrder?->couvert_fee ?? 0.0);
        }

        if ($this->deliveryType === 'entrega') {
            return 0.0;
        }

        return $this->branchServiceCharge?->calculateCouvert($this->cartTotal) ?? 0.0;
    }

    #[Computed]
    public function serviceFeeAmount(): float
    {
        return $this->serviceFeeWaived ? 0.0 : $this->rawServiceFeeAmount;
    }

    #[Computed]
    public function couvertFeeAmount(): float
    {
        return $this->couvertFeeWaived ? 0.0 : $this->rawCouvertFeeAmount;
    }
}
