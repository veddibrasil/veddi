<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Garçom mandou a comanda pra produção ("Finalizar Pedido") — pode ter clicado
 * do celular, sem QZ Tray instalado. Broadcast pro mesmo canal do PDV pra
 * qualquer tela aberta na filial (a que tiver impressora pareada de verdade)
 * poder pegar e imprimir sozinha.
 */
class TabOrderSentToProduction implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** @param  array<int, string>  $stations */
    public function __construct(public Order $order, public array $stations) {}

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
            'stations' => $this->stations,
        ];
    }
}
