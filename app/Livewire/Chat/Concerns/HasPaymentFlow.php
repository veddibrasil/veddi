<?php

namespace App\Livewire\Chat\Concerns;

use App\Contracts\OrderServiceInterface;
use App\Events\OrderStatusUpdated;
use App\Models\Branch;
use App\Models\CompanyNotification;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Services\Order\CartOptionPricing;
use App\Services\Order\DeliveryService;
use App\Services\Order\OrderCancellationPolicy;
use App\Services\Order\OrderService;
use App\Services\Payment\PaymentCalculatorService;
use App\Services\Payment\PaymentOrchestrator;
use App\Services\Payment\PaymentService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

trait HasPaymentFlow
{
    public function selectPaymentMethod(string $method): void
    {
        abort_unless(in_array($method, ['PIX', 'CARD', 'CASH'], true), 422);
        $restrictedMethod = $this->appliedCoupon['payment_method'] ?? null;
        if ($restrictedMethod !== null && $method !== $restrictedMethod) {
            return;
        }

        $this->paymentMethod = $method;

        if ($method === 'CARD') {
            $this->recalculateCardFee();
        }

        $label = match ($method) {
            'CASH' => 'Dinheiro',
            'CARD' => 'Cartão de Crédito',
            default => 'PIX',
        };
        $this->addMessage('user', $label);

        if ($this->taxId === '' && in_array($method, ['PIX', 'CARD'])) {
            $this->addMessage('bot', 'Para gerar o pagamento precisamos do seu CPF. Por favor, informe.');
            $this->transitionTo('CHECKOUT_CPF');

            return;
        }

        $this->transitionTo('CHECKOUT_CONFIRM');
    }

    public function confirmOrder(): void
    {
        if (! $this->checkoutKey) {
            $this->checkoutKey = \Illuminate\Support\Str::random(64);
        }
        \Illuminate\Support\Facades\Cache::lock('chat-checkout:'.$this->checkoutKey, 30)->block(5, function () {
            $existing = Order::withoutGlobalScopes()->where('company_id', $this->companyId)->where('checkout_key', $this->checkoutKey)->first();
            if ($existing) {
                if (app(\App\Services\Chat\ChatAccess::class)->canAccessOrder($existing)) {
                    $this->orderId = $existing->id;
                    $this->transitionTo(match (true) {
                        in_array($existing->status, ['cancelled', 'refunded']) => 'ORDER_FAILED',
                        in_array($existing->status, ['pending', 'awaiting_payment']) => strtoupper($existing->payment_method) === 'CARD' ? 'PAYMENT_CARD_FORM' : 'PAYMENT_PIX',
                        default => 'ORDER_CONFIRMED',
                    });
                }

                return;
            }
            $this->placeConfirmedOrder();
        });
    }

    private function placeConfirmedOrder(): void
    {
        if ($this->paymentMethod === 'CASH') {
            $status = $this->scheduledAt ? 'scheduled' : 'paid';
        } else {
            $status = 'pending';
        }
        $this->placeOrder($status);
    }

    public function backToPaymentMethod(): void
    {
        $this->paymentMethod = '';
        $this->cardFeeBreakdown = [];
        $this->transitionTo('CHECKOUT_PAYMENT_METHOD');
    }

    private function recalculateCardFee(): void
    {
        $company = $this->currentCompany();

        if ($company?->card_fee_absorbed_by_company) {
            $this->cardFeeBreakdown = [];

            return;
        }

        $total = (float) $this->getOrderTotalProperty();

        if ($total <= 0) {
            $this->cardFeeBreakdown = [];

            return;
        }

        $cardRate = (float) config('payments.credit_card.default_rate', 0.028);
        $platformFeeRate = $company->feePercentageForOrder();

        $this->cardFeeBreakdown = app(PaymentCalculatorService::class)->calculate(
            $total,
            $cardRate,
            $platformFeeRate,
        );
    }

    /**
     * `$deliveryFee` é propriedade pública Livewire — recalculada durante o fluxo
     * (resolveDeliveryFee), mas nada impede o client de sobrescrevê-la depois disso
     * e antes de confirmar. Nunca confiar nela sem recalcular aqui, na hora H.
     */
    private function reauthorizeDeliveryFee(): void
    {
        if ($this->orderType !== 'delivery') {
            $this->deliveryFee = 0.0;

            return;
        }

        $branch = Branch::find($this->selectedBranchId);
        $settings = $branch?->deliverySetting;

        if (! $settings || ! $settings->active) {
            $this->deliveryFee = 0.0;

            return;
        }

        // Pedido mínimo e "frete grátis acima de R$ X" decididos com o subtotal do banco: o
        // cartTotal soma `cart.*.price`, que vem do navegador e serve só para exibir.
        $result = app(DeliveryService::class)->validate(
            $settings,
            $this->neighborhood,
            $this->serverCartSubtotal(),
            $this->customer_latitude !== '' ? (float) $this->customer_latitude : null,
            $this->customer_longitude !== '' ? (float) $this->customer_longitude : null
        );

        $this->deliveryFee = $result['fee'];
    }

    /**
     * Subtotal do carrinho com preço e adicionais vindos do banco — mesmo cálculo de
     * OrderService::createOrder (effective_price + CartOptionPricing). Produto que não é da
     * empresa fica fora da conta (o createOrder recusa o pedido de qualquer forma).
     */
    private function serverCartSubtotal(): float
    {
        $productId = fn ($item, $cartKey): int => (int) ((is_array($item) ? ($item['product_id'] ?? null) : null) ?? explode('_', (string) $cartKey)[0]);

        $products = Product::withoutGlobalScopes()
            ->where('company_id', $this->companyId)
            ->whereIn('id', collect($this->cart)->map($productId)->unique()->values())
            ->with('optionGroups')
            ->get()
            ->keyBy('id');

        $optionPricing = app(CartOptionPricing::class);
        $subtotal = 0.0;

        foreach ($this->cart as $cartKey => $item) {
            $product = $products->get($productId($item, $cartKey));

            if (! $product) {
                continue;
            }

            $extra = (float) $optionPricing->resolve($product, is_array($item) ? $item : [])['extra'];
            $subtotal += ((float) $product->effective_price + $extra) * max(0, (int) ($item['qty'] ?? 0));
        }

        return $subtotal;
    }

    /**
     * Empresa e filial conferidas de novo na hora do pedido: o estado do chat sobrevive na sessão
     * (carrinho montado com a loja aberta e confirmado depois do fechamento, empresa bloqueada no
     * meio da conversa) e horário/pausa só eram checados em selectBranch(). Recusa com mensagem
     * ao cliente, sem o aviso de "falha ao criar pedido" para a loja.
     */
    private function orderPlacementAllowed(?Carbon $scheduledAt): bool
    {
        if (! $this->companyAcceptsOrders(\App\Models\Company::find($this->companyId))) {
            Log::channel('chat')->warning('placeOrder recusado: empresa sem permissão de venda', [
                'company_id' => $this->companyId,
                'customer_id' => $this->customerId,
            ]);

            $this->addMessage('bot', 'No momento esta loja não está aceitando pedidos.');
            $this->transitionTo('UNAVAILABLE');

            return false;
        }

        $branch = Branch::withoutGlobalScopes()
            ->where('id', $this->selectedBranchId)
            ->where('company_id', $this->companyId)
            ->where('active', true)
            ->first();

        $refusal = match (true) {
            $branch === null => 'A filial escolhida não está disponível no momento.',
            // Agendado: o horário escolhido já foi validado contra a grade da filial.
            $scheduledAt !== null => null,
            $branch->isPaused() => "A filial {$branch->name} está temporariamente fechada. Tente mais tarde.",
            ! $this->branchOpenNow($branch) => "A filial {$branch->name} está fechada no momento. Tente mais tarde.",
            default => null,
        };

        if ($refusal === null) {
            return true;
        }

        Log::channel('chat')->info('placeOrder recusado: filial indisponível', [
            'customer_id' => $this->customerId,
            'branch_id' => $this->selectedBranchId,
        ]);

        $this->addMessage('bot', $refusal);

        return false;
    }

    /**
     * Branch::isOpen() não cobre a janela que vira a noite (ex.: 18:00–02:00); o initialize()
     * do chat cobre, então a mesma tolerância vale aqui para não travar loja noturna.
     */
    private function branchOpenNow(Branch $branch): bool
    {
        if ($branch->isOpen()) {
            return true;
        }

        $now = now(config('app.timezone'));
        $day = (int) $now->format('w');

        if ($branch->available_days !== null && ! in_array($day, $branch->available_days)) {
            return false;
        }

        $hours = $branch->hoursForDay($day);
        $time = $now->format('H:i:s');

        return $hours['opens_at'] && $hours['closes_at']
            && $hours['opens_at'] > $hours['closes_at']
            && ($time >= $hours['opens_at'] || $time <= $hours['closes_at']);
    }

    private function placeOrder(string $initialStatus): void
    {
        if ($this->submitting || $this->orderId || ! $this->checkoutReady) {
            return;
        }
        $this->validate([
            'phone' => ['required', 'regex:/^\d{10,11}$/'],
            'name' => ['required', 'string', 'min:3', 'max:100'],
            'email' => ['nullable', 'email', 'max:200'],
            'paymentMethod' => ['required', 'in:PIX,CARD,CASH'],
            'orderType' => ['required', 'in:delivery,pickup'],
            'cart' => ['required', 'array', 'min:1'],
            'notes' => ['nullable', 'string', 'max:500'],
            'complement' => ['nullable', 'string', 'max:100'],
            'customer_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'customer_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'taxId' => [in_array($this->paymentMethod, ['PIX', 'CARD']) ? 'required' : 'nullable', 'regex:/^\d{3}\.?\d{3}\.?\d{3}-?\d{2}$/'],
            ...($this->orderType === 'delivery' ? [
                'address' => ['required', 'string', 'min:5', 'max:255'],
                'number' => ['required', 'string', 'max:20'],
                'neighborhood' => ['required', 'string', 'max:100'],
                'city' => ['required', 'string', 'max:100'],
                'cep' => ['required', 'regex:/^\d{5}-?\d{3}$/'],
            ] : []),
        ]);
        $identity = $this->verifiedCustomer();
        $this->customerId = $this->useSavedProfile && $identity?->phone === $this->phone ? $identity?->id : null;

        $scheduledAt = $this->scheduledAt ? Carbon::parse($this->scheduledAt) : null;

        if (! $this->orderPlacementAllowed($scheduledAt)) {
            return;
        }

        // Sem limite aqui, um cliente conseguia gerar pedidos `pending`/`awaiting_payment`
        // em sequência sem nunca pagar (spam) — o único freio antes era o debounce de clique.
        $rateLimitKey = "place-order:{$this->companyId}:session:".hash('sha256', session()->getId());
        $ipLimitKey = "place-order:{$this->companyId}:ip:".hash('sha256', (string) request()->ip());

        if (RateLimiter::tooManyAttempts($rateLimitKey, 8) || RateLimiter::tooManyAttempts($ipLimitKey, 60)) {
            Log::channel('chat')->warning('placeOrder rate limitado', ['customer_id' => $this->customerId]);
            $this->addMessage('bot', 'Muitas tentativas de pedido em pouco tempo. Aguarde alguns minutos e tente novamente.');

            return;
        }

        RateLimiter::hit($rateLimitKey, 300);
        RateLimiter::hit($ipLimitKey, 300);

        $this->submitting = true;
        $this->isLoading = true;

        $orderService = app(OrderServiceInterface::class);

        $coupon = null;
        if ($this->appliedCoupon) {
            $coupon = Coupon::find($this->appliedCoupon['id']);
        }

        $fiscalRecipient = $this->fiscalRecipientForCheckout();

        try {
            $this->reauthorizeDeliveryFee();

            $order = $orderService->createOrder(
                $this->customerId,
                $this->selectedBranchId,
                $this->cart,
                $this->notes,
                $this->paymentMethod,
                $this->orderType,
                $initialStatus,
                $this->deliveryFee,
                $coupon,
                $scheduledAt,
                buyerData: $this->checkoutBuyerData(),
                checkoutKey: $this->checkoutKey,
                fiscalRecipient: $fiscalRecipient,
            );

            Log::channel('orders')->info('Pedido criado no chat', [
                'order_id' => $order->id,
                'customer_id' => $this->customerId,
                'branch_id' => $this->selectedBranchId,
                'payment_method' => $this->paymentMethod,
                'order_type' => $this->orderType,
                'status' => $initialStatus,
            ]);

        } catch (RuntimeException $e) {
            Log::channel('discord')->error('Falha ao criar pedido no chat', [
                'type' => 'orders',
                'customer_id' => $this->customerId,
                'branch_id' => $this->selectedBranchId,
                'payment_method' => $this->paymentMethod,
                'order_type' => $this->orderType,
                'error' => $e->getMessage(),
            ]);

            if ($company = $this->currentCompany()) {
                CompanyNotification::create([
                    'company_id' => $company->id,
                    'type' => 'order_failed',
                    'title' => 'Falha ao criar pedido',
                    'subtitle' => $e->getMessage(),
                ]);
            }

            $this->addMessage('bot', 'Não foi possível criar o pedido: '.$e->getMessage());
            $this->submitting = false;
            $this->isLoading = false;

            return;
        }

        $this->orderId = $order->id;

        app(\App\Services\Chat\ChatAccess::class)->grantOrder($order);
        $this->trackingLink = app(\App\Services\Chat\ChatAccess::class)->orderLink($order);
        $customer = $order->customer;
        $company = $this->currentCompany();

        \App\Events\NewOrderPlaced::dispatch($order->load('customer'));

        Log::channel('orders')->info('Evento NewOrderPlaced disparado', [
            'order_id' => $order->id,
            'customer_id' => $this->customerId,
            'branch_id' => $this->selectedBranchId,
            'payment_method' => $this->paymentMethod,
            'order_type' => $this->orderType,
            'status' => $initialStatus,
        ]);

        $summary = $orderService->buildOrderSummaryFromOrder($order);

        if ($initialStatus === 'scheduled') {
            $scheduledLabel = Carbon::parse($this->scheduledAt)->setTimezone(config('app.timezone'))->format('d/m/Y \à\s H:i');
            $this->addMessage('bot', $summary."\n\n🕐 Pedido agendado para {$scheduledLabel}. Pagamento em dinheiro na entrega. Obrigado!");
            $this->transitionTo('ORDER_CONFIRMED');
        } elseif ($initialStatus === 'paid') {
            // Pedido nasce 'paid' (dinheiro) e nunca passa por outra transição de status depois —
            // sem isso, os listeners de OrderStatusUpdated (WhatsApp, e-mail) nunca disparam.
            OrderStatusUpdated::dispatch($order);
            $this->addMessage('bot', $summary."\n\nPagamento em dinheiro na entrega. Obrigado!");
            $this->transitionTo('ORDER_CONFIRMED');
        } elseif ($this->paymentMethod === 'CARD') {
            $this->loadCustomerCards($customer);
            $this->addMessage('bot', $summary."\n\nPreencha os dados do cartão para finalizar.");
            $this->transitionTo('PAYMENT_CARD_FORM');
        } else {
            app(PaymentService::class)->dispatchPayment($order, $customer, $company, $this->paymentMethod);
            $this->addMessage('bot', $summary."\n\nGerando PIX...");
            $this->transitionTo('PAYMENT_PIX');
        }

        $this->submitting = false;
        $this->isLoading = false;
    }

    /** Access comes from the server identity or an explicit order grant, never component ids. */
    private function findOwnOrder(bool $manage = false): ?Order
    {
        if (! $this->orderId || ! $this->companyId) {
            return null;
        }
        $order = Order::withoutGlobalScopes()->where('company_id', $this->companyId)->find($this->orderId);

        return $order && app(\App\Services\Chat\ChatAccess::class)->canAccessOrder($order, $manage) ? $order : null;
    }

    private function checkoutBuyerData(): array
    {
        return [
            'name' => $this->name, 'phone' => $this->phone, 'email' => $this->email ?: null,
            'tax_id' => preg_replace('/\D/', '', $this->taxId),
            'phone_verified' => app(\App\Services\Chat\ChatAccess::class)->phoneVerified($this->companyId, $this->phone),
            'whatsapp_opt_in' => $this->whatsappOptIn && app(\App\Services\Chat\ChatAccess::class)->phoneVerified($this->companyId, $this->phone) && $this->whatsappAvailable(),
            'address' => $this->orderType === 'delivery' ? $this->address : null,
            'number' => $this->number, 'complement' => $this->complement,
            'neighborhood' => $this->neighborhood, 'city' => $this->city,
            'cep' => preg_replace('/\D/', '', $this->cep),
            'latitude' => $this->customer_latitude !== '' ? (float) $this->customer_latitude : null,
            'longitude' => $this->customer_longitude !== '' ? (float) $this->customer_longitude : null,
        ];
    }

    public function checkPaymentStatus(): void
    {
        if (! $this->orderId) {
            return;
        }

        if ($this->step === 'PAYMENT_CARD_FORM') {
            return;
        }

        $phase = in_array($this->step, ['PAYMENT_PIX', 'PAYMENT_CARD_AWAITING'], true) ? 'pay' : 'delivery';
        $rateLimitKey = "status-check:{$phase}:{$this->orderId}";
        $maxAttempts = $phase === 'pay' ? 30 : 10;

        if (RateLimiter::tooManyAttempts($rateLimitKey, $maxAttempts)) {
            Log::channel('chat')->warning('checkPaymentStatus rate limitado', [
                'order_id' => $this->orderId,
                'customer_id' => $this->customerId,
                'phase' => $phase,
            ]);

            return;
        }
        RateLimiter::hit($rateLimitKey, 60);

        $order = $this->findOwnOrder();
        if (! $order) {
            Log::channel('chat')->warning('checkPaymentStatus: pedido não encontrado', [
                'order_id' => $this->orderId,
                'customer_id' => $this->customerId,
            ]);

            return;
        }

        $status = $order->status;
        if ($this->trackingOnly || ! $this->findOwnOrder(true)) {
            $this->trackingSummary = [
                'order_number' => $order->order_number, 'status' => $status,
                'status_label' => $order->status_label, 'order_type' => $order->order_type,
            ];

            return;
        }

        if ($status === 'awaiting_payment' && ! $this->pixCopyPaste && $this->findOwnOrder(true)) {
            $payment = $order->payment;
            if ($payment) {
                $sessionKey = 'payment_token_'.$this->orderId;
                $storedToken = session($sessionKey);

                if ($storedToken === null || ! hash_equals((string) $storedToken, (string) $payment->payment_token)) {
                    session([$sessionKey => $payment->payment_token]);
                }

                $this->pixQrCode = $payment->pix_qr_code;
                $this->pixCopyPaste = $payment->pix_copy_paste;
                $this->paymentId = $payment->asaas_payment_id;
                $this->expiresAt = $payment->expires_at?->toIso8601String();
            } else {
                Log::channel('chat')->warning('Pedido aguardando pagamento sem registro de Payment', [
                    'order_id' => $this->orderId,
                    'customer_id' => $this->customerId,
                ]);
            }
        }

        if ($status === $this->lastNotifiedStatus) {
            return;
        }

        Log::channel('chat')->info('Status do pedido atualizado', [
            'order_id' => $this->orderId,
            'customer_id' => $this->customerId,
            'status' => $status,
        ]);

        $scheduledLabel = $order->scheduled_at?->setTimezone(config('app.timezone'))->format('d/m/Y \à\s H:i');
        $messages = [
            'awaiting_payment' => "⏳ Pedido {$order->order_number} recebido! Aguardando confirmação do pagamento.",
            'scheduled' => "✅ Pagamento confirmado! Seu pedido {$order->order_number} está agendado para {$scheduledLabel}. Você receberá atualizações quando começarmos a preparar.",
            'paid' => "✅ Após a confirmação o seu pedido {$order->order_number} será preparado.",
            'preparing' => "👨‍🍳 Seu pedido {$order->order_number} está sendo preparado! Em breve ficará pronto.",
            'ready' => "✅ Pedido {$order->order_number} pronto!".($order->order_type === 'delivery' ? ' Logo sai para entrega.' : ''),
            'out_for_delivery' => "🛵 Pedido {$order->order_number} saiu para entrega! Aguarde em breve.",
            'delivered' => "🎉 Pedido {$order->order_number} entregue! Bom apetite e obrigado pela preferência!",
            'cancelled' => "❌ Seu pedido {$order->order_number} foi cancelado. Entre em contato se precisar de ajuda.",
        ];

        if (isset($messages[$status])) {
            $this->addMessage('bot', $messages[$status]);
            $this->lastNotifiedStatus = $status;
        }

        if (in_array($status, ['paid', 'scheduled'])) {
            $this->transitionTo('ORDER_CONFIRMED');
        }

        if ($status === 'cancelled' && $this->step !== 'ORDER_CONFIRMED') {
            $this->transitionTo('ORDER_FAILED');
        }
    }

    public function handlePaymentExpired(): void
    {
        if ($this->paymentMethod === 'CARD') {
            return;
        }

        $order = $this->findOwnOrder(true);
        if (! $order) {
            Log::channel('chat')->warning('handlePaymentExpired: pedido não encontrado', [
                'order_id' => $this->orderId,
                'customer_id' => $this->customerId,
            ]);

            return;
        }

        $this->pixQrCode = null;
        $this->pixCopyPaste = null;
        $this->expiresAt = null;
        $this->paymentId = null;

        $customer = $order?->customer;
        $company = $this->currentCompany();

        app(PaymentService::class)->expireAndRenew($order, $customer, $company, $this->paymentMethod);
        $this->addMessage('bot', 'O tempo para pagamento expirou. Gerando nova cobrança...');
    }

    /**
     * Carrega os cartões salvos do cliente (tokens Vindi de cobranças aprovadas
     * anteriores) para oferecer reuso sem re-digitar PAN/validade/nome.
     */
    private function loadCustomerCards(Customer $customer): void
    {
        $customer = $this->verifiedCustomer(true);
        if (! $customer || ! $this->useSavedProfile || $this->findOwnOrder(true)?->customer_id !== $customer->id) {
            $this->customerCards = [];
            $this->selectedCardId = null;
            $this->useNewCard = true;

            return;
        }
        $this->customerCards = $customer->cards()
            ->get(['id', 'brand', 'last_four'])
            ->map(fn ($card) => ['id' => $card->id, 'label' => $card->label()])
            ->toArray();

        $this->selectedCardId = $this->customerCards[0]['id'] ?? null;
        $this->useNewCard = $this->customerCards === [];
    }

    public function selectSavedCard(int $cardId): void
    {
        $customer = $this->verifiedCustomer(true);
        if (! $customer || ! $customer->cards()->whereKey($cardId)->exists()) {
            return;
        }
        $this->selectedCardId = $cardId;
        $this->useNewCard = false;
        $this->cardCvv = '';
        $this->cardError = null;
    }

    public function useNewCardForm(): void
    {
        $this->useNewCard = true;
        $this->selectedCardId = null;
        $this->cardError = null;
    }

    public function submitCardPayment(): void
    {
        if ($this->submitting) {
            return;
        }
        $this->cardError = null;

        $usingSavedCard = ! $this->useNewCard && $this->selectedCardId;

        if ($usingSavedCard && ! $this->verifiedCustomer(true)) {
            $this->cardError = 'Confirme seu telefone novamente para usar cartão salvo.';

            return;
        }

        if ($usingSavedCard) {
            $this->validate([
                'cardCvv' => ['required', 'min:3', 'max:4'],
            ], [
                'cardCvv.required' => 'Informe o CVV.',
                'cardCvv.min' => 'CVV inválido.',
            ]);
        } else {
            $this->validate([
                'cardNumber' => ['required', 'min:14'],
                'cardExpiry' => ['required', 'regex:/^\d{2}\/\d{2}$/'],
                'cardCvv' => ['required', 'min:3', 'max:4'],
                'cardHolderName' => ['required', 'min:3'],
            ], [
                'cardNumber.required' => 'Informe o número do cartão.',
                'cardNumber.min' => 'Número do cartão inválido.',
                'cardExpiry.required' => 'Informe a validade.',
                'cardExpiry.regex' => 'Validade inválida. Use MM/AA.',
                'cardCvv.required' => 'Informe o CVV.',
                'cardCvv.min' => 'CVV inválido.',
                'cardHolderName.required' => 'Informe o nome impresso no cartão.',
                'cardHolderName.min' => 'Nome inválido.',
            ]);
        }

        $this->submitting = true;

        $order = $this->findOwnOrder(true);
        $customer = $order?->customer;
        $company = $this->currentCompany();

        if (! $order) {
            $this->cardError = 'Pedido não encontrado. Tente novamente.';
            $this->submitting = false;

            Log::channel('chat')->warning('submitCardPayment: pedido não encontrado', [
                'order_id' => $this->orderId,
                'customer_id' => $this->customerId,
            ]);

            return;
        }

        // Pedido cancelado (pelo admin, por expiração) ou já pago enquanto o cliente preenchia o
        // cartão: não cobra (o PaymentOrchestrator também recusa) e leva o chat ao estado real.
        if (! in_array($order->status, ['pending', 'awaiting_payment'], true)) {
            $this->leaveCardFormForClosedOrder($order);

            return;
        }

        if ($usingSavedCard) {
            $cardData = [
                'saved_card_id' => $this->selectedCardId,
                'ccv' => $this->cardCvv,
                'cpfCnpj' => $customer->tax_id ?? '',
                'postalCode' => $this->cardPostalCode ?: ($customer->cep ?? ''),
                'addressNumber' => $this->cardAddressNumber ?: 'S/N',
            ];
        } else {
            [$expMonth, $expYear] = explode('/', $this->cardExpiry);
            $expiryYear = strlen($expYear) === 2 ? '20'.$expYear : $expYear;

            $cardData = [
                'holderName' => $this->cardHolderName,
                'number' => $this->cardNumber,
                'expiryMonth' => $expMonth,
                'expiryYear' => $expiryYear,
                'ccv' => $this->cardCvv,
                'cpfCnpj' => $customer->tax_id ?? '',
                'postalCode' => $this->cardPostalCode ?: ($customer->cep ?? ''),
                'addressNumber' => $this->cardAddressNumber ?: 'S/N',
            ];
        }

        try {
            $result = app(PaymentOrchestrator::class)->processCreditCard(
                $order, $usingSavedCard ? $this->verifiedCustomer(true) : $customer, $company, $cardData, 1
            );

            Log::channel('orders')->info('Resultado do cartão recebido no chat', [
                'order_id' => $order->id,
                'customer_id' => $this->customerId,
                'approved' => $result['approved'],
                'declined' => $result['declined'] ?? false,
            ]);

            if ($result['approved']) {
                $this->cardNumber = '';
                $this->cardExpiry = '';
                $this->cardCvv = '';
                $this->cardHolderName = '';
                $this->cardPostalCode = '';
                $this->cardAddressNumber = '';
                $this->customerCards = [];
                $this->selectedCardId = null;
                $this->useNewCard = false;
                $this->cardFeeBreakdown = [];

                OrderStatusUpdated::dispatch($order->fresh());
                $this->addMessage('bot', 'Pagamento aprovado! Seu pedido está confirmado.');
                $this->transitionTo('ORDER_CONFIRMED');
            } elseif (($result['declined'] ?? false) && ! in_array($order->fresh()->status, ['pending', 'awaiting_payment'], true)) {
                // Pedido cancelado durante a cobrança (o valor, se capturado, é estornado).
                $this->leaveCardFormForClosedOrder($order->fresh());
            } elseif ($result['declined'] ?? false) {
                // Recusa definitiva da Vindi (não análise assíncrona) — mostra o motivo
                // real e deixa o cliente corrigir/tentar outro cartão. O cartão nunca é
                // salvo nesse caminho (PaymentOrchestrator só persiste em cobrança aprovada).
                $this->cardCvv = '';
                $this->cardError = $result['decline_reason'] ?? 'Pagamento recusado. Verifique os dados ou tente outro cartão.';
                $this->submitting = false;
            } else {
                $this->cardNumber = '';
                $this->cardExpiry = '';
                $this->cardCvv = '';
                $this->cardHolderName = '';
                $this->cardPostalCode = '';
                $this->cardAddressNumber = '';
                $this->customerCards = [];
                $this->selectedCardId = null;
                $this->useNewCard = false;
                $this->cardFeeBreakdown = [];

                $this->addMessage('bot', 'Pagamento em análise. Você será notificado assim que confirmado.');
                $this->transitionTo('PAYMENT_CARD_AWAITING');
            }
        } catch (\Throwable $e) {
            Log::channel('discord')->error('Erro ao processar cartão no chat', [
                'type' => 'payments',
                'order_id' => $this->orderId,
                'error' => $e->getMessage(),
            ]);
            $this->cardCvv = '';
            $this->cardError = 'Não foi possível processar o pagamento. Tente novamente.';
            $this->submitting = false;
        }
    }

    private function leaveCardFormForClosedOrder(Order $order): void
    {
        Log::channel('chat')->info('submitCardPayment: pedido fora do fluxo de pagamento', [
            'order_id' => $order->id,
            'customer_id' => $this->customerId,
            'status' => $order->status,
        ]);

        $this->cardCvv = '';
        $this->submitting = false;
        $this->lastNotifiedStatus = $order->status;

        if (in_array($order->status, ['cancelled', 'refunded'], true)) {
            $this->addMessage('bot', "❌ Seu pedido {$order->order_number} foi cancelado e não pode mais ser pago. Entre em contato se precisar de ajuda.");
            $this->transitionTo('ORDER_FAILED');

            return;
        }

        $this->addMessage('bot', "✅ O pagamento do pedido {$order->order_number} já foi confirmado.");
        $this->transitionTo('ORDER_CONFIRMED');
    }

    public function simulatePayment(): void
    {
        $order = $this->findOwnOrder(true);
        if (! $order) {
            return;
        }

        app(PaymentService::class)->simulatePayment($order->id);
        $this->addMessage('bot', 'Pagamento simulado! Pedido confirmado.');
        $this->transitionTo('ORDER_CONFIRMED');
    }

    public function requestCancelOrder(): void
    {
        $order = $this->findOwnOrder(true);
        if (! $order) {
            $this->addMessage('bot', 'Não é possível cancelar o pedido no momento. Entre em contato com a loja.');

            return;
        }

        try {
            app(OrderCancellationPolicy::class)->authorizeCustomerCancel($order);
        } catch (\RuntimeException $e) {
            $this->addMessage('bot', 'Não é possível cancelar o pedido no momento. Entre em contato com a loja.');

            Log::channel('orders')->info('Cancelamento pelo cliente rejeitado pela política', [
                'order_id' => $order->id,
                'customer_id' => $this->customerId,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $this->showCancelConfirm = true;
    }

    public function confirmCancelOrder(): void
    {
        $order = $this->findOwnOrder(true);
        if (! $order) {
            $this->showCancelConfirm = false;
            $this->addMessage('bot', 'Não foi possível cancelar o pedido. Entre em contato com a loja.');

            return;
        }

        try {
            app(OrderServiceInterface::class)->cancelOrder($order, $this->customerId);
        } catch (RuntimeException $e) {
            $this->showCancelConfirm = false;
            $this->addMessage('bot', 'Não foi possível cancelar o pedido. Entre em contato com a loja.');

            Log::channel('orders')->info('Cancelamento confirmado pelo cliente falhou', [
                'order_id' => $order->id,
                'customer_id' => $this->customerId,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        // Painel (tempo real), WhatsApp e demais listeners acompanham o cancelamento feito pelo cliente.
        OrderStatusUpdated::dispatch($order->fresh());

        $this->showCancelConfirm = false;
        $this->lastNotifiedStatus = 'cancelled';
        $this->addMessage('bot', "❌ Pedido {$order->order_number} cancelado. Se precisar de ajuda, entre em contato com a loja.");
        $this->transitionTo('ORDER_CANCELLED');
    }

    public function dismissCancelOrder(): void
    {
        $this->showCancelConfirm = false;
    }

    public function changePaymentMethod(): void
    {
        if ($this->orderId) {
            $order = $this->findOwnOrder(true);
            if (! $order) {
                return;
            }

            // Na tela do PIX o pedido já está 'awaiting_payment' (QR válido): cancelar só 'pending'
            // deixava o pedido antigo vivo ao lado do novo (estoque e cupom em dobro, PIX pagável).
            // cancelBySystem cancela os dois status, devolve estoque/cupom e avisa painel/WhatsApp;
            // se o PIX for pago mesmo assim, o webhook estorna.
            if ($order && app(OrderService::class)->cancelBySystem($order, 'Cliente trocou a forma de pagamento', 'chat_change_payment_method')) {
                Log::channel('orders')->info('Pedido cancelado para trocar forma de pagamento', [
                    'order_id' => $order->id,
                    'customer_id' => $this->customerId,
                ]);
            } elseif ($order && ! in_array($status = $order->fresh()->status, ['cancelled', 'refunded'], true)) {
                // Pago (ou já em andamento na loja) enquanto o cliente estava na tela: não abre um
                // segundo pedido ao lado deste.
                $this->lastNotifiedStatus = $status;
                $this->addMessage('bot', "Seu pedido {$order->order_number} já foi confirmado e não pode mais trocar a forma de pagamento.");
                $this->transitionTo('ORDER_CONFIRMED');

                return;
            }
        }

        $this->checkoutKey = \Illuminate\Support\Str::random(64);
        $this->orderId = null;
        $this->paymentId = null;
        $this->pixQrCode = null;
        $this->pixCopyPaste = null;
        $this->expiresAt = null;
        $this->submitting = false;
        $this->cardError = null;
        $this->cardNumber = '';
        $this->cardExpiry = '';
        $this->cardCvv = '';
        $this->cardHolderName = '';
        $this->cardPostalCode = '';
        $this->cardAddressNumber = '';
        $this->customerCards = [];
        $this->selectedCardId = null;
        $this->useNewCard = false;
        $this->cardFeeBreakdown = [];

        $this->addMessage('bot', 'Escolha uma nova forma de pagamento:');
        $this->transitionTo('CHECKOUT_PAYMENT_METHOD');
    }

    public function retryOrder(): void
    {
        if ($this->orderId) {
            Log::channel('orders')->info('Pedido abandonado via retryOrder', [
                'order_id' => $this->orderId,
                'customer_id' => $this->customerId,
            ]);
        }

        $this->checkoutKey = \Illuminate\Support\Str::random(64);
        $this->cart = [];
        $this->orderId = null;
        $this->pixQrCode = null;
        $this->pixCopyPaste = null;
        $this->paymentId = null;
        $this->expiresAt = null;
        $this->submitting = false;
        $this->notes = '';
        $this->paymentMethod = 'PIX';
        $this->orderType = 'delivery';
        $this->deliveryFee = 0.0;
        $this->freeDelivery = false;
        $this->couponInput = '';
        $this->appliedCoupon = null;
        $this->couponDiscount = 0.0;
        $this->couponError = null;
        $this->scheduledAt = null;
        $this->scheduleInput = '';
        $this->transitionTo('MENU_BROWSE');
    }

    private function friendlyDeclineMessage(?string $reason): string
    {
        return match ($reason) {
            'INSUFFICIENT_FUNDS' => 'Saldo insuficiente no cartão.',
            'EXPIRED_CARD' => 'Cartão expirado. Verifique a validade.',
            'INVALID_CARD', 'INVALID_NUMBER' => 'Dados do cartão inválidos.',
            'SECURITY_VIOLATION', 'INVALID_CVV' => 'CVV inválido.',
            'BLOCKED_CARD' => 'Cartão bloqueado. Contate seu banco.',
            default => 'Pagamento recusado. Verifique os dados ou tente outro cartão.',
        };
    }
}
