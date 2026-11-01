<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\Order\OrderService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExpireUnacceptedChatOrders implements ShouldQueue
{
    use Queueable;

    public function handle(OrderService $orders): void
    {
        // Scheduled maintenance spans tenants. Every mutation rechecks the selected company's order under lock.
        Order::withoutGlobalScopes()->where('channel', 'chat')->where('order_type', '!=', 'pdv')
            ->whereIn('status', ['pending', 'awaiting_payment', 'paid', 'scheduled'])
            ->whereNull('chat_accepted_at')->where('chat_acceptance_expires_at', '<=', now())
            ->whereHas('company', fn ($query) => $query->where('chat_acceptance_timeout_minutes', '>', 0))
            ->orderBy('id')->chunkById(50, function ($batch) use ($orders) {
                foreach ($batch as $order) {
                    $orders->cancelUnacceptedChat($order);
                }
            });
    }
}
