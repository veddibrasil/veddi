<?php

namespace App\Models\Concerns;

use App\Models\Company;
use App\Models\Order;
use Illuminate\Validation\ValidationException;

trait HasChatAcceptance
{
    public function requiresChatAcceptance(): bool
    {
        return ($this->channel ?? 'chat') === 'chat' && $this->order_type !== 'pdv'
            && ! $this->chat_accepted_at
            && in_array($this->status, ['pending', 'awaiting_payment', 'paid', 'scheduled'], true);
    }

    public function awaitingChatAcceptance(): bool
    {
        return $this->requiresChatAcceptance()
            && (in_array($this->status, ['paid', 'scheduled'], true)
                || ($this->payment_on_delivery && $this->status === 'awaiting_payment'));
    }

    public function chatAcceptanceExpired(): bool
    {
        return $this->chat_acceptance_expires_at?->isPast()
            && (int) $this->company?->chat_acceptance_timeout_minutes > 0;
    }

    protected static function bootHasChatAcceptance(): void
    {
        static::saving(function (Order $order) {
            if (($order->channel ?? 'chat') !== 'chat' || $order->order_type === 'pdv') {
                return;
            }
            if ($order->chat_acceptance_expires_at && in_array($order->status, ['preparing', 'ready', 'out_for_delivery', 'delivered'], true)) {
                // Claim acceptance atomically against the deadline job. A stale panel cannot reopen its cancellation.
                $accepted = Order::withoutGlobalScopes()->where('company_id', $order->company_id)->whereKey($order->id)
                    ->whereIn('status', ['pending', 'awaiting_payment', 'paid', 'scheduled'])
                    ->whereNull('chat_accepted_at')->update(['chat_accepted_at' => now(), 'chat_acceptance_expires_at' => null]);
                if (! $accepted) {
                    $current = Order::withoutGlobalScopes()->where('company_id', $order->company_id)->find($order->id);
                    if (in_array($current?->status, ['cancelled', 'refunded'], true)) {
                        throw ValidationException::withMessages(['order' => 'Este pedido já foi cancelado. Atualize a página.']);
                    }
                }
                $order->chat_accepted_at = now();
                $order->chat_acceptance_expires_at = null;

                return;
            }
            if ($order->chat_accepted_at || $order->chat_acceptance_expires_at) {
                return;
            }
            $readyForAcceptance = in_array($order->status, ['paid', 'scheduled'], true)
                || ($order->payment_on_delivery && $order->status === 'awaiting_payment');
            $companyId = $order->company_id ?? (app()->bound('current.company') ? app('current.company')->id : null);
            $minutes = (int) Company::whereKey($companyId)->value('chat_acceptance_timeout_minutes');
            if ($readyForAcceptance && $minutes > 0 && ($order->isDirty('status') || ! $order->exists)) {
                $startsAt = $order->scheduled_at?->isFuture() ? $order->scheduled_at->copy() : now();
                $order->chat_acceptance_expires_at = $startsAt->addMinutes($minutes);
            }
        });
    }
}
