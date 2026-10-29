<?php

namespace App\Livewire\Admin\Orders\Concerns;

use App\Enums\Food99CancelReason;
use App\Exceptions\Food99ApiException;
use App\Models\Order;
use App\Services\Food99\Food99OrderActionService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ações de pedido 99Food no kanban (Orders/Index) e na tela do pedido (Orders/Show). Toda
 * etapa passa pela API da 99 antes de mudar aqui (Food99OrderActionService).
 */
trait HandlesFood99Orders
{
    /** Pedido 99Food com o modal de recusa/cancelamento aberto, ou null. */
    public ?int $food99CancelOrderId = null;

    public string $food99CancelReason = '';

    public string $food99CancelText = '';

    /** Pedido do modal já aceito: a ação vira cancelamento (muda o texto do modal). */
    public bool $food99CancelAccepted = false;

    /** Entrega própria: libera os motivos de entrega (sem entregador, fora da área...). */
    public bool $food99CancelSelfDelivery = false;

    /** Pedido da tela, já filtrado por empresa/filial do usuário. */
    abstract protected function food99ActionableOrder(int $orderId): Order;

    abstract protected function canOperateFood99(): bool;

    /** Kanban/status: etapa local pedida → ação na 99. */
    protected function updateFood99OrderStatus(Order $order, string $newStatus): bool
    {
        if ($newStatus === 'cancelled') {
            $this->openFood99CancelModal($order->id);

            return false;
        }

        $details = $order->food99Details();
        $service = app(Food99OrderActionService::class);
        $userId = auth()->id();

        $action = match ($newStatus) {
            'preparing' => $details->awaitingConfirmation() ? fn () => $service->accept($order, $userId) : null,
            'ready' => fn () => $service->markReady($order, $userId),
            'out_for_delivery' => fn () => $service->dispatch($order, $userId),
            'delivered' => match (true) {
                $details->isPickup() => fn () => $service->finishPickup($order, $userId),
                $details->isSelfDelivery() => fn () => $service->markDelivered($order, $userId),
                default => null,
            },
            default => null,
        };

        if ($action === null) {
            session()->flash('error', 'Na 99Food essa etapa não é feita pela loja: coleta, entrega e conclusão da entrega 99 são registradas pela própria 99Food.');

            return false;
        }

        return $this->runFood99Action($action, match ($newStatus) {
            'preparing' => 'Pedido aceito na 99Food.',
            'ready' => '99Food avisada: pedido pronto.',
            'out_for_delivery' => 'Pedido despachado na 99Food.',
            default => 'Pedido concluído na 99Food.',
        });
    }

    public function acceptFood99Order(int $orderId): void
    {
        abort_unless($this->canOperateFood99(), 403);

        $this->updateFood99OrderStatus($this->food99ActionableOrder($orderId), 'preparing');
    }

    public function finishFood99Order(int $orderId, string $code = ''): void
    {
        abort_unless($this->canOperateFood99(), 403);

        $order = $this->food99ActionableOrder($orderId);
        $details = $order->food99Details();
        $service = app(Food99OrderActionService::class);

        $this->runFood99Action(
            $details?->isPickup()
                ? fn () => $service->finishPickup($order, auth()->id(), $code)
                : fn () => $service->markDelivered($order, auth()->id(), $code),
            $details?->isPickup() ? 'Retirada registrada na 99Food.' : 'Entrega registrada na 99Food.',
        );
    }

    public function confirmFood99CashPayment(int $orderId): void
    {
        abort_unless($this->canOperateFood99(), 403);

        $order = $this->food99ActionableOrder($orderId);
        $this->runFood99Action(fn () => app(Food99OrderActionService::class)->confirmCashPayment($order, auth()->id()), 'Recebimento do dinheiro confirmado na 99Food.');
    }

    public function openFood99CancelModal(int $orderId): void
    {
        abort_unless($this->canOperateFood99(), 403);

        $order = $this->food99ActionableOrder($orderId);
        $details = $order->food99Details();
        abort_unless($details !== null, 404);

        $this->food99CancelOrderId = $order->id;
        $this->food99CancelAccepted = ! $details->awaitingConfirmation();
        $this->food99CancelSelfDelivery = $details->isSelfDelivery();
        $this->food99CancelReason = '';
        $this->food99CancelText = '';
    }

    public function closeFood99CancelModal(): void
    {
        $this->food99CancelOrderId = null;
        $this->food99CancelAccepted = false;
        $this->food99CancelSelfDelivery = false;
        $this->food99CancelReason = '';
        $this->food99CancelText = '';
    }

    public function confirmFood99Cancel(): void
    {
        abort_unless($this->canOperateFood99(), 403);

        if (! $this->food99CancelOrderId || $this->food99CancelReason === '') {
            return;
        }

        $order = $this->food99ActionableOrder($this->food99CancelOrderId);
        $reasonId = (int) $this->food99CancelReason;
        $text = $this->food99CancelText;
        $wasAccepted = $this->food99CancelAccepted;

        $done = $this->runFood99Action(
            fn () => app(Food99OrderActionService::class)->cancel($order, $reasonId, $text, auth()->id()),
            $wasAccepted ? 'Pedido cancelado na 99Food.' : 'Pedido recusado na 99Food.',
        );

        if ($done) {
            $this->closeFood99CancelModal();

            Log::channel('food99')->info('99Food: recusa/cancelamento acionado pelo admin', [
                'order_id' => $order->id,
                'admin_id' => auth()->id(),
                'reason_id' => $reasonId,
                'ja_aceito' => $wasAccepted,
            ]);
        }
    }

    /** @return array<int, Food99CancelReason> */
    public function food99CancelReasons(): array
    {
        return Food99CancelReason::forDelivery($this->food99CancelSelfDelivery);
    }

    private function runFood99Action(callable $action, string $success): bool
    {
        try {
            $action();
        } catch (Throwable $e) {
            session()->flash('error', Food99ApiException::messageFor($e));

            return false;
        }

        session()->flash('status', $success);

        return true;
    }
}
