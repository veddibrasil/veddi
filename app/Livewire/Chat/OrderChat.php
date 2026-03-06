<?php

namespace App\Livewire\Chat;

use App\Jobs\ProcessOrder;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCategory;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
    public string $neighborhood = '';
    public string $cep          = '';

    // --- Branch ---
    public ?int $selectedBranchId = null;

    // --- Cart: [product_id => ['qty', 'name', 'price']] ---
    public array $cart = [];

    // --- Order ---
    public string $notes   = '';
    public ?int $orderId   = null;

    // --- Payment ---
    public ?string $pixQrCode    = null;
    public ?string $pixCopyPaste = null;
    public ?string $paymentId    = null;

    // --- UI ---
    public array $messages  = [];
    public bool $isLoading  = false;
    public ?string $cartError = null;

    public function mount(): void
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
                'phone' => ['required', 'string', 'regex:/^\(?\d{2}\)?[\s\-]?\d{4,5}[\-]?\d{4}$/'],
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
                'neighborhood' => ['required', 'string', 'max:100'],
                'cep'          => ['required', 'regex:/^\d{5}-?\d{3}$/'],
            ],
            'CHECKOUT_NOTES' => [
                'notes' => ['nullable', 'string', 'max:500'],
            ],
            default => [],
        };
    }

    protected function messages(): array
    {
        return [
            'phone.required' => 'Informe seu telefone.',
            'phone.regex'    => 'Telefone inválido. Use o formato (44) 99999-9999.',
            'name.required'  => 'Informe seu nome.',
            'name.min'       => 'Nome muito curto.',
            'email.required' => 'Informe seu e-mail.',
            'email.email'    => 'E-mail inválido.',
            'email.unique'   => 'Esse e-mail já está cadastrado. Use outro.',
            'address.required'      => 'Informe o endereço.',
            'neighborhood.required' => 'Informe o bairro.',
            'cep.required'          => 'Informe o CEP.',
            'cep.regex'             => 'CEP inválido. Use o formato 00000-000.',
        ];
    }

    // --- Step: Identify ---

    public function submitPhone(): void
    {
        $this->validate($this->rules(), $this->messages());

        $normalized = preg_replace('/\D/', '', $this->phone);
        $customer   = Customer::findByPhone($normalized);

        if ($customer) {
            $this->customerId = $customer->id;
            $this->name       = $customer->name;
            $this->addMessage('user', $this->phone);
            $this->addMessage('bot', "Que bom te ver de volta, {$customer->name}! Escolha uma filial para continuar.");
            $this->transitionTo('BRANCH_SELECT');
        } else {
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
                'neighborhood' => $this->neighborhood,
                'cep'          => preg_replace('/\D/', '', $this->cep),
            ]);
        } catch (Exception $e) {
            $this->addMessage('bot', $e->getMessage());
            $this->transitionTo('REGISTER_EMAIL');
            return;
        }

        $this->customerId = $customer->id;
        $this->addMessage('user', "{$this->address}, {$this->neighborhood} — CEP {$this->cep}");
        $this->addMessage('bot', 'Cadastro criado com sucesso! Agora escolha uma filial.');
        $this->transitionTo('BRANCH_SELECT');
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
        $this->transitionTo('CHECKOUT_NOTES');
    }

    public function backToMenu(): void
    {
        $this->transitionTo('MENU_BROWSE');
    }

    // --- Step: Checkout ---

    public function submitOrder(): void
    {
        $this->validate($this->rules(), $this->messages());
        $this->isLoading = true;

        $subtotal = collect($this->cart)->sum(fn ($item) => $item['qty'] * $item['price']);

        $order = DB::transaction(function () use ($subtotal) {
            $order = Order::create([
                'customer_id' => $this->customerId,
                'branch_id'   => $this->selectedBranchId,
                'subtotal'    => $subtotal,
                'total'       => $subtotal,
                'status'      => 'pending',
                'notes'       => $this->notes,
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

        ProcessOrder::dispatch($order, $customer, $company);

        $this->addMessage('bot', "Pedido {$order->order_number} recebido! Gerando PIX...");
        $this->transitionTo('PAYMENT_PIX');
        $this->isLoading = false;
    }

    public function simulatePayment(): void
    {
        if (! $this->orderId) {
            return;
        }

        $order = Order::find($this->orderId);

        if (! $order || $order->status !== 'awaiting_payment') {
            return;
        }

        $order->update(['status' => 'paid']);

        Payment::where('order_id', $order->id)->update([
            'status'  => 'paid',
            'paid_at' => now(),
        ]);

        $this->addMessage('bot', "✅ Pagamento simulado com sucesso! Seu pedido {$order->order_number} está sendo preparado. Obrigado!");
        $this->transitionTo('ORDER_CONFIRMED');
    }

    // --- Step: Payment polling ---

    public function checkPaymentStatus(): void
    {
        if (! $this->orderId) {
            return;
        }

        $order = Order::find($this->orderId);

        if (! $order) {
            return;
        }

        // Busca dados do PIX assim que o Job processar
        if ($order->status === 'awaiting_payment' && ! $this->pixCopyPaste) {
            $payment = $order->payment;
            if ($payment) {
                $this->pixQrCode    = $payment->pix_qr_code;
                $this->pixCopyPaste = $payment->pix_copy_paste;
                $this->paymentId    = $payment->abacatepay_billing_id;
            }
        }

        if ($order->status === 'paid') {
            $this->addMessage('bot', "Pagamento confirmado! Seu pedido {$order->order_number} está sendo preparado. Obrigado!");
            $this->transitionTo('ORDER_CONFIRMED');
        }

        if ($order->status === 'cancelled') {
            $this->addMessage('bot', 'Houve um problema ao processar seu pagamento. Por favor, tente novamente.');
            $this->transitionTo('ORDER_FAILED');
        }
    }

    public function retryOrder(): void
    {
        $this->cart        = [];
        $this->orderId     = null;
        $this->pixQrCode   = null;
        $this->pixCopyPaste = null;
        $this->paymentId   = null;
        $this->notes       = '';
        $this->transitionTo('MENU_BROWSE');
    }

    public function startNewOrder(): void
    {
        $this->reset([
            'step', 'customerId', 'phone', 'name', 'email',
            'address', 'neighborhood', 'cep', 'selectedBranchId',
            'cart', 'notes', 'orderId', 'pixQrCode', 'pixCopyPaste',
            'paymentId', 'messages', 'isLoading', 'cartError',
        ]);
        $this->step = 'IDENTIFY_PHONE';
        $this->mount();
    }

    // --- Helpers ---

    private function transitionTo(string $step): void
    {
        $this->step = $step;
        $this->resetErrorBag();
    }

    private function addMessage(string $from, string $text): void
    {
        $this->messages[] = [
            'from' => $from,
            'text' => $text,
            'time' => now()->format('H:i'),
        ];
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
