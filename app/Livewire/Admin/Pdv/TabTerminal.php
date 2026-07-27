<?php

namespace App\Livewire\Admin\Pdv;

use App\Livewire\Admin\Pdv\Concerns\HasCashSession;
use App\Livewire\Admin\Pdv\Concerns\HasCatalog;
use App\Livewire\Admin\Pdv\Concerns\HasCustomerManagement;
use App\Livewire\Admin\Pdv\Concerns\HasManualDiscount;
use App\Livewire\Admin\Pdv\Concerns\HasOpenTabs;
use App\Livewire\Admin\Pdv\Concerns\HasOrderTotals;
use App\Livewire\Admin\Pdv\Concerns\HasPaymentState;
use App\Livewire\Admin\Pdv\Concerns\HasProductLookup;
use App\Models\Branch;
use App\Models\PdvAuditLog;
use App\Models\Product;
use Livewire\Component;

/** Mesa/comanda. Venda direta/balcão fica em {@see Terminal}. */
class TabTerminal extends Component
{
    use HasCashSession;
    use HasCatalog;
    use HasCustomerManagement;
    use HasManualDiscount;
    use HasOpenTabs;
    use HasOrderTotals;
    use HasPaymentState;
    use HasProductLookup;

    // ── Estado da interface ──────────────────────────────────────────────────
    public string $step = 'catalog'; // open_cash | catalog | payment | pix | success

    public bool $showSessionHistory = false;

    // ── Filial ───────────────────────────────────────────────────────────────
    public ?int $selectedBranchId = null;

    // ── Catálogo ─────────────────────────────────────────────────────────────
    public string $search = '';

    public ?int $activeCategoryId = null;

    // ── Leitor de código de barras ────────────────────────────────────────────
    public string $barcodeInput = '';

    // ── Sempre vazio: comanda não usa carrinho pendente, o clique já lança
    //    direto na order (sendProductToComanda). Propriedade existe só porque
    //    HasProductLookup/HasOrderTotals são compartilhadas com o Terminal. ────
    public array $cart = [];

    // ── Sempre 'balcao': mesa/comanda não tem fluxo de entrega. Propriedade
    //    existe só porque HasCustomerManagement é compartilhada com o Terminal. ─
    public string $deliveryType = 'balcao';

    // ── Cliente (opcional) ───────────────────────────────────────────────────
    public string $customerQuery = '';

    public string $customerName = '';

    public ?int $customerId = null;

    public bool $customerFound = false;

    public array $customerResults = [];

    // ── Pagamento (fechamento de comanda) ────────────────────────────────────
    public string $paymentMethod = 'cash';

    public string $cashReceivedInput = '';

    // ── Desconto manual ───────────────────────────────────────────────────────
    public string $manualDiscountType = 'fixed'; // 'fixed' | 'percent'

    public string $manualDiscountInput = '';

    public float $manualDiscountAmount = 0.0;

    public bool $manualDiscountAllowed = false;

    public bool $serviceFeeWaived = false;

    public bool $couvertFeeWaived = false;

    // ── Observação ────────────────────────────────────────────────────────────
    public string $notes = '';

    // ── Resultado ─────────────────────────────────────────────────────────────
    public float $changeAmount = 0.0;

    public ?string $lastOrderNumber = null;

    public ?float $lastOrderTotal = null;

    public ?int $lastOrderId = null;

    // ── Cliente novo (criação inline) ─────────────────────────────────────────
    public bool $showCreateCustomer = false;

    public string $newCustomerName = '';

    public string $newCustomerPhone = '';

    public ?string $createCustomerError = null;

    // ── Caixa (sessão PDV) ────────────────────────────────────────────────────
    public ?int $cashSessionId = null;

    public string $terminalName = '';

    // ── Mesa / Comanda ───────────────────────────────────────────────────────
    public ?int $selectedTableId = null;

    public string $newTableNumber = '';

    public ?int $openTabOrderId = null;

    public ?int $closingTabOrderId = null;

    public ?int $viewingTabItemsOrderId = null;

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
            $this->step = 'catalog';

            return;
        }

        // Não-garçom precisa de caixa aberto pra fechar comanda (a cobrança entra na conferência
        // dele) — mas quem abre/fecha caixa é só o Terminal (venda direta). Sem sessão, manda
        // abrir lá primeiro em vez de duplicar a UI de abertura de caixa aqui.
        $this->syncCashSession();

        if (! $this->cashSessionId) {
            $this->redirect(route('admin.pdv.checkout'));

            return;
        }

        $this->step = 'catalog';
    }

    // ── Filial ───────────────────────────────────────────────────────────────

    public function updatedSelectedBranchId(): void
    {
        $this->activeCategoryId = null;
        $this->search = '';
        $this->openTabOrderId = null;
        $this->selectedTableId = null;
        unset($this->branchServiceCharge);

        if ($this->isWaiter) {
            $this->step = 'catalog';

            return;
        }

        $this->syncCashSession();

        if (! $this->cashSessionId) {
            $this->redirect(route('admin.pdv.checkout'));
        }
    }

    /** Lançamento direto na comanda — sem carrinho pendente, ao contrário do Terminal. */
    public function addProduct(int $productId): void
    {
        if (! $this->selectedBranchId) {
            return;
        }

        $product = Product::withoutGlobalScopes()
            ->whereHas('branches', fn ($q) => $q
                ->where('branches.id', $this->selectedBranchId)
                ->where('branch_product.available', true)
            )
            ->where('active', true)
            ->where('available_in_pdv', true)
            ->find($productId);

        if (! $product || ! $this->checkStockBeforeAdd($productId, $product->name)) {
            return;
        }

        $this->sendProductToComanda($product);

        if ($this->getErrorBag()->isEmpty()) {
            $this->dispatch('product-added-to-cart', name: $product->name);
        }
    }

    public function addProductWithOptions(int $productId, array $optionSelections): void
    {
        if (! $this->selectedBranchId) {
            return;
        }

        $product = Product::withoutGlobalScopes()
            ->with('optionGroups')
            ->whereHas('branches', fn ($q) => $q
                ->where('branches.id', $this->selectedBranchId)
                ->where('branch_product.available', true)
            )
            ->where('active', true)
            ->where('available_in_pdv', true)
            ->find($productId);

        if (! $product || ! $this->checkStockBeforeAdd($productId, $product->name)) {
            return;
        }

        $this->sendProductToComanda($product, $optionSelections);

        if ($this->getErrorBag()->isEmpty()) {
            $this->dispatch('product-added-to-cart', name: $product->name);
        }
    }

    public function backToCatalog(): void
    {
        $this->closingTabOrderId = null;
        $this->step = 'catalog';
        $this->resetPaymentState();
    }

    /** Confirma o fechamento da comanda em pagamento — wrapper público pro closeTab() privado de HasOpenTabs. */
    public function confirmCloseTab(): void
    {
        $this->closeTab();
    }

    public function resetTerminal(): void
    {
        $this->customerQuery = '';
        $this->customerName = '';
        $this->customerId = null;
        $this->customerFound = false;
        $this->customerResults = [];
        $this->lastOrderNumber = null;
        $this->lastOrderTotal = null;
        $this->lastOrderId = null;
        $this->changeAmount = 0.0;
        $this->notes = '';
        $this->step = 'catalog';
        $this->showSessionHistory = false;
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
        return view('livewire.admin.pdv.tab-terminal')
            ->layout('layouts.app.pdv');
    }
}
