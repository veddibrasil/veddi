<?php

namespace App\Livewire\Chat;

use App\Exceptions\DeliveryException;
use App\Models\Branch;
use App\Models\ChatMessage;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ProductCategory;
use App\Services\ChatService;
use App\Services\DeliveryService;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Livewire\Component;
use RuntimeException;

class OrderChat extends Component
{
    // --- State machine ---
    public string $step = 'IDENTIFY_PHONE';

    // --- Customer data ---
    public ?int $customerId = null;
    public string $phone        = '';
    public string $name         = '';
    public string $email        = '';
    public string $address      = '';
    public string $complement   = '';
    public string $neighborhood = '';
    public string $city         = '';
    public string $cep          = '';

    // --- Branch ---
    public ?int $selectedBranchId = null;

    // --- Cart: [product_id => ['qty', 'name', 'price']] ---
    public array $cart = [];

    // --- Order ---
    public string $notes         = '';
    public string $taxId         = '';
    public ?int $orderId         = null;
    public string $paymentMethod = 'PIX';
    public string $orderType     = 'delivery'; // 'delivery' | 'pickup'
    public float  $deliveryFee   = 0.0;
    public bool   $freeDelivery  = false;

    // --- Company ---
    public ?int $companyId = null;

    // --- Edit profile ---
    public ?string $previousStep = null;

    // --- Payment ---
    public ?string $pixQrCode    = null;
    public ?string $pixCopyPaste = null;
    public ?string $paymentId    = null;
    public ?string $paymentUrl   = null;
    public ?string $expiresAt    = null;

    // --- Support chat ---
    public string $supportMessage    = '';
    public ?int $lastAdminMessageId  = null;

    // --- UI ---
    public array $messages    = [];
    public bool $isLoading    = false;
    public ?string $lastNotifiedStatus = null;
    public bool $submitting   = false;
    public ?string $cartError = null;
    public bool $showEndConfirm = false;
    public bool $showCancelConfirm = false;

    public function boot(): void
    {
        if (! $this->companyId) {
            return;
        }

        $bound = app()->bound('current.company') ? app('current.company') : null;

        if (! $bound || $bound->id !== $this->companyId) {
            $company = \App\Models\Company::find($this->companyId);
            if ($company) {
                app()->instance('current.company', $company);
            }
        }
    }

    public function mount(): void
    {
        $currentCompanyId = app()->bound('current.company') ? app('current.company')->id : null;

        $savedState = session('chat_state');
        if ($savedState && ($savedState['companyId'] ?? null) === $currentCompanyId) {
            foreach ($savedState as $key => $value) {
                if (property_exists($this, $key)) {
                    $this->$key = $value;
                }
            }
            return;
        }

        session()->forget('chat_state');
        $this->companyId = $currentCompanyId;
        $this->initialize();
    }

    private function initialize(): void
    {
        $company     = app()->bound('current.company') ? app('current.company') : null;
        $companyName = $company?->name ?? config('app.name');
        $companyId   = $this->companyId;

        $hasOpenBranch = Cache::remember("open_branches:company:{$companyId}", now()->addMinutes(1), fn () =>
            Branch::withoutGlobalScopes()
                ->where('active', true)
                ->where('company_id', $companyId)
                ->where('opens_at', '<=', now()->format('H:i:s'))
                ->where('closes_at', '>=', now()->format('H:i:s'))
                ->exists()
        );

        if (! $hasOpenBranch) {
            $this->addMessage('bot', "Olá! Bem-vindo ao {$companyName}! No momento estamos fora do horário de atendimento. Consulte os horários de cada filial e volte mais tarde. 😊");
            $this->transitionTo('CLOSED');
            return;
        }

        $this->addMessage('bot', "Olá! Bem-vindo ao {$companyName}! Para começar, informe seu número de telefone com DDD.");
    }

    // --- Validation rules per step ---
    protected function rules(): array
    {
        return match ($this->step) {
            'IDENTIFY_PHONE' => [
                'phone' => ['required', 'string', 'regex:/^\d{10,11}$/'],
            ],
            'REGISTER_NAME' => [
                'name' => ['required', 'string', 'min:3', 'max:100'],
            ],
            'REGISTER_EMAIL' => [
                'email' => [
                    'required', 'email', 'max:200',
                    app()->bound('current.company')
                        ? Rule::unique('customers', 'email')->where('company_id', app('current.company')->id)
                        : Rule::unique('customers', 'email'),
                ],
            ],
            'REGISTER_ADDRESS' => [
                'address'      => ['required', 'string', 'min:5', 'max:255'],
                'complement'   => ['nullable', 'string', 'max:100'],
                'neighborhood' => ['required', 'string', 'max:100'],
                'city'         => ['required', 'string', 'max:100'],
                'cep'          => ['required', 'regex:/^\d{5}-?\d{3}$/'],
            ],
            'CHECKOUT_NOTES' => [
                'notes' => ['nullable', 'string', 'max:500'],
            ],
            'CHECKOUT_CPF' => [
                'taxId' => ['required', 'string', 'regex:/^\d{3}\.?\d{3}\.?\d{3}-?\d{2}$/'],
            ],
            'EDIT_PROFILE' => [
                'name'         => ['required', 'string', 'min:3', 'max:100'],
                'address'      => ['required', 'string', 'min:5', 'max:255'],
                'complement'   => ['nullable', 'string', 'max:100'],
                'neighborhood' => ['required', 'string', 'max:100'],
                'city'         => ['required', 'string', 'max:100'],
                'cep'          => ['required', 'regex:/^\d{5}-?\d{3}$/'],
            ],
            default => [],
        };
    }

    protected function messages(): array
    {
        return [
            'phone.required' => 'Informe seu telefone.',
            'phone.regex'    => 'Telefone inválido. Informe DDD + número (10 ou 11 dígitos).',
            'name.required'  => 'Informe seu nome.',
            'name.min'       => 'Nome muito curto.',
            'email.required' => 'Informe seu e-mail.',
            'email.email'    => 'E-mail inválido.',
            'email.unique'   => 'Esse e-mail já está cadastrado. Use outro.',
            'address.required'      => 'Informe o endereço.',
            'neighborhood.required' => 'Informe o bairro.',
            'city.required'         => 'Informe a cidade.',
            'cep.required'          => 'Informe o CEP.',
            'cep.regex'             => 'CEP inválido. Use o formato 00000-000.',
            'taxId.required'        => 'Informe seu CPF.',
            'taxId.regex'           => 'CPF inválido. Use o formato 000.000.000-00.',
        ];
    }

    // --- Step: Identify ---

    public function submitPhone(): void
    {
        $this->phone = preg_replace('/\D/', '', $this->phone);
        $this->validate($this->rules(), $this->messages());

        $customer = Customer::findByPhone($this->phone);

        if ($customer) {
            $this->customerId   = $customer->id;
            $this->name         = $customer->name;
            $this->email        = $customer->email ?? '';
            $this->address      = $customer->address ?? '';
            $this->complement   = $customer->complement ?? '';
            $this->neighborhood = $customer->neighborhood ?? '';
            $this->city         = $customer->city ?? '';
            $this->cep          = $customer->cep ?? '';
            $this->taxId        = $customer->tax_id ?? '';
            Log::channel('chat')->info('Cliente identificado pelo telefone', ['customer_id' => $customer->id, 'phone' => $this->phone]);
            $this->addMessage('user', $this->phone);
            $this->addMessage('bot', "Que bom te ver de volta, {$customer->name}! Escolha uma filial para continuar.");
            $this->transitionTo('BRANCH_SELECT');
        } else {
            Log::channel('chat')->info('Telefone não encontrado — iniciando cadastro', ['phone' => $this->phone]);
            $this->addMessage('user', $this->phone);
            $this->addMessage('bot', 'Não encontrei seu cadastro. Vamos criar um rapidinho! Qual é o seu nome completo?');
            $this->transitionTo('REGISTER_NAME');
        }
    }

    // --- Step: Register ---

    public function submitName(): void
    {
        $this->validate($this->rules(), $this->messages());
        $this->addMessage('user', $this->name);
        $this->addMessage('bot', "Prazer, {$this->name}! Qual é o seu e-mail?");
        $this->transitionTo('REGISTER_EMAIL');
    }

    public function submitEmail(): void
    {
        $this->validate($this->rules(), $this->messages());
        $this->addMessage('user', $this->email);
        $this->addMessage('bot', 'Agora me passe seu endereço de entrega.');
        $this->transitionTo('REGISTER_ADDRESS');
    }

    public function submitAddress(): void
    {
        $this->validate($this->rules(), $this->messages());

        $normalized = preg_replace('/\D/', '', $this->phone);

        try {
            $customer = Customer::create([
                'name'         => $this->name,
                'phone'        => $normalized,
                'email'        => $this->email,
                'address'      => $this->address,
                'complement'   => $this->complement,
                'neighborhood' => $this->neighborhood,
                'city'         => $this->city,
                'cep'          => preg_replace('/\D/', '', $this->cep),
            ]);
        } catch (\Exception $e) {
            $this->addMessage('bot', $e->getMessage());
            $this->transitionTo('REGISTER_EMAIL');
            return;
        }

        $this->customerId = $customer->id;
        Log::channel('chat')->info('Novo cliente cadastrado', ['customer_id' => $customer->id, 'phone' => $normalized]);
        $addressSummary = $this->address;
        if ($this->complement) {
            $addressSummary .= ", {$this->complement}";
        }
        $addressSummary .= " — {$this->neighborhood}, {$this->city} — CEP {$this->cep}";
        $this->addMessage('user', $addressSummary);
        $this->addMessage('bot', 'Cadastro criado com sucesso! Agora escolha uma filial.');
        $this->transitionTo('BRANCH_SELECT');
    }

    // --- Step: Edit Profile ---

    public function openEditProfile(): void
    {
        $this->previousStep = $this->step;
        $this->transitionTo('EDIT_PROFILE');
    }

    public function submitEditProfile(): void
    {
        $this->validate($this->rules(), $this->messages());

        $customer = Customer::findOrFail($this->customerId);
        $customer->update([
            'name'         => $this->name,
            'address'      => $this->address,
            'complement'   => $this->complement,
            'neighborhood' => $this->neighborhood,
            'city'         => $this->city,
            'cep'          => preg_replace('/\D/', '', $this->cep),
        ]);

        $this->addMessage('bot', "Cadastro atualizado com sucesso!");
        $this->transitionTo($this->previousStep ?? 'BRANCH_SELECT');
        $this->previousStep = null;
    }

    public function cancelEditProfile(): void
    {
        $this->transitionTo($this->previousStep ?? 'BRANCH_SELECT');
        $this->previousStep = null;
    }

    // --- Step: Branch ---

    public function selectBranch(int $branchId): void
    {
        $branch = Branch::withoutGlobalScopes()
            ->where('id', $branchId)
            ->where('company_id', $this->companyId)
            ->where('active', true)
            ->firstOrFail();

        if (! $branch->isOpen()) {
            $this->addMessage('bot', "A filial {$branch->name} está fechada no momento. Horário: {$branch->opens_at} às {$branch->closes_at}. Escolha outra filial ou tente mais tarde.");
            return;
        }

        $this->selectedBranchId = $branch->id;
        Log::channel('chat')->info('Filial selecionada', ['customer_id' => $this->customerId, 'branch_id' => $branch->id, 'branch_name' => $branch->name]);
        $this->addMessage('user', $branch->name);
        $this->addMessage('bot', "Ótimo! Aqui está o cardápio da {$branch->name}. Adicione os itens que quiser!");
        $this->transitionTo('MENU_BROWSE');
    }

    // --- Step: Cart ---

    public function addToCart(int $productId, int $quantity = 1): void
    {
        $this->cartError = null;
        $product = \App\Models\Product::findOrFail($productId);

        $cart = $this->cart;
        if (isset($cart[$productId])) {
            $cart[$productId]['qty'] += $quantity;
        } else {
            $cart[$productId] = [
                'qty'   => $quantity,
                'name'  => $product->name,
                'price' => (float) $product->price,
            ];
        }
        $this->cart = $cart;
    }

    public function removeFromCart(int $productId): void
    {
        $cart = $this->cart;
        unset($cart[$productId]);
        $this->cart = $cart;
    }

    public function updateCartQty(int $productId, int $qty): void
    {
        if ($qty <= 0) {
            $this->removeFromCart($productId);
            return;
        }
        $cart                    = $this->cart;
        $cart[$productId]['qty'] = $qty;
        $this->cart              = $cart;
    }

    public function proceedToCheckout(): void
    {
        if (empty($this->cart)) {
            $this->cartError = 'Adicione pelo menos um produto ao carrinho.';
            return;
        }
        $this->cartError = null;
        $this->transitionTo('CART_REVIEW');
    }

    public function confirmCart(): void
    {
        $this->transitionTo('CHECKOUT_ORDER_TYPE');
    }

    public function selectOrderType(string $type): void
    {
        $this->orderType = $type;
        $label = $type === 'pickup' ? 'Retirada no local' : 'Entrega';
        $this->addMessage('user', $label);

        if ($type === 'pickup') {
            $this->deliveryFee  = 0.0;
            $this->freeDelivery = false;
            $this->addMessage('bot', 'Alguma observação sobre o pedido?');
            $this->transitionTo('CHECKOUT_NOTES');
            return;
        }

        $this->resolveDeliveryFee();
    }

    private function resolveDeliveryFee(): void
    {
        $branch   = Branch::find($this->selectedBranchId);
        $settings = $branch?->deliverySetting;

        if (! $settings || ! $settings->active) {
            $this->deliveryFee  = 0.0;
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

            $this->deliveryFee  = $result['fee'];
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

    public function backToMenu(): void
    {
        $this->transitionTo('MENU_BROWSE');
    }

    // --- Step: Checkout ---

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

    public function selectPaymentMethod(string $method): void
    {
        $this->paymentMethod = $method;

        if ($method === 'CASH') {
            $this->addMessage('user', 'Dinheiro');
            $this->placeOrder('paid');
            return;
        }

        $label = $method === 'CARD' ? 'Cartão de Crédito' : 'PIX';
        $this->addMessage('user', $label);
        $this->placeOrder('pending');
    }

    /**
     * Cria o pedido com validação de preços no servidor e inicia o fluxo correto.
     */
    private function placeOrder(string $initialStatus): void
    {
        if ($this->submitting) {
            return;
        }
        $this->submitting = true;
        $this->isLoading  = true;

        $orderService = app(OrderService::class);

        try {
            $order = $orderService->createOrder(
                $this->customerId,
                $this->selectedBranchId,
                $this->cart,
                $this->notes,
                $this->paymentMethod,
                $this->orderType,
                $initialStatus,
                $this->deliveryFee
            );
        } catch (RuntimeException $e) {
            $this->addMessage('bot', 'Não foi possível criar o pedido: ' . $e->getMessage());
            $this->submitting = false;
            $this->isLoading  = false;
            return;
        }

        $this->orderId = $order->id;

        $customer = Customer::findOrFail($this->customerId);
        $company  = app()->bound('current.company') ? app('current.company') : null;

        if ($this->taxId && ! $customer->tax_id) {
            $customer->update(['tax_id' => preg_replace('/\D/', '', $this->taxId)]);
            $customer->refresh();
        }

        \App\Events\NewOrderPlaced::dispatch($order->load('customer'));

        $summary = $orderService->buildOrderSummaryFromOrder($order);

        if ($initialStatus === 'paid') {
            $this->addMessage('bot', $summary . "\n\nPagamento em dinheiro na entrega. Obrigado!");
            $this->transitionTo('ORDER_CONFIRMED');
        } else {
            app(PaymentService::class)->dispatchPayment($order, $customer, $company, $this->paymentMethod);
            $label = $this->paymentMethod === 'CARD' ? 'cobrança no cartão' : 'PIX';
            $this->addMessage('bot', $summary . "\n\nGerando {$label}...");
            $this->transitionTo('PAYMENT_PIX');
        }

        $this->isLoading = false;
    }

    // --- Real-time listeners ---

    public function getListeners(): array
    {
        if (! $this->orderId) {
            return [];
        }

        return [
            "echo:order.{$this->orderId},OrderStatusUpdated" => 'checkPaymentStatus',
            "echo:order.{$this->orderId},AdminMessageSent"   => 'receiveAdminMessage',
        ];
    }

    public function sendSupportMessage(): void
    {
        $this->validate(['supportMessage' => ['required', 'string', 'max:500']]);

        if (! $this->orderId) {
            return;
        }

        $text = $this->supportMessage;
        $this->supportMessage = '';

        app(ChatService::class)->sendCustomerMessage($this->orderId, $text);
        $this->addMessage('user', $text);
    }

    public function receiveAdminMessage(array $data): void
    {
        $message = $data['message'] ?? '';
        if ($message === '') {
            return;
        }

        $this->addMessage('bot', $message);

        if ($this->orderId) {
            $latest = ChatMessage::where('order_id', $this->orderId)
                ->where('sender', 'admin')
                ->max('id');

            if ($latest) {
                $this->lastAdminMessageId = $latest;
                $this->saveToSession();
            }
        }
    }

    public function pollAdminMessages(): void
    {
        if (! $this->orderId) {
            return;
        }

        $newMessages = app(ChatService::class)->pollAdminMessages($this->orderId, $this->lastAdminMessageId);

        foreach ($newMessages as $msg) {
            $this->addMessage('bot', $msg->message);
            $this->lastAdminMessageId = $msg->id;
        }
    }

    // --- Step: Payment polling ---

    public function checkPaymentStatus(): void
    {
        if (! $this->orderId) {
            return;
        }

        $phase        = $this->step === 'PAYMENT_PIX' ? 'pay' : 'delivery';
        $rateLimitKey = "status-check:{$phase}:{$this->orderId}";
        $maxAttempts  = $phase === 'pay' ? 30 : 10;

        if (RateLimiter::tooManyAttempts($rateLimitKey, $maxAttempts)) {
            return;
        }
        RateLimiter::hit($rateLimitKey, 60);

        $order = Order::find($this->orderId);
        if (! $order) {
            return;
        }

        if ($order->status === 'awaiting_payment' && ! $this->pixCopyPaste && ! $this->paymentUrl) {
            $payment = $order->payment;
            if ($payment) {
                $sessionKey  = 'payment_token_' . $this->orderId;
                $storedToken = session($sessionKey);

                if ($storedToken === null) {
                    session([$sessionKey => $payment->payment_token]);
                } elseif (! hash_equals((string) $storedToken, (string) $payment->payment_token)) {
                    return;
                }

                $this->pixQrCode    = $payment->pix_qr_code;
                $this->pixCopyPaste = $payment->pix_copy_paste;
                $this->paymentId    = $payment->abacatepay_billing_id;
                $this->paymentUrl   = $payment->abacatepay_url;
                $this->expiresAt    = $payment->expires_at?->toIso8601String();
            }
        }

        $status = $order->status;

        if ($status === $this->lastNotifiedStatus) {
            return;
        }

        Log::channel('chat')->info('Status do pedido atualizado', [
            'order_id'    => $this->orderId,
            'customer_id' => $this->customerId,
            'status'      => $status,
        ]);

        $messages = [
            'paid'      => "✅ Pagamento confirmado! Seu pedido {$order->order_number} está sendo preparado. Obrigado!",
            'preparing' => "👨‍🍳 Seu pedido {$order->order_number} está sendo preparado! Em breve ficará pronto.",
            'ready'     => "🛵 Pedido {$order->order_number} pronto e saiu para entrega! Aguarde em breve.",
            'delivered' => "🎉 Pedido {$order->order_number} entregue! Bom apetite e obrigado pela preferência!",
            'cancelled' => "❌ Seu pedido {$order->order_number} foi cancelado. Entre em contato se precisar de ajuda.",
        ];

        if (isset($messages[$status])) {
            $this->addMessage('bot', $messages[$status]);
            $this->lastNotifiedStatus = $status;
        }

        if ($status === 'paid') {
            $this->transitionTo('ORDER_CONFIRMED');
        }

        if ($status === 'cancelled' && $this->step !== 'ORDER_CONFIRMED') {
            $this->transitionTo('ORDER_FAILED');
        }
    }

    public function handlePaymentExpired(): void
    {
        $order = Order::find($this->orderId);
        if (! $order) {
            return;
        }

        $this->pixQrCode    = null;
        $this->pixCopyPaste = null;
        $this->paymentUrl   = null;
        $this->expiresAt    = null;
        $this->paymentId    = null;

        $customer = Customer::findOrFail($this->customerId);
        $company  = app()->bound('current.company') ? app('current.company') : null;

        app(PaymentService::class)->expireAndRenew($order, $customer, $company, $this->paymentMethod);
        $this->addMessage('bot', 'O tempo para pagamento expirou. Gerando nova cobrança...');
    }

    public function simulatePayment(): void
    {
        app(PaymentService::class)->simulatePayment($this->orderId);
        $this->addMessage('bot', "Pagamento simulado! Pedido confirmado.");
        $this->transitionTo('ORDER_CONFIRMED');
    }

    public function requestCancelOrder(): void
    {
        $order = Order::find($this->orderId);
        if (! $order || ! in_array($order->status, ['paid', 'preparing'])) {
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
            app(OrderService::class)->cancelOrder($order, $this->customerId);
        } catch (RuntimeException $e) {
            $this->showCancelConfirm = false;
            $this->addMessage('bot', 'Não foi possível cancelar o pedido. Entre em contato com a loja.');
            return;
        }

        $this->showCancelConfirm  = false;
        $this->lastNotifiedStatus = 'cancelled';
        $this->addMessage('bot', "❌ Pedido {$order->order_number} cancelado. Se precisar de ajuda, entre em contato com a loja.");
        $this->transitionTo('ORDER_FAILED');
    }

    public function dismissCancelOrder(): void
    {
        $this->showCancelConfirm = false;
    }

    public function retryOrder(): void
    {
        $this->cart          = [];
        $this->orderId       = null;
        $this->pixQrCode     = null;
        $this->pixCopyPaste  = null;
        $this->paymentId     = null;
        $this->paymentUrl    = null;
        $this->expiresAt     = null;
        $this->submitting    = false;
        $this->notes         = '';
        $this->paymentMethod = 'PIX';
        $this->orderType     = 'delivery';
        $this->deliveryFee   = 0.0;
        $this->freeDelivery  = false;
        $this->transitionTo('MENU_BROWSE');
    }

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

    public function endChat(): void
    {
        $this->resetState();
        $this->initialize();
    }

    private function resetState(): void
    {
        session()->forget('chat_state');
        $this->step             = 'IDENTIFY_PHONE';
        $this->customerId       = null;
        $this->phone            = '';
        $this->name             = '';
        $this->email            = '';
        $this->address          = '';
        $this->complement       = '';
        $this->neighborhood     = '';
        $this->city             = '';
        $this->cep              = '';
        $this->selectedBranchId = null;
        $this->cart             = [];
        $this->notes            = '';
        $this->taxId            = '';
        $this->orderId          = null;
        $this->pixQrCode        = null;
        $this->pixCopyPaste     = null;
        $this->paymentId        = null;
        $this->paymentUrl       = null;
        $this->expiresAt        = null;
        $this->paymentMethod    = 'PIX';
        $this->orderType        = 'delivery';
        $this->deliveryFee      = 0.0;
        $this->freeDelivery     = false;
        $this->previousStep     = null;
        $this->messages         = [];
        $this->isLoading        = false;
        $this->submitting       = false;
        $this->cartError        = null;
        $this->showEndConfirm   = false;
        $this->showCancelConfirm   = false;
        $this->lastNotifiedStatus  = null;
        $this->supportMessage      = '';
        $this->lastAdminMessageId  = null;
        $this->resetErrorBag();
    }

    // --- Helpers ---

    private function transitionTo(string $step): void
    {
        $this->step = $step;
        $this->resetErrorBag();
        $this->saveToSession();
    }

    private function addMessage(string $from, string $text): void
    {
        $this->messages[] = [
            'from' => $from,
            'text' => $text,
            'time' => now()->format('H:i'),
        ];
        $this->saveToSession();
    }

    private function saveToSession(): void
    {
        session(['chat_state' => [
            'step'             => $this->step,
            'companyId'        => $this->companyId,
            'customerId'       => $this->customerId,
            'phone'            => $this->phone,
            'name'             => $this->name,
            'email'            => $this->email,
            'address'          => $this->address,
            'complement'       => $this->complement,
            'neighborhood'     => $this->neighborhood,
            'city'             => $this->city,
            'cep'              => $this->cep,
            'selectedBranchId' => $this->selectedBranchId,
            'cart'             => $this->cart,
            'notes'            => $this->notes,
            'taxId'            => $this->taxId,
            'orderId'          => $this->orderId,
            'paymentMethod'    => $this->paymentMethod,
            'orderType'        => $this->orderType,
            'deliveryFee'      => $this->deliveryFee,
            'freeDelivery'     => $this->freeDelivery,
            'previousStep'     => $this->previousStep,
            'pixQrCode'        => $this->pixQrCode,
            'pixCopyPaste'     => $this->pixCopyPaste,
            'paymentId'        => $this->paymentId,
            'paymentUrl'       => $this->paymentUrl,
            'expiresAt'        => $this->expiresAt,
            'messages'             => $this->messages,
            'lastNotifiedStatus'   => $this->lastNotifiedStatus,
            'showCancelConfirm'    => $this->showCancelConfirm,
            'lastAdminMessageId'   => $this->lastAdminMessageId,
        ]]);
    }

    // --- Computed ---

    public function getCartTotalProperty(): float
    {
        return collect($this->cart)->sum(fn ($item) => $item['qty'] * $item['price']);
    }

    public function getOrderTotalProperty(): float
    {
        return $this->cartTotal + $this->deliveryFee;
    }

    public function getCartCountProperty(): int
    {
        return collect($this->cart)->sum(fn ($item) => $item['qty']);
    }

    public function getBranchesProperty()
    {
        $companyId = $this->companyId;
        return Cache::remember("branches:company:{$companyId}", now()->addMinutes(10), fn () =>
            Branch::withoutGlobalScopes()
                ->where('active', true)
                ->where('company_id', $companyId)
                ->orderBy('name')
                ->get()
        );
    }

    public function getMenuProperty()
    {
        $companyId = $this->companyId;
        return Cache::remember("menu:branch:{$this->selectedBranchId}:company:{$companyId}", now()->addMinutes(5), fn () =>
            ProductCategory::withoutGlobalScopes()
                ->where('active', true)
                ->where('company_id', $companyId)
                ->orderBy('sort_order')
                ->with(['products' => function ($q) {
                    $q->whereHas('branches', fn ($b) => $b
                        ->where('branches.id', $this->selectedBranchId)
                        ->where('branch_product.available', true))
                        ->where('active', true)
                        ->orderBy('sort_order');
                }])
                ->get()
        );
    }

    public function render()
    {
        return view('livewire.chat.order-chat')
            ->layout('layouts.chat');
    }
}
