<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Pedido da 99Food que não virou pedido no painel (falha repetida). Atualiza sino e lista de
 * pedidos na hora: a loja precisa aceitar ou cancelar pelo app da 99Food em até 5 minutos.
 */
class Food99OrderNotImported implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $companyId, public int $food99WebhookEventId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('orders.'.$this->companyId)];
    }

    public function broadcastWith(): array
    {
        return ['food99_webhook_event_id' => $this->food99WebhookEventId];
    }
}
