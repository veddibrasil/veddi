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
        if (property_exists($this, 'closingTableId') && $this->closingTableId) {
            return (float) $this->closingTableOrders->sum('subtotal');
        }

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
        // Fechamento em grupo (todas as comandas de uma mesa juntas): mesma fórmula do
        // fechamento individual, só que somando subtotal/taxas de todas as comandas —
        // os toggles de isenção de taxa (serviceFeeWaived/couvertFeeWaived) se aplicam
        // a todas de uma vez (ver HasOpenTabs::closeTableTabs()). Desconto manual não
        // entra aqui — isso continua sendo feito comanda por comanda, antes de agrupar.
        if (property_exists($this, 'closingTableId') && $this->closingTableId) {
            return max(0.0, round($this->cartTotal + $this->serviceFeeAmount + $this->couvertFeeAmount, 2));
        }

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
        // Taxa única por mesa: calcula uma vez sobre o subtotal combinado de todas as comandas,
        // não soma a taxa que cada comanda já carrega individualmente (senão taxa fixa, tipo
        // couvert, dobraria/triplicaria conforme o número de comandas abertas na mesa).
        if (property_exists($this, 'closingTableId') && $this->closingTableId) {
            return $this->branchServiceCharge?->calculateServiceFee($this->cartTotal) ?? 0.0;
        }

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
        // Idem rawServiceFeeAmount(): couvert único por mesa, não soma por comanda.
        if (property_exists($this, 'closingTableId') && $this->closingTableId) {
            return $this->branchServiceCharge?->calculateCouvert($this->cartTotal) ?? 0.0;
        }

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
