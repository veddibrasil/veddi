<?php

namespace App\Livewire\Admin\Pdv\Concerns;

use App\Events\OrderStatusUpdated;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\RestaurantTable;
use App\Services\Order\OrderService;
use App\Services\Payment\PaymentOrchestrator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;

trait HasOpenTabs
{
    public function updatedOrderMode(): void
    {
        if ($this->isWaiter) {
            $this->orderMode = 'mesa';
        }

        // Carrinho não é limpo aqui: trocar de modo é navegação, não deve derrubar
        // itens que o operador já selecionou — só some com remoção explícita ou
        // ao finalizar (adicionar à comanda / concluir pedido).
        $this->selectedTableId = null;
        $this->openTabOrderId = null;
        $this->viewingTabItemsOrderId = null;
    }

    public function selectOpenTab(int $orderId): void
    {
        $this->openTabOrderId = $orderId;
        $this->tabMessage = null;
    }

    public function toggleTabItems(int $orderId): void
    {
        $this->viewingTabItemsOrderId = $this->viewingTabItemsOrderId === $orderId ? null : $orderId;
    }

    public function deselectOpenTab(): void
    {
        $this->openTabOrderId = null;
        $this->selectedTableId = null;
    }

    /** Cadastro rápido de mesa direto do terminal, sem sair pra tela de configurações da filial. */
    public function registerTable(): void
    {
        abort_unless(! $this->isWaiter, 403);

        $this->resetValidation('newTableNumber');

        if (! $this->selectedBranchId) {
            return;
        }

        $number = (int) $this->newTableNumber;

        if ($number <= 0) {
            $this->addError('newTableNumber', 'Informe um número de mesa válido.');

            return;
        }

        $exists = RestaurantTable::where('branch_id', $this->selectedBranchId)
            ->where('number', $number)
            ->exists();

        if ($exists) {
            $this->addError('newTableNumber', "Mesa {$number} já está cadastrada.");

            return;
        }

        $company = app('current.company');

        $table = RestaurantTable::create([
            'company_id' => $company->id,
            'branch_id' => $this->selectedBranchId,
            'number' => $number,
            'active' => true,
        ]);

        $this->newTableNumber = '';
        $this->selectedTableId = $table->id;
        unset($this->availableTables);
    }

    public function openTab(): void
    {
        if (empty($this->cart) || ! $this->selectedBranchId) {
            return;
        }

        $this->resetValidation('selectedTableId');

        $table = $this->availableTables->firstWhere('id', $this->selectedTableId);

        if (! $table) {
            $this->addError('selectedTableId', 'Selecione uma mesa disponível.');

            return;
        }

        $label = 'Mesa '.$table->number;

        $company = app('current.company');
        $customerId = $this->resolveCustomerId($company);

        DB::beginTransaction();
        try {
            $order = app(OrderService::class)->createOrder(
                customerId: $customerId,
                branchId: $this->selectedBranchId,
                cart: $this->buildOrderCart(),
                notes: $this->notes,
                paymentMethod: '',
                orderType: 'pdv',
                status: 'pending',
                serviceFee: $this->serviceFeeAmount,
                couvertFee: $this->couvertFeeAmount,
            );

            $order->update([
                'pdv_cash_session_id' => $this->cashSessionId,
                'is_open_tab' => true,
                'table_label' => $label,
                'restaurant_table_id' => $table?->id,
            ]);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->addError('order', $e->getMessage());

            return;
        }

        $this->audit('tab_opened', [
            'order_id' => $order->id,
            'amount' => (float) $order->total,
            'reason' => $label,
        ]);

        $this->cart = [];
        $this->selectedTableId = null;
        $this->openTabOrderId = null;
        unset($this->openTabs);
        unset($this->availableTables);
    }

    /** Chamado tanto pelo botão "Adicionar" direto na lista de comandas (orderId explícito) quanto por "Enviar itens" na comanda ativa (usa $openTabOrderId). */
    public function addItemsToTab(?int $orderId = null): void
    {
        $orderId ??= $this->openTabOrderId;

        if (! $orderId) {
            return;
        }

        if (empty($this->cart)) {
            $this->addError('order', 'Selecione ao menos um item no catálogo antes de adicionar à comanda.');

            return;
        }

        $order = Order::withoutGlobalScopes()
            ->where('is_open_tab', true)
            ->find($orderId);

        if (! $order) {
            $this->addError('order', 'Comanda não encontrada ou já fechada.');

            return;
        }

        try {
            $order = app(OrderService::class)->addItemsToOrder($order, $this->buildOrderCart());
        } catch (\Throwable $e) {
            $this->addError('order', $e->getMessage());

            return;
        }

        $this->audit('tab_items_added', [
            'order_id' => $order->id,
            'amount' => (float) $order->total,
        ]);

        $this->cart = [];
        $this->tabMessage = "Itens adicionados à comanda \"{$order->table_label}\".";
        unset($this->openTabs);
    }

    public function proceedToCloseTab(int $orderId): void
    {
        abort_unless(! $this->isWaiter, 403);

        $this->closingTabOrderId = $orderId;
        $this->step = 'payment';
        $this->resetPaymentState();
    }

    private function closeTab(): void
    {
        abort_unless(! $this->isWaiter, 403);

        $order = Order::withoutGlobalScopes()
            ->where('branch_id', $this->selectedBranchId)
            ->where('is_open_tab', true)
            ->find($this->closingTabOrderId);

        if (! $order) {
            $this->addError('order', 'Comanda não encontrada ou já fechada.');
            $this->closingTabOrderId = null;

            return;
        }

        DB::beginTransaction();
        try {
            $isPaidOnCreate = in_array($this->paymentMethod, ['cash', 'credit_card', 'pix']);

            app(OrderService::class)->applyManualDiscountToOrder(
                $order,
                $this->manualDiscountAmount,
                $this->serviceFeeWaived,
                $this->couvertFeeWaived,
            );

            $order->update([
                'payment_method' => $this->paymentMethod,
                'status' => $isPaidOnCreate ? 'paid' : 'awaiting_payment',
                'is_open_tab' => false,
                'notes' => $this->notes,
                // Reatribui à sessão de quem está fechando/recebendo agora — a comanda pode ter
                // sido aberta sem sessão (garçom) ou por outro operador; sem isso o dinheiro
                // recebido não entra na conferência de caixa de quem realmente fechou.
                'pdv_cash_session_id' => $this->cashSessionId,
                // Comanda geralmente abre sem cliente vinculado (guest); se o mesário identificar
                // o cliente só agora no fechamento, precisa gravar — senão a busca/seleção feita
                // na tela de pagamento é descartada e o pedido fica com "Cliente Balcão".
                ...($this->customerId ? ['customer_id' => $this->customerId] : []),
            ]);

            if ($this->paymentMethod === 'cash') {
                $cashReceived = (float) str_replace(',', '.', $this->cashReceivedInput ?: $order->total);
                $order->cash_received = $cashReceived;
                $order->cash_change = max(0.0, round($cashReceived - (float) $order->total, 2));
                $order->save();

                $result = app(PaymentOrchestrator::class)->processCash($order);
                $this->changeAmount = $result['change'];
            } elseif ($this->paymentMethod === 'credit_card') {
                app(PaymentOrchestrator::class)->processCardMachine($order);
            } elseif ($this->paymentMethod === 'pix') {
                app(PaymentOrchestrator::class)->processPixManual($order);
            }

            DB::commit();

            // Fora da transação: fechar comanda com status 'paid' não passa por createOrder()
            // nem por nenhuma outra transição — sem isso a nota fiscal automática nunca dispara
            // pra pedidos pagos aqui (ver OrderService::createOrder() pro mesmo problema na criação).
            if ($isPaidOnCreate) {
                OrderStatusUpdated::dispatch($order);
            }

            $this->lastOrderTotal = (float) $order->total;
            $this->audit('tab_closed', [
                'order_id' => $order->id,
                'amount' => (float) $order->total,
                'metadata' => ['payment_method' => $this->paymentMethod],
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->addError('order', $e->getMessage());

            return;
        }

        $this->lastOrderNumber = $order->order_number;
        $this->lastOrderId = $order->id;
        $this->openTabOrderId = null;
        $this->closingTabOrderId = null;
        $this->step = 'success';
    }

    // ── Computed ──────────────────────────────────────────────────────────────

    /** Memoiza a comanda em fechamento — evita 3 finds() repetidos (cartTotal, service fee, couvert). */
    #[Computed]
    public function closingTabOrder(): ?Order
    {
        if (! $this->closingTabOrderId) {
            return null;
        }

        return Order::withoutGlobalScopes()->find($this->closingTabOrderId);
    }

    #[Computed]
    public function openTabs(): Collection
    {
        if (! $this->selectedBranchId) {
            return collect();
        }

        // Escopo por filial (não por sessão de caixa): comandas abertas precisam ficar visíveis
        // pra qualquer operador da filial — garçom sem sessão abre, caixa de outra sessão fecha.
        return Order::withoutGlobalScopes()
            ->where('branch_id', $this->selectedBranchId)
            ->where('is_open_tab', true)
            ->latest()
            ->get(['id', 'order_number', 'table_label', 'total', 'restaurant_table_id']);
    }

    #[Computed]
    public function viewingTabItems(): Collection
    {
        if (! $this->viewingTabItemsOrderId) {
            return collect();
        }

        return OrderItem::where('order_id', $this->viewingTabItemsOrderId)->get();
    }

    /** Itens da comanda atualmente selecionada — exibidos sem precisar de clique extra. */
    #[Computed]
    public function activeTabItems(): Collection
    {
        if (! $this->openTabOrderId) {
            return collect();
        }

        return OrderItem::where('order_id', $this->openTabOrderId)->get();
    }

    #[Computed]
    public function closingTabItems(): Collection
    {
        if (! $this->closingTabOrderId) {
            return collect();
        }

        return OrderItem::where('order_id', $this->closingTabOrderId)->get();
    }

    /** Mesas pré-cadastradas da filial que a empresa optou por usar (não estão ocupadas por comanda aberta). */
    #[Computed]
    public function availableTables(): Collection
    {
        if (! $this->selectedBranchId) {
            return collect();
        }

        $occupiedIds = $this->openTabs->pluck('restaurant_table_id')->filter()->all();

        return RestaurantTable::where('branch_id', $this->selectedBranchId)
            ->where('active', true)
            ->whereNotIn('id', $occupiedIds)
            ->orderBy('number')
            ->get();
    }

    /** Filial já tem ao menos uma mesa cadastrada — abrir mesa/comanda exige mesa pré-cadastrada. */
    #[Computed]
    public function branchUsesRegisteredTables(): bool
    {
        if (! $this->selectedBranchId) {
            return false;
        }

        return RestaurantTable::where('branch_id', $this->selectedBranchId)->exists();
    }
}
