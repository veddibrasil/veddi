<?php

namespace App\Livewire\Chat;

use App\Events\AdminSupportMessageSent;
use App\Events\NewSupportMessage;
use App\Events\SupportMessageSent;
use App\Exceptions\CouponException;
use App\Exceptions\DeliveryException;
use App\Models\Branch;
use App\Models\ChatMessage;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ProductCategory;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\ChatService;
use App\Services\CouponService;
use App\Services\DeliveryService;
use App\Services\OrderService;
use App\Events\OrderStatusUpdated;
use App\Models\Payment;
use App\Services\AsaasService;
use App\Services\PaymentService;
use App\Services\WalletService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
    public string $number       = '';
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
    public ?string $expiresAt    = null;

    // --- Card payment ---
    public string  $cardNumber     = '';
    public string  $cardExpiry     = '';
    public string  $cardCvv        = '';
    public string  $cardHolderName = '';
    public ?string $cardError      = null;

    // --- Support chat (order-based) ---
    public string $supportMessage    = '';
    public ?int $lastAdminMessageId  = null;

    // --- Support chat (general / ticket-based) ---
    public ?int $supportTicketId            = null;
    public string $generalSupportMessage    = '';
    public ?int $lastAdminSupportMessageId  = null;

    // --- Coupon ---
    public string $couponInput    = '';
    public ?array $appliedCoupon  = null; // ['code', 'type', 'discount', 'label']
    public float $couponDiscount  = 0.0;
    public ?string $couponError   = null;

    // --- UI ---
    public array $messages    = [];
    public bool $isLoading    = false;
    public ?string $lastNotifiedStatus = null;
    public bool $submitting   = false;
    public ?string $cartError = null;
    public bool $showEndConfirm = false;
    public bool $showCancelConfirm = false;
    public bool $showSupportModal = false;
    public array $supportConversation = [];

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
        $slug = request()->route('company');

        if (! $slug) {
            abort(404);
        }

        $company = \App\Models\Company::where('slug', $slug)->first();

        if (! $company) {
            abort(404);
        }

        app()->instance('current.company', $company);
        view()->share('currentCompany', $company);

        $currentCompanyId = $company->id;

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

        $branches = $this->branches;

        if ($branches->count() === 1) {
            $branch = $branches->first();
            $this->selectedBranchId = $branch->id;
            $this->addMessage('bot', "Olá! Bem-vindo ao {$companyName}! 🛍️ Explore o cardápio e monte seu pedido.");
            $this->transitionTo('MENU_BROWSE');
        } else {
            $this->addMessage('bot', "Olá! Bem-vindo ao {$companyName}! 🏪 Escolha uma filial para ver o cardápio.");
            $this->transitionTo('BRANCH_SELECT');
        }
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
                'number'       => ['required', 'string', 'max:20'],
                'complement'   => ['nullable', 'string', 'max:100'],
                'neighborhood' => ['required', 'string', 'max:100'],
                'city'         => ['required', 'string', 'max:100'],
                'cep'          => ['required', 'regex:/^\d{5}-?\d{3}$/'],
            ],
            'CHECKOUT_DELIVERY_ADDRESS' => [
                'address'      => ['required', 'string', 'min:5', 'max:255'],
                'number'       => ['required', 'string', 'max:20'],
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
                'number'       => ['required', 'string', 'max:20'],
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
            'number.required'       => 'Informe o número.',
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
            $this->number       = $customer->number ?? '';
            $this->city         = $customer->city ?? '';
            $this->cep          = $customer->cep ?? '';
            $this->taxId        = $customer->tax_id ?? '';
            Log::channel('chat')->info('Cliente identificado pelo telefone', ['customer_id' => $customer->id, 'phone' => $this->phone]);
            $this->addMessage('user', $this->phone);
            $nextStep = !empty($this->cart) ? 'CHECKOUT_COUPON' : 'MENU_BROWSE';
            $this->addMessage('bot', "Que bom te ver de volta, {$customer->name}! Continuando com seu pedido...");
            $this->transitionTo($nextStep);
        } else {
            $existing = Customer::findByPhoneGlobally($this->phone);

            if ($existing) {
                $customer = Customer::create([
                    'phone'        => $this->phone,
                    'name'         => $existing->name,
                    'email'        => $existing->email,
                    'address'      => $existing->address,
                    'complement'   => $existing->complement,
                    'neighborhood' => $existing->neighborhood,
                    'number'       => $existing->number,
                    'city'         => $existing->city,
                    'cep'          => $existing->cep,
                    'tax_id'       => $existing->tax_id,
                ]);

                $this->customerId   = $customer->id;
                $this->name         = $customer->name;
                $this->email        = $customer->email ?? '';
                $this->address      = $customer->address ?? '';
                $this->complement   = $customer->complement ?? '';
                $this->neighborhood = $customer->neighborhood ?? '';
                $this->number       = $customer->number ?? '';
                $this->city         = $customer->city ?? '';
                $this->cep          = $customer->cep ?? '';
                $this->taxId        = $customer->tax_id ?? '';

                Log::channel('chat')->info('Cliente identificado via outra empresa', [
                    'customer_id'       => $customer->id,
                    'source_company_id' => $existing->company_id,
                    'phone'             => $this->phone,
                ]);
                $this->addMessage('user', $this->phone);
                $nextStep = !empty($this->cart) ? 'CHECKOUT_COUPON' : 'MENU_BROWSE';
                $this->addMessage('bot', "Que bom te ver de novo, {$customer->name}! Continuando com seu pedido...");
                $this->transitionTo($nextStep);
            } else {
                Log::channel('chat')->info('Telefone não encontrado — iniciando cadastro', ['phone' => $this->phone]);
                $this->addMessage('user', $this->phone);
                $this->addMessage('bot', 'Não encontrei seu cadastro. Vamos criar um rapidinho! Qual é o seu nome completo?');
                $this->transitionTo('REGISTER_NAME');
            }
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

        $customer = Customer::updateOrCreate(
            ['phone' => $normalized],
            [
                'name'         => $this->name,
                'email'        => $this->email,
                'address'      => $this->address,
                'complement'   => $this->complement,
                'neighborhood' => $this->neighborhood,
                'city'         => $this->city,
                'number'       => $this->number,
                'cep'          => preg_replace('/\D/', '', $this->cep),
            ]
        );

        $this->customerId = $customer->id;
        Log::channel('chat')->info('Novo cliente cadastrado', ['customer_id' => $customer->id, 'phone' => $normalized]);
        $addressSummary = $this->address;
        if ($this->complement) {
            $addressSummary .= ", {$this->complement}";
        }
        $addressSummary .= " — {$this->neighborhood}, {$this->number}, {$this->city} — CEP {$this->cep}";
        $this->addMessage('user', $addressSummary);
        $nextStep = !empty($this->cart) ? 'CHECKOUT_COUPON' : 'MENU_BROWSE';
        $this->addMessage('bot', "Cadastro criado com sucesso! Continuando com seu pedido...");
        $this->transitionTo($nextStep);
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
            'number'       => $this->number,
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
        if (! $this->customerId) {
            $this->addMessage('bot', 'Ótimo pedido! Para continuar, preciso do seu número de telefone com DDD.');
            $this->transitionTo('IDENTIFY_PHONE');
            return;
        }

        $this->couponInput   = '';
        $this->appliedCoupon = null;
        $this->couponDiscount = 0.0;
        $this->couponError   = null;
        $this->transitionTo('CHECKOUT_COUPON');
    }

    // --- Step: Coupon ---

    public function applyCoupon(): void
    {
        $this->couponError = null;
        $code = strtoupper(trim($this->couponInput));

        if ($code === '') {
            $this->couponError = 'Informe um código de cupom.';
            return;
        }

        try {
            $coupon = app(CouponService::class)->validate(
                $code,
                $this->customerId,
                $this->cart,
                $this->cartTotal
            );

            $discount = app(CouponService::class)->calculateDiscount(
                $coupon,
                $this->cart,
                $this->cartTotal,
                $this->deliveryFee
            );

            $this->appliedCoupon = [
                'id'       => $coupon->id,
                'code'     => $coupon->code,
                'type'     => $coupon->type,
                'discount' => $discount,
                'label'    => $coupon->name,
            ];
            $this->couponDiscount = $discount;
            $this->couponError    = null;

            $discountLabel = $coupon->type === 'free_delivery'
                ? 'Frete grátis aplicado!'
                : 'Desconto de R$ ' . number_format($discount, 2, ',', '.') . ' aplicado!';

            $this->addMessage('user', "Cupom: {$coupon->code}");
            $this->addMessage('bot', "✅ {$discountLabel} Escolha o tipo de entrega:");
            $this->transitionTo('CHECKOUT_ORDER_TYPE');
        } catch (CouponException $e) {
            $this->couponError = $e->getMessage();
        }
    }

    public function skipCoupon(): void
    {
        $this->appliedCoupon  = null;
        $this->couponDiscount = 0.0;
        $this->couponError    = null;
        $this->couponInput    = '';
        $this->transitionTo('CHECKOUT_ORDER_TYPE');
    }

    public function removeCoupon(): void
    {
        $this->appliedCoupon  = null;
        $this->couponDiscount = 0.0;
        $this->couponError    = null;
        $this->couponInput    = '';
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

        // Para delivery, confirma/altera endereço antes de calcular frete
        $this->transitionTo('CHECKOUT_DELIVERY_ADDRESS');
    }

    public function confirmDeliveryAddress(): void
    {
        $this->validate($this->rules(), $this->messages());

        if ($this->customerId) {
            $customer = Customer::findOrFail($this->customerId);
            $customer->update([
                'address'      => $this->address,
                'complement'   => $this->complement,
                'neighborhood' => $this->neighborhood,
                'number'       => $this->number,
                'city'         => $this->city,
                'cep'          => preg_replace('/\D/', '', $this->cep),
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

        $coupon = null;
        if ($this->appliedCoupon) {
            $coupon = Coupon::find($this->appliedCoupon['id']);
        }

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
        } elseif ($this->paymentMethod === 'CARD') {
            $this->addMessage('bot', $summary . "\n\nPreencha os dados do cartão para finalizar.");
            $this->transitionTo('PAYMENT_CARD_FORM');
        } else {
            app(PaymentService::class)->dispatchPayment($order, $customer, $company, $this->paymentMethod);
            $this->addMessage('bot', $summary . "\n\nGerando PIX...");
            $this->transitionTo('PAYMENT_PIX');
        }

        $this->isLoading = false;
    }

    // --- Real-time listeners ---

    public function getListeners(): array
    {
        $listeners = [];

        if ($this->orderId) {
            $listeners["echo:order.{$this->orderId},OrderStatusUpdated"] = 'checkPaymentStatus';
            $listeners["echo:order.{$this->orderId},AdminMessageSent"]   = 'receiveAdminMessage';
        }

        if ($this->supportTicketId) {
            $listeners["echo:support.{$this->supportTicketId},AdminSupportMessageSent"] = 'receiveAdminSupportMessage';
            $listeners["echo:support.{$this->supportTicketId},SupportTicketClosed"]     = 'onSupportTicketClosed';
        }

        return $listeners;
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

    // --- Support shortcut (from header icon) ---

    public function goToSupport(): void
    {
        if (! $this->customerId) {
            return;
        }

        $company = app()->bound('current.company') ? app('current.company') : null;
        if (! $company) {
            return;
        }

        if (! $this->supportTicketId) {
            $ticket = SupportTicket::withoutGlobalScopes()->create([
                'company_id'  => $company->id,
                'customer_id' => $this->customerId,
                'status'      => 'open',
            ]);
            $this->supportTicketId = $ticket->id;
        }

        $this->supportConversation = SupportMessage::where('ticket_id', $this->supportTicketId)
            ->orderBy('created_at')
            ->get()
            ->map(fn ($m) => [
                'sender'     => $m->sender,
                'message'    => $m->message,
                'created_at' => $m->created_at->format('H:i'),
            ])
            ->toArray();

        $this->showSupportModal = true;
        $this->saveToSession();
    }

    public function closeSupportModal(): void
    {
        $this->showSupportModal = false;
        $this->saveToSession();
    }

    // --- Step: Main menu ---

    public function submitMainMenu(string $option): void
    {
        if ($option === '1') {
            $this->addMessage('user', '1 - Fazer pedido');
            $this->addMessage('bot', 'Ótimo! Escolha uma filial para continuar.');
            $this->transitionTo('BRANCH_SELECT');
        } elseif ($option === '2') {
            $this->addMessage('user', '2 - Falar com o suporte');

            $company = app()->bound('current.company') ? app('current.company') : null;

            if (! $this->customerId || ! $company) {
                return;
            }

            if (! $this->supportTicketId) {
                $ticket = SupportTicket::withoutGlobalScopes()->create([
                    'company_id'  => $company->id,
                    'customer_id' => $this->customerId,
                    'status'      => 'open',
                ]);
                $this->supportTicketId = $ticket->id;
            }

            $this->supportConversation = SupportMessage::where('ticket_id', $this->supportTicketId)
                ->orderBy('created_at')
                ->get()
                ->map(fn ($m) => [
                    'sender'     => $m->sender,
                    'message'    => $m->message,
                    'created_at' => $m->created_at->format('H:i'),
                ])
                ->toArray();

            $this->showSupportModal = true;
            $this->saveToSession();
        }
    }

    // --- Step: Support chat (general) ---

    public function sendGeneralSupportMessage(): void
    {
        $this->validate(['generalSupportMessage' => ['required', 'string', 'max:500']]);

        if (! $this->supportTicketId) {
            return;
        }

        $text = $this->generalSupportMessage;
        $this->generalSupportMessage = '';

        $msg = SupportMessage::create([
            'ticket_id' => $this->supportTicketId,
            'sender'    => 'customer',
            'message'   => $text,
        ]);

        $ticket = SupportTicket::withoutGlobalScopes()->find($this->supportTicketId);
        SupportMessageSent::dispatch($msg);
        if ($ticket) {
            NewSupportMessage::dispatch($msg, $ticket);
        }

        $this->supportConversation[] = [
            'sender'     => 'customer',
            'message'    => $text,
            'created_at' => now()->format('H:i'),
        ];
    }

    public function receiveAdminSupportMessage(array $data): void
    {
        $message = $data['message'] ?? '';
        if ($message === '') {
            return;
        }

        // Advance the poll baseline so the next poll doesn't duplicate this message
        if (! empty($data['message_id'])) {
            $this->lastAdminSupportMessageId = (int) $data['message_id'];
        } else {
            // Fallback: set baseline to current max to skip anything up to now
            $this->lastAdminSupportMessageId = SupportMessage::where('ticket_id', $this->supportTicketId)
                ->where('sender', 'admin')
                ->max('id') ?? $this->lastAdminSupportMessageId;
        }

        $this->supportConversation[] = [
            'sender'     => 'admin',
            'message'    => $message,
            'created_at' => now()->format('H:i'),
        ];
    }

    public function onSupportTicketClosed(array $data): void
    {
        if (! $this->supportTicketId) {
            return; // already handled
        }

        $this->supportConversation[] = [
            'sender'     => 'system',
            'message'    => '✅ Ticket encerrado. Obrigado pelo contato!',
            'created_at' => now()->format('H:i'),
        ];

        $this->supportTicketId = null;
        $this->saveToSession();
    }

    public function pollAdminSupportMessages(): void
    {
        if (! $this->supportTicketId) {
            return;
        }

        // Check if ticket was closed (fallback for when echo/websocket doesn't deliver the event)
        $ticket = SupportTicket::withoutGlobalScopes()->find($this->supportTicketId);
        if (! $ticket || $ticket->status === 'closed') {
            $this->onSupportTicketClosed([]);
            return;
        }

        // On first poll, set baseline to current max to avoid replaying old messages
        if ($this->lastAdminSupportMessageId === null) {
            $this->lastAdminSupportMessageId = SupportMessage::where('ticket_id', $this->supportTicketId)
                ->where('sender', 'admin')
                ->max('id') ?? 0;
            $this->saveToSession();
            return;
        }

        $newMessages = SupportMessage::where('ticket_id', $this->supportTicketId)
            ->where('sender', 'admin')
            ->where('id', '>', $this->lastAdminSupportMessageId)
            ->orderBy('id')
            ->get();

        foreach ($newMessages as $msg) {
            $this->supportConversation[] = [
                'sender'     => 'admin',
                'message'    => $msg->message,
                'created_at' => $msg->created_at->format('H:i'),
            ];
            $this->lastAdminSupportMessageId = $msg->id;
        }
    }

    // --- Step: Payment polling ---

    public function checkPaymentStatus(): void
    {
        if (! $this->orderId) {
            return;
        }

        // Credit card payments are synchronous — no polling needed
        if ($this->step === 'PAYMENT_CARD_FORM') {
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

        $status = $order->status;

        if ($status === 'awaiting_payment' && ! $this->pixCopyPaste) {
            $payment = $order->payment;
            if ($payment) {
                $sessionKey  = 'payment_token_' . $this->orderId;
                $storedToken = session($sessionKey);

                if ($storedToken === null || ! hash_equals((string) $storedToken, (string) $payment->payment_token)) {
                    session([$sessionKey => $payment->payment_token]);
                }

                $this->pixQrCode    = $payment->pix_qr_code;
                $this->pixCopyPaste = $payment->pix_copy_paste;
                $this->paymentId    = $payment->asaas_payment_id;
                $this->expiresAt    = $payment->expires_at?->toIso8601String();
            }
        }

        if ($status === $this->lastNotifiedStatus) {
            return;
        }

        Log::channel('chat')->info('Status do pedido atualizado', [
            'order_id'    => $this->orderId,
            'customer_id' => $this->customerId,
            'status'      => $status,
        ]);

        $messages = [
            'awaiting_payment' => "⏳ Pedido {$order->order_number} recebido! Aguardando confirmação do pagamento.",
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
        // Credit card payments are synchronous — no expiration needed
        if ($this->paymentMethod === 'CARD') {
            return;
        }

        $order = Order::find($this->orderId);
        if (! $order) {
            return;
        }

        $this->pixQrCode    = null;
        $this->pixCopyPaste = null;
        $this->expiresAt    = null;
        $this->paymentId    = null;

        $customer = Customer::findOrFail($this->customerId);
        $company  = app()->bound('current.company') ? app('current.company') : null;

        app(PaymentService::class)->expireAndRenew($order, $customer, $company, $this->paymentMethod);
        $this->addMessage('bot', 'O tempo para pagamento expirou. Gerando nova cobrança...');
    }

    public function submitCardPayment(): void
    {
        $this->cardError = null;

        $this->validate([
            'cardNumber'     => ['required', 'min:14'],
            'cardExpiry'     => ['required', 'regex:/^\d{2}\/\d{2}$/'],
            'cardCvv'        => ['required', 'min:3', 'max:4'],
            'cardHolderName' => ['required', 'min:3'],
        ], [
            'cardNumber.required'     => 'Informe o número do cartão.',
            'cardNumber.min'          => 'Número do cartão inválido.',
            'cardExpiry.required'     => 'Informe a validade.',
            'cardExpiry.regex'        => 'Validade inválida. Use MM/AA.',
            'cardCvv.required'        => 'Informe o CVV.',
            'cardCvv.min'             => 'CVV inválido.',
            'cardHolderName.required' => 'Informe o nome impresso no cartão.',
            'cardHolderName.min'      => 'Nome inválido.',
        ]);

        $order    = Order::find($this->orderId);
        $customer = Customer::findOrFail($this->customerId);
        $company  = app()->bound('current.company') ? app('current.company') : null;

        if (! $order) {
            $this->cardError = 'Pedido não encontrado. Tente novamente.';
            return;
        }

        // Parse expiry MM/YY → expiryMonth, expiryYear (4 digits)
        [$expMonth, $expYear] = explode('/', $this->cardExpiry);
        $expiryYear = strlen($expYear) === 2 ? '20' . $expYear : $expYear;

        try {
            $asaas = app(AsaasService::class);

            $asaasCustomerId = $asaas->findOrCreateCustomer([
                'name'    => $customer->name,
                'email'   => $customer->email,
                'cpfCnpj' => $customer->tax_id ?? '',
                'phone'   => $customer->phone ?? null,
            ]);

            $charge = $asaas->createCreditCardCharge(
                $asaasCustomerId,
                (float) $order->total,
                "Pedido #{$order->order_number}" . ($company ? " - {$company->name}" : ''),
                (string) $order->id,
                [
                    'holderName'  => $this->cardHolderName,
                    'number'      => $this->cardNumber,
                    'expiryMonth' => $expMonth,
                    'expiryYear'  => $expiryYear,
                    'ccv'         => $this->cardCvv,
                ],
                [
                    'name'          => $customer->name,
                    'email'         => $customer->email ?? '',
                    'cpfCnpj'       => $customer->tax_id ?? '',
                    'postalCode'    => $customer->cep ?? '',
                    'addressNumber' => 'S/N',
                    'phone'         => $customer->phone ?? null,
                ]
            );

            $status = $charge['status'] ?? null;

            if ($status === 'CONFIRMED' || $status === 'RECEIVED') {
                $newPayment = Payment::create([
                    'order_id'         => $order->id,
                    'asaas_payment_id' => $charge['id'],
                    'payment_gateway'  => 'asaas',
                    'amount'           => $order->total,
                    'status'           => 'paid',
                    'paid_at'          => now(),
                    'payment_token'    => hash('sha256', $order->id . $customer->id . uniqid()),
                ]);

                $order->update(['status' => 'paid']);

                app(WalletService::class)->creditForOrder($order->fresh(), $newPayment);

                OrderStatusUpdated::dispatch($order->fresh());

                $this->cardNumber     = '';
                $this->cardExpiry     = '';
                $this->cardCvv        = '';
                $this->cardHolderName = '';

                $this->addMessage('bot', '✅ Pagamento aprovado! Seu pedido está confirmado.');
                $this->transitionTo('ORDER_CONFIRMED');
            } else {
                $declineReason = $charge['creditCard']['declineReason'] ?? $charge['declineReason'] ?? null;
                $this->cardError = $this->friendlyDeclineMessage($declineReason);
            }
        } catch (\Throwable $e) {
            Log::channel('payments')->error('Erro ao processar cartão no chat', [
                'order_id' => $this->orderId,
                'error'    => $e->getMessage(),
            ]);
            $this->cardError = 'Não foi possível processar o pagamento. Tente novamente.';
        }
    }

    private function friendlyDeclineMessage(?string $reason): string
    {
        return match ($reason) {
            'INSUFFICIENT_FUNDS'         => 'Saldo insuficiente no cartão.',
            'EXPIRED_CARD'               => 'Cartão expirado. Verifique a validade.',
            'INVALID_CARD', 'INVALID_NUMBER' => 'Dados do cartão inválidos.',
            'SECURITY_VIOLATION', 'INVALID_CVV' => 'CVV inválido.',
            'BLOCKED_CARD'               => 'Cartão bloqueado. Contate seu banco.',
            default                      => 'Pagamento recusado. Verifique os dados ou tente outro cartão.',
        };
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
        if (! $order || ! in_array($order->status, ['awaiting_payment', 'paid', 'preparing'])) {
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
        $this->cart           = [];
        $this->orderId        = null;
        $this->pixQrCode      = null;
        $this->pixCopyPaste   = null;
        $this->paymentId      = null;
        $this->expiresAt      = null;
        $this->submitting     = false;
        $this->notes          = '';
        $this->paymentMethod  = 'PIX';
        $this->orderType      = 'delivery';
        $this->deliveryFee    = 0.0;
        $this->freeDelivery   = false;
        $this->couponInput    = '';
        $this->appliedCoupon  = null;
        $this->couponDiscount = 0.0;
        $this->couponError    = null;
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
        $this->number           = '';
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
        $this->couponInput      = '';
        $this->appliedCoupon    = null;
        $this->couponDiscount   = 0.0;
        $this->couponError      = null;
        $this->messages         = [];
        $this->isLoading        = false;
        $this->submitting       = false;
        $this->cartError        = null;
        $this->showEndConfirm   = false;
        $this->showCancelConfirm   = false;
        $this->lastNotifiedStatus  = null;
        $this->supportMessage              = '';
        $this->lastAdminMessageId          = null;
        $this->supportTicketId             = null;
        $this->generalSupportMessage       = '';
        $this->lastAdminSupportMessageId   = null;
        $this->showSupportModal            = false;
        $this->supportConversation         = [];
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
            'number'           => $this->number,
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
            'couponInput'      => $this->couponInput,
            'appliedCoupon'    => $this->appliedCoupon,
            'couponDiscount'   => $this->couponDiscount,
            'pixQrCode'        => $this->pixQrCode,
            'pixCopyPaste'     => $this->pixCopyPaste,
            'paymentId'        => $this->paymentId,
            'expiresAt'        => $this->expiresAt,
            'messages'             => $this->messages,
            'lastNotifiedStatus'   => $this->lastNotifiedStatus,
            'showCancelConfirm'    => $this->showCancelConfirm,
            'lastAdminMessageId'         => $this->lastAdminMessageId,
            'supportTicketId'            => $this->supportTicketId,
            'lastAdminSupportMessageId'  => $this->lastAdminSupportMessageId,
            'showSupportModal'           => $this->showSupportModal,
            'supportConversation'        => $this->supportConversation,
        ]]);
    }

    // --- Computed ---

    public function getCartTotalProperty(): float
    {
        return collect($this->cart)->sum(fn ($item) => $item['qty'] * $item['price']);
    }

    public function getOrderTotalProperty(): float
    {
        $deliveryFeeAfterCoupon = ($this->appliedCoupon && $this->appliedCoupon['type'] === 'free_delivery')
            ? 0.0
            : $this->deliveryFee;

        return max(0, $this->cartTotal + $deliveryFeeAfterCoupon - $this->couponDiscount);
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
        $branchId  = $this->selectedBranchId;


        return Cache::remember("menu:branch:{$branchId}:company:{$companyId}", now()->addMinutes(5), function () use ($companyId, $branchId) {
            return ProductCategory::withoutGlobalScopes()
                ->where('active', true)
                ->where('company_id', $companyId)
                ->orderBy('sort_order')
                ->with(['products' => function ($q) use ($branchId) {
                    $q->join('branch_product as bp', function ($join) use ($branchId) {
                            $join->on('bp.product_id', '=', 'products.id')
                                 ->where('bp.branch_id', $branchId);
                        })
                        ->where('products.active', true)
                        ->select('products.*', 'bp.available', 'bp.track_stock', 'bp.quantity')
                        ->orderBy('products.sort_order');
                }])
                ->get();
        });
    }

    public function render()
    {
        $currentCompany = app()->bound('current.company') ? app('current.company') : null;

        return view('livewire.chat.order-chat', compact('currentCompany'))
            ->layout('layouts.chat');
    }
}
