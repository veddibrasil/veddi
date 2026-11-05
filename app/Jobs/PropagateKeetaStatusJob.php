<?php

namespace App\Jobs;

use App\Exceptions\KeetaApiException;
use App\Models\Order;
use App\Services\Keeta\KeetaOrderActionService;
use App\Support\Keeta\KeetaOrderDetails;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rede de segurança pra mudança de status local de pedido Keeta feita fora do
 * KeetaOrderActionService (ver App\Listeners\PropagateKeetaOrderStatus). As telas já chamam a
 * Keeta antes de mudar o status e os eventos gravam a etapa; nos dois casos ela já foi
 * alcançada e este job não faz nada.
 *
 * 'preparing' não propaga: aceitar é o confirm (KeetaOrderActionService::accept).
 */
class PropagateKeetaStatusJob implements ShouldQueue
{
    use Queueable;

    /** Status local → etapa da Keeta que ele exige. */
    private const PROPAGATABLE_STATUSES = [
        'ready' => 'READY',
        'out_for_delivery' => 'DISPATCHED',
        'delivered' => 'DELIVERED',
    ];

    public function __construct(public int $orderId, public string $status)
    {
        $this->onQueue('critical');
    }

    public function handle(KeetaOrderActionService $actions): void
    {
        $stage = self::PROPAGATABLE_STATUSES[$this->status] ?? null;
        if ($stage === null) {
            return;
        }

        $order = Order::withoutGlobalScopes()->find($this->orderId);
        if (! $order || ! $order->external_order_id || $order->status !== $this->status) {
            return;
        }

        $details = $order->keetaDetails();
        // Só a etapa gravada pela Keeta/ações conta: inferir do status local diria que ela já foi
        // alcançada justamente porque o status local mudou.
        $known = $details?->get('keeta_stage');
        $knownRank = KeetaOrderDetails::STAGE_RANK[$known] ?? -1;
        if (! $details || $known === 'CANCELLED' || $knownRank >= KeetaOrderDetails::STAGE_RANK[$stage]) {
            return;
        }

        $previousCompany = app()->bound('current.company') ? app('current.company') : null;

        try {
            app()->instance('current.company', $order->company);

            match (true) {
                $stage === 'READY' && $details->canMarkReady() => $actions->markReady($order),
                $stage === 'DISPATCHED' && $details->canDispatch() => $actions->dispatch($order),
                $stage === 'DELIVERED' && $details->canMarkDelivered() => $actions->markDelivered($order),
                $stage === 'DELIVERED' && $details->canDispatch() => $this->dispatchAndDeliver($actions, $order),
                // Entrega Keeta: só o "pronto" é da loja; coleta e conclusão vêm da Keeta.
                $details->isDeliveredByPlatform() && $details->canMarkReady() => $actions->markReady($order),
                default => Log::channel('keeta')->warning('Keeta: status local mudou mas a etapa não é da loja informar', [
                    'order_id' => $order->id,
                    'status' => $this->status,
                    'keeta_stage' => $known,
                ]),
            };
        } catch (Throwable $e) {
            Log::channel('keeta')->error('Keeta: falha ao propagar status', [
                'order_id' => $order->id,
                'status' => $this->status,
                'error' => KeetaApiException::forLog($e),
            ]);

            throw $e;
        } finally {
            $previousCompany ? app()->instance('current.company', $previousCompany) : app()->forgetInstance('current.company');
        }
    }

    /** Entrega própria marcada como entregue sem passar por "a caminho". */
    private function dispatchAndDeliver(KeetaOrderActionService $actions, Order $order): void
    {
        $actions->dispatch($order);
        $actions->markDelivered($order->refresh());
    }
}
