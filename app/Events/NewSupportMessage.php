<?php

namespace App\Events;

use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewSupportMessage implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $companyId;

    public function __construct(public SupportMessage $message, public SupportTicket $ticket)
    {
        $this->companyId = $ticket->company_id;
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('admin-support.'.$this->companyId),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'ticket_id' => $this->ticket->id,
            'message' => $this->message->message,
            'customer_name' => $this->ticket->customer?->name ?? 'Cliente',
            'created_at' => $this->message->created_at->format('H:i'),
        ];
    }
}
