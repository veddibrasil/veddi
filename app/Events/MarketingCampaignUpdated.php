<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/** Progresso de campanha para as telas do super admin (canal só de super admin). */
class MarketingCampaignUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public string $broadcastQueue = 'marketing';

    public function __construct(
        public readonly int $campaignId,
        public readonly string $status,
        public readonly int $sentCount,
        public readonly int $recipientsCount,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('superadmin.marketing')];
    }

    public function broadcastAs(): string
    {
        return 'MarketingCampaignUpdated';
    }

    public function broadcastWith(): array
    {
        return [
            'campaign_id' => $this->campaignId,
            'status' => $this->status,
            'sent_count' => $this->sentCount,
            'recipients_count' => $this->recipientsCount,
        ];
    }
}
