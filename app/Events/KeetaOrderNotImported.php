<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Pedido da Keeta que não virou pedido no painel (falha repetida). Atualiza sino e lista de
 * pedidos na hora: a loja precisa confirmar ou cancelar pelo portal da Keeta em até 5 minutos.
 */
class KeetaOrderNotImported implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $companyId, public int $keetaWebhookEventId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('orders.'.$this->companyId)];
    }

    public function broadcastWith(): array
    {
        return ['keeta_webhook_event_id' => $this->keetaWebhookEventId];
    }
}
