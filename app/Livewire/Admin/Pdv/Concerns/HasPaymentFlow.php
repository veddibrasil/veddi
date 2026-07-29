<?php

namespace App\Livewire\Admin\Pdv\Concerns;

use App\Events\NewOrderPlaced;
use App\Events\OrderStatusUpdated;
use App\Models\Branch;
use App\Services\Order\OrderService;
use App\Services\Payment\PaymentOrchestrator;
use Illuminate\Support\Facades\DB;

trait HasPaymentFlow
{
    public function proceedToPayment(): void
    {
        abort_unless(! $this->isWaiter, 403);

        if (empty($this->cart) || ! $this->selectedBranchId) {
            return;
        }

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

    public function processOrder(): void
    {
        abort_unless(! $this->isWaiter, 403);

        if (empty($this->cart) || ! $this->selectedBranchId) {
            return;
        }

        if ($this->deliveryType === 'entrega' && ! $this->customerId) {
            $this->addError('order', 'Selecione ou cadastre um cliente para entrega.');

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

        $orderCart = $this->buildOrderCart();

        DB::beginTransaction();
        try {
            $isPaidOnCreate = in_array($this->paymentMethod, ['cash', 'credit_card', 'pix'])
                && ! ($this->deliveryType === 'entrega' && $this->deliveryPaymentStatus === 'on_delivery');

            // Pedido agendado: pagamento pode ser coletado agora, mas o status fica 'scheduled'
            // (não 'paid') pra não disparar preparo/nota fiscal antes da hora combinada — mesma
            // convenção usada no agendamento do chat público.
            $status = $isPaidOnCreate
                ? ($scheduledAt ? 'scheduled' : 'paid')
                : 'awaiting_payment';

            $order = app(OrderService::class)->createOrder(
                customerId: $customerId,
                branchId: $this->selectedBranchId,
                cart: $orderCart,
                notes: $this->notes,
                paymentMethod: $this->paymentMethod,
                orderType: 'pdv',
                status: $status,
                deliveryFee: $this->deliveryFeeAmount,
                scheduledAt: $scheduledAt,
                extraDiscount: $this->manualDiscountAmount,
                serviceFee: $this->serviceFeeAmount,
                couvertFee: $this->couvertFeeAmount,
            );

            // Link order to current cash session
            if ($this->cashSessionId) {
                $order->pdv_cash_session_id = $this->cashSessionId;
                $order->save();
            }

            if ($this->deliveryType === 'entrega') {
                $order->delivery_address = $this->deliveryAddress;
                $order->delivery_number = $this->deliveryNumber;
                $order->delivery_complement = $this->deliveryComplement;
                $order->delivery_neighborhood = $this->deliveryNeighborhood;
                $order->delivery_city = $this->deliveryCity;
                $order->delivery_cep = $this->deliveryCep;
                $order->save();
            }

            if ($isPaidOnCreate) {
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
            }

            DB::commit();

            // Fora da transação: pedido nasce 'paid' aqui (à vista/cartão/pix no PDV, quando não
            // agendado) e nunca passa por outra transição depois — sem isso a nota fiscal automática
            // nunca dispara. Se agendado, status é 'scheduled' e o listener de nota fiscal ignora.
            if ($isPaidOnCreate) {
                OrderStatusUpdated::dispatch($order);
            }

            // Notifica cozinha/bar (mesmo canal usado pro chat) — sem isso quem só opera pelo PDV
            // não sabe que um pedido novo chegou pra preparar.
            NewOrderPlaced::dispatch($order->load('customer'));

            $this->lastOrderTotal = (float) $order->total;
            $this->audit('order_created', [
                'order_id' => $order->id,
                'amount' => (float) $order->total,
                'metadata' => [
                    'payment_method' => $this->paymentMethod,
                    'cart_count' => $this->cartCount,
                    'manual_discount' => $this->manualDiscountAmount,
                ],
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->addError('order', $e->getMessage());

            return;
        }

        $this->lastOrderNumber = $order->order_number;
        $this->lastOrderId = $order->id;
        $this->step = 'success';
    }
}
