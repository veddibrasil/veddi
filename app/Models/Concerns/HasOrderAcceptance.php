<?php

namespace App\Models\Concerns;

use App\Enums\OrderChannel;
use App\Events\OrderAcceptedForProduction;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Aceite do pedido igual para todo canal. Telas, alarme e serviços perguntam aqui, nunca pelo
 * canal; o que muda por canal fica em HasChatAcceptance e MarketplaceOrderDetails.
 */
trait HasOrderAcceptance
{
    /**
     * Pedido mais antigo que isso não conta como pendente: chat anterior ao aceite obrigatório
     * nunca teve chat_accepted_at e, sem prazo configurado, ficaria pendente para sempre.
     */
    private const ACCEPTANCE_LOOKBACK_HOURS = 24;

    /**
     * Saiu de "aguardando aceite" sem ser cancelado: avisa as telas para imprimir a via de
     * produção. Fica no modelo porque o aceite chega por vários caminhos (painel, alarme,
     * "Preparando" no kanban, app da plataforma via webhook/polling, aceite automático da Keeta).
     */
    protected static function bootHasOrderAcceptance(): void
    {
        static::updated(function (self $order) {
            if (! $order->wasChanged(['status', 'chat_accepted_at', 'external_metadata']) || $order->isClosed()) {
                return;
            }

            $before = (new static)->setRawAttributes($order->getRawOriginal(), true);
            $before->exists = true;

            if (! $before->awaitingAcceptance() || $order->awaitingAcceptance()) {
                return;
            }

            DB::afterCommit(fn () => OrderAcceptedForProduction::dispatch($order));
        });
    }

    /** Pedido que ainda vai passar pelo aceite (ou pelo pagamento online antes dele): não imprime na chegada. */
    public function waitsForAcceptance(): bool
    {
        return $this->awaitingAcceptance() || $this->requiresChatAcceptance();
    }

    public function awaitingAcceptance(): bool
    {
        return $this->marketplaceDetails()?->awaitingConfirmation() ?? $this->awaitingChatAcceptance();
    }

    public function acceptanceExpired(): bool
    {
        return $this->marketplaceDetails()?->confirmationExpired() ?? $this->chatAcceptanceExpired();
    }

    /** Prazo de aceite; null no chat de empresa sem prazo configurado. */
    public function acceptanceDeadline(): ?CarbonInterface
    {
        if ($marketplace = $this->marketplaceDetails()) {
            return $marketplace->confirmationDeadline();
        }

        return (int) $this->company?->chat_acceptance_timeout_minutes > 0 ? $this->chat_acceptance_expires_at : null;
    }

    /** awaitingAcceptance() em SQL. O prazo vencido fica de fora: cada canal decide em PHP. */
    public function scopeWhereAwaitingAcceptance(Builder $query): Builder
    {
        return $query
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('channel', OrderChannel::Chat->value)
                    ->where('order_type', '!=', 'pdv')->whereNull('chat_accepted_at')
                    ->where(fn ($q) => $q->whereIn('status', ['paid', 'scheduled'])
                        ->orWhere(fn ($q) => $q->where('status', 'awaiting_payment')->where('payment_on_delivery', true))))
                ->orWhere(fn ($q) => $q->where('channel', OrderChannel::Ifood->value)
                    ->whereIn('status', ['pending', 'paid', 'scheduled'])
                    ->where('external_metadata->ifood_status', 'PLACED'))
                ->orWhere(fn ($q) => $q->where('channel', OrderChannel::Food99->value)
                    ->where('status', 'pending')
                    ->where('external_metadata->food99_stage', 'CREATED'))
                ->orWhere(fn ($q) => $q->where('channel', OrderChannel::Keeta->value)
                    ->where('status', 'pending')
                    ->where('external_metadata->keeta_stage', 'CREATED')))
            ->where(fn ($q) => $q->where('created_at', '>=', now()->subHours(self::ACCEPTANCE_LOOKBACK_HOURS))
                ->orWhere('scheduled_at', '>', now()));
    }
}
