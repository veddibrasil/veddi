<?php

namespace App\Livewire\Admin\Pdv\Concerns;

use App\Models\Order;
use App\Services\Order\OrderCancellationPolicy;
use App\Services\Order\StockService;
use Illuminate\Support\Facades\Log;

trait HasOrderCancellation
{
    public function cancelLastOrder(): void
    {
        if ($this->lastOrderId) {
            $this->cancelPdvOrder($this->lastOrderId);
            $this->confirmingCancelOrder = false;
        }
    }

    public function cancelPdvOrder(int $orderId): void
    {
        abort_unless(! $this->isWaiter, 403);

        $company = app('current.company');

        $order = Order::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('order_type', 'pdv')
            ->find($orderId);

        if (! $order || in_array($order->status, ['cancelled', 'refunded'])) {
            $this->addError('cancel', 'Pedido não encontrado ou já cancelado.');

            return;
        }

        // Allow cancel for any order linked to current session, or fallback to branch + session time
        $isSessionOrder = $this->cashSessionId && (
            $order->pdv_cash_session_id === $this->cashSessionId
            || ($order->branch_id === $this->selectedBranchId && $this->cashSession && $order->created_at >= $this->cashSession->created_at)
        );

        if (! $isSessionOrder) {
            $this->addError('cancel', 'Pedido não pertence à sessão atual.');

            return;
        }

        try {
            app(OrderCancellationPolicy::class)->authorizeAdminCancel($order);
        } catch (\RuntimeException $e) {
            $this->addError('cancel', $e->getMessage());

            return;
        }

        $order->update(['status' => 'cancelled']);
        app(StockService::class)->restoreForOrder($order);

        Log::channel('orders')->info('Pedido PDV cancelado pelo operador', [
            'order_id' => $order->id,
            'user_id' => auth()->id(),
        ]);

        $this->audit('order_cancelled', [
            'order_id' => $order->id,
            'amount' => (float) $order->total,
            'metadata' => [
                'order_number' => $order->order_number,
            ],
        ]);

        $this->confirmingCancelSessionOrderId = null;

        if ($this->lastOrderId === $orderId) {
            $this->confirmingCancelOrder = false;
            $this->resetTerminal();
        }
    }
}
