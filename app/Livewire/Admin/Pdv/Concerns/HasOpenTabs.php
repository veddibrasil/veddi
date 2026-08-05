<?php

namespace App\Livewire\Admin\Pdv\Concerns;

use App\Events\NewOrderPlaced;
use App\Events\OrderStatusUpdated;
use App\Events\TabItemsReadyForProduction;
use App\Events\TabOrderSentToProduction;
use App\Models\BranchPrinter;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\RestaurantTable;
use App\Services\Order\OrderService;
use App\Services\Payment\PaymentOrchestrator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;

trait HasOpenTabs
{
    use HasAutoPrint;

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
        $this->tabCustomerName = '';
        $this->openingNewTabForTable = false;
    }

    public function selectOpenTab(int $orderId): void
    {
        $this->openTabOrderId = $orderId;
    }

    public function toggleTabItems(int $orderId): void
    {
        $this->viewingTabItemsOrderId = $this->viewingTabItemsOrderId === $orderId ? null : $orderId;
    }

    public function deselectOpenTab(): void
    {
        $this->openTabOrderId = null;
        $this->selectedTableId = null;
        $this->tabCustomerName = '';
        $this->openingNewTabForTable = false;
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

    /**
     * Lança um produto direto na comanda — chamado no clique do catálogo quando o modo é 'mesa'.
     * Sem mesa/comanda aberta ainda, cria a comanda já com esse primeiro item (e mantém selecionada,
     * diferente de openTab(), pra continuar recebendo cliques direto no catálogo).
     */
    public function sendProductToComanda(Product $product, array $optionSelections = []): void
    {
        if (! $this->selectedBranchId) {
            return;
        }

        try {
            if ($this->openTabOrderId) {
                $order = Order::withoutGlobalScopes()
                    ->where('is_open_tab', true)
                    ->find($this->openTabOrderId);

                if (! $order) {
                    $this->addError('order', 'Comanda não encontrada ou já fechada.');

                    return;
                }

                app(OrderService::class)->addOrIncrementItem($order, $product, $optionSelections);
            } else {
                $table = $this->availableTables->firstWhere('id', $this->selectedTableId);

                if (! $table) {
                    $this->addError('selectedTableId', 'Selecione uma mesa disponível.');

                    return;
                }

                $company = app('current.company');
                $customerId = $this->resolveCustomerId($company);

                $order = app(OrderService::class)->createOrder(
                    customerId: $customerId,
                    branchId: $this->selectedBranchId,
                    cart: [(string) $product->id => ['product_id' => $product->id, 'qty' => 1, 'options' => $optionSelections]],
                    notes: $this->notes,
                    paymentMethod: '',
                    orderType: 'pdv',
                    status: 'pending',
                );

                $order->update([
                    'pdv_cash_session_id' => $this->cashSessionId,
                    'is_open_tab' => true,
                    'table_label' => $this->buildTabLabel($table, $this->tabCustomerName),
                    'restaurant_table_id' => $table->id,
                ]);

                // Preços já sobem sem taxa de serviço/couvert em createOrder() acima — recalcula
                // a partir do item real recém-criado pra aplicar a taxa da filial corretamente.
                app(OrderService::class)->applyManualDiscountToOrder($order, 0.0);

                $this->openTabOrderId = $order->id;

                // Notifica cozinha/bar já na abertura da comanda — mesmo canal usado pro PDV
                // balcão e pro chat.
                NewOrderPlaced::dispatch($order->load('customer'));

                $this->audit('tab_opened', [
                    'order_id' => $order->id,
                    'amount' => (float) $order->total,
                    'reason' => $order->table_label,
                ]);
            }
        } catch (\Throwable $e) {
            $this->addError('stock', $e->getMessage());

            return;
        }

        $this->notifyPendingProductionItems($order);

        unset($this->openTabs);
        unset($this->availableTables);
    }

    /**
     * Item novo lançado (ou incrementado) na comanda — se a filial tem impressora com
     * auto_print ativo em cozinha/bar, avisa a produção sozinha, sem esperar o
     * garçom clicar em "Finalizar Pedido". A quantidade é marcada como enviada
     * aqui mesmo, antes do broadcast: decidir "o que ainda não foi impresso" tem
     * que ser atômico nesta requisição — se ficasse pra quem recebe o broadcast
     * recalcular depois, duas telas de PDV abertas na mesma filial poderiam
     * calcular pendências diferentes ou brigar por quem consome o item primeiro.
     */
    private function notifyPendingProductionItems(Order $order): void
    {
        $items = OrderItem::where('order_id', $order->id)->get();
        $stationItems = [];

        foreach (['cozinha', 'bar'] as $station) {
            $pending = $items
                ->map(fn (OrderItem $item) => ['item' => $item, 'qty' => $item->pendingQuantityForStation($station)])
                ->filter(fn (array $entry) => $entry['qty'] > 0);

            if ($pending->isEmpty()) {
                continue;
            }

            $hasAutoPrintPrinter = BranchPrinter::where('branch_id', $order->branch_id)
                ->where('station', $station)
                ->where('auto_print', true)
                ->where('active', true)
                ->exists();

            if (! $hasAutoPrintPrinter) {
                continue;
            }

            $sentColumn = $station === 'cozinha' ? 'kitchen_sent_quantity' : 'bar_sent_quantity';

            foreach ($pending as $entry) {
                $entry['item']->update([$sentColumn => $entry['item']->quantity]);
            }

            $stationItems[$station] = $pending
                ->map(fn (array $entry) => ['id' => $entry['item']->id, 'qty' => $entry['qty']])
                ->values()
                ->all();
        }

        if ($stationItems === []) {
            return;
        }

        TabItemsReadyForProduction::dispatch($order, $stationItems);
    }

    /**
     * Envia a comanda pra produção — dispara a impressão da via completa (sem
     * filtro por categoria) em CADA impressora ativa da filial nas estações
     * geral/cozinha/bar (mesmo pedido inteiro em todas, não uma por categoria),
     * que cozinha/bar usam pra preparo e o garçom usa pra levar até a mesa certa
     * (já traz o table_label no cabeçalho do cupom). Fica de fora a estação
     * 'entrega' — não faz sentido pra pedido de mesa.
     * Disponível pro garçom: é o passo dele, não do caixa — não fecha nem cobra a
     * comanda, só avisa a produção. Pode ser chamado de novo se entrar item depois
     * (reimprime o pedido completo; não há rastreio de "já enviado" por item).
     */
    public function finalizeOrder(): void
    {
        if (! $this->openTabOrderId) {
            return;
        }

        $order = Order::withoutGlobalScopes()
            ->where('is_open_tab', true)
            ->find($this->openTabOrderId);

        if (! $order) {
            $this->addError('order', 'Comanda não encontrada ou já fechada.');

            return;
        }

        if (! OrderItem::where('order_id', $order->id)->exists()) {
            return;
        }

        $stations = $order->branch->printers()
            ->where('active', true)
            ->whereIn('station', ['geral', 'cozinha', 'bar'])
            ->get(['station'])
            ->pluck('station')
            ->values();

        // Broadcast em vez de dispatch local: quem clica "Finalizar Pedido" pode ser o
        // garçom no celular, sem QZ Tray nenhum. Quem tem que imprimir é a tela de PDV
        // que estiver com a impressora de verdade pareada — não necessariamente esta.
        if ($stations->isNotEmpty()) {
            TabOrderSentToProduction::dispatch($order, $stations->all());
        }

        $this->dispatch('pdv-toast', message: "Pedido de {$order->table_label} enviado pra produção.");

        $this->audit('tab_order_finalized', [
            'order_id' => $order->id,
            'reason' => $order->table_label,
        ]);

        // Comanda continua aberta (só foi enviada pra produção, não fechada/paga) — volta
        // pra seleção de mesas/comandas em vez de travar o operador na comanda atual, mesmo
        // comportamento de "voltar pra próxima" já usado em closeTab().
        $this->openTabOrderId = null;
        $this->selectedTableId = null;
        $this->tabCustomerName = '';
        $this->openingNewTabForTable = false;
    }

    /** Remove um item já lançado da comanda ativa. Disponível para garçom e caixa. */
    public function removeTabItem(int $orderItemId): void
    {
        if (! $this->openTabOrderId) {
            return;
        }

        $order = Order::withoutGlobalScopes()
            ->where('is_open_tab', true)
            ->find($this->openTabOrderId);

        if (! $order) {
            $this->addError('order', 'Comanda não encontrada ou já fechada.');

            return;
        }

        $item = OrderItem::where('order_id', $order->id)->find($orderItemId);

        if (! $item) {
            return;
        }

        try {
            app(OrderService::class)->removeItemFromOrder($order, $item);
        } catch (\Throwable $e) {
            $this->addError('order', $e->getMessage());

            return;
        }

        $this->audit('tab_item_removed', [
            'order_id' => $order->id,
            'reason' => $item->product_name,
        ]);

        unset($this->openTabs);
    }

    /** Ajusta a quantidade de um item já lançado na comanda ativa. Disponível para garçom e caixa. */
    public function updateTabItemQuantity(int $orderItemId, int $quantity): void
    {
        if (! $this->openTabOrderId) {
            return;
        }

        $order = Order::withoutGlobalScopes()
            ->where('is_open_tab', true)
            ->find($this->openTabOrderId);

        if (! $order) {
            $this->addError('order', 'Comanda não encontrada ou já fechada.');

            return;
        }

        $item = OrderItem::where('order_id', $order->id)->find($orderItemId);

        if (! $item) {
            return;
        }

        try {
            app(OrderService::class)->updateOrderItemQuantity($order, $item, $quantity);
        } catch (\Throwable $e) {
            $this->addError('order', $e->getMessage());

            return;
        }

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
                $this->dispatchAutoPrintPayload($order, includeReceiptStations: false);
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
        // Mesmo comportamento do Terminal (caixa livre): não trava numa tela de
        // sucesso — volta direto pra seleção de comandas (mesaNotCommitted volta a
        // true) e o card flutuante do header mostra o resultado por cima, sem
        // bloquear o operador de já abrir a próxima mesa.
        $this->selectedTableId = null;
        $this->step = 'catalog';
    }

    /**
     * Rótulo da comanda: com nome informado, "Mesa N · Nome"; sem nome, mesa livre vira só
     * "Mesa N" e mesa que já tem comanda(s) aberta(s) ganha sufixo sequencial "Mesa N (2)" —
     * evita dois cards idênticos quando mais de uma comanda abre na mesma mesa.
     */
    private function buildTabLabel(RestaurantTable $table, string $customerName): string
    {
        $name = trim($customerName);

        if ($name !== '') {
            return "Mesa {$table->number} · {$name}";
        }

        $openCount = Order::withoutGlobalScopes()
            ->where('restaurant_table_id', $table->id)
            ->where('is_open_tab', true)
            ->count();

        return $openCount > 0 ? "Mesa {$table->number} (".($openCount + 1).')' : "Mesa {$table->number}";
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
            ->get(['id', 'order_number', 'table_label', 'subtotal', 'service_fee', 'couvert_fee', 'manual_discount', 'total', 'restaurant_table_id']);
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

    /**
     * Mesas pré-cadastradas da filial que a empresa optou por usar. Inclui mesa já ocupada por
     * comanda aberta — clicar nela abre uma comanda adicional, independente das existentes (ver
     * {@see buildTabLabel} pra como cada uma fica identificada).
     */
    #[Computed]
    public function availableTables(): Collection
    {
        if (! $this->selectedBranchId) {
            return collect();
        }

        return RestaurantTable::where('branch_id', $this->selectedBranchId)
            ->where('active', true)
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
