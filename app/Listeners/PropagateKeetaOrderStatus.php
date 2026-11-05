<?php

namespace App\Listeners;

use App\Enums\OrderChannel;
use App\Events\OrderStatusUpdated;
use App\Jobs\PropagateKeetaStatusJob;

class PropagateKeetaOrderStatus
{
    public function handle(OrderStatusUpdated $event): void
    {
        $order = $event->order;

        if ($order->channel !== OrderChannel::Keeta->value) {
            return;
        }

        PropagateKeetaStatusJob::dispatch($order->id, $order->status);
    }
}
