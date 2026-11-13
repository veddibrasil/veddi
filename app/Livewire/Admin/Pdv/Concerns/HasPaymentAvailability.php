<?php

namespace App\Livewire\Admin\Pdv\Concerns;

use App\Livewire\Admin\Pdv\TabTerminal;
use App\Models\Branch;

trait HasPaymentAvailability
{
    public function getAvailablePaymentMethodsProperty(): array
    {
        $company = app()->bound('current.company') ? app('current.company') : null;
        $branch = $company ? Branch::where('company_id', $company->id)->find($this->selectedBranchId) : null;
        $type = $this instanceof TabTerminal ? 'mesa' : $this->deliveryType;

        return $branch?->paymentMethodsFor('pdv', $type) ?? [];
    }

    public function getCanSplitPaymentProperty(): bool
    {
        if (($this->deliveryType === 'entrega' && $this->deliveryPaymentStatus === 'on_delivery')
            || ($this->deliveryType === 'retirar' && $this->pickupPaymentStatus === 'on_pickup')) {
            return false;
        }

        return array_diff($this->getAvailablePaymentMethodsProperty(), ['cash']) !== [];
    }

    private function syncAvailablePaymentMethod(): void
    {
        $methods = $this->getAvailablePaymentMethodsProperty();
        if (! in_array($this->paymentMethod, $methods, true)) {
            $this->paymentMethod = $methods[0] ?? '';
            $this->cashReceivedInput = '';
        }
        if (! $this->getCanSplitPaymentProperty()) {
            $this->isSplitPayment = false;
            $this->splitPayments = [];
        }
    }

    private function paymentAvailabilityError(): ?string
    {
        $allowed = $this->getAvailablePaymentMethodsProperty();
        $selected = $this->isSplitPayment ? array_column($this->splitPayments, 'method') : [$this->paymentMethod];
        if ($allowed === [] || $selected === [] || array_diff($selected, $allowed) !== []) {
            return 'Esta forma de pagamento não está disponível para esta modalidade na filial. Escolha uma das opções permitidas.';
        }

        return null;
    }

    public function updatedDeliveryPaymentStatus(): void
    {
        $this->syncAvailablePaymentMethod();
    }

    public function updatedPickupPaymentStatus(): void
    {
        $this->syncAvailablePaymentMethod();
    }
}
