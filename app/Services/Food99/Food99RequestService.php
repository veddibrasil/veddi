<?php

namespace App\Services\Food99;

use App\Contracts\Food99EventHandler;
use App\Contracts\Food99GatewayContract;
use App\Enums\OrderChannel;
use App\Events\Food99OrderUpdated;
use App\Models\CompanyNotification;
use App\Models\Food99Integration;
use App\Models\Food99Request;
use App\Models\Food99WebhookEvent;
use App\Models\Order;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/**
 * Pedidos do cliente na 99Food: cancelamento (orderCancelApply, 5 min pra responder, sem
 * resposta = recusado) e reembolso (orderRefundApply, 24 h, sem resposta = aceito). Aceitar o
 * cancelamento não cancela o pedido aqui: o orderCancel da 99 é que cancela.
 */
class Food99RequestService implements Food99EventHandler
{
    private const KINDS = [
        'orderCancelApply' => Food99Request::KIND_CANCEL,
        'orderRefundApply' => Food99Request::KIND_REFUND,
    ];

    public function __construct(private readonly Food99GatewayContract $gateway) {}

    public function handles(string $type): bool
    {
        return isset(self::KINDS[$type]);
    }

    public function handle(Food99WebhookEvent $event): bool
    {
        $integration = $event->food99Integration;
        $data = $event->data();
        $applyId = isset($data['apply_id']) ? (string) $data['apply_id'] : null;

        if (! $integration || ! $applyId) {
            return false;
        }

        $kind = self::KINDS[$event->event_type];
        $order = $this->findOrder($integration, $event->external_order_id);

        $existing = Food99Request::withoutGlobalScopes()->where('apply_id', $applyId)->first();
        if ($existing) {
            $existing->update(['last_seen_at' => now(), 'order_id' => $existing->order_id ?? $order?->id]);

            return true;
        }

        try {
            $request = Food99Request::withoutGlobalScopes()->create([
                'company_id' => $integration->company_id,
                'food99_integration_id' => $integration->id,
                'order_id' => $order?->id,
                'external_order_id' => (string) ($event->external_order_id ?? ''),
                'kind' => $kind,
                'apply_id' => $applyId,
                'apply_reason' => isset($data['apply_reason']) ? mb_substr((string) $data['apply_reason'], 0, 2000) : null,
                'reason_options' => $this->reasonOptions($data[$kind === Food99Request::KIND_CANCEL ? 'reason_list' : 'base_reason_list'] ?? []),
                'images' => array_values(array_filter((array) ($data['images'] ?? []), 'is_string')),
                'expires_at' => now()->addMinutes(Food99Request::DEADLINE_MINUTES[$kind]),
                'last_seen_at' => now(),
                'status' => Food99Request::STATUS_OPEN,
            ]);
        } catch (UniqueConstraintViolationException) {
            return true;
        }

        $event->update(['order_id' => $order?->id]);
        $this->notifyNew($request, $order);

        if ($order) {
            Food99OrderUpdated::dispatch($order);
        }

        Log::channel('food99')->info('99Food: solicitação do cliente recebida', [
            'food99_request_id' => $request->id,
            'kind' => $kind,
            'order_id' => $order?->id,
        ]);

        return true;
    }

    public function accept(Food99Request $request, ?int $userId = null): void
    {
        $this->assertOpen($request);
        $integration = $this->integration($request);

        $request->kind === Food99Request::KIND_CANCEL
            ? $this->gateway->respondCancelApply($integration, $request->apply_id, true)
            : $this->gateway->respondRefundApply($integration, $request->apply_id, true);

        $this->record($request, Food99Request::STATUS_ACCEPTED, null, $userId);
    }

    /**
     * Recusa. Reembolso exige um dos motivos da lista que a 99 mandou (base_reason_id); o texto
     * vai ao cliente. [VALIDAR 99Food] se a recusa de cancelamento aceita texto livre.
     */
    public function reject(Food99Request $request, string $reason, ?string $baseReasonId = null, ?int $userId = null): void
    {
        $this->assertOpen($request);

        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 200) {
            throw new InvalidArgumentException('Explique a recusa ao cliente (5 a 200 caracteres).');
        }

        $integration = $this->integration($request);

        if ($request->kind === Food99Request::KIND_REFUND) {
            $option = collect($request->reason_options ?? [])->firstWhere('id', (string) $baseReasonId);
            if (! $option) {
                throw new InvalidArgumentException('Escolha um dos motivos de recusa oferecidos pela 99Food.');
            }

            $this->gateway->respondRefundApply($integration, $request->apply_id, false, (int) $option['id'], $option['label'], $reason);
        } else {
            $this->gateway->respondCancelApply($integration, $request->apply_id, false, $reason);
        }

        $this->record($request, Food99Request::STATUS_REJECTED, $reason, $userId);
    }

    /** Solicitações sem resposta no prazo: a 99 já decidiu (cancelamento recusado, reembolso aceito). */
    public function expireOverdue(): int
    {
        $expired = 0;

        Food99Request::withoutGlobalScopes()
            ->where('status', Food99Request::STATUS_OPEN)
            ->where('expires_at', '<', now())
            ->lazyById()
            ->each(function (Food99Request $request) use (&$expired) {
                $request->update(['status' => Food99Request::STATUS_EXPIRED]);
                $expired++;

                if ($order = $request->order_id ? Order::withoutGlobalScopes()->find($request->order_id) : null) {
                    Food99OrderUpdated::dispatch($order);
                }
            });

        return $expired;
    }

    private function record(Food99Request $request, string $status, ?string $reason, ?int $userId): void
    {
        $request->update([
            'status' => $status,
            'response_reason' => $reason,
            'responded_by' => $userId,
            'responded_at' => now(),
        ]);

        if ($order = $request->order_id ? Order::withoutGlobalScopes()->find($request->order_id) : null) {
            Food99OrderUpdated::dispatch($order);
        }

        Log::channel('food99')->info('99Food: solicitação do cliente respondida', [
            'food99_request_id' => $request->id,
            'kind' => $request->kind,
            'status' => $status,
            'user_id' => $userId,
        ]);
    }

    private function assertOpen(Food99Request $request): void
    {
        if (! $request->isOpen()) {
            throw new RuntimeException('Esta solicitação já foi respondida ou o prazo acabou.');
        }
    }

    private function integration(Food99Request $request): Food99Integration
    {
        $integration = Food99Integration::withoutGlobalScopes()
            ->where('company_id', $request->company_id)
            ->whereKey($request->food99_integration_id)
            ->whereIn('status', [Food99Integration::STATUS_ACTIVE, Food99Integration::STATUS_PAUSED])
            ->first();

        return $integration ?? throw new RuntimeException('A 99Food não está conectada nesta filial. Reconecte a integração para responder.');
    }

    private function findOrder(Food99Integration $integration, ?string $externalOrderId): ?Order
    {
        return $externalOrderId ? Order::withoutGlobalScopes()
            ->where('company_id', $integration->company_id)
            ->where('channel', OrderChannel::Food99->value)
            ->where('external_order_id', $externalOrderId)
            ->first() : null;
    }

    /** @return array<int, array{id: string, label: string}> */
    private function reasonOptions(mixed $list): array
    {
        return collect(is_array($list) ? $list : [])
            ->map(function ($item, $key) {
                if (is_string($item)) {
                    return ['id' => (string) $key, 'label' => $item];
                }
                if (! is_array($item)) {
                    return null;
                }
                $id = $item['base_reason_id'] ?? $item['reason_id'] ?? $item['id'] ?? $key;
                $label = $item['base_reason'] ?? $item['reason'] ?? $item['desc'] ?? $item['name'] ?? null;

                return $label !== null ? ['id' => (string) $id, 'label' => (string) $label] : null;
            })
            ->filter()->values()->all();
    }

    private function notifyNew(Food99Request $request, ?Order $order): void
    {
        $number = $order?->food99Details()?->displayId();
        $cancel = $request->kind === Food99Request::KIND_CANCEL;

        CompanyNotification::create([
            'company_id' => $request->company_id,
            'type' => 'food99_request',
            'title' => ($cancel ? 'Cliente pediu cancelamento' : 'Cliente pediu reembolso').' — 99Food'.($number ? " #{$number}" : ''),
            'subtitle' => $cancel
                ? 'Responda em até 5 min. Sem resposta, a 99Food recusa o cancelamento.'
                : 'Responda em até 24 h. Sem resposta, a 99Food aceita o reembolso.',
            'link' => $order ? route('admin.orders.show', $order->id) : route('admin.orders.index'),
        ]);
    }
}
