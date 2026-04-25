<?php

namespace App\Livewire\Chat;

use App\Livewire\Chat\Concerns\HasCartManagement;
use App\Livewire\Chat\Concerns\HasChatSupport;
use App\Livewire\Chat\Concerns\HasCustomerProfile;
use App\Livewire\Chat\Concerns\HasOrderFlow;
use App\Livewire\Chat\Concerns\HasPaymentFlow;
use App\Models\Branch;
use App\Models\ProductCategory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Livewire\Component;

class OrderChat extends Component
{
    use HasCartManagement;
    use HasChatSupport;
    use HasCustomerProfile;
    use HasOrderFlow;
    use HasPaymentFlow;

    // --- State machine ---
    public string $step = 'IDENTIFY_PHONE';

    // --- Customer data ---
    public ?int $customerId = null;

    public string $phone = '';

    public string $name = '';

    public string $email = '';

    public string $address = '';

    public string $number = '';

    public string $complement = '';

    public string $neighborhood = '';

    public string $city = '';

    public string $cep = '';

    // --- Branch ---
    public ?int $selectedBranchId = null;

    // --- Cart: [product_id => ['qty', 'name', 'price']] ---
    public array $cart = [];

    // --- Order ---
    public string $notes = '';

    public string $taxId = '';

    public ?int $orderId = null;

    public string $paymentMethod = 'PIX';

    public string $orderType = 'delivery'; // 'delivery' | 'pickup'

    public float $deliveryFee = 0.0;

    public bool $freeDelivery = false;

    // --- Company ---
    public ?int $companyId = null;

    // --- Edit profile ---
    public ?string $previousStep = null;

    // --- Payment ---
    public ?string $pixQrCode = null;

    public ?string $pixCopyPaste = null;

    public ?string $paymentId = null;

    public ?string $expiresAt = null;

    // --- Card payment ---
    public string $cardNumber = '';

    public string $cardExpiry = '';

    public string $cardCvv = '';

    public string $cardHolderName = '';

    public string $cardPostalCode = '';

    public string $cardAddressNumber = '';

    public array $cardFeeBreakdown = [];

    public ?string $cardError = null;

    // --- Support chat (order-based) ---
    public string $supportMessage = '';

    public ?int $lastAdminMessageId = null;

    // --- Support chat (general / ticket-based) ---
    public ?int $supportTicketId = null;

    public string $generalSupportMessage = '';

    public ?int $lastAdminSupportMessageId = null;

    // --- Coupon ---
    public string $couponInput = '';

    public ?array $appliedCoupon = null; // ['code', 'type', 'discount', 'label']

    public float $couponDiscount = 0.0;

    public ?string $couponError = null;

    // --- UI ---
    public array $messages = [];

    public bool $isLoading = false;

    public ?string $lastNotifiedStatus = null;

    public bool $submitting = false;

    public ?string $cartError = null;

    public bool $showEndConfirm = false;

    public bool $showCancelConfirm = false;

    public bool $showSupportModal = false;

    public array $supportConversation = [];

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

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
        $company = app()->bound('current.company') ? app('current.company') : null;
        $companyName = $company?->name ?? config('app.name');
        $companyId = $this->companyId;

        $hasOpenBranch = Cache::remember(
            "open_branches:company:{$companyId}",
            now()->addMinutes(1),
            function () use ($companyId) {
                $nowTime = now(config('app.timezone'))->format('H:i:s');

                return Branch::withoutGlobalScopes()
                    ->where('active', true)
                    ->where('company_id', $companyId)
                    ->where(function ($query) use ($nowTime) {
                        $query->where(function ($q) use ($nowTime) {
                            $q->whereColumn('opens_at', '<=', 'closes_at')
                                ->where('opens_at', '<=', $nowTime)
                                ->where('closes_at', '>=', $nowTime);
                        })
                            ->orWhere(function ($q) use ($nowTime) {
                                $q->whereColumn('opens_at', '>', 'closes_at')
                                    ->where(function ($sub) use ($nowTime) {
                                        $sub->where('opens_at', '<=', $nowTime)
                                            ->orWhere('closes_at', '>=', $nowTime);
                                    });
                            });
                    })
                    ->exists();
            }
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

    // -------------------------------------------------------------------------
    // Real-time listeners
    // -------------------------------------------------------------------------

    public function getListeners(): array
    {
        $listeners = [];

        if ($this->orderId) {
            $listeners["echo:order.{$this->orderId},OrderStatusUpdated"] = 'checkPaymentStatus';
            $listeners["echo:order.{$this->orderId},AdminMessageSent"] = 'receiveAdminMessage';
        }

        if ($this->supportTicketId) {
            $listeners["echo:support.{$this->supportTicketId},AdminSupportMessageSent"] = 'receiveAdminSupportMessage';
            $listeners["echo:support.{$this->supportTicketId},SupportTicketClosed"] = 'onSupportTicketClosed';
        }

        return $listeners;
    }

    // -------------------------------------------------------------------------
    // Validation rules per step
    // -------------------------------------------------------------------------

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
                'address' => ['required', 'string', 'min:5', 'max:255'],
                'number' => ['required', 'string', 'max:20'],
                'complement' => ['nullable', 'string', 'max:100'],
                'neighborhood' => ['required', 'string', 'max:100'],
                'city' => ['required', 'string', 'max:100'],
                'cep' => ['required', 'regex:/^\d{5}-?\d{3}$/'],
            ],
            'CHECKOUT_DELIVERY_ADDRESS' => [
                'address' => ['required', 'string', 'min:5', 'max:255'],
                'number' => ['required', 'string', 'max:20'],
                'complement' => ['nullable', 'string', 'max:100'],
                'neighborhood' => ['required', 'string', 'max:100'],
                'city' => ['required', 'string', 'max:100'],
                'cep' => ['required', 'regex:/^\d{5}-?\d{3}$/'],
            ],
            'CHECKOUT_NOTES' => [
                'notes' => ['nullable', 'string', 'max:500'],
            ],
            'CHECKOUT_CPF' => [
                'taxId' => ['required', 'string', 'regex:/^\d{3}\.?\d{3}\.?\d{3}-?\d{2}$/'],
            ],
            'EDIT_PROFILE' => [
                'name' => ['required', 'string', 'min:3', 'max:100'],
                'address' => ['required', 'string', 'min:5', 'max:255'],
                'number' => ['required', 'string', 'max:20'],
                'complement' => ['nullable', 'string', 'max:100'],
                'neighborhood' => ['required', 'string', 'max:100'],
                'city' => ['required', 'string', 'max:100'],
                'cep' => ['required', 'regex:/^\d{5}-?\d{3}$/'],
            ],
            default => [],
        };
    }

    protected function messages(): array
    {
        return [
            'phone.required' => 'Informe seu telefone.',
            'phone.regex' => 'Telefone inválido. Informe DDD + número (10 ou 11 dígitos).',
            'name.required' => 'Informe seu nome.',
            'name.min' => 'Nome muito curto.',
            'email.required' => 'Informe seu e-mail.',
            'email.email' => 'E-mail inválido.',
            'email.unique' => 'Esse e-mail já está cadastrado. Use outro.',
            'address.required' => 'Informe o endereço.',
            'number.required' => 'Informe o número.',
            'neighborhood.required' => 'Informe o bairro.',
            'city.required' => 'Informe a cidade.',
            'cep.required' => 'Informe o CEP.',
            'cep.regex' => 'CEP inválido. Use o formato 00000-000.',
            'taxId.required' => 'Informe seu CPF.',
            'taxId.regex' => 'CPF inválido. Use o formato 000.000.000-00.',
        ];
    }

    // -------------------------------------------------------------------------
    // Computed properties
    // -------------------------------------------------------------------------

    public function getCartTotalProperty(): float
    {
        return collect($this->cart)->sum(function ($item) {
            $optionsExtra = 0.0;
            foreach ($item['options'] ?? [] as $group) {
                foreach ($group['selections'] ?? [] as $sel) {
                    $optionsExtra += ($sel['qty'] ?? 0) * ($sel['additional_price'] ?? 0);
                }
            }

            return $item['qty'] * ((float) $item['price'] + $optionsExtra);
        });
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

        return Cache::remember("branches:company:{$companyId}", now()->addMinutes(10), fn () => Branch::withoutGlobalScopes()
            ->where('active', true)
            ->where('company_id', $companyId)
            ->with('deliverySetting')
            ->orderBy('name')
            ->get()
        );
    }

    public function getMenuProperty()
    {
        $companyId = $this->companyId;
        $branchId = $this->selectedBranchId;

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
                        ->orderBy('products.sort_order')
                        ->with('optionGroups.options');
                }])
                ->get();
        });
    }

    // -------------------------------------------------------------------------
    // Component helpers
    // -------------------------------------------------------------------------

    public function endChat(): void
    {
        $this->resetState();
        $this->initialize();
    }

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
            'step' => $this->step,
            'companyId' => $this->companyId,
            'customerId' => $this->customerId,
            'phone' => $this->phone,
            'name' => $this->name,
            'email' => $this->email,
            'address' => $this->address,
            'complement' => $this->complement,
            'neighborhood' => $this->neighborhood,
            'number' => $this->number,
            'city' => $this->city,
            'cep' => $this->cep,
            'selectedBranchId' => $this->selectedBranchId,
            'cart' => $this->cart,
            'notes' => $this->notes,
            'taxId' => $this->taxId,
            'orderId' => $this->orderId,
            'paymentMethod' => $this->paymentMethod,
            'orderType' => $this->orderType,
            'deliveryFee' => $this->deliveryFee,
            'freeDelivery' => $this->freeDelivery,
            'previousStep' => $this->previousStep,
            'couponInput' => $this->couponInput,
            'appliedCoupon' => $this->appliedCoupon,
            'couponDiscount' => $this->couponDiscount,
            'pixQrCode' => $this->pixQrCode,
            'pixCopyPaste' => $this->pixCopyPaste,
            'paymentId' => $this->paymentId,
            'expiresAt' => $this->expiresAt,
            'messages' => $this->messages,
            'lastNotifiedStatus' => $this->lastNotifiedStatus,
            'showCancelConfirm' => $this->showCancelConfirm,
            'lastAdminMessageId' => $this->lastAdminMessageId,
            'supportTicketId' => $this->supportTicketId,
            'lastAdminSupportMessageId' => $this->lastAdminSupportMessageId,
            'showSupportModal' => $this->showSupportModal,
            'supportConversation' => $this->supportConversation,
        ]]);
    }

    private function resetState(): void
    {
        session()->forget('chat_state');
        $this->step = 'IDENTIFY_PHONE';
        $this->customerId = null;
        $this->phone = '';
        $this->name = '';
        $this->email = '';
        $this->address = '';
        $this->complement = '';
        $this->neighborhood = '';
        $this->number = '';
        $this->city = '';
        $this->cep = '';
        $this->selectedBranchId = null;
        $this->cart = [];
        $this->notes = '';
        $this->taxId = '';
        $this->orderId = null;
        $this->pixQrCode = null;
        $this->pixCopyPaste = null;
        $this->paymentId = null;
        $this->expiresAt = null;
        $this->paymentMethod = 'PIX';
        $this->orderType = 'delivery';
        $this->deliveryFee = 0.0;
        $this->freeDelivery = false;
        $this->previousStep = null;
        $this->couponInput = '';
        $this->appliedCoupon = null;
        $this->couponDiscount = 0.0;
        $this->couponError = null;
        $this->messages = [];
        $this->isLoading = false;
        $this->submitting = false;
        $this->cartError = null;
        $this->showEndConfirm = false;
        $this->showCancelConfirm = false;
        $this->lastNotifiedStatus = null;
        $this->supportMessage = '';
        $this->lastAdminMessageId = null;
        $this->supportTicketId = null;
        $this->generalSupportMessage = '';
        $this->lastAdminSupportMessageId = null;
        $this->showSupportModal = false;
        $this->supportConversation = [];
        $this->cardNumber = '';
        $this->cardExpiry = '';
        $this->cardCvv = '';
        $this->cardHolderName = '';
        $this->cardPostalCode = '';
        $this->cardAddressNumber = '';
        $this->cardError = null;
        $this->cardFeeBreakdown = [];
        $this->resetErrorBag();
    }

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    public function render()
    {
        $currentCompany = app()->bound('current.company') ? app('current.company') : null;

        return view('livewire.chat.order-chat', compact('currentCompany'))
            ->layout('layouts.chat');
    }
}
