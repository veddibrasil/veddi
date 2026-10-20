<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Emissão não autorizada (rejeitada pela SEFAZ, erro da Focus ou falha definitiva do job).
 * O PDV que pediu "Emitir e imprimir nota fiscal" escuta o canal do pedido pra avisar o
 * caixa na hora — antes a falha só virava sino no painel e o caixa ficava esperando a
 * impressão. Mesmo canal privado de FiscalNoteAuthorized (só staff da empresa).
 */
class FiscalNoteFailed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $orderId,
        public ?string $reason = null,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('order.'.$this->orderId),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->orderId,
            'reason' => $this->reason ? mb_substr($this->reason, 0, 300) : null,
        ];
    }

    public function broadcastAs(): string
    {
        return 'FiscalNoteFailed';
    }
}
