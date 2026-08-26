<?php

namespace App\Livewire\Admin\Pdv;

use App\Livewire\Admin\Pdv\Concerns\HasCartManagement;
use App\Livewire\Admin\Pdv\Concerns\HasCashSession;
use App\Livewire\Admin\Pdv\Concerns\HasCatalog;
use App\Livewire\Admin\Pdv\Concerns\HasClosingReports;
use App\Livewire\Admin\Pdv\Concerns\HasCustomerManagement;
use App\Livewire\Admin\Pdv\Concerns\HasDeliveryFee;
use App\Livewire\Admin\Pdv\Concerns\HasManualDiscount;
use App\Livewire\Admin\Pdv\Concerns\HasOrderCancellation;
use App\Livewire\Admin\Pdv\Concerns\HasOrderTotals;
use App\Livewire\Admin\Pdv\Concerns\HasPaymentFlow;
use App\Livewire\Admin\Pdv\Concerns\HasPaymentState;
use App\Livewire\Admin\Pdv\Concerns\HasProductLookup;
use App\Livewire\Admin\Pdv\Concerns\HasScheduling;
use App\Livewire\Admin\Pdv\Concerns\HasSplitPayment;
use App\Models\Branch;
use App\Models\PdvAuditLog;
use Livewire\Component;

/** Venda direta / balcão. Fluxo de mesa/comanda fica em {@see TabTerminal}. */
class Terminal extends Component
{
    use HasCartManagement;
    use HasCashSession;
    use HasCatalog;
    use HasClosingReports;
    use HasCustomerManagement;
    use HasDeliveryFee;
    use HasManualDiscount;
    use HasOrderCancellation;
    use HasOrderTotals;
    use HasPaymentFlow;
    use HasPaymentState;
    use HasProductLookup;
    use HasScheduling;
    use HasSplitPayment;

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

    // Só aparece se company.canUseFiscalNotes() — desmarcado por padrão, controla
    // só a impressão automática da nota (a emissão em si é sempre obrigatória).
    public bool $printFiscalNote = false;

    public bool $canUseFiscalNotes = false;

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

    // ── Agendamento ───────────────────────────────────────────────────────────
    public bool $isScheduled = false;

    public string $scheduleDate = '';

    public string $scheduleTime = '';

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

    // ── Fechamento de comanda (sempre null aqui — Terminal não abre mesa/comanda,
    //    só TabTerminal. Fica declarado porque HasOrderTotals bifurca nele pra
    //    reaproveitar os mesmos cálculos de total/taxa do wizard de pagamento) ──
    public ?int $closingTabOrderId = null;

    // ── Relatórios de fechamento ──────────────────────────────────────────────
    public bool $showClosingReports = false;

    public ?int $viewingClosedSessionId = null;

    // ── Permissões ────────────────────────────────────────────────────────────
    public bool $canOperate = false;

    public bool $canManageClosing = false;

    // Sempre false no Terminal: garçom (pdv.waiter_operate sem pdv.operate) é redirecionado pro
    // TabTerminal (mesa/comanda) já no Selector, antes de chegar aqui. Propriedade continua
    // existindo porque traits compartilhadas com TabTerminal (HasCashSession, HasManualDiscount,
    // HasOrderCancellation, HasClosingReports) checam `$this->isWaiter` internamente.
    public bool $isWaiter = false;

    public function mount(): void
    {
        $company = app()->bound('current.company') ? app('current.company') : null;
        $user = auth()->user();

        abort_unless($company, 403);
        abort_unless($company->pdv_module_enabled, 403, 'Módulo PDV não está habilitado para esta empresa.');

        $canFullyOperate = (bool) $user?->hasPermission('pdv.operate', $company);

        abort_unless($canFullyOperate, 403);

        $this->canOperate = true;
        $this->canManageClosing = $user->canManageClosing($company);
        $this->manualDiscountAllowed = (bool) $company->pdv_manual_discount_enabled;
        $this->canUseFiscalNotes = $company->canUseFiscalNotes();

        $userBranchId = $user->branchIdForCompany($company);

        $branch = $userBranchId
            ? Branch::where('company_id', $company->id)->where('active', true)->find($userBranchId)
            : null;

        if (! $branch) {
            $branch = Branch::where('company_id', $company->id)
                ->where('active', true)
                ->orderBy('id')
                ->first();
        }

        $this->selectedBranchId = $branch?->id;

        $this->syncCashSession();
    }

    // ── Filial ───────────────────────────────────────────────────────────────

    public function updatedSelectedBranchId(): void
    {
        $this->cart = [];
        $this->activeCategoryId = null;
        $this->search = '';
        unset($this->branchServiceCharge);

        $this->syncCashSession();
    }

    public function resetTerminal(): void
    {
        $this->resetCartForNextOrder();
        $this->dismissOrderSuccess();
    }

    /**
     * Só limpa o card flutuante de sucesso (número, total, troco, confirmação de
     * cancelar) — nunca mexe no carrinho, porque o operador pode já ter começado a
     * montar o próximo pedido enquanto o card do anterior ainda estava na tela.
     */
    public function dismissOrderSuccess(): void
    {
        $this->lastOrderNumber = null;
        $this->lastOrderTotal = null;
        $this->lastOrderId = null;
        $this->confirmingCancelOrder = false;
        $this->changeAmount = 0.0;
    }

    private function resetCartForNextOrder(): void
    {
        $this->cart = [];
        $this->customerQuery = '';
        $this->customerName = '';
        $this->customerId = null;
        $this->customerFound = false;
        $this->customerResults = [];
        $this->notes = '';
        $this->step = 'catalog';
        $this->showSessionHistory = false;
        $this->resetPaymentState();
        $this->resetDeliveryState();
        $this->resetScheduleState();
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
