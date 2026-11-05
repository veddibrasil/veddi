<?php

namespace App\Livewire\Admin\Orders\Concerns;

use App\Exceptions\KeetaApiException;
use App\Models\KeetaRequest;
use App\Services\Keeta\KeetaRequestService;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Throwable;

/** Solicitações de cancelamento/reembolso do cliente Keeta na tela do pedido (Orders/Show). */
trait HandlesKeetaRequests
{
    /** Solicitação com a recusa aberta, ou null. */
    public ?int $keetaRejectRequestId = null;

    public string $keetaRejectCode = '';

    public string $keetaRejectReason = '';

    /** @return Collection<int, KeetaRequest> */
    #[Computed]
    public function keetaRequests(): Collection
    {
        if ($this->order->channel !== 'keeta' || $this->userStation) {
            return new Collection;
        }

        return KeetaRequest::where('order_id', $this->order->id)->latest()->get()
            ->sortByDesc(fn (KeetaRequest $request) => $request->isAnswerable())->values();
    }

    public function acceptKeetaRequest(int $requestId): void
    {
        abort_unless($this->canOperateKeeta(), 403);

        $request = $this->findKeetaRequest($requestId);
        $this->runKeetaRequestAction(
            fn () => app(KeetaRequestService::class)->accept($request, auth()->id()),
            'Reembolso aceito. A Keeta devolve o valor ao cliente.',
        );
    }

    public function openKeetaRequestReject(int $requestId): void
    {
        abort_unless($this->canOperateKeeta(), 403);

        $this->keetaRejectRequestId = $this->findKeetaRequest($requestId)->id;
        $this->keetaRejectCode = '';
        $this->keetaRejectReason = '';
        $this->resetErrorBag();
    }

    public function closeKeetaRequestReject(): void
    {
        $this->keetaRejectRequestId = null;
        $this->keetaRejectCode = '';
        $this->keetaRejectReason = '';
    }

    public function confirmKeetaRequestReject(): void
    {
        abort_unless($this->canOperateKeeta(), 403);

        if (! $this->keetaRejectRequestId) {
            return;
        }

        $request = $this->findKeetaRequest($this->keetaRejectRequestId);
        $done = $this->runKeetaRequestAction(
            fn () => app(KeetaRequestService::class)->reject($request, $this->keetaRejectCode, $this->keetaRejectReason, auth()->id()),
            'Recusa enviada à Keeta.',
        );

        if ($done) {
            $this->closeKeetaRequestReject();
        }
    }

    private function findKeetaRequest(int $requestId): KeetaRequest
    {
        return KeetaRequest::where('order_id', $this->order->id)->findOrFail($requestId);
    }

    private function runKeetaRequestAction(callable $action, string $success): bool
    {
        try {
            $action();
        } catch (\InvalidArgumentException $e) {
            $this->addError('keetaRejectReason', $e->getMessage());

            return false;
        } catch (Throwable $e) {
            session()->flash('error', KeetaApiException::messageFor($e));

            return false;
        }

        unset($this->keetaRequests);
        session()->flash('status', $success);

        return true;
    }
}
