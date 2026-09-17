<?php

namespace App\Events;

use App\Models\FiscalNote;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FiscalNoteAuthorized implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public FiscalNote $note,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('order.'.$this->note->order_id),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->note->order_id,
            'fiscal_note_id' => $this->note->id,
            'access_key' => $this->note->access_key,
        ];
    }

    public function broadcastAs(): string
    {
        return 'FiscalNoteAuthorized';
    }
}
