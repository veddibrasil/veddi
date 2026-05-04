<?php

namespace App\Livewire\Chat\Concerns;

use App\Models\Order;
use Illuminate\Support\Facades\Log;

trait HasOrderRecovery
{
    protected function findRecoverableOrder(int $customerId, int $companyId): ?Order
    {
        return Order::withoutGlobalScopes()
            ->where('customer_id', $customerId)
            ->where('company_id', $companyId)
            ->whereIn('status', ['pending', 'awaiting_payment'])
            ->where('payment_method', '!=', 'CASH')
            ->latest()
            ->first();
    }

    public function resumePendingOrder(): void
    {
        $orderId = $this->pendingOrderSummary['id'] ?? null;

        $order = Order::withoutGlobalScopes()
            ->with(['items', 'payment', 'coupon'])
            ->find($orderId);

        if (! $order || ! in_array($order->status, ['pending', 'awaiting_payment'])) {
            $this->pendingOrderSummary = null;
            $this->addMessage('bot', 'Pedido anterior não encontrado ou já finalizado. Vamos fazer um novo!');
            $this->transitionTo('MENU_BROWSE');

            return;
        }

        $this->orderId = $order->id;
        $this->selectedBranchId = $order->branch_id;
        $this->paymentMethod = strtoupper($order->payment_method);
        $this->orderType = $order->order_type;
        $this->deliveryFee = (float) $order->delivery_fee;
        $this->notes = $order->notes ?? '';

        // Rebuild cart from order items for display (order already placed — not recalculating)
        $this->cart = [];
        foreach ($order->items as $item) {
            $this->cart[$item->product_id] = [
                'qty' => $item->quantity,
                'name' => $item->product_name,
                'price' => (float) $item->unit_price,
                'options' => [],
            ];
        }

        if ($order->coupon) {
            $coupon = $order->coupon;
            $this->appliedCoupon = [
                'id' => $coupon->id,
                'code' => $coupon->code,
                'type' => $coupon->type,
                'discount' => (float) $order->discount,
                'label' => $coupon->name,
            ];
            $this->couponDiscount = (float) $order->discount;
        }

        $this->pendingOrderSummary = null;

        Log::channel('chat')->info('Sessão recuperada do banco', [
            'order_id' => $order->id,
            'customer_id' => $this->customerId,
            'status' => $order->status,
            'payment_method' => $order->payment_method,
        ]);

        $this->addMessage('bot', "Pedido #{$order->order_number} recuperado! Continuando o pagamento...");

        $payment = $order->payment;

        if ($this->paymentMethod === 'CARD') {
            $this->transitionTo('PAYMENT_CARD_FORM');

            return;
        }

        // PIX: restore QR data if payment record exists
        if ($payment) {
            $this->pixQrCode = $payment->pix_qr_code;
            $this->pixCopyPaste = $payment->pix_copy_paste;
            $this->paymentId = $payment->asaas_payment_id;
            $this->expiresAt = $payment->expires_at?->toIso8601String();
        }

        $this->transitionTo('PAYMENT_PIX');
    }

    public function discardPendingOrder(): void
    {
        $this->pendingOrderSummary = null;
        $this->addMessage('bot', 'Sem problemas! Vamos começar um novo pedido.');
        $this->transitionTo('MENU_BROWSE');
    }
}
