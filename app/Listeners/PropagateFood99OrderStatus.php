<?php

namespace App\Listeners;

use App\Enums\OrderChannel;
use App\Events\OrderStatusUpdated;
use App\Jobs\PropagateFood99StatusJob;

class PropagateFood99OrderStatus
{
    public function handle(OrderStatusUpdated $event): void
    {
        $order = $event->order;

        if ($order->channel !== OrderChannel::Food99->value) {
            return;
        }

        PropagateFood99StatusJob::dispatch($order->id, $order->status);
    }
}
