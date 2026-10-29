<?php

namespace App\Livewire\Chat\Concerns;

use App\Exceptions\CouponException;
use App\Exceptions\DeliveryException;
use App\Models\Branch;
use App\Models\Coupon;
use App\Models\Order;
use App\Services\Order\CouponService;
use App\Services\Order\DeliveryService;
use App\Services\Order\GeocodingService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

trait HasOrderFlow
{
    // --- Cart review → coupon ---

    public function confirmCart(): void
    {
        if (empty($this->cart)) {
            $this->cartError = 'Adicione pelo menos um produto antes de continuar.';

            return;
        }

        $this->couponInput = '';
        $this->appliedCoupon = null;
        $this->couponDiscount = 0.0;
        $this->couponError = null;
        // Identity already resolved (restored profile or back to cart): offer the coupon again,
        // since the identification steps that normally lead to it will be skipped.
        $this->orderTypeChosen = false;
        $this->transitionTo($this->checkoutReady ? 'CHECKOUT_COUPON' : 'CHECKOUT_ORDER_TYPE');
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
                $this->useSavedProfile ? $this->verifiedCustomer()?->id : null,
                $this->cart,
                $this->cartTotal,
                $this->phone
            );

            // Produto grátis não abate valor: o brinde entra no pedido a R$ 0,00 (OrderService).
            $discount = $coupon->type === 'free_product' ? 0.0 : $couponService->calculateDiscount(
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
                'payment_method' => $coupon->payment_method,
            ];
            $this->couponDiscount = $discount;
            $this->couponError = null;

            if ($coupon->payment_method !== null) {
                $this->paymentMethod = $coupon->payment_method;
            }

            $discountLabel = match ($coupon->type) {
                'free_delivery' => 'Frete grátis aplicado!',
                'free_product' => 'Brinde incluído no pedido'.($coupon->freeProduct ? ": {$coupon->freeProduct->name}" : '').'!',
                default => 'Desconto de R$ '.number_format($discount, 2, ',', '.').' aplicado!',
            };

            $paymentNote = $coupon->payment_method !== null
                ? ' Válido somente para pagamento via '.$coupon->payment_method.'.'
                : '';

            $this->addMessage('user', "Cupom: {$coupon->code}");
            $this->addMessage('bot', "✅ {$discountLabel}{$paymentNote}".($this->orderTypeChosen ? '' : ' Escolha o tipo de entrega:'));
            $this->proceedAfterCoupon();

            Log::channel('chat')->info('Cupom aplicado', [
                'customer_id' => $this->customerId,
                'coupon_code' => $coupon->code,
                'discount' => $discount,
                'cart_total' => $this->cartTotal,
            ]);
        } catch (CouponException $e) {
            $this->couponError = $e->getMessage();

            Log::channel('chat')->info('Cupom rejeitado', [
                'customer_id' => $this->customerId,
                'coupon_code' => $code,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function skipCoupon(): void
    {
        $this->appliedCoupon = null;
        $this->couponDiscount = 0.0;
        $this->couponError = null;
        $this->couponInput = '';
        $this->proceedAfterCoupon();
    }

    private function proceedAfterCoupon(): void
    {
        if ($this->orderTypeChosen) {
            $this->continueWithOrderType();

            return;
        }
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
        abort_unless(in_array($type, ['delivery', 'pickup'], true), 422);
        $this->orderType = $type;
        $this->orderTypeChosen = true;
        $this->addMessage('user', $type === 'pickup' ? 'Retirada no local' : 'Entrega');
        $this->continueWithOrderType();
    }

    /** Segue para a próxima etapa do tipo já escolhido, sem repetir a escolha no histórico. */
    private function continueWithOrderType(): void
    {
        if (! $this->checkoutReady) {
            $this->addMessage('bot', 'Qual é o seu telefone com DDD?');
            $this->transitionTo('IDENTIFY_PHONE');

            return;
        }

        if ($this->orderType === 'pickup') {
            $this->deliveryFee = 0.0;
            $this->freeDelivery = false;

            $company = $this->currentCompany();
            if ($company?->schedulingEnabled()) {
                $this->transitionTo('CHECKOUT_SCHEDULE');

                return;
            }

            $this->addMessage('bot', 'Alguma observação sobre o pedido?');
            $this->transitionTo('CHECKOUT_NOTES');

            return;
        }

        $this->transitionTo('CHECKOUT_DELIVERY_ADDRESS');
    }

    // --- Delivery ---

    public function confirmDeliveryAddress(): void
    {
        $this->validate($this->rulesFor('CHECKOUT_DELIVERY_ADDRESS'), $this->messages());

        $geocodedNow = false;
        if ($this->customer_latitude === '' || $this->customer_longitude === '') {
            $coords = app(GeocodingService::class)->geocode([
                'address' => $this->address,
                'number' => $this->number,
                'neighborhood' => $this->neighborhood,
                'city' => $this->city,
                'cep' => preg_replace('/\D/', '', $this->cep),
            ]);

            if ($coords !== null) {
                $this->customer_latitude = (string) $coords['latitude'];
                $this->customer_longitude = (string) $coords['longitude'];
                $geocodedNow = true;
            } else {
                Log::channel('chat')->info('Geocoding não encontrou coordenadas', [
                    'customer_id' => $this->customerId,
                    'cep' => preg_replace('/\D/', '', $this->cep),
                    'city' => $this->city,
                ]);
            }
        }

        // O pin só é pedido quando o frete depende da distância/área; na taxa fixa as coordenadas
        // encontradas ficam gravadas, mas o cliente segue direto.
        $settings = Branch::find($this->selectedBranchId)?->deliverySetting;
        if ($geocodedNow && $settings?->active && $this->deliveryNeedsCoordinates($settings)) {
            $this->dispatch('chat-geocoded',
                lat: (float) $this->customer_latitude,
                lng: (float) $this->customer_longitude,
            );
            $this->addMessage('bot', 'Encontramos seu endereço no mapa 📍 Confira se o pino está no lugar certo (arraste se precisar) e toque em "Confirmar local".');

            return;
        }

        $this->addMessage('user', $this->deliveryAddressSummary());

        $this->resolveDeliveryFee();
    }

    private function deliveryAddressSummary(): string
    {
        $summary = trim("{$this->address}, {$this->number}", ', ');
        if ($this->complement) {
            $summary .= ", {$this->complement}";
        }

        return "{$summary} — {$this->neighborhood}, {$this->city}";
    }

    private function resolveDeliveryFee(): void
    {
        $branch = Branch::find($this->selectedBranchId);
        $settings = $branch?->deliverySetting;

        if (! $settings || ! $settings->active) {
            $this->deliveryFee = 0.0;
            $this->freeDelivery = false;

            $company = $this->currentCompany();
            if ($company?->schedulingEnabled()) {
                $this->transitionTo('CHECKOUT_SCHEDULE');

                return;
            }

            $this->addMessage('bot', 'Alguma observação sobre o pedido?');
            $this->transitionTo('CHECKOUT_NOTES');

            return;
        }

        try {
            if (
                $this->deliveryNeedsCoordinates($settings)
                && ($this->customer_latitude === '' || $this->customer_longitude === '')
            ) {
                $this->addMessage('bot', 'Para calcular o frete, precisamos saber onde fica seu endereço. Toque em "Marcar no mapa", abaixo dos campos, e posicione o pino na sua casa.');

                return;
            }

            $result = app(DeliveryService::class)->validate(
                $settings,
                $this->neighborhood,
                $this->cartTotal,
                $this->customer_latitude !== '' ? (float) $this->customer_latitude : null,
                $this->customer_longitude !== '' ? (float) $this->customer_longitude : null
            );

            $this->deliveryFee = $result['fee'];
            $this->freeDelivery = $result['free'];

            $this->transitionTo('CHECKOUT_DELIVERY_FEE');

            Log::channel('chat')->info('Frete calculado', [
                'customer_id' => $this->customerId,
                'branch_id' => $this->selectedBranchId,
                'neighborhood' => $this->neighborhood,
                'fee' => $result['fee'],
                'free' => $result['free'],
            ]);
        } catch (DeliveryException $e) {
            $this->addMessage('bot', $e->getMessage());

            Log::channel('chat')->info('Frete rejeitado', [
                'customer_id' => $this->customerId,
                'branch_id' => $this->selectedBranchId,
                'neighborhood' => $this->neighborhood,
                'cart_total' => $this->cartTotal,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function deliveryNeedsCoordinates(\App\Models\DeliverySetting $settings): bool
    {
        if ($settings->branch_latitude === null || $settings->branch_longitude === null) {
            return false;
        }

        $hasRadiusGate = $settings->service_radius_km !== null && $settings->service_radius_km > 0;
        $isDistanceBased = $settings->fee_type === 'distance';

        return $hasRadiusGate || $isDistanceBased;
    }

    public function confirmDeliveryFee(): void
    {
        $company = $this->currentCompany();
        if ($company?->schedulingEnabled()) {
            $this->transitionTo('CHECKOUT_SCHEDULE');

            return;
        }

        $this->addMessage('bot', 'Alguma observação sobre o pedido?');
        $this->transitionTo('CHECKOUT_NOTES');
    }

    // --- Notes & CPF ---

    public function proceedFromNotes(): void
    {
        $this->validate($this->rulesFor('CHECKOUT_NOTES'), $this->messages());
        $this->addMessage('user', $this->notes ?: '(sem observações)');
        $this->addMessage('bot', 'Escolha a forma de pagamento:');
        $this->transitionTo('CHECKOUT_PAYMENT_METHOD');
    }

    public function submitCpf(): void
    {
        $this->validate($this->rulesFor('CHECKOUT_CPF'), $this->messages());
        $this->addMessage('user', $this->taxId);
        $this->transitionTo('CHECKOUT_CONFIRM');
    }

    // --- Order history ---

    public function goToOrderHistory(): void
    {
        if (! $this->verifiedCustomer()) {
            $this->orderHistory = [];

            return;
        }

        $this->orderHistoryPreviousStep = $this->step;

        $this->orderHistory = Order::withoutGlobalScopes()
            ->where('customer_id', $this->verifiedCustomer()->id)
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

    public function backToDeliveryFee(): void
    {
        $this->transitionTo('CHECKOUT_DELIVERY_FEE');
    }

    public function backToNotes(): void
    {
        $this->transitionTo('CHECKOUT_NOTES');
    }

    public function backToSchedule(): void
    {
        $this->transitionTo('CHECKOUT_SCHEDULE');
    }

    public function backFromSchedule(): void
    {
        if ($this->orderType === 'pickup') {
            $this->transitionTo('CHECKOUT_ORDER_TYPE');

            return;
        }

        if ($this->deliveryFee > 0 || $this->freeDelivery) {
            $this->transitionTo('CHECKOUT_DELIVERY_FEE');

            return;
        }

        $this->transitionTo('CHECKOUT_DELIVERY_ADDRESS');
    }

    // --- Scheduling ---

    public function selectScheduleOption(string $option): void
    {
        if ($option === 'now') {
            $this->scheduledAt = null;
            $this->scheduleInput = '';
            $this->addMessage('user', 'Agora, o mais rápido possível');
            $this->addMessage('bot', 'Alguma observação sobre o pedido?');
            $this->transitionTo('CHECKOUT_NOTES');
        }
        // 'schedule' → blade toggles the datetime input via Alpine
    }

    public function submitScheduleTime(): void
    {
        $this->resetErrorBag('scheduledAt');

        $company = $this->currentCompany();
        $minMinutes = $company?->schedule_min_advance_minutes ?? 60;

        if (empty($this->scheduleDate) || empty($this->scheduleTime)) {
            $this->addError('scheduledAt', 'Informe a data e horário desejados.');

            return;
        }

        try {
            $scheduled = Carbon::createFromFormat('Y-m-d H:i', $this->scheduleDate.' '.$this->scheduleTime, config('app.timezone'));
        } catch (\Exception) {
            $this->addError('scheduledAt', 'Data/hora inválida.');

            Log::channel('chat')->info('Agendamento rejeitado: formato inválido', [
                'customer_id' => $this->customerId,
                'branch_id' => $this->selectedBranchId,
                'scheduleDate' => $this->scheduleDate,
                'scheduleTime' => $this->scheduleTime,
            ]);

            return;
        }

        if ($scheduled->isPast()) {
            $this->addError('scheduledAt', 'O horário deve ser no futuro.');

            Log::channel('chat')->info('Agendamento rejeitado: horário no passado', [
                'customer_id' => $this->customerId,
                'branch_id' => $this->selectedBranchId,
                'attempted_at' => $scheduled->toIso8601String(),
            ]);

            return;
        }

        $minTime = now(config('app.timezone'))->addMinutes($minMinutes);
        if ($scheduled->lt($minTime)) {
            $this->addError('scheduledAt', "Agende com pelo menos {$minMinutes} minutos de antecedência.");

            Log::channel('chat')->info('Agendamento rejeitado: antecedência mínima não atingida', [
                'customer_id' => $this->customerId,
                'branch_id' => $this->selectedBranchId,
                'attempted_at' => $scheduled->toIso8601String(),
                'min_minutes' => $minMinutes,
            ]);

            return;
        }

        $branch = Branch::find($this->selectedBranchId);
        if ($branch) {
            $dayOfWeek = (int) $scheduled->format('w');
            $timeStr = $scheduled->format('H:i');

            $availableDays = $branch->available_days;
            if ($availableDays !== null && ! in_array($dayOfWeek, $availableDays)) {
                $this->addError('scheduledAt', 'A filial não atende no dia selecionado.');

                Log::channel('chat')->info('Agendamento rejeitado: filial fechada no dia', [
                    'customer_id' => $this->customerId,
                    'branch_id' => $this->selectedBranchId,
                    'attempted_at' => $scheduled->toIso8601String(),
                    'day_of_week' => $dayOfWeek,
                ]);

                return;
            }

            if (! $branch->isWithinSchedulingSlot($dayOfWeek, $timeStr)) {
                $this->addError('scheduledAt', 'Não há agendamento disponível nesse horário.');

                Log::channel('chat')->info('Agendamento rejeitado: fora dos horários de agendamento', [
                    'customer_id' => $this->customerId,
                    'branch_id' => $this->selectedBranchId,
                    'attempted_time' => $timeStr,
                ]);

                return;
            }

            if ($pause = $branch->activePauseAt($scheduled)) {
                $reasonText = $pause->reason ? " Motivo: {$pause->reason}." : '';
                $this->addError('scheduledAt', "A filial estará fechada nesse período.{$reasonText}");

                Log::channel('chat')->info('Agendamento rejeitado: filial em pausa', [
                    'customer_id' => $this->customerId,
                    'branch_id' => $this->selectedBranchId,
                    'attempted_at' => $scheduled->toIso8601String(),
                    'pause_id' => $pause->id,
                ]);

                return;
            }
        }

        Log::channel('chat')->info('Agendamento confirmado', [
            'customer_id' => $this->customerId,
            'branch_id' => $this->selectedBranchId,
            'scheduled_at' => $scheduled->toIso8601String(),
        ]);

        $this->scheduledAt = $scheduled->toIso8601String();
        $scheduledLabel = $scheduled->format('d/m/Y \à\s H:i');
        $this->addMessage('user', "Agendado para {$scheduledLabel}");
        $this->addMessage('bot', 'Alguma observação sobre o pedido?');
        $this->transitionTo('CHECKOUT_NOTES');
    }
}
