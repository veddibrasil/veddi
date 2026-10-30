<?php

namespace App\Livewire\Admin\Orders\Concerns;

use App\Exceptions\Food99ApiException;
use App\Models\Food99Request;
use App\Services\Food99\Food99RequestService;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Throwable;

/** Solicitações de cancelamento/reembolso do cliente 99Food na tela do pedido (Orders/Show). */
trait HandlesFood99Requests
{
    /** Solicitação com a recusa aberta, ou null. */
    public ?int $food99RejectRequestId = null;

    public string $food99RejectReason = '';

    public string $food99RejectBaseReason = '';

    /** @return Collection<int, Food99Request> */
    #[Computed]
    public function food99Requests(): Collection
    {
        if ($this->order->channel !== '99food' || $this->userStation) {
            return new Collection;
        }

        return Food99Request::where('order_id', $this->order->id)->latest()->get()
            ->sortByDesc(fn (Food99Request $request) => $request->isOpen())->values();
    }

    public function acceptFood99Request(int $requestId): void
    {
        abort_unless($this->canOperateFood99(), 403);

        $request = $this->findFood99Request($requestId);
        $this->runFood99RequestAction(
            fn () => app(Food99RequestService::class)->accept($request, auth()->id()),
            $request->kind === Food99Request::KIND_CANCEL
                ? 'Cancelamento aceito. A 99Food cancela o pedido e reembolsa o cliente.'
                : 'Reembolso aceito na 99Food.',
        );
    }

    public function openFood99RequestReject(int $requestId): void
    {
        abort_unless($this->canOperateFood99(), 403);

        $this->food99RejectRequestId = $this->findFood99Request($requestId)->id;
        $this->food99RejectReason = '';
        $this->food99RejectBaseReason = '';
        $this->resetErrorBag();
    }

    public function closeFood99RequestReject(): void
    {
        $this->food99RejectRequestId = null;
        $this->food99RejectReason = '';
        $this->food99RejectBaseReason = '';
    }

    public function confirmFood99RequestReject(): void
    {
        abort_unless($this->canOperateFood99(), 403);

        if (! $this->food99RejectRequestId) {
            return;
        }

        $request = $this->findFood99Request($this->food99RejectRequestId);
        $done = $this->runFood99RequestAction(
            fn () => app(Food99RequestService::class)->reject($request, $this->food99RejectReason, $this->food99RejectBaseReason ?: null, auth()->id()),
            'Recusa enviada à 99Food.',
        );

        if ($done) {
            $this->closeFood99RequestReject();
        }
    }

    private function findFood99Request(int $requestId): Food99Request
    {
        return Food99Request::where('order_id', $this->order->id)->findOrFail($requestId);
    }

    private function runFood99RequestAction(callable $action, string $success): bool
    {
        try {
            $action();
        } catch (\InvalidArgumentException $e) {
            $this->addError('food99RejectReason', $e->getMessage());

            return false;
        } catch (Throwable $e) {
            session()->flash('error', Food99ApiException::messageFor($e));

            return false;
        }

        unset($this->food99Requests);
        session()->flash('status', $success);

        return true;
    }
}
