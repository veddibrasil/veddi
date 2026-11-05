<?php

namespace App\Livewire\Admin\Orders\Concerns;

use App\Enums\KeetaCancelReason;
use App\Exceptions\KeetaApiException;
use App\Models\Order;
use App\Services\Keeta\KeetaOrderActionService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ações de pedido Keeta no kanban (Orders/Index) e na tela do pedido (Orders/Show). Toda etapa
 * passa pela API da Keeta antes de mudar aqui (KeetaOrderActionService).
 */
trait HandlesKeetaOrders
{
    /** Pedido Keeta com o modal de recusa/cancelamento aberto, ou null. */
    public ?int $keetaCancelOrderId = null;

    public string $keetaCancelReason = '';

    public string $keetaCancelText = '';

    /** Ids de item da Keeta (item indisponível / cardápio desatualizado). */
    public array $keetaCancelItems = [];

    /** Pedido do modal já aceito: a ação vira cancelamento (muda o texto do modal). */
    public bool $keetaCancelAccepted = false;

    /** Entrega própria: libera os motivos de entrega. */
    public bool $keetaCancelSelfDelivery = false;

    /** @var array<int, array{id: string, name: string}> */
    public array $keetaCancelOrderItems = [];

    /** Pedido da tela, já filtrado por empresa/filial do usuário. */
    abstract protected function keetaActionableOrder(int $orderId): Order;

    abstract protected function canOperateKeeta(): bool;

    /** Kanban/status: etapa local pedida → ação na Keeta. */
    protected function updateKeetaOrderStatus(Order $order, string $newStatus): bool
    {
        if ($newStatus === 'cancelled') {
            $this->openKeetaCancelModal($order->id);

            return false;
        }

        $details = $order->keetaDetails();
        $service = app(KeetaOrderActionService::class);
        $userId = auth()->id();

        $action = match ($newStatus) {
            'preparing' => $details->awaitingConfirmation() ? fn () => $service->accept($order, $userId) : null,
            'ready' => fn () => $service->markReady($order, $userId),
            'out_for_delivery' => fn () => $service->dispatch($order, $userId),
            'delivered' => $details->isSelfDelivery() ? fn () => $service->markDelivered($order, $userId) : null,
            default => null,
        };

        if ($action === null) {
            session()->flash('error', 'Na Keeta essa etapa não é feita pela loja: coleta, entrega e conclusão da entrega Keeta são registradas pela própria Keeta.');

            return false;
        }

        return $this->runKeetaAction($action, match ($newStatus) {
            'preparing' => 'Pedido confirmado na Keeta.',
            'ready' => 'Keeta avisada: pedido pronto.',
            'out_for_delivery' => 'Pedido despachado na Keeta.',
            default => 'Entrega registrada na Keeta.',
        });
    }

    public function openKeetaCancelModal(int $orderId): void
    {
        abort_unless($this->canOperateKeeta(), 403);

        $order = $this->keetaActionableOrder($orderId);
        $details = $order->keetaDetails();
        abort_unless($details !== null, 404);

        if ($details->cancellationPending()) {
            session()->flash('error', 'O cancelamento deste pedido já foi pedido à Keeta. Aguarde a confirmação.');

            return;
        }

        $this->keetaCancelOrderId = $order->id;
        $this->keetaCancelAccepted = ! $details->awaitingConfirmation();
        $this->keetaCancelSelfDelivery = $details->isSelfDelivery();
        $this->keetaCancelOrderItems = collect($details->get('order_items', []))
            ->filter(fn ($item) => is_array($item) && ($item['id'] ?? '') !== '')
            ->map(fn (array $item) => ['id' => (string) $item['id'], 'name' => (string) ($item['name'] ?? 'Item')])
            ->values()->all();
        $this->keetaCancelReason = '';
        $this->keetaCancelText = '';
        $this->keetaCancelItems = [];
    }

    public function closeKeetaCancelModal(): void
    {
        $this->keetaCancelOrderId = null;
        $this->keetaCancelAccepted = false;
        $this->keetaCancelSelfDelivery = false;
        $this->keetaCancelOrderItems = [];
        $this->keetaCancelReason = '';
        $this->keetaCancelText = '';
        $this->keetaCancelItems = [];
    }

    public function confirmKeetaCancel(): void
    {
        abort_unless($this->canOperateKeeta(), 403);

        if (! $this->keetaCancelOrderId || $this->keetaCancelReason === '') {
            return;
        }

        $order = $this->keetaActionableOrder($this->keetaCancelOrderId);
        $code = $this->keetaCancelReason;
        $wasAccepted = $this->keetaCancelAccepted;

        $done = $this->runKeetaAction(
            fn () => app(KeetaOrderActionService::class)->requestCancel($order, $code, $this->keetaCancelText, auth()->id(), array_values(array_map('strval', $this->keetaCancelItems))),
            ($wasAccepted ? 'Cancelamento' : 'Recusa').' pedido à Keeta. O pedido é cancelado aqui assim que a Keeta confirmar.',
        );

        if ($done) {
            $this->closeKeetaCancelModal();

            Log::channel('keeta')->info('Keeta: recusa/cancelamento acionado pelo admin', [
                'order_id' => $order->id,
                'admin_id' => auth()->id(),
                'code' => $code,
                'ja_aceito' => $wasAccepted,
            ]);
        }
    }

    /** @return array<int, KeetaCancelReason> */
    public function keetaCancelReasons(): array
    {
        return KeetaCancelReason::forDelivery($this->keetaCancelSelfDelivery);
    }

    private function runKeetaAction(callable $action, string $success): bool
    {
        try {
            $action();
        } catch (Throwable $e) {
            session()->flash('error', KeetaApiException::messageFor($e));

            return false;
        }

        session()->flash('status', $success);

        return true;
    }
}
