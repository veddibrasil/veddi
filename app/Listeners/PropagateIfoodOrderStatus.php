<?php

namespace App\Listeners;

use App\Enums\OrderChannel;
use App\Events\OrderStatusUpdated;
use App\Jobs\PropagateIfoodStatusJob;

class PropagateIfoodOrderStatus
{
    public function handle(OrderStatusUpdated $event): void
    {
        $order = $event->order;

        if ($order->channel !== OrderChannel::Ifood->value) {
            return;
        }

        PropagateIfoodStatusJob::dispatch($order->id, $order->status);
    }
}
