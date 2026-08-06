<?php

namespace App\Livewire\Admin\Pdv\Concerns;

use App\Events\NewOrderPlaced;
use App\Events\OrderStatusUpdated;
use App\Events\TabOrderSentToProduction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\RestaurantTable;
use App\Services\Order\OrderService;
use App\Services\Payment\PaymentOrchestrator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

        unset($this->openTabs);
        unset($this->availableTables);
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
            Log::channel('orders')->info('[auto-print] Finalizar Pedido: disparando TabOrderSentToProduction', [
                'order_id' => $order->id,
                'branch_id' => $order->branch_id,
                'stations' => $stations->all(),
            ]);

            TabOrderSentToProduction::dispatch($order, $stations->all());
        } else {
            Log::channel('orders')->info('[auto-print] Finalizar Pedido: nenhuma impressora ativa na filial, não broadcasta', [
                'order_id' => $order->id,
                'branch_id' => $order->branch_id,
            ]);
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

    /**
     * Pega todas as comandas abertas de uma mesa pra pagar/dividir tudo junto num modal só.
     * Já entra com o split pré-preenchido, uma parte por comanda no valor dos itens dela — a
     * taxa de serviço/couvert (única por mesa, calculada uma vez sobre o subtotal combinado)
     * entra inteira na primeira parte, não em cada comanda. O operador só ajusta o método (ou
     * o valor, se quiser combinar diferente) antes de confirmar.
     */
    public function proceedToCloseTableTabs(int $tableId): void
    {
        abort_unless(! $this->isWaiter, 403);

        $this->closingTableId = $tableId;
        $this->step = 'payment';
        $this->resetPaymentState();

        $tableFees = $this->serviceFeeAmount + $this->couvertFeeAmount;

        $this->isSplitPayment = true;
        $this->splitPayments = $this->closingTableOrders->values()->map(function (Order $order, int $index) use ($tableFees) {
            $amount = number_format((float) $order->subtotal + ($index === 0 ? $tableFees : 0.0), 2, '.', '');

            return [
                'method' => $index === 0 ? 'cash' : 'credit_card',
                'amount' => $amount,
                'cash_received' => $index === 0 ? $amount : '',
                'paid' => false,
            ];
        })->all();
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

        if ($this->isSplitPayment) {
            if ($error = $this->validateSplitPayments()) {
                $this->addError('order', $error);

                return;
            }
        }

        DB::beginTransaction();
        try {
            $isPaidOnCreate = $this->isSplitPayment || in_array($this->paymentMethod, ['cash', 'credit_card', 'pix']);

            app(OrderService::class)->applyManualDiscountToOrder(
                $order,
                $this->manualDiscountAmount,
                $this->serviceFeeWaived,
                $this->couvertFeeWaived,
            );

            $order->update([
                'payment_method' => $this->effectivePaymentMethod(),
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

            if ($this->isSplitPayment) {
                $parts = $this->buildSplitPartsForOrchestrator();
                $cashPart = collect($parts)->firstWhere('method', 'cash');

                if ($cashPart) {
                    $order->cash_received = $cashPart['cash_received'];
                    $order->cash_change = max(0.0, round($cashPart['cash_received'] - $cashPart['amount'], 2));
                    $order->save();
                }

                $results = app(PaymentOrchestrator::class)->processSplit($order, $parts);
                $this->changeAmount = collect($results)->sum('change');
            } elseif ($this->paymentMethod === 'cash') {
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
                'metadata' => ['payment_method' => $this->effectivePaymentMethod()],
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
     * Fecha e paga TODAS as comandas abertas de uma mesa de uma vez, num único pagamento
     * (dividido ou não). Cada comanda continua sendo um Order separado — o que muda é só
     * o momento da cobrança. As partes do pagamento (a soma bate com o total combinado)
     * são consumidas em sequência, uma comanda de cada vez, até cobrir o total de cada uma
     * (como um caixa contando dinheiro): evita fragmentar toda comanda em pedacinho de cada
     * método. Taxa de serviço/couvert é única por mesa (calculada uma vez sobre o subtotal
     * combinado, não somada comanda por comanda) e entra inteira na primeira comanda do grupo;
     * as demais ficam só com o próprio subtotal. Isenção de taxa (mesmos toggles do fechamento
     * individual) se aplica à mesa toda. Não mexe em desconto manual — isso continua sendo
     * feito comanda por comanda, antes de agrupar.
     */
    private function closeTableTabs(): void
    {
        abort_unless(! $this->isWaiter, 403);

        $orders = $this->closingTableOrders;

        if ($orders->isEmpty()) {
            $this->addError('order', 'Nenhuma comanda aberta encontrada para esta mesa.');
            $this->closingTableId = null;

            return;
        }

        if ($this->isSplitPayment) {
            if ($error = $this->validateSplitPayments()) {
                $this->addError('order', $error);

                return;
            }
        }

        // Pool de partes em centavos — evita erro de arredondamento de float ao fatiar
        // o pagamento combinado entre N comandas. Pagamento único vira um "split" de 1
        // parte só, reaproveitando o mesmo mecanismo de distribuição.
        $pool = $this->isSplitPayment
            ? array_map(fn ($part) => [
                'method' => $part['method'],
                'cents' => (int) round($part['amount'] * 100),
                'cash_received_cents' => array_key_exists('cash_received', $part) ? (int) round($part['cash_received'] * 100) : null,
            ], $this->buildSplitPartsForOrchestrator())
            : [[
                'method' => $this->paymentMethod,
                'cents' => (int) round($this->cartTotalAfterDiscount * 100),
                'cash_received_cents' => $this->paymentMethod === 'cash'
                    ? (int) round((float) str_replace(',', '.', $this->cashReceivedInput ?: $this->cartTotalAfterDiscount) * 100)
                    : null,
            ]];

        // Capturado antes do loop: taxa única da mesa (subtotal combinado, taxa calculada uma
        // vez), pra não recalcular em cima de totais já mutados comanda a comanda no meio dele.
        $tableServiceFee = $this->serviceFeeAmount;
        $tableCouvertFee = $this->couvertFeeAmount;

        DB::beginTransaction();
        try {
            $closedOrders = [];
            $totalChangeCents = 0;
            $lastMethod = $pool[0]['method'];

            foreach ($orders as $orderIndex => $order) {
                $order = app(OrderService::class)->applyGroupFeesToOrder(
                    $order,
                    $orderIndex === 0 ? $tableServiceFee : 0.0,
                    $orderIndex === 0 ? $tableCouvertFee : 0.0,
                );

                $orderCents = (int) round((float) $order->total * 100);
                $orderParts = [];

                while ($orderCents > 0) {
                    if ($pool === []) {
                        // Defesa contra resíduo de 1 centavo por arredondamento de float —
                        // não deveria acontecer (validateSplitPayments já garante soma bater),
                        // mas evita RuntimeException no processSplit por diferença ínfima.
                        $orderParts[] = ['method' => $lastMethod, 'amount' => $orderCents / 100];
                        $orderCents = 0;

                        break;
                    }

                    $take = min($pool[0]['cents'], $orderCents);
                    $piece = ['method' => $pool[0]['method'], 'amount' => $take / 100];
                    $lastMethod = $pool[0]['method'];

                    if ($pool[0]['method'] === 'cash') {
                        // Só a fatia que esgota a parte cash do pool carrega o "recebido" de
                        // verdade — troco só existe uma vez, no fim do dinheiro do pool.
                        $isLastCashSlice = $take === $pool[0]['cents'];
                        $piece['cash_received'] = $isLastCashSlice
                            ? ($pool[0]['cash_received_cents'] ?? $pool[0]['cents']) / 100
                            : $take / 100;
                    }

                    $orderParts[] = $piece;

                    $pool[0]['cents'] -= $take;
                    if ($pool[0]['cash_received_cents'] !== null) {
                        $pool[0]['cash_received_cents'] -= $take;
                    }
                    $orderCents -= $take;

                    if ($pool[0]['cents'] <= 0) {
                        array_shift($pool);
                    }
                }

                $cashPiece = collect($orderParts)->firstWhere('method', 'cash');
                if ($cashPiece) {
                    $order->cash_received = $cashPiece['cash_received'];
                    $order->cash_change = max(0.0, round($cashPiece['cash_received'] - $cashPiece['amount'], 2));
                    $totalChangeCents += (int) round($order->cash_change * 100);
                }

                $order->update([
                    'payment_method' => $this->effectivePaymentMethod(),
                    'status' => 'paid',
                    'is_open_tab' => false,
                    'pdv_cash_session_id' => $this->cashSessionId,
                    ...($this->customerId ? ['customer_id' => $this->customerId] : []),
                ]);

                app(PaymentOrchestrator::class)->processSplit($order, $orderParts);

                $closedOrders[] = $order;
            }

            DB::commit();

            foreach ($closedOrders as $order) {
                OrderStatusUpdated::dispatch($order);
                $this->dispatchAutoPrintPayload($order, includeReceiptStations: false);
            }

            $this->changeAmount = $totalChangeCents / 100;
            $this->lastOrderTotal = (float) collect($closedOrders)->sum('total');
            $this->lastOrderNumber = $closedOrders[0]->order_number;
            $this->lastOrderId = $closedOrders[0]->id;

            foreach ($closedOrders as $order) {
                $this->audit('tab_closed', [
                    'order_id' => $order->id,
                    'amount' => (float) $order->total,
                    'metadata' => ['payment_method' => $this->effectivePaymentMethod(), 'table_group' => true],
                ]);
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->addError('order', $e->getMessage());

            return;
        }

        $this->dispatch('pdv-toast', message: count($closedOrders).' comanda(s) da mesa fechada(s) e pagas.');

        $this->openTabOrderId = null;
        $this->closingTableId = null;
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

    /** Todas as comandas abertas da mesa em fechamento em grupo — memoizado (usado nos totais e no closeTableTabs()). */
    #[Computed]
    public function closingTableOrders(): Collection
    {
        if (! $this->closingTableId) {
            return collect();
        }

        return Order::withoutGlobalScopes()
            ->with('items')
            ->where('branch_id', $this->selectedBranchId)
            ->where('restaurant_table_id', $this->closingTableId)
            ->where('is_open_tab', true)
            ->orderBy('id')
            ->get();
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
