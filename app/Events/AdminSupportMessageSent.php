<?php

namespace App\Events;

use App\Models\SupportMessage;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AdminSupportMessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public SupportMessage $supportMessage) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('support.'.$this->supportMessage->ticket_id),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'message_id' => $this->supportMessage->id,
            'message' => $this->supportMessage->message,
            'created_at' => $this->supportMessage->created_at->format('H:i'),
        ];
    }
}
