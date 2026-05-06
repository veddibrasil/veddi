<?php

namespace App\Livewire\Admin\Orders;

use App\Contracts\RefundServiceInterface;
use App\Events\OrderStatusUpdated;
use App\Models\Order;
use App\Services\Order\OrderCancellationPolicy;
use App\Services\Order\StockService;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

class Show extends Component
{
    public Order $order;

    public bool $canUpdate = false;

    public bool $showManualRefundModal = false;

    public string $manualRefundType = 'gateway'; // 'gateway' | 'offline'

    public string $manualRefundJustification = '';

    public function mount(): void
    {
        $user = auth()->user();

        if ($user->isSuperAdmin()) {
            $this->canUpdate = true;
        } elseif (app()->bound('current.company')) {
            $this->canUpdate = $user->hasPermission('orders.update', app('current.company'));
        }

    }

    public function updateStatus(string $status): void
    {
        abort_unless($this->canUpdate, 403);

        $allowed = ['pending', 'awaiting_payment', 'paid', 'preparing', 'ready', 'delivered', 'cancelled'];

        if (! in_array($status, $allowed)) {
            $this->addError('status', 'Status inválido.');

            return;
        }

        try {
            if ($status === 'cancelled') {
                app(OrderCancellationPolicy::class)->authorizeAdminCancel($this->order);
            }
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        $previousStatus = $this->order->status;

        $this->order->update(['status' => $status]);

        if ($status === 'cancelled' && $previousStatus !== 'cancelled') {
            app(StockService::class)->restoreForOrder($this->order);
        }
        $this->order->refresh();

        OrderStatusUpdated::dispatch($this->order);

        Log::channel('orders')->info('Status do pedido alterado pelo admin', [
            'order_id' => $this->order->id,
            'admin_id' => auth()->id(),
            'status_anterior' => $previousStatus,
            'status_novo' => $status,
        ]);

        session()->flash('status', 'Status atualizado.');
    }

    public function openManualRefundModal(): void
    {
        abort_unless($this->canUpdate, 403);

        $this->order->loadMissing('payment');

        if (! $this->order->payment || $this->order->payment->status !== 'paid') {
            session()->flash('error', 'Pagamento não elegível para reembolso.');

            return;
        }

        $this->manualRefundType = 'gateway';
        $this->manualRefundJustification = '';
        $this->showManualRefundModal = true;
    }

    public function closeManualRefundModal(): void
    {
        $this->showManualRefundModal = false;
    }

    public function manualRefund(): void
    {
        abort_unless($this->canUpdate, 403);

        $this->validate([
            'manualRefundType' => ['required', 'in:gateway,offline'],
            'manualRefundJustification' => $this->manualRefundType === 'offline'
                ? ['required', 'string', 'min:10']
                : ['nullable'],
        ]);

        $this->order->loadMissing('payment');

        $payment = $this->order->payment;

        if (! $payment || $payment->status !== 'paid') {
            session()->flash('error', 'Pagamento não elegível para reembolso.');
            $this->showManualRefundModal = false;

            return;
        }

        if ($this->order->status !== 'cancelled') {
            $this->order->update(['status' => 'cancelled']);
            $this->order->refresh();
            app(StockService::class)->restoreForOrder($this->order);
            OrderStatusUpdated::dispatch($this->order);
        }

        $reason = $this->manualRefundType === 'offline'
            ? 'store_issue'
            : 'customer_request';

        $refund = app(RefundServiceInterface::class)->initiateRefund(
            $this->order,
            $payment,
            (float) $payment->amount,
            'admin',
            auth()->id(),
            $reason,
        );

        if ($this->manualRefundType === 'offline') {
            // Mark immediately as succeeded — no gateway call needed
            app(RefundServiceInterface::class)->markSucceeded($refund, [
                'external_refund_id' => null,
                'external_status' => 'OFFLINE',
                'raw' => ['justification' => $this->manualRefundJustification],
            ]);
        }

        Log::channel('payments')->info('Reembolso manual iniciado pelo admin', [
            'order_id' => $this->order->id,
            'admin_id' => auth()->id(),
            'refund_id' => $refund->id,
            'type' => $this->manualRefundType,
            'amount' => $payment->amount,
        ]);

        $this->showManualRefundModal = false;
        session()->flash('status', $this->manualRefundType === 'offline'
            ? 'Reembolso offline registrado com sucesso.'
            : 'Reembolso via gateway iniciado. Acompanhe o status abaixo.'
        );
    }

    public function render()
    {
        return view('livewire.admin.orders.show')
            ->layout('layouts.app', ['title' => "Pedido {$this->order->order_number}"]);
    }
}
