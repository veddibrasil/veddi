<?php

namespace App\Livewire\Admin\Pdv\Concerns;

/**
 * Reset do formulário de pagamento — usado tanto ao entrar no checkout de venda direta quanto ao
 * fechar comanda. Não mexe em campos de entrega: só o Terminal (venda direta) tem HasDeliveryFee,
 * então ele reseta `deliveryType`/endereço separadamente via resetDeliveryState() nos seus próprios
 * pontos de chamada.
 */
trait HasPaymentState
{
    private function resetPaymentState(): void
    {
        $this->paymentMethod = 'cash';
        $this->cashReceivedInput = '';
        $this->manualDiscountAmount = 0.0;
        $this->manualDiscountInput = '';
        $this->manualDiscountType = 'fixed';
        $this->serviceFeeWaived = false;
        $this->couvertFeeWaived = false;
        $this->printFiscalNote = false;
    }
}
