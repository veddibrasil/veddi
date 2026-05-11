<?php

namespace App\Livewire\Chat\Concerns;

use App\Contracts\AsaasServiceInterface;
use App\Contracts\OrderServiceInterface;
use App\Contracts\TransactionServiceInterface;
use App\Contracts\WalletServiceInterface;
use App\DTOs\AsaasCustomerDTO;
use App\DTOs\CreditCardDTO;
use App\DTOs\CreditCardHolderDTO;
use App\Events\OrderStatusUpdated;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Order\OrderCancellationPolicy;
use App\Services\Order\StockService;
use App\Services\Payment\PaymentCalculatorService;
use App\Services\Payment\PaymentService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
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

        if ($this->taxId === '') {
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

        $settings = $company?->paymentSettings;
        $total = (float) $this->getOrderTotalProperty();

        if ($total <= 0) {
            $this->cardFeeBreakdown = [];

            return;
        }

        $anticipationDays = $settings?->default_anticipation_days ?? 15;

        $this->cardFeeBreakdown = app(PaymentCalculatorService::class)->calculate(
            $total,
            $anticipationDays,
            $settings
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

        $summary = $orderService->buildOrderSummaryFromOrder($order);

        if ($initialStatus === 'scheduled') {
            $scheduledLabel = Carbon::parse($this->scheduledAt)->setTimezone(config('app.timezone'))->format('d/m/Y \à\s H:i');
            $this->addMessage('bot', $summary."\n\n🕐 Pedido agendado para {$scheduledLabel}. Pagamento em dinheiro na entrega. Obrigado!");
            $this->transitionTo('ORDER_CONFIRMED');
        } elseif ($initialStatus === 'paid') {
            $this->addMessage('bot', $summary."\n\nPagamento em dinheiro na entrega. Obrigado!");
            $this->transitionTo('ORDER_CONFIRMED');
        } elseif ($this->paymentMethod === 'CARD') {
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

        $phase = $this->step === 'PAYMENT_PIX' ? 'pay' : 'delivery';
        $rateLimitKey = "status-check:{$phase}:{$this->orderId}";
        $maxAttempts = $phase === 'pay' ? 30 : 10;

        if (RateLimiter::tooManyAttempts($rateLimitKey, $maxAttempts)) {
            return;
        }
        RateLimiter::hit($rateLimitKey, 60);

        $order = Order::find($this->orderId);
        if (! $order) {
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
            'paid' => "✅ Pagamento confirmado! Seu pedido {$order->order_number} já será preparado. Obrigado!",
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

    public function submitCardPayment(): void
    {
        if ($this->submitting) {
            return;
        }
        $this->cardError = null;

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

        $this->submitting = true;

        $order = Order::find($this->orderId);
        $customer = Customer::findOrFail($this->customerId);
        $company = $this->currentCompany();

        if (! $order) {
            $this->cardError = 'Pedido não encontrado. Tente novamente.';
            $this->submitting = false;

            return;
        }

        $settings = $company?->paymentSettings;
        $anticipationDays = $settings?->default_anticipation_days ?? 15;
        $breakdown = app(PaymentCalculatorService::class)->calculate(
            (float) $order->total,
            $anticipationDays,
            $settings
        );

        $cardFeeAbsorbed = $company?->card_fee_absorbed_by_company ?? false;
        $chargeAmount = $cardFeeAbsorbed ? (float) $order->total : $breakdown['final_amount'];
        $cardFee = $cardFeeAbsorbed
            ? round((float) $order->total * $breakdown['total_rate'], 2)
            : $breakdown['fee_amount'];

        [$expMonth, $expYear] = explode('/', $this->cardExpiry);
        $expiryYear = strlen($expYear) === 2 ? '20'.$expYear : $expYear;

        try {
            $asaas = app(AsaasServiceInterface::class);

            $asaasCustomerId = $asaas->findOrCreateCustomer(new AsaasCustomerDTO(
                name: $customer->name,
                email: $customer->email,
                cpfCnpj: $customer->tax_id ?? '',
                phone: $customer->phone ?? null,
            ));

            $charge = $asaas->createCreditCardCharge(
                customerId: $asaasCustomerId,
                amount: $chargeAmount,
                description: "Pedido #{$order->order_number}".($company ? " - {$company->name}" : ''),
                externalReference: (string) $order->id,
                creditCard: new CreditCardDTO(
                    holderName: $this->cardHolderName,
                    number: $this->cardNumber,
                    expiryMonth: $expMonth,
                    expiryYear: $expiryYear,
                    ccv: $this->cardCvv,
                ),
                holderInfo: new CreditCardHolderDTO(
                    name: $customer->name,
                    email: $customer->email ?? '',
                    cpfCnpj: $customer->tax_id ?? '',
                    postalCode: $this->cardPostalCode ?: ($customer->cep ?? ''),
                    addressNumber: $this->cardAddressNumber ?: 'S/N',
                    phone: $customer->phone ?? null,
                ),
                installments: 1,
            );

            $status = $charge['status'] ?? null;

            if ($status === 'CONFIRMED' || $status === 'RECEIVED') {
                DB::transaction(function () use ($order, $customer, $charge, $chargeAmount, $cardFee, $breakdown, $anticipationDays) {
                    $newPayment = Payment::create([
                        'order_id' => $order->id,
                        'asaas_payment_id' => $charge['id'],
                        'payment_gateway' => 'asaas',
                        'amount' => $chargeAmount,
                        'original_amount' => $breakdown['original_amount'],
                        'card_fee' => $cardFee,
                        'card_fee_rate' => $breakdown['total_rate'],
                        'installments' => 1,
                        'anticipation_days' => $anticipationDays,
                        'status' => 'paid',
                        'paid_at' => now(),
                        'payment_token' => hash('sha256', $order->id.$customer->id.uniqid()),
                    ]);

                    $order->update(['status' => 'paid']);

                    app(WalletServiceInterface::class)->creditForOrder($order->fresh(), $newPayment);
                    app(TransactionServiceInterface::class)->createForPayment($order->fresh(), $newPayment);
                });

                OrderStatusUpdated::dispatch($order->fresh());

                Log::channel('payments')->info('Cartão aprovado no chat', [
                    'order_id' => $order->id,
                    'customer_id' => $customer->id,
                    'asaas_payment_id' => $charge['id'],
                    'original_amount' => $breakdown['original_amount'],
                    'final_amount' => $chargeAmount,
                    'card_fee' => $cardFee,
                    'card_fee_absorbed' => $cardFeeAbsorbed,
                ]);

                $this->cardNumber = '';
                $this->cardExpiry = '';
                $this->cardCvv = '';
                $this->cardHolderName = '';
                $this->cardPostalCode = '';
                $this->cardAddressNumber = '';
                $this->cardFeeBreakdown = [];

                $this->addMessage('bot', 'Pagamento aprovado! Seu pedido está confirmado.');
                $this->transitionTo('ORDER_CONFIRMED');
            } else {
                $declineReason = $charge['creditCard']['declineReason'] ?? $charge['declineReason'] ?? null;
                Log::channel('payments')->warning('Cartão recusado no chat', [
                    'order_id' => $this->orderId,
                    'customer_id' => $this->customerId,
                    'decline_reason' => $declineReason,
                ]);
                $this->cardError = $this->friendlyDeclineMessage($declineReason);
                $this->submitting = false;
            }
        } catch (\Throwable $e) {
            Log::channel('discord')->error('Erro ao processar cartão no chat', [
                'type' => 'payments',
                'order_id' => $this->orderId,
                'error' => $e->getMessage(),
            ]);
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
        } catch (\RuntimeException) {
            $this->addMessage('bot', 'Não é possível cancelar o pedido no momento. Entre em contato com a loja.');

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
        } catch (RuntimeException) {
            $this->showCancelConfirm = false;
            $this->addMessage('bot', 'Não foi possível cancelar o pedido. Entre em contato com a loja.');

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
        $this->cardFeeBreakdown = [];

        $this->addMessage('bot', 'Escolha uma nova forma de pagamento:');
        $this->transitionTo('CHECKOUT_PAYMENT_METHOD');
    }

    public function retryOrder(): void
    {
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
