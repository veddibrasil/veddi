<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Garçom lançou item novo na comanda — se a filial tem impressora auto_print em
 * cozinha/bar, o item precisa sair sozinho, sem esperar "Finalizar Pedido". O
 * "quem ainda não foi impresso" já foi resolvido e marcado como enviado no
 * momento do lançamento (ver HasOpenTabs::notifyPendingProductionItems) — o
 * payload aqui é só os pares item/quantidade prontos pra imprimir, pra qualquer
 * tela de PDV que receber o broadcast poder montar o cupom sem recalcular nada
 * (e sem correr o risco de duas telas calcularem coisas diferentes).
 */
class TabItemsReadyForProduction implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** @param  array<string, array<int, array{id: int, qty: int}>>  $stationItems */
    public function __construct(public Order $order, public array $stationItems) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('orders.'.$this->order->company_id),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->order->id,
            'branch_id' => $this->order->branch_id,
            'stations' => $this->stationItems,
        ];
    }
}
