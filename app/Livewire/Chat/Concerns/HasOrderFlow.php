<?php

namespace App\Livewire\Chat\Concerns;

use App\Exceptions\CouponException;
use App\Exceptions\DeliveryException;
use App\Models\Branch;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Services\Order\CouponService;
use App\Services\Order\DeliveryService;

trait HasOrderFlow
{
    // --- Step: Main menu ---

    public function submitMainMenu(string $option): void
    {
        if ($option === '1') {
            $this->addMessage('user', '1 - Fazer pedido');
            $this->addMessage('bot', 'Ótimo! Escolha uma filial para continuar.');
            $this->transitionTo('BRANCH_SELECT');

            return;
        }

        if ($option === '2') {
            $this->addMessage('user', '2 - Falar com o suporte');
            $this->openSupportTicket();
        }
    }

    // --- Cart review → coupon ---

    public function confirmCart(): void
    {
        if (! $this->customerId) {
            $this->addMessage('bot', 'Ótimo pedido! Para continuar, preciso do seu número de telefone com DDD.');
            $this->transitionTo('IDENTIFY_PHONE');

            return;
        }

        $this->couponInput = '';
        $this->appliedCoupon = null;
        $this->couponDiscount = 0.0;
        $this->couponError = null;
        $this->transitionTo('CHECKOUT_COUPON');
    }

    // --- Coupon ---

    public function applyCoupon(): void
    {
        $this->couponError = null;
        $code = strtoupper(trim($this->couponInput));

        if ($code === '') {
            $this->couponError = 'Informe um código de cupom.';

            return;
        }

        try {
            $couponService = app(CouponService::class);

            $coupon = $couponService->validate(
                $code,
                $this->customerId,
                $this->cart,
                $this->cartTotal
            );

            $discount = $couponService->calculateDiscount(
                $coupon,
                $this->cart,
                $this->cartTotal,
                $this->deliveryFee
            );

            $this->appliedCoupon = [
                'id' => $coupon->id,
                'code' => $coupon->code,
                'type' => $coupon->type,
                'discount' => $discount,
                'label' => $coupon->name,
            ];
            $this->couponDiscount = $discount;
            $this->couponError = null;

            $discountLabel = $coupon->type === 'free_delivery'
                ? 'Frete grátis aplicado!'
                : 'Desconto de R$ '.number_format($discount, 2, ',', '.').' aplicado!';

            $this->addMessage('user', "Cupom: {$coupon->code}");
            $this->addMessage('bot', "✅ {$discountLabel} Escolha o tipo de entrega:");
            $this->transitionTo('CHECKOUT_ORDER_TYPE');
        } catch (CouponException $e) {
            $this->couponError = $e->getMessage();
        }
    }

    public function skipCoupon(): void
    {
        $this->appliedCoupon = null;
        $this->couponDiscount = 0.0;
        $this->couponError = null;
        $this->couponInput = '';
        $this->transitionTo('CHECKOUT_ORDER_TYPE');
    }

    public function removeCoupon(): void
    {
        $this->appliedCoupon = null;
        $this->couponDiscount = 0.0;
        $this->couponError = null;
        $this->couponInput = '';
    }

    // --- Order type ---

    public function selectOrderType(string $type): void
    {
        $this->orderType = $type;
        $label = $type === 'pickup' ? 'Retirada no local' : 'Entrega';
        $this->addMessage('user', $label);

        if ($type === 'pickup') {
            $this->deliveryFee = 0.0;
            $this->freeDelivery = false;
            $this->addMessage('bot', 'Alguma observação sobre o pedido?');
            $this->transitionTo('CHECKOUT_NOTES');

            return;
        }

        $this->transitionTo('CHECKOUT_DELIVERY_ADDRESS');
    }

    // --- Delivery ---

    public function confirmDeliveryAddress(): void
    {
        $this->validate($this->rules(), $this->messages());

        if ($this->customerId) {
            $customer = Customer::findOrFail($this->customerId);
            $customer->update([
                'address' => $this->address,
                'complement' => $this->complement,
                'neighborhood' => $this->neighborhood,
                'number' => $this->number,
                'city' => $this->city,
                'cep' => preg_replace('/\D/', '', $this->cep),
            ]);
        }

        $addressSummary = $this->address;
        if ($this->complement) {
            $addressSummary .= ", {$this->complement}";
        }
        $addressSummary .= " — {$this->neighborhood}, {$this->city}";
        $this->addMessage('user', $addressSummary);

        $this->resolveDeliveryFee();
    }

    private function resolveDeliveryFee(): void
    {
        $branch = Branch::find($this->selectedBranchId);
        $settings = $branch?->deliverySetting;

        if (! $settings || ! $settings->active) {
            $this->deliveryFee = 0.0;
            $this->freeDelivery = false;
            $this->addMessage('bot', 'Alguma observação sobre o pedido?');
            $this->transitionTo('CHECKOUT_NOTES');

            return;
        }

        try {
            $result = app(DeliveryService::class)->validate(
                $settings,
                $this->neighborhood,
                $this->cartTotal
            );

            $this->deliveryFee = $result['fee'];
            $this->freeDelivery = $result['free'];

            $this->transitionTo('CHECKOUT_DELIVERY_FEE');
        } catch (DeliveryException $e) {
            $this->addMessage('bot', $e->getMessage());
        }
    }

    public function confirmDeliveryFee(): void
    {
        $this->addMessage('bot', 'Alguma observação sobre o pedido?');
        $this->transitionTo('CHECKOUT_NOTES');
    }

    // --- Notes & CPF ---

    public function proceedFromNotes(): void
    {
        $this->validate($this->rules(), $this->messages());
        $this->addMessage('user', $this->notes ?: '(sem observações)');

        if ($this->taxId !== '') {
            $this->addMessage('bot', 'Escolha a forma de pagamento:');
            $this->transitionTo('CHECKOUT_PAYMENT_METHOD');

            return;
        }

        $this->addMessage('bot', 'Para gerar o pagamento precisamos do seu CPF. Por favor, informe.');
        $this->transitionTo('CHECKOUT_CPF');
    }

    public function submitCpf(): void
    {
        $this->validate($this->rules(), $this->messages());
        $this->addMessage('user', $this->taxId);
        $this->addMessage('bot', 'Escolha a forma de pagamento:');
        $this->transitionTo('CHECKOUT_PAYMENT_METHOD');
    }

    // --- Order history ---

    public function goToOrderHistory(): void
    {
        if (! $this->customerId) {
            return;
        }

        $this->orderHistoryPreviousStep = $this->step;

        $this->orderHistory = Order::withoutGlobalScopes()
            ->where('customer_id', $this->customerId)
            ->where('company_id', $this->companyId)
            ->with('items')
            ->latest()
            ->take(15)
            ->get()
            ->map(fn (Order $order) => [
                'order_number' => $order->order_number,
                'total' => (float) $order->total,
                'status' => $order->status,
                'status_label' => $order->status_label,
                'payment_method' => strtoupper($order->payment_method),
                'order_type' => $order->order_type,
                'items_count' => $order->items->count(),
                'created_at' => $order->created_at->format('d/m/Y H:i'),
            ])
            ->toArray();

        $this->transitionTo('ORDER_HISTORY');
    }

    public function backFromOrderHistory(): void
    {
        $this->orderHistory = [];
        $this->transitionTo($this->orderHistoryPreviousStep ?? 'MENU_BROWSE');
        $this->orderHistoryPreviousStep = null;
    }

    // --- New order / retry ---

    public function startNewOrder(): void
    {
        $this->resetState();
        $this->initialize();
    }

    public function startNewOrderKeepHistory(): void
    {
        $savedMessages = $this->messages;
        $this->resetState();
        $this->messages = $savedMessages;
        $this->initialize();
    }

    // --- Back navigation ---

    public function backToMenu(): void
    {
        $this->transitionTo('MENU_BROWSE');
    }

    public function backToIdentifyPhone(): void
    {
        $this->transitionTo('IDENTIFY_PHONE');
    }

    public function backToRegisterName(): void
    {
        $this->transitionTo('REGISTER_NAME');
    }

    public function backToRegisterEmail(): void
    {
        $this->transitionTo('REGISTER_EMAIL');
    }

    public function backToCartReview(): void
    {
        $this->transitionTo('CART_REVIEW');
    }

    public function backToOrderType(): void
    {
        $this->transitionTo('CHECKOUT_ORDER_TYPE');
    }

    public function backToDeliveryAddress(): void
    {
        $this->transitionTo('CHECKOUT_DELIVERY_ADDRESS');
    }

    public function backToNotes(): void
    {
        $this->transitionTo('CHECKOUT_NOTES');
    }

    public function backToCpf(): void
    {
        $this->transitionTo('CHECKOUT_CPF');
    }
}
