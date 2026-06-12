<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WalletBalanceUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $companyId,
        public readonly float $availableBalance,
        public readonly float $pendingBalance,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('wallet.'.$this->companyId),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'available_balance' => $this->availableBalance,
            'pending_balance' => $this->pendingBalance,
        ];
    }
}
