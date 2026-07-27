<?php

namespace App\Livewire\Admin\Pdv;

use App\Livewire\Admin\Pdv\Concerns\HasCartManagement;
use App\Livewire\Admin\Pdv\Concerns\HasCashSession;
use App\Livewire\Admin\Pdv\Concerns\HasCatalog;
use App\Livewire\Admin\Pdv\Concerns\HasClosingReports;
use App\Livewire\Admin\Pdv\Concerns\HasCustomerManagement;
use App\Livewire\Admin\Pdv\Concerns\HasDeliveryFee;
use App\Livewire\Admin\Pdv\Concerns\HasManualDiscount;
use App\Livewire\Admin\Pdv\Concerns\HasOpenTabs;
use App\Livewire\Admin\Pdv\Concerns\HasOrderCancellation;
use App\Livewire\Admin\Pdv\Concerns\HasPaymentFlow;
use App\Models\Branch;
use App\Models\PdvAuditLog;
use Livewire\Component;

class Terminal extends Component
{
    use HasCartManagement;
    use HasCashSession;
    use HasCatalog;
    use HasClosingReports;
    use HasCustomerManagement;
    use HasDeliveryFee;
    use HasManualDiscount;
    use HasOpenTabs;
    use HasOrderCancellation;
    use HasPaymentFlow;

    // ── Estado da interface ──────────────────────────────────────────────────
    public string $step = 'catalog'; // open_cash | catalog | payment | pix | success | close_cash

    public bool $showSessionHistory = false;

    // ── Filial ───────────────────────────────────────────────────────────────
    public ?int $selectedBranchId = null;

    // ── Catálogo ─────────────────────────────────────────────────────────────
    public string $search = '';

    public ?int $activeCategoryId = null;

    // ── Leitor de código de barras ────────────────────────────────────────────
    public string $barcodeInput = '';

    // ── Carrinho: [cartKey => [product_id, name, price, qty, options?]] ──────
    public array $cart = [];

    // ── Cliente (opcional) ───────────────────────────────────────────────────
    public string $customerQuery = '';

    public string $customerName = '';

    public ?int $customerId = null;

    public bool $customerFound = false;

    public array $customerResults = [];

    // ── Pagamento ─────────────────────────────────────────────────────────────
    public string $paymentMethod = 'cash';

    public string $cashReceivedInput = '';

    // ── Desconto manual ───────────────────────────────────────────────────────
    public string $manualDiscountType = 'fixed'; // 'fixed' | 'percent'

    public string $manualDiscountInput = '';

    public float $manualDiscountAmount = 0.0;

    public bool $manualDiscountAllowed = false;

    public bool $serviceFeeWaived = false;

    public bool $couvertFeeWaived = false;

    // ── Entrega ───────────────────────────────────────────────────────────────
    public string $deliveryType = 'balcao'; // 'balcao' | 'entrega'

    public string $deliveryAddress = '';

    public string $deliveryNumber = '';

    public string $deliveryComplement = '';

    public string $deliveryNeighborhood = '';

    public string $deliveryCity = '';

    public string $deliveryCep = '';

    public float $deliveryFeeAmount = 0.0;

    public ?string $deliveryFeeError = null;

    public string $deliveryPaymentStatus = 'paid'; // 'paid' | 'on_delivery' — só relevante quando deliveryType === 'entrega'

    // ── Observação ────────────────────────────────────────────────────────────
    public string $notes = '';

    // ── Resultado ─────────────────────────────────────────────────────────────
    public float $changeAmount = 0.0;

    public ?string $lastOrderNumber = null;

    public ?float $lastOrderTotal = null;

    public ?int $lastOrderId = null;

    public bool $confirmingCancelOrder = false;

    // ── Cliente novo (criação inline) ─────────────────────────────────────────
    public bool $showCreateCustomer = false;

    public string $newCustomerName = '';

    public string $newCustomerPhone = '';

    public ?string $createCustomerError = null;

    // ── Caixa (sessão PDV) ────────────────────────────────────────────────────
    public ?int $cashSessionId = null;

    public string $openingAmountInput = '';

    public string $terminalName = '';

    public string $closingAmountInput = '';

    public string $reconciliationNotes = '';

    // ── Movimentação manual do caixa ─────────────────────────────────────────
    public string $cashMovementType = 'supply'; // supply | withdrawal

    public string $cashMovementAmountInput = '';

    public string $cashMovementReason = '';

    public bool $showCashMovementForm = false;

    // ── Histórico de sessão ───────────────────────────────────────────────────
    public ?int $confirmingCancelSessionOrderId = null;

    // ── Tipo de venda / Mesa-Comanda ───────────────────────────────────────────
    public string $orderMode = 'impressao'; // 'impressao' | 'mesa'

    public ?int $selectedTableId = null;

    public string $newTableNumber = '';

    public ?int $openTabOrderId = null;

    public ?int $closingTabOrderId = null;

    public ?int $viewingTabItemsOrderId = null;

    // ── Relatórios de fechamento ──────────────────────────────────────────────
    public bool $showClosingReports = false;

    public ?int $viewingClosedSessionId = null;

    // ── Permissões ────────────────────────────────────────────────────────────
    public bool $canOperate = false;

    // Garçom: só abre mesa/comanda e lança itens — sem caixa, pagamento, desconto ou cancelamento.
    public bool $isWaiter = false;

    public function mount(): void
    {
        $company = app()->bound('current.company') ? app('current.company') : null;
        $user = auth()->user();

        abort_unless($company, 403);
        abort_unless($company->pdv_module_enabled, 403, 'Módulo PDV não está habilitado para esta empresa.');

        $canFullyOperate = (bool) $user?->hasPermission('pdv.operate', $company);
        $canWaiterOperate = (bool) $user?->hasPermission('pdv.waiter_operate', $company);

        abort_unless($canFullyOperate || $canWaiterOperate, 403);

        $this->canOperate = true;
        $this->isWaiter = ! $canFullyOperate && $canWaiterOperate;
        $this->manualDiscountAllowed = (bool) $company->pdv_manual_discount_enabled;

        $branch = $this->isWaiter && $user->branchIdForCompany($company)
            ? Branch::where('company_id', $company->id)->find($user->branchIdForCompany($company))
            : Branch::where('company_id', $company->id)
                ->where('active', true)
                ->orderBy('id')
                ->first();

        $this->selectedBranchId = $branch?->id;

        if ($this->isWaiter) {
            $this->orderMode = 'mesa';
            $this->step = 'catalog';

            return;
        }

        $this->syncCashSession();
    }

    // ── Filial ───────────────────────────────────────────────────────────────

    public function updatedSelectedBranchId(): void
    {
        $this->cart = [];
        $this->activeCategoryId = null;
        $this->search = '';
        $this->openTabOrderId = null;
        unset($this->branchServiceCharge);

        if ($this->isWaiter) {
            $this->step = 'catalog';

            return;
        }

        $this->syncCashSession();
    }

    public function resetTerminal(): void
    {
        $this->cart = [];
        $this->customerQuery = '';
        $this->customerName = '';
        $this->customerId = null;
        $this->customerFound = false;
        $this->customerResults = [];
        $this->lastOrderNumber = null;
        $this->lastOrderTotal = null;
        $this->lastOrderId = null;
        $this->confirmingCancelOrder = false;
        $this->changeAmount = 0.0;
        $this->notes = '';
        $this->step = 'catalog';
        $this->showSessionHistory = false;
        $this->orderMode = $this->isWaiter ? 'mesa' : 'impressao';
        $this->selectedTableId = null;
        $this->openTabOrderId = null;
        $this->closingTabOrderId = null;
        $this->resetPaymentState();
    }

    private function audit(string $action, array $data = []): void
    {
        $company = app()->bound('current.company') ? app('current.company') : null;

        if (! $company) {
            return;
        }

        PdvAuditLog::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'branch_id' => $this->selectedBranchId,
            'pdv_cash_session_id' => $this->cashSessionId,
            'order_id' => $data['order_id'] ?? null,
            'user_id' => auth()->id(),
            'action' => $action,
            'amount' => $data['amount'] ?? null,
            'reason' => $data['reason'] ?? null,
            'metadata' => $data['metadata'] ?? null,
        ]);
    }

    public function render()
    {
        return view('livewire.admin.pdv.terminal')
            ->layout('layouts.app.pdv');
    }
}
