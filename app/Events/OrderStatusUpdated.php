<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderStatusUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Order $order,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('order.'.$this->order->id),
            new PrivateChannel('orders.'.$this->order->company_id),
            // O chat público do cliente também escuta este evento (acompanhar status
            // do próprio pedido) e não tem User autenticado — Laravel bloqueia
            // PrivateChannel pra convidado antes até de chamar o authorizer, então
            // mantemos uma cópia pública aqui. Só é seguro porque broadcastWith()
            // abaixo é deliberadamente mínimo (sem PII/valor/forma de pagamento) —
            // qualquer dado mais sensível deve ir só pelos canais privados acima.
            new Channel('order.'.$this->order->id),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'status' => $this->order->status,
            'order_number' => $this->order->order_number,
        ];
    }
}
