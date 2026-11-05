<?php

namespace App\Services\Keeta;

use App\Contracts\KeetaEventHandler;
use App\Contracts\KeetaGatewayContract;
use App\Events\KeetaOrderUpdated;
use App\Exceptions\KeetaApiException;
use App\Models\CompanyNotification;
use App\Models\KeetaIntegration;
use App\Models\KeetaRequest;
use App\Models\KeetaWebhookEvent;
use App\Models\Order;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/**
 * Solicitações do cliente na Keeta:
 *  - USER_REFUND_REQUEST: a loja aceita (acceptRefund) ou recusa (rejectRefund); vale a
 *    primeira resposta, pela API ou pelo portal. REFUNDED/REFUND_FAILED fecham a solicitação.
 *  - CANCELLATION_REQUESTED: se foi a loja que pediu (requestCancellation), só confirma o
 *    pedido em andamento; se foi o cliente, abre um aviso. Quem cancela é o evento CANCELLED.
 */
class KeetaRequestService implements KeetaEventHandler
{
    public const TYPES = ['USER_REFUND_REQUEST', 'CANCELLATION_REQUESTED', 'REFUNDED', 'REFUND_FAILED'];

    public function __construct(
        private readonly KeetaGatewayContract $gateway,
        private readonly KeetaOrderStatusSync $sync,
    ) {}

    public function handles(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    public function handle(KeetaWebhookEvent $event): bool
    {
        $integration = $event->keetaIntegration;
        if (! $integration || ! $event->external_order_id) {
            return false;
        }

        $order = KeetaOrderEventProcessor::findOrder($event);
        if ($order) {
            $event->update(['order_id' => $order->id]);
        }

        return match ($event->event_type) {
            'USER_REFUND_REQUEST' => $this->open($event, $integration, $order, KeetaRequest::KIND_REFUND),
            'CANCELLATION_REQUESTED' => $this->cancellationRequested($event, $integration, $order),
            'REFUNDED' => $this->refundFinished($event, $integration, $order, true),
            'REFUND_FAILED' => $this->refundFinished($event, $integration, $order, false),
            default => false,
        };
    }

    public function accept(KeetaRequest $request, ?int $userId = null): void
    {
        $this->assertAnswerable($request);

        try {
            $this->gateway->acceptRefund($this->integration($request), $request->external_order_id);
        } catch (KeetaApiException $e) {
            // 422: já respondida pelo portal; o REFUNDED/REFUND_FAILED fecha a solicitação.
            if (! $e->isStateConflict()) {
                throw $e;
            }
        }

        $this->record($request, KeetaRequest::STATUS_ACCEPTED, null, $userId);
    }

    public function reject(KeetaRequest $request, string $code, string $reason, ?int $userId = null): void
    {
        $this->assertAnswerable($request);

        $reason = trim($reason);
        if (! isset(KeetaRequest::REJECT_CODES[$code])) {
            throw new InvalidArgumentException('Escolha o motivo da recusa.');
        }
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 200) {
            throw new InvalidArgumentException('Explique a recusa ao cliente (5 a 200 caracteres).');
        }

        try {
            $this->gateway->rejectRefund($this->integration($request), $request->external_order_id, $code, $reason);
        } catch (KeetaApiException $e) {
            if (! $e->isStateConflict()) {
                throw $e;
            }
        }

        $this->record($request, KeetaRequest::STATUS_REJECTED, $reason, $userId);
    }

    /** Solicitações sem resposta no prazo ficam marcadas (a decisão final vem da Keeta). */
    public function expireOverdue(): int
    {
        $expired = 0;

        // Cancelamento pedido pelo cliente é decidido pela Keeta e fecha com o CANCELLED.
        KeetaRequest::withoutGlobalScopes()
            ->where('kind', KeetaRequest::KIND_REFUND)
            ->where('status', KeetaRequest::STATUS_OPEN)
            ->where('expires_at', '<', now())
            ->lazyById()
            ->each(function (KeetaRequest $request) use (&$expired) {
                $request->update(['status' => KeetaRequest::STATUS_EXPIRED]);
                $expired++;

                if ($order = $request->order_id ? Order::withoutGlobalScopes()->find($request->order_id) : null) {
                    KeetaOrderUpdated::dispatch($order);
                }
            });

        return $expired;
    }

    private function cancellationRequested(KeetaWebhookEvent $event, KeetaIntegration $integration, ?Order $order): bool
    {
        // Eco do requestCancellation da própria loja: o pedido já está marcado como em andamento.
        if ($order && ($order->keetaDetails()?->get('cancellation_request')['status'] ?? null) === 'requested') {
            return true;
        }

        return $this->open($event, $integration, $order, KeetaRequest::KIND_CANCEL);
    }

    private function open(KeetaWebhookEvent $event, KeetaIntegration $integration, ?Order $order, string $kind): bool
    {
        $metadata = $event->metadata();
        $money = $metadata['money'] ?? null;

        try {
            $request = KeetaRequest::withoutGlobalScopes()->create([
                'company_id' => $integration->company_id,
                'keeta_integration_id' => $integration->id,
                'order_id' => $order?->id,
                'external_order_id' => (string) $event->external_order_id,
                'kind' => $kind,
                'event_id' => $event->event_id,
                'reason' => mb_substr((string) ($metadata['applyReason'] ?? $metadata['reason'] ?? ''), 0, 2000) ?: null,
                // Metadata de reembolso vem em centavos.
                'amount' => is_numeric($money) ? round(((float) $money) / 100, 2) : null,
                'items' => $this->refundItems($metadata),
                'expires_at' => now()->addMinutes(KeetaRequest::DEADLINE_MINUTES[$kind]),
                'status' => KeetaRequest::STATUS_OPEN,
            ]);
        } catch (UniqueConstraintViolationException) {
            return true;
        }

        $this->notifyNew($request, $order);

        if ($order) {
            KeetaOrderUpdated::dispatch($order);
        }

        Log::channel('keeta')->info('Keeta: solicitação do cliente recebida', [
            'keeta_request_id' => $request->id,
            'kind' => $kind,
            'order_id' => $order?->id,
        ]);

        return true;
    }

    private function refundFinished(KeetaWebhookEvent $event, KeetaIntegration $integration, ?Order $order, bool $refunded): bool
    {
        $metadata = $event->metadata();
        $money = $metadata['money'] ?? null;
        $amount = is_numeric($money) ? round(((float) $money) / 100, 2) : null;

        KeetaRequest::withoutGlobalScopes()
            ->where('company_id', $integration->company_id)
            ->where('external_order_id', (string) $event->external_order_id)
            ->where('kind', KeetaRequest::KIND_REFUND)
            ->whereIn('status', [KeetaRequest::STATUS_OPEN, KeetaRequest::STATUS_EXPIRED])
            ->update(['status' => $refunded ? KeetaRequest::STATUS_ACCEPTED : KeetaRequest::STATUS_REJECTED, 'responded_at' => now()]);

        if (! $order) {
            return true;
        }

        $refunds = $order->keetaDetails()?->get('refunds', []) ?? [];
        $refunds[] = array_filter([
            'status' => $refunded ? 'refunded' : 'failed',
            'amount' => $amount,
            'partial' => isset($metadata['partRefund']) ? (bool) $metadata['partRefund'] : null,
            'reason' => isset($metadata['handleReason']) ? mb_substr((string) $metadata['handleReason'], 0, 255) : null,
            'at' => now()->toIso8601String(),
        ], fn ($value) => $value !== null);
        $this->sync->mergeMetadata($order, ['refunds' => $refunds]);

        if ($refunded) {
            CompanyNotification::create([
                'company_id' => $order->company_id,
                'type' => 'keeta_alert',
                'title' => mb_substr('Reembolso feito no pedido Keeta #'.$order->keetaDetails()->displayId(), 0, 255),
                'subtitle' => $amount !== null ? 'Valor devolvido ao cliente: R$ '.number_format($amount, 2, ',', '.').'.' : 'A Keeta devolveu o valor ao cliente.',
                'link' => route('admin.orders.show', $order->id),
            ]);
        }

        return true;
    }

    private function record(KeetaRequest $request, string $status, ?string $reason, ?int $userId): void
    {
        $request->update([
            'status' => $status,
            'response_reason' => $reason,
            'responded_by' => $userId,
            'responded_at' => now(),
        ]);

        if ($order = $request->order_id ? Order::withoutGlobalScopes()->find($request->order_id) : null) {
            KeetaOrderUpdated::dispatch($order);
        }

        Log::channel('keeta')->info('Keeta: solicitação do cliente respondida', [
            'keeta_request_id' => $request->id,
            'kind' => $request->kind,
            'status' => $status,
            'user_id' => $userId,
        ]);
    }

    private function assertAnswerable(KeetaRequest $request): void
    {
        if (! $request->isAnswerable()) {
            throw new RuntimeException($request->kind === KeetaRequest::KIND_CANCEL
                ? 'O pedido de cancelamento do cliente é decidido pela Keeta.'
                : 'Esta solicitação já foi respondida ou o prazo acabou.');
        }
    }

    private function integration(KeetaRequest $request): KeetaIntegration
    {
        $integration = KeetaIntegration::withoutGlobalScopes()
            ->where('company_id', $request->company_id)
            ->whereKey($request->keeta_integration_id)
            ->whereIn('status', [KeetaIntegration::STATUS_ACTIVE, KeetaIntegration::STATUS_PAUSED])
            ->whereNotNull('keeta_merchant_id')
            ->first();

        return $integration ?? throw new RuntimeException('A Keeta não está conectada nesta filial. Reconecte a integração para responder.');
    }

    /** @return array<int, array{name: string, quantity: int, amount: ?float}>|null */
    private function refundItems(array $metadata): ?array
    {
        $items = collect(is_array($metadata['refundProducts'] ?? null) ? $metadata['refundProducts'] : [])
            ->filter(fn ($item) => is_array($item))
            ->map(fn (array $item) => [
                'name' => mb_substr((string) ($item['name'] ?? 'Item'), 0, 120),
                'quantity' => (int) ($item['refundCount'] ?? $item['count'] ?? 1),
                'amount' => isset($item['priceWithGroup']['amount']) ? round(((float) $item['priceWithGroup']['amount']) / 100, 2) : null,
            ])->values()->all();

        return $items === [] ? null : $items;
    }

    private function notifyNew(KeetaRequest $request, ?Order $order): void
    {
        $number = $order?->keetaDetails()?->displayId();
        $cancel = $request->kind === KeetaRequest::KIND_CANCEL;

        CompanyNotification::create([
            'company_id' => $request->company_id,
            'type' => 'keeta_request',
            'title' => ($cancel ? 'Cliente pediu cancelamento' : 'Cliente pediu reembolso').' — Keeta'.($number ? " #{$number}" : ''),
            'subtitle' => $cancel
                ? 'A Keeta analisa o pedido de cancelamento. Acompanhe no pedido.'
                : 'Aceite ou recuse o reembolso no pedido ou no portal da Keeta.',
            'link' => $order ? route('admin.orders.show', $order->id) : route('admin.orders.index'),
        ]);
    }
}
