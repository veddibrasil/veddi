<?php

namespace App\Livewire\Admin\Pdv\Concerns;

use App\Events\NewOrderPlaced;
use App\Events\OrderStatusUpdated;
use App\Models\Branch;
use App\Services\Order\OrderService;
use Illuminate\Support\Facades\DB;

trait HasPaymentFlow
{
    use HasAutoPrint;
    use HasPaymentSettlement;

    // Guarda contra double-submit: se duas chamadas a processOrder() caírem na mesma
    // requisição (ex.: F10 e clique no botão disparados no mesmo tick, que o Livewire
    // agrupa numa única requisição), a segunda precisa ser barrada aqui — senão os dois
    // criam pedido e nota fiscal duplicados de verdade. Não cobre duas requisições HTTP
    // genuinamente concorrentes (cada uma hidrata sua própria instância a partir do mesmo
    // snapshot); isso já é mitigado no client travando o F10 junto com o botão desabilitado.
    public bool $processingOrder = false;

    public function proceedToPayment(): void
    {
        abort_unless(! $this->isWaiter, 403);

        if (empty($this->cart) || ! $this->selectedBranchId) {
            return;
        }

        $this->assertSelectedBranchBelongsToCurrentCompany();

        $this->step = 'payment';
        $this->resetPaymentState();
        $this->resetDeliveryState();
        $this->resetScheduleState();
    }

    public function backToCatalog(): void
    {
        $this->step = 'catalog';
        $this->resetPaymentState();
        $this->resetDeliveryState();
        $this->resetScheduleState();
    }

    /**
     * `$cashReceived` é o valor que está no campo "Valor recebido" no instante do clique/F10. O campo
     * sincroniza com debounce (`wire:model.live`); sem isso, confirmar antes do debounce vencer
     * gravava o valor antigo (ou vazio = "exato") como dinheiro recebido.
     */
    public function processOrder(?string $cashReceived = null): void
    {
        abort_unless(! $this->isWaiter, 403);

        $this->syncCashReceivedFromClient($cashReceived);

        if ($this->processingOrder) {
            return;
        }

        if (empty($this->cart) || ! $this->selectedBranchId) {
            return;
        }

        $this->assertSelectedBranchBelongsToCurrentCompany();

        $this->processingOrder = true;

        try {
            $this->doProcessOrder();
        } finally {
            $this->processingOrder = false;
        }
    }

    private function doProcessOrder(): void
    {
        if (in_array($this->deliveryType, ['entrega', 'retirar']) && ! $this->customerId) {
            $this->addError('order', $this->deliveryType === 'entrega'
                ? 'Selecione ou cadastre um cliente para entrega.'
                : 'Selecione ou cadastre um cliente para retirada.');

            return;
        }

        if ($this->deliveryType === 'entrega') {
            $errors = $this->deliveryAddressErrors();

            if ($errors !== []) {
                $this->addError('order', implode(' ', $errors));

                return;
            }
        }

        if ($this->isScheduled && ! $this->customerId) {
            $this->addError('order', 'Selecione ou cadastre um cliente para agendar o pedido.');

            return;
        }

        $branch = Branch::find($this->selectedBranchId);
        $scheduledAt = $branch ? $this->resolveScheduledAt($branch) : null;

        if ($this->isScheduled && ! $scheduledAt) {
            return;
        }

        $company = app('current.company');
        $customerId = $this->resolveCustomerId($company);

        if ($this->isSplitPayment) {
            if ($this->deliveryType === 'entrega' && $this->deliveryPaymentStatus === 'on_delivery') {
                $this->addError('order', 'Split não está disponível para pagamento coletado na entrega.');

                return;
            }

            if ($this->deliveryType === 'retirar' && $this->pickupPaymentStatus === 'on_pickup') {
                $this->addError('order', 'Split não está disponível para pagamento coletado na retirada.');

                return;
            }

            if ($error = $this->validateSplitPayments()) {
                $this->addError('order', $error);

                return;
            }
        }

        if ($error = $this->manualDiscountError($company)) {
            $this->addError('order', $error);

            return;
        }

        $orderCart = $this->buildOrderCart();

        $isPaidOnCreate = ($this->isSplitPayment || in_array($this->paymentMethod, ['cash', 'credit_card', 'pix']))
            && ! ($this->deliveryType === 'entrega' && $this->deliveryPaymentStatus === 'on_delivery')
            && ! ($this->deliveryType === 'retirar' && $this->pickupPaymentStatus === 'on_pickup');

        // Pedido agendado: pagamento pode ser coletado agora, mas o status fica 'scheduled'
        // (não 'paid') pra não disparar preparo/nota fiscal antes da hora combinada — mesma
        // convenção usada no agendamento do chat público.
        $status = $isPaidOnCreate
            ? ($scheduledAt ? 'scheduled' : 'paid')
            : 'awaiting_payment';

        try {
            $order = DB::transaction(function () use ($customerId, $orderCart, $status, $scheduledAt, $isPaidOnCreate) {
                $order = app(OrderService::class)->createOrder(
                    customerId: $customerId,
                    branchId: $this->selectedBranchId,
                    cart: $orderCart,
                    notes: $this->notes,
                    paymentMethod: $this->effectivePaymentMethod(),
                    orderType: 'pdv',
                    status: $status,
                    deliveryFee: $this->effectiveDeliveryFee(),
                    scheduledAt: $scheduledAt,
                    extraDiscount: $this->manualDiscountAmount,
                    serviceFee: $this->serviceFeeAmount,
                    couvertFee: $this->couvertFeeAmount,
                );

                $order->delivery_type = $this->deliveryType;

                // Link order to current cash session
                if ($this->cashSessionId) {
                    $order->pdv_cash_session_id = $this->cashSessionId;
                }

                if ($this->deliveryType === 'entrega') {
                    $order->delivery_address = $this->deliveryAddress;
                    $order->delivery_number = $this->deliveryNumber;
                    $order->delivery_complement = $this->deliveryComplement;
                    $order->delivery_neighborhood = $this->deliveryNeighborhood;
                    $order->delivery_city = $this->deliveryCity;
                    $order->delivery_cep = $this->deliveryCep;
                }

                $order->save();

                if ($isPaidOnCreate) {
                    $this->settleOrderPayment($order);
                }

                $this->audit('order_created', [
                    'order_id' => $order->id,
                    'amount' => (float) $order->total,
                    'metadata' => [
                        'payment_method' => $this->effectivePaymentMethod(),
                        'cart_count' => $this->cartCount,
                        'manual_discount' => $this->manualDiscountAmount,
                    ],
                ]);

                return $order;
            });
        } catch (\Throwable $e) {
            $this->reportPaymentFailure($e);

            return;
        }

        // Fora da transação: pedido nasce 'paid' aqui (à vista/cartão/pix no PDV, quando não
        // agendado) e nunca passa por outra transição depois — sem isso a nota fiscal automática
        // nunca dispara. Se agendado, status é 'scheduled' e o listener de nota fiscal ignora.
        // Cada efeito é isolado: o pedido já foi gravado e pago, então uma falha aqui (Reverb fora
        // do ar, SEFAZ) não pode virar "erro ao processar" e induzir o operador a refazer a venda.
        if ($isPaidOnCreate) {
            $this->afterCommit('order_status_updated', $order, fn () => OrderStatusUpdated::dispatch($order));
        }

        // Notifica cozinha/bar (mesmo canal usado pro chat) — sem isso quem só opera pelo PDV
        // não sabe que um pedido novo chegou pra preparar.
        $this->afterCommit('new_order_broadcast', $order, fn () => NewOrderPlaced::dispatch($order->load('customer')));

        if ($isPaidOnCreate) {
            $this->afterCommit('auto_print', $order, fn () => $this->dispatchAutoPrintPayload($order));
        }

        $this->lastOrderTotal = (float) $order->total;

        $this->lastOrderNumber = $order->order_number;
        $this->lastOrderId = $order->id;

        // Não trava em tela de "sucesso": limpa o carrinho e já libera o catálogo pro
        // próximo pedido. O card flutuante (fora do fluxo de step) mostra o resultado
        // por cima, sem bloquear o caixa.
        $this->resetCartForNextOrder();
    }
}
