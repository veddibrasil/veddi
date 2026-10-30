<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\Food99\Food99OrderActionService;
use App\Support\Food99\Food99OrderDetails;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rede de segurança pra mudança de status local de pedido 99Food feita fora do
 * Food99OrderActionService (ver App\Listeners\PropagateFood99OrderStatus). As telas já chamam a
 * 99 antes de mudar o status e os callbacks gravam a etapa; nos dois casos ela já foi alcançada
 * e este job não faz nada.
 *
 * 'preparing' não propaga: aceitar é order/confirm (Food99OrderActionService::accept).
 */
class PropagateFood99StatusJob implements ShouldQueue
{
    use Queueable;

    /** Status local → etapa da 99 que ele exige. */
    private const PROPAGATABLE_STATUSES = [
        'ready' => 'READY',
        'out_for_delivery' => 'DISPATCHED',
        'delivered' => 'FINISHED',
    ];

    public function __construct(public int $orderId, public string $status)
    {
        $this->onQueue('critical');
    }

    /** Entrega própria marcada como entregue sem passar por "a caminho". */
    private function dispatchAndDeliver(Food99OrderActionService $actions, Order $order): void
    {
        $actions->dispatch($order);
        $actions->markDelivered($order->refresh());
    }

    public function handle(Food99OrderActionService $actions): void
    {
        $stage = self::PROPAGATABLE_STATUSES[$this->status] ?? null;
        if ($stage === null) {
            return;
        }

        $order = Order::withoutGlobalScopes()->find($this->orderId);
        if (! $order || ! $order->external_order_id || $order->status !== $this->status) {
            return;
        }

        $details = $order->food99Details();
        // Só a etapa gravada pela 99/ações conta: inferir do status local diria que ela já foi
        // alcançada justamente porque o status local mudou.
        $known = $details?->get('food99_stage');
        $knownRank = Food99OrderDetails::STAGE_RANK[$known] ?? -1;
        if (! $details || $known === 'CANCELLED' || $knownRank >= Food99OrderDetails::STAGE_RANK[$stage]) {
            return;
        }

        $previousCompany = app()->bound('current.company') ? app('current.company') : null;

        try {
            app()->instance('current.company', $order->company);

            match (true) {
                $stage === 'READY' && $details->canMarkReady() => $actions->markReady($order),
                $stage === 'DISPATCHED' && $details->canDispatch() => $actions->dispatch($order),
                $stage === 'FINISHED' && $details->canFinishPickup() => $actions->finishPickup($order),
                $stage === 'FINISHED' && $details->canMarkDelivered() => $actions->markDelivered($order),
                $stage === 'FINISHED' && $details->canDispatch() => $this->dispatchAndDeliver($actions, $order),
                // Entrega 99: só o "pronto" é da loja; coleta e conclusão vêm da 99.
                $details->isDeliveredByPlatform() && $details->canMarkReady() => $actions->markReady($order),
                default => Log::channel('food99')->warning('99Food: status local mudou mas a etapa não é da loja informar', [
                    'order_id' => $order->id,
                    'status' => $this->status,
                    'food99_stage' => $known,
                ]),
            };
        } catch (Throwable $e) {
            Log::channel('food99')->error('99Food: falha ao propagar status', [
                'order_id' => $order->id,
                'status' => $this->status,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            $previousCompany ? app()->instance('current.company', $previousCompany) : app()->forgetInstance('current.company');
        }
    }
}
