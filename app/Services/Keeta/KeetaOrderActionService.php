<?php

namespace App\Services\Keeta;

use App\Contracts\KeetaGatewayContract;
use App\Enums\KeetaCancelReason;
use App\Exceptions\KeetaApiException;
use App\Models\KeetaIntegration;
use App\Models\Order;
use App\Support\Keeta\KeetaOrderDetails;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Ações da loja num pedido Keeta. Cada uma chama a API da Keeta primeiro e só muda o pedido
 * local se ela aceitar (2xx), pra tela nunca mostrar uma etapa que a Keeta não tem.
 *
 * 422 ("order status is not in a valid state") é ação repetida ou fora de ordem: relê o
 * pedido na Keeta e aplica a etapa real, sem tratar como erro se ela já foi alcançada.
 */
class KeetaOrderActionService
{
    public function __construct(
        private readonly KeetaGatewayContract $gateway,
        private readonly KeetaOrderStatusSync $sync,
    ) {}

    /** Confirma o pedido (prazo de 5 min; depois a Keeta cancela e fecha a loja temporariamente). */
    public function accept(Order $order, ?int $userId = null): void
    {
        $details = $this->details($order);
        if (! $details->awaitingConfirmation()) {
            throw new RuntimeException('Este pedido já foi confirmado ou encerrado na Keeta.');
        }

        $integration = $this->resolveIntegration($order);
        $this->call($order, $integration, 'CONFIRMED', fn () => $this->gateway->confirmOrder($integration, $details->orderId(), (string) $order->order_number));
        $this->sync->advance($order, 'CONFIRMED', $userId, 'veddi');

        $this->log('pedido confirmado', $order, $userId);
    }

    public function markReady(Order $order, ?int $userId = null): void
    {
        $details = $this->details($order);
        if (! $details->canMarkReady()) {
            throw new RuntimeException($details->awaitingConfirmation()
                ? 'Aceite o pedido antes de marcar como pronto.'
                : 'O pedido não está em preparo na Keeta.');
        }

        $integration = $this->resolveIntegration($order);
        $this->call($order, $integration, 'READY', fn () => $this->gateway->readyForPickup($integration, $details->orderId()));
        $this->sync->advance($order, 'READY', $userId, 'veddi');

        $this->log('pedido pronto', $order, $userId);
    }

    /** Saiu para entrega (só entrega própria). */
    public function dispatch(Order $order, ?int $userId = null): void
    {
        $details = $this->details($order);
        if (! $details->canDispatch()) {
            throw new RuntimeException(match (true) {
                $details->isPickup() => 'Pedido de retirada não tem despacho.',
                $details->isDeliveredByPlatform() => 'A entrega deste pedido é da Keeta: o despacho acontece quando o entregador coleta.',
                $details->isFinished() => 'Este pedido já foi encerrado na Keeta.',
                default => 'Aceite o pedido antes de despachar.',
            });
        }

        $integration = $this->resolveIntegration($order);
        $this->call($order, $integration, 'DISPATCHED', fn () => $this->gateway->dispatchOrder($integration, $details->orderId(), [
            'event' => ['type' => 'DELIVERY_ONGOING', 'datetime' => now()->utc()->format('Y-m-d\TH:i:s.v\Z')],
        ]));
        $this->sync->advance($order, 'DISPATCHED', $userId, 'veddi');

        $this->log('pedido despachado (entrega própria)', $order, $userId);
    }

    /** Entrega própria concluída. */
    public function markDelivered(Order $order, ?int $userId = null): void
    {
        $details = $this->details($order);
        if (! $details->canMarkDelivered()) {
            throw new RuntimeException($details->isDeliveredByPlatform()
                ? 'A entrega deste pedido é da Keeta: ela conclui o pedido após a entrega.'
                : 'Despache o pedido antes de marcar como entregue.');
        }

        $integration = $this->resolveIntegration($order);
        $this->call($order, $integration, 'DELIVERED', fn () => $this->gateway->markDelivered($integration, $details->orderId()));
        $this->sync->advance($order, 'DELIVERED', $userId, 'veddi');

        $this->log('pedido entregue (entrega própria)', $order, $userId);
    }

    /**
     * Pede o cancelamento à Keeta (recusa antes do aceite ou cancelamento depois). A Keeta
     * responde 202: o pedido local só cancela quando chegar o evento CANCELLED.
     *
     * @param  array<int, string>  $itemIds  ids de item da Keeta (UNAVAILABLE_ITEM/OUTDATED_MENU)
     */
    public function requestCancel(Order $order, string $code, ?string $reasonText = null, ?int $userId = null, array $itemIds = []): void
    {
        $details = $this->details($order);
        if ($details->isFinished()) {
            throw new RuntimeException('Este pedido já foi encerrado na Keeta.');
        }
        if ($details->cancellationPending()) {
            throw new RuntimeException('O cancelamento deste pedido já foi pedido à Keeta. Aguarde a confirmação.');
        }

        $reason = $this->assertValidReason($details, $code, $reasonText);
        $text = trim((string) $reasonText) !== '' ? trim((string) $reasonText) : $reason->label();
        $validIds = collect($details->get('order_items', []))->pluck('id')->map(fn ($id) => (string) $id)->all();
        $itemIds = $reason->takesItems() ? array_values(array_intersect(array_map('strval', $itemIds), $validIds)) : [];
        $wasAccepted = ! $details->awaitingConfirmation();

        $this->gateway->requestCancellation(
            $this->resolveIntegration($order),
            $details->orderId(),
            $reason->value,
            $text,
            'MANUAL',
            $reason === KeetaCancelReason::UnavailableItem ? $itemIds : [],
            $reason === KeetaCancelReason::OutdatedMenu ? $itemIds : [],
        );

        $this->sync->mergeMetadata($order, ['cancellation_request' => [
            'status' => 'requested',
            'reason_code' => $reason->value,
            'reason' => $text,
            'at' => now()->toIso8601String(),
            'user_id' => $userId,
        ]]);

        $this->log($wasAccepted ? 'cancelamento pedido pela loja' : 'recusa pedida pela loja', $order, $userId, ['code' => $reason->value]);
    }

    /** @return array<int, KeetaCancelReason> */
    public function cancelReasons(Order $order): array
    {
        return KeetaCancelReason::forDelivery($this->details($order)->isSelfDelivery());
    }

    public function resolveIntegration(Order $order): KeetaIntegration
    {
        if ($order->channel !== 'keeta' || ! $order->external_order_id) {
            throw new InvalidArgumentException('Pedido não pertence à Keeta.');
        }

        // Suspensa não recebe pedido novo, mas os em andamento precisam terminar.
        $integration = KeetaIntegration::withoutGlobalScopes()
            ->where('company_id', $order->company_id)
            ->where('branch_id', $order->branch_id)
            ->whereIn('status', [KeetaIntegration::STATUS_ACTIVE, KeetaIntegration::STATUS_PAUSED])
            ->whereNotNull('keeta_merchant_id')
            ->first();

        if (! $integration) {
            throw new RuntimeException('A Keeta não está conectada nesta filial. Reconecte a integração para operar o pedido.');
        }

        return $integration;
    }

    /**
     * Chama a Keeta. Com 422, relê o pedido e aplica a etapa real: se a etapa pedida (ou uma
     * depois dela) já foi alcançada, segue como feito; senão repassa o erro.
     */
    private function call(Order $order, KeetaIntegration $integration, string $targetStage, callable $action): void
    {
        try {
            $action();
        } catch (KeetaApiException $e) {
            if (! $e->isStateConflict()) {
                throw $e;
            }

            $remoteStage = $this->remoteStage($order, $integration);
            if ($remoteStage === 'CANCELLED') {
                throw new RuntimeException('Este pedido já foi cancelado na Keeta.');
            }

            $reached = $remoteStage !== null
                && (KeetaOrderDetails::STAGE_RANK[$remoteStage] ?? -1) >= (KeetaOrderDetails::STAGE_RANK[$targetStage] ?? PHP_INT_MAX);

            if ($remoteStage !== null) {
                $this->sync->advance($order, $remoteStage, source: 'keeta_reconcile');
            }

            if (! $reached) {
                throw $e;
            }
        }
    }

    private function remoteStage(Order $order, KeetaIntegration $integration): ?string
    {
        try {
            $remote = $this->gateway->getOrder($integration, $this->details($order)->orderId());
        } catch (Throwable) {
            return null;
        }

        $last = strtoupper((string) ($remote['lastEvent'] ?? ''));

        return $last === 'CANCELLED' ? 'CANCELLED' : (KeetaOrderDetails::EVENT_STAGES[$last] ?? null);
    }

    private function assertValidReason(KeetaOrderDetails $details, string $code, ?string $reasonText): KeetaCancelReason
    {
        $reason = KeetaCancelReason::tryFrom($code);

        if (! $reason || ($reason->selfDeliveryOnly() && ! $details->isSelfDelivery())) {
            throw new InvalidArgumentException('Motivo de recusa/cancelamento inválido para este pedido.');
        }

        $text = trim((string) $reasonText);
        if ($reason->requiresText() && (mb_strlen($text) < 5 || mb_strlen($text) > 200)) {
            throw new InvalidArgumentException('Descreva o motivo (5 a 200 caracteres).');
        }
        if (mb_strlen($text) > 200) {
            throw new InvalidArgumentException('O motivo pode ter no máximo 200 caracteres.');
        }

        return $reason;
    }

    private function details(Order $order): KeetaOrderDetails
    {
        return $order->keetaDetails() ?? throw new InvalidArgumentException('Pedido não pertence à Keeta.');
    }

    private function log(string $message, Order $order, ?int $userId, array $context = []): void
    {
        Log::channel('keeta')->info("Keeta: {$message}", array_merge([
            'order_id' => $order->id,
            'keeta_order_id' => $order->external_order_id,
            'user_id' => $userId,
        ], $context));
    }
}
