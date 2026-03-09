<?php

namespace App\Livewire\Chat;

use App\Events\CustomerMessageSent;
use App\Jobs\ProcessOrder;
use App\Models\Branch;
use App\Models\ChatMessage;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCategory;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Livewire\Component;

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

    public function mount(): void
    {
        // Restaura sessão se existir
        $savedState = session('chat_state');
        if ($savedState) {
            foreach ($savedState as $key => $value) {
                if (property_exists($this, $key)) {
                    $this->$key = $value;
                }
            }
            return;
        }

        $this->initialize();
    }

    private function initialize(): void
    {
        $company = app()->bound('current.company') ? app('current.company') : null;
        $companyName = $company?->name ?? config('app.name');

        $companyId = $company?->id;

        $hasOpenBranch = Cache::remember("open_branches:company:{$companyId}", now()->addMinutes(1), fn () =>
            Branch::where('active', true)
                ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
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

        $normalized = $this->phone;
        $customer   = Customer::findByPhone($normalized);

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
            Log::channel('chat')->info('Cliente identificado pelo telefone', ['customer_id' => $customer->id, 'phone' => $normalized]);
            $this->addMessage('user', $this->phone);
            $this->addMessage('bot', "Que bom te ver de volta, {$customer->name}! Escolha uma filial para continuar.");
            $this->transitionTo('BRANCH_SELECT');
        } else {
            Log::channel('chat')->info('Telefone não encontrado — iniciando cadastro', ['phone' => $normalized]);
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
        } catch (Exception $e) {
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
        $branch = Branch::where('id', $branchId)->where('active', true)->firstOrFail();

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
        $product = Product::findOrFail($productId);

        if (isset($this->cart[$productId])) {
            $cart = $this->cart;
            $cart[$productId]['qty'] += $quantity;
            $this->cart = $cart;
        } else {
            $cart = $this->cart;
            $cart[$productId] = [
                'qty'   => $quantity,
                'name'  => $product->name,
                'price' => (float) $product->price,
            ];
            $this->cart = $cart;
        }
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
            $this->submitCashOrder();
            return;
        }

        $label = $method === 'CARD' ? 'Cartão de Crédito' : 'PIX';
        $this->addMessage('user', $label);
        $this->submitOrder();
    }

    public function submitCashOrder(): void
    {
        $this->isLoading = true;

        $subtotal = collect($this->cart)->sum(fn ($item) => $item['qty'] * $item['price']);

        $order = DB::transaction(function () use ($subtotal) {
            $order = Order::create([
                'customer_id'    => $this->customerId,
                'branch_id'      => $this->selectedBranchId,
                'subtotal'       => $subtotal,
                'total'          => $subtotal,
                'status'         => 'paid',
                'notes'          => $this->notes,
                'payment_method' => 'cash',
                'order_type'     => $this->orderType,
            ]);

            foreach ($this->cart as $productId => $item) {
                OrderItem::create([
                    'order_id'     => $order->id,
                    'product_id'   => $productId,
                    'product_name' => $item['name'],
                    'unit_price'   => $item['price'],
                    'quantity'     => $item['qty'],
                    'subtotal'     => $item['qty'] * $item['price'],
                ]);
            }

            return $order;
        });

        $this->orderId = $order->id;

        $customer = Customer::findOrFail($this->customerId);
        if ($this->taxId && ! $customer->tax_id) {
            $customer->update(['tax_id' => preg_replace('/\D/', '', $this->taxId)]);
        }

        Log::channel('orders')->info('Pedido em dinheiro criado', [
            'order_id'     => $order->id,
            'order_number' => $order->order_number,
            'customer_id'  => $this->customerId,
            'branch_id'    => $this->selectedBranchId,
            'total'        => $order->total,
            'order_type'   => $this->orderType,
        ]);

        \App\Events\NewOrderPlaced::dispatch($order->load('customer'));

        $this->addMessage('bot', $this->buildOrderSummary($order->order_number) . "\n\nPagamento em dinheiro na entrega. Obrigado!");
        $this->transitionTo('ORDER_CONFIRMED');
        $this->isLoading = false;
    }

    public function submitOrder(): void
    {
        if ($this->submitting) {
            return;
        }
        $this->submitting = true;

        $rules = $this->rules();
        if (! empty($rules)) {
            $this->validate($rules, $this->messages());
        }
        $this->isLoading = true;

        $subtotal = collect($this->cart)->sum(fn ($item) => $item['qty'] * $item['price']);

        $order = DB::transaction(function () use ($subtotal) {
            $order = Order::create([
                'customer_id'    => $this->customerId,
                'branch_id'      => $this->selectedBranchId,
                'subtotal'       => $subtotal,
                'total'          => $subtotal,
                'status'         => 'pending',
                'notes'          => $this->notes,
                'payment_method' => strtolower($this->paymentMethod),
                'order_type'     => $this->orderType,
            ]);

            foreach ($this->cart as $productId => $item) {
                OrderItem::create([
                    'order_id'     => $order->id,
                    'product_id'   => $productId,
                    'product_name' => $item['name'],
                    'unit_price'   => $item['price'],
                    'quantity'     => $item['qty'],
                    'subtotal'     => $item['qty'] * $item['price'],
                ]);
            }

            return $order;
        });

        $this->orderId = $order->id;

        $customer = Customer::findOrFail($this->customerId);
        $company  = app()->bound('current.company') ? app('current.company') : null;

        if ($this->taxId && ! $customer->tax_id) {
            $customer->update(['tax_id' => preg_replace('/\D/', '', $this->taxId)]);
            $customer->refresh();
        }

        Log::channel('orders')->info('Pedido criado — aguardando pagamento eletrônico', [
            'order_id'       => $order->id,
            'order_number'   => $order->order_number,
            'customer_id'    => $this->customerId,
            'branch_id'      => $this->selectedBranchId,
            'total'          => $order->total,
            'payment_method' => $this->paymentMethod,
            'order_type'     => $this->orderType,
        ]);

        \App\Events\NewOrderPlaced::dispatch($order->load('customer'));

        ProcessOrder::dispatch($order, $customer, $company, $this->paymentMethod);

        $label = $this->paymentMethod === 'CARD' ? 'cobrança no cartão' : 'PIX';
        $this->addMessage('bot', $this->buildOrderSummary($order->order_number) . "\n\nGerando {$label}...");
        $this->transitionTo('PAYMENT_PIX');
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

        $chatMessage = ChatMessage::create([
            'order_id' => $this->orderId,
            'sender'   => 'customer',
            'message'  => $text,
        ]);

        CustomerMessageSent::dispatch($chatMessage);
        $this->addMessage('user', $text);
    }

    public function receiveAdminMessage(array $data): void
    {
        $message = $data['message'] ?? '';
        if ($message === '') {
            return;
        }

        $this->addMessage('bot', $message);

        // Sincroniza o cursor do poll para não duplicar a mensagem recebida via Echo
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

        $query = ChatMessage::where('order_id', $this->orderId)
            ->where('sender', 'admin');

        if ($this->lastAdminMessageId) {
            $query->where('id', '>', $this->lastAdminMessageId);
        }

        $newMessages = $query->orderBy('id')->get();

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

        // Rate limit separado: pagamento (poll 5s → max 30/min) vs entrega (poll 15s → max 10/min)
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

        // Busca dados do PIX assim que o Job processar
        if ($order->status === 'awaiting_payment' && ! $this->pixCopyPaste && ! $this->paymentUrl) {
            $payment = $order->payment;
            if ($payment) {
                // Ownership verification via session (server-side, cannot be tampered by client)
                $sessionKey = 'payment_token_' . $this->orderId;
                $storedToken = session($sessionKey);

                if ($storedToken === null) {
                    // First time loading: store token in session
                    session([$sessionKey => $payment->payment_token]);
                } elseif (! hash_equals((string) $storedToken, (string) $payment->payment_token)) {
                    // Token mismatch: reject silently
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
            'order_id'   => $this->orderId,
            'customer_id' => $this->customerId,
            'status'     => $status,
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

        // Mark current payment as expired
        $order->payment?->update(['status' => 'expired']);

        // Re-dispatch job to generate a new billing if order is still awaiting payment
        if (in_array($order->status, ['pending', 'awaiting_payment'])) {
            $this->pixQrCode    = null;
            $this->pixCopyPaste = null;
            $this->paymentUrl   = null;
            $this->expiresAt    = null;
            $this->paymentId    = null;

            // Clear ownership token so new one is accepted on next poll
            session()->forget('payment_token_' . $this->orderId);

            $customer = Customer::findOrFail($this->customerId);
            $company  = app()->bound('current.company') ? app('current.company') : null;

            ProcessOrder::dispatch($order, $customer, $company, $this->paymentMethod);
            $this->addMessage('bot', 'O tempo para pagamento expirou. Gerando nova cobrança...');
        }
    }

    public function simulatePayment(): void
    {
        abort_unless(config('app.debug'), 403);

        $payment = Payment::where('order_id', $this->orderId)->first();

        if (! $payment) {
            return;
        }

        $payment->update(['status' => 'paid', 'paid_at' => now()]);
        $payment->order->update(['status' => 'paid']);

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
        if (! $order || ! in_array($order->status, ['paid', 'preparing'])) {
            $this->showCancelConfirm = false;
            $this->addMessage('bot', 'Não foi possível cancelar o pedido. Entre em contato com a loja.');
            return;
        }

        $order->update(['status' => 'cancelled']);
        $this->showCancelConfirm = false;
        $this->lastNotifiedStatus = 'cancelled';

        Log::channel('orders')->info('Pedido cancelado pelo cliente', [
            'order_id'    => $order->id,
            'customer_id' => $this->customerId,
        ]);

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
        $this->step            = 'IDENTIFY_PHONE';
        $this->customerId      = null;
        $this->phone           = '';
        $this->name            = '';
        $this->email           = '';
        $this->address         = '';
        $this->complement      = '';
        $this->neighborhood    = '';
        $this->city            = '';
        $this->cep             = '';
        $this->selectedBranchId = null;
        $this->cart            = [];
        $this->notes           = '';
        $this->taxId           = '';
        $this->orderId         = null;
        $this->pixQrCode       = null;
        $this->pixCopyPaste    = null;
        $this->paymentId       = null;
        $this->paymentUrl      = null;
        $this->expiresAt       = null;
        $this->paymentMethod   = 'PIX';
        $this->orderType       = 'delivery';
        $this->previousStep    = null;
        $this->messages        = [];
        $this->isLoading           = false;
        $this->submitting          = false;
        $this->cartError           = null;
        $this->showEndConfirm      = false;
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

    private function buildOrderSummary(string $orderNumber): string
    {
        $lines = ["Pedido {$orderNumber} recebido! Aqui está o resumo:"];

        foreach ($this->cart as $item) {
            $subtotal = $item['qty'] * $item['price'];
            $lines[] = "• {$item['qty']}x {$item['name']} — R$ " . number_format($subtotal, 2, ',', '.');
        }

        $total = collect($this->cart)->sum(fn ($item) => $item['qty'] * $item['price']);
        $lines[] = "\nTotal: R$ " . number_format($total, 2, ',', '.');

        return implode("\n", $lines);
    }

    // --- Computed ---

    public function getCartTotalProperty(): float
    {
        return collect($this->cart)->sum(fn ($item) => $item['qty'] * $item['price']);
    }

    public function getCartCountProperty(): int
    {
        return collect($this->cart)->sum(fn ($item) => $item['qty']);
    }

    public function getBranchesProperty()
    {
        $companyId = app()->bound('current.company') ? app('current.company')->id : null;
        return Cache::remember("branches:company:{$companyId}", now()->addMinutes(10), fn () =>
            Branch::where('active', true)
                ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
                ->orderBy('name')
                ->get()
        );
    }

    public function getMenuProperty()
    {
        return Cache::remember("menu:branch:{$this->selectedBranchId}", now()->addMinutes(5), fn () =>
            ProductCategory::with(['products' => function ($q) {
                $q->whereHas('branches', fn ($b) => $b
                    ->where('branches.id', $this->selectedBranchId)
                    ->where('branch_product.available', true))
                    ->where('active', true)
                    ->orderBy('sort_order');
            }])->where('active', true)->orderBy('sort_order')->get()
        );
    }

    public function render()
    {
        return view('livewire.chat.order-chat')
            ->layout('layouts.chat');
    }
}
