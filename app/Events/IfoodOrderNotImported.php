<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Pedido do iFood que não virou pedido no painel (item sem vínculo, falha repetida) ou
 * negociação de um pedido que não existe aqui. Atualiza sino e lista de pedidos na hora:
 * o restaurante precisa aceitar ou cancelar pelo Gestor de Pedidos do iFood dentro do prazo.
 */
class IfoodOrderNotImported implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $companyId, public int $ifoodOrderEventId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('orders.'.$this->companyId)];
    }

    public function broadcastWith(): array
    {
        return ['ifood_order_event_id' => $this->ifoodOrderEventId];
    }
}
