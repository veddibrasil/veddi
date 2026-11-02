<?php

namespace App\Services\Order;

use App\Events\OrderStatusUpdated;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChatOrderAcceptance
{
    public function accept(Order $order, User $user): Order
    {
        abort_unless(app(OrderAcceptance::class)->canAcceptOrder($user, $order), 403);

        return DB::transaction(function () use ($order, $user) {
            $locked = Order::withoutGlobalScopes()->where('company_id', $order->company_id)
                ->whereKey($order->id)->lockForUpdate()->firstOrFail();

            // Repeated clicks or another operator accepting must not restart preparation.
            if ($locked->chat_accepted_at) {
                return $locked;
            }

            if (! $locked->awaitingChatAcceptance() || $locked->chatAcceptanceExpired()) {
                throw ValidationException::withMessages(['orderAcceptance' => 'Este pedido não está disponível para aceite. Atualize a página.']);
            }

            $previousStatus = $locked->status;
            $locked->chat_accepted_at = now();
            $locked->chat_acceptance_expires_at = null;
            $locked->status = $locked->scheduled_at?->isFuture() ? 'scheduled' : 'preparing';
            $locked->save();

            app(OrderService::class)->recordStatusHistory($locked, $user->id, $previousStatus, $locked->status, null, ['source' => 'chat_acceptance']);
            DB::afterCommit(fn () => OrderStatusUpdated::dispatch($locked));

            return $locked;
        });
    }
}
