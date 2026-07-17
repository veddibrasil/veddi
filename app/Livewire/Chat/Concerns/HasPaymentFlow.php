<?php

namespace App\Livewire\Chat\Concerns;

use App\Contracts\OrderServiceInterface;
use App\Events\OrderStatusUpdated;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Services\Order\OrderCancellationPolicy;
use App\Services\Order\StockService;
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

    private function placeOrder(string $initialStatus): void
    {
        if ($this->submitting) {
            return;
        }
        $this->submitting = true;
        $this->isLoading = true;

        $orderService = app(OrderServiceInterface::class);

        $coupon = null;
        if ($this->appliedCoupon) {
            $coupon = Coupon::find($this->appliedCoupon['id']);
        }

        $scheduledAt = $this->scheduledAt ? Carbon::parse($this->scheduledAt) : null;

        try {
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
            $this->addMessage('bot', 'Não foi possível criar o pedido: '.$e->getMessage());
            $this->submitting = false;
            $this->isLoading = false;

            return;
        }

        $this->orderId = $order->id;

        $customer = Customer::findOrFail($this->customerId);
        $company = $this->currentCompany();

        if ($this->taxId && ! $customer->tax_id) {
            $customer->update(['tax_id' => preg_replace('/\D/', '', $this->taxId)]);
            $customer->refresh();
        }

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
            // sem isso, nada escuta OrderStatusUpdated e a nota fiscal automática nunca dispara.
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

        $order = Order::find($this->orderId);
        if (! $order) {
            Log::channel('chat')->warning('checkPaymentStatus: pedido não encontrado', [
                'order_id' => $this->orderId,
                'customer_id' => $this->customerId,
            ]);

            return;
        }

        $status = $order->status;

        if ($status === 'awaiting_payment' && ! $this->pixCopyPaste) {
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
            'ready' => "🛵 Pedido {$order->order_number} pronto e saiu para entrega! Aguarde em breve.",
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

        $order = Order::find($this->orderId);
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

        $customer = Customer::findOrFail($this->customerId);
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
        $this->customerCards = $customer->cards()
            ->get(['id', 'brand', 'last_four'])
            ->map(fn ($card) => ['id' => $card->id, 'label' => $card->label()])
            ->toArray();

        $this->selectedCardId = $this->customerCards[0]['id'] ?? null;
        $this->useNewCard = $this->customerCards === [];
    }

    public function selectSavedCard(int $cardId): void
    {
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

        $order = Order::find($this->orderId);
        $customer = Customer::findOrFail($this->customerId);
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
                $order, $customer, $company, $cardData, 1
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

    public function simulatePayment(): void
    {
        app(PaymentService::class)->simulatePayment($this->orderId);
        $this->addMessage('bot', 'Pagamento simulado! Pedido confirmado.');
        $this->transitionTo('ORDER_CONFIRMED');
    }

    public function requestCancelOrder(): void
    {
        $order = Order::find($this->orderId);
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
        $order = Order::find($this->orderId);
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
            $order = Order::find($this->orderId);
            if ($order && $order->status === 'pending') {
                $order->update(['status' => 'cancelled']);
                app(StockService::class)->restoreForOrder($order);
                Log::channel('orders')->info('Pedido cancelado para trocar forma de pagamento', [
                    'order_id' => $order->id,
                    'customer_id' => $this->customerId,
                ]);
            }
        }

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
