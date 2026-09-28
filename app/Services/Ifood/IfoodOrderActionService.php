<?php

namespace App\Services\Ifood;

use App\Contracts\IfoodGatewayContract;
use App\Events\IfoodOrderUpdated;
use App\Models\IfoodDispute;
use App\Models\IfoodIntegration;
use App\Models\Order;
use App\Support\Ifood\IfoodOrderDetails;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/**
 * Ações da loja num pedido iFood. Cada uma chama a API do iFood primeiro e só muda o
 * pedido local se o iFood aceitar, pra tela nunca mostrar uma etapa que o iFood não tem.
 */
class IfoodOrderActionService
{
    private readonly IfoodOrderStatusSync $sync;

    public function __construct(private readonly IfoodGatewayContract $gateway, ?IfoodOrderStatusSync $sync = null)
    {
        $this->sync = $sync ?? app(IfoodOrderStatusSync::class);
    }

    /**
     * Confirma o pedido no iFood. Pedido imediato já vai pra "Preparando"; agendado
     * continua em "Agendado" até a loja iniciar o preparo.
     */
    public function accept(Order $order, ?int $userId = null): void
    {
        $details = $this->details($order);
        if (! $details->awaitingConfirmation()) {
            throw new RuntimeException('Este pedido já foi confirmado ou encerrado no iFood.');
        }

        $this->gateway->confirmOrder($this->resolveIntegration($order), $order->external_order_id);
        $this->sync->advance($order, 'CONFIRMED', $userId, 'veddi');

        $this->log('pedido aceito', $order, $userId);
    }

    public function startPreparation(Order $order, ?int $userId = null): void
    {
        if (! $this->details($order)->canStartPreparation()) {
            throw new RuntimeException('Só dá pra iniciar o preparo de pedido agendado já confirmado.');
        }

        $this->gateway->updateOrderStatus($this->resolveIntegration($order), $order->external_order_id, 'preparing');
        $this->sync->advance($order, 'PREPARATION_STARTED', $userId, 'veddi');

        $this->log('preparo iniciado', $order, $userId);
    }

    /** Avisa o iFood que o pedido está pronto (etapa obrigatória antes do despacho). */
    public function markReady(Order $order, ?int $userId = null): void
    {
        $details = $this->details($order);
        if (! $details->canMarkReady()) {
            throw new RuntimeException($details->awaitingConfirmation()
                ? 'Aceite o pedido antes de marcar como pronto.'
                : 'O pedido não está em preparo no iFood.');
        }

        $this->gateway->updateOrderStatus($this->resolveIntegration($order), $order->external_order_id, 'ready');
        $this->sync->advance($order, 'READY_TO_PICKUP', $userId, 'veddi');

        $this->log('pedido pronto', $order, $userId);
    }

    /** Despacho de entrega própria. Entrega pelo iFood é despachada quando o entregador retira. */
    public function dispatch(Order $order, ?int $userId = null): void
    {
        $details = $this->details($order);
        if (! $details->canDispatch()) {
            throw new RuntimeException(match (true) {
                ! $details->isDelivery() => 'Pedido de retirada ou consumo no local não tem despacho: o iFood conclui quando o cliente retira.',
                $details->isDeliveredByIfood() => 'A entrega deste pedido é feita pelo iFood: o despacho acontece quando o entregador retira.',
                $details->isFinished() => 'Este pedido já foi encerrado no iFood.',
                default => 'Marque o pedido como pronto antes de despachar: o iFood exige o aviso de pronto primeiro.',
            });
        }

        $this->gateway->updateOrderStatus($this->resolveIntegration($order), $order->external_order_id, 'out_for_delivery');
        $this->sync->advance($order, 'DISPATCHED', $userId, 'veddi');

        $this->log('pedido despachado', $order, $userId);
    }

    /**
     * Recusa o pedido antes de aceitar. Motivo precisa ser um dos códigos
     * fechados aceitos pelo iFood — validado ANTES de chamar a API externa.
     * O pedido só é cancelado aqui quando o evento CANCELLED chegar.
     */
    public function reject(Order $order, string $reasonCode, ?int $userId = null): void
    {
        $reason = $this->assertValidReason($order, $reasonCode);

        $this->gateway->rejectOrder($this->resolveIntegration($order), $order->external_order_id, $reasonCode);
        $this->recordCancellationRequest($order, $reasonCode, $reason, $userId);

        $this->log('pedido recusado', $order, $userId, ['reason' => $reasonCode]);
    }

    /**
     * Solicita cancelamento de um pedido já aceito. O cancelamento no iFood não é
     * imediato: vem CANCELLED (aceito) ou CANCELLATION_REQUEST_FAILED (recusado) depois
     * (ver IfoodOrderEventProcessor). O status local não muda aqui.
     */
    public function requestCancellation(Order $order, string $reasonCode, ?int $userId = null): void
    {
        $reason = $this->assertValidReason($order, $reasonCode);

        $this->gateway->requestCancellation($this->resolveIntegration($order), $order->external_order_id, $reasonCode);
        $this->recordCancellationRequest($order, $reasonCode, $reason, $userId);

        $this->log('cancelamento solicitado, aguardando confirmação do iFood', $order, $userId, ['reason' => $reasonCode]);
    }

    public function getCancellationReasons(Order $order): array
    {
        return $this->gateway->getCancellationReasons($this->resolveIntegration($order), $order->external_order_id);
    }

    public function acceptDispute(IfoodDispute $dispute, ?string $reason = null, ?int $userId = null): void
    {
        $this->assertDisputeOpen($dispute);

        $reasons = $dispute->acceptReasons();
        if ($reasons !== [] && ! in_array($reason, $reasons, true)) {
            throw new InvalidArgumentException('Escolha um dos motivos de aceite oferecidos pelo iFood.');
        }

        $this->gateway->acceptDispute($this->disputeIntegration($dispute), $dispute->dispute_id, $reason);
        $this->recordDisputeResponse($dispute, 'ACCEPTED', $reason, $userId);
    }

    public function rejectDispute(IfoodDispute $dispute, string $reason, ?int $userId = null): void
    {
        $this->assertDisputeOpen($dispute);

        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 250) {
            throw new InvalidArgumentException('Explique a recusa ao cliente (5 a 250 caracteres).');
        }

        $this->gateway->rejectDispute($this->disputeIntegration($dispute), $dispute->dispute_id, $reason);
        $this->recordDisputeResponse($dispute, 'REJECTED', $reason, $userId);
    }

    /**
     * Contraproposta com uma das alternativas da disputa: reembolso/benefício em reais
     * (até o máximo que o iFood permitir) ou tempo adicional de entrega.
     *
     * @param  array{amount?: float|string|null, minutes?: int|string|null, reason?: ?string}  $input
     */
    public function proposeAlternative(IfoodDispute $dispute, string $alternativeId, array $input, ?int $userId = null): void
    {
        $this->assertDisputeOpen($dispute);

        $alternative = collect($dispute->alternatives ?? [])->first(fn ($alt) => (string) ($alt['id'] ?? '') === $alternativeId);
        if (! $alternative) {
            throw new InvalidArgumentException('Alternativa não oferecida nesta negociação.');
        }

        $body = $this->alternativeBody($alternative, $input);

        $this->gateway->proposeDisputeAlternative($this->disputeIntegration($dispute), $dispute->dispute_id, $alternativeId, $body);
        $this->recordDisputeResponse($dispute, 'ALTERNATIVE', json_encode($body['metadata'], JSON_UNESCAPED_UNICODE), $userId);
    }

    private function alternativeBody(array $alternative, array $input): array
    {
        $type = (string) ($alternative['type'] ?? '');
        $meta = $alternative['metadata'] ?? [];

        if ($type === 'ADDITIONAL_TIME') {
            $minutes = (string) (int) ($input['minutes'] ?? 0);
            $reason = (string) ($input['reason'] ?? '');
            $allowedMinutes = array_map('strval', $meta['allowedsAdditionalTimeInMinutes'] ?? []);
            $allowedReasons = array_map('strval', $meta['allowedsAdditionalTimeReasons'] ?? []);

            if (($allowedMinutes !== [] && ! in_array($minutes, $allowedMinutes, true)) || (int) $minutes <= 0) {
                throw new InvalidArgumentException('Escolha um tempo adicional permitido pelo iFood.');
            }
            if ($allowedReasons !== [] && ! in_array($reason, $allowedReasons, true)) {
                throw new InvalidArgumentException('Escolha um motivo de atraso permitido pelo iFood.');
            }

            return ['type' => $type, 'metadata' => array_filter([
                'additionalTimeInMinutes' => $minutes,
                'additionalTimeReason' => $reason ?: null,
            ])];
        }

        // REFUND / BENEFIT: valor em centavos, como o iFood manda em maxAmount.
        $cents = (int) round(((float) str_replace(',', '.', (string) ($input['amount'] ?? 0))) * 100);
        $max = isset($meta['maxAmount']['value']) ? (int) $meta['maxAmount']['value'] : null;

        if ($cents <= 0 || ($max !== null && $cents > $max)) {
            throw new InvalidArgumentException($max !== null
                ? 'Informe um valor entre R$ 0,01 e R$ '.number_format($max / 100, 2, ',', '.').'.'
                : 'Informe um valor maior que zero.');
        }

        return ['type' => $type, 'metadata' => [
            'amount' => ['value' => (string) $cents, 'currency' => $meta['maxAmount']['currency'] ?? 'BRL'],
        ]];
    }

    private function disputeIntegration(IfoodDispute $dispute): IfoodIntegration
    {
        return IfoodIntegration::withoutGlobalScopes()
            ->where('company_id', $dispute->company_id)
            ->findOrFail($dispute->ifood_integration_id);
    }

    private function assertDisputeOpen(IfoodDispute $dispute): void
    {
        if (! $dispute->isOpen()) {
            throw new RuntimeException('Esta negociação já foi respondida ou expirou.');
        }
    }

    private function recordDisputeResponse(IfoodDispute $dispute, string $response, ?string $reason, ?int $userId): void
    {
        $dispute->update([
            'status' => IfoodDispute::STATUS_RESPONDED,
            'response' => $response,
            'response_reason' => $reason !== null ? mb_substr($reason, 0, 250) : null,
            'responded_by' => $userId,
            'responded_at' => now(),
        ]);

        $order = $dispute->order_id ? Order::withoutGlobalScopes()->find($dispute->order_id) : null;
        if ($order) {
            IfoodOrderUpdated::dispatch($order);
        }

        Log::channel('ifood')->info('iFood: negociação respondida', [
            'dispute_id' => $dispute->dispute_id,
            'order_id' => $dispute->order_id,
            'response' => $response,
            'user_id' => $userId,
        ]);
    }

    private function recordCancellationRequest(Order $order, string $reasonCode, ?string $reason, ?int $userId): void
    {
        $this->sync->mergeMetadata($order, ['cancellation_request' => [
            'status' => 'requested',
            'reason_code' => $reasonCode,
            'reason' => $reason,
            'at' => now()->toIso8601String(),
            'user_id' => $userId,
        ]]);
    }

    /** @return string|null descrição do motivo */
    private function assertValidReason(Order $order, string $reasonCode): ?string
    {
        $reason = collect($this->getCancellationReasons($order))->first(fn ($reason) => (string) ($reason['code'] ?? '') === $reasonCode);

        if (! $reason) {
            throw new InvalidArgumentException("Motivo inválido para recusa/cancelamento iFood: '{$reasonCode}'.");
        }

        return $reason['description'] ?? null;
    }

    private function details(Order $order): IfoodOrderDetails
    {
        return $order->ifoodDetails() ?? throw new InvalidArgumentException('Pedido não pertence ao iFood.');
    }

    private function resolveIntegration(Order $order): IfoodIntegration
    {
        if ($order->channel !== 'ifood' || ! $order->external_order_id) {
            throw new InvalidArgumentException('Pedido não pertence ao iFood.');
        }
        $integration = IfoodIntegration::withoutGlobalScopes()->where('company_id', $order->company_id)->where('branch_id', $order->branch_id)
            ->where('status', 'active')
            ->first();

        if (! $integration) {
            throw new RuntimeException("iFood: nenhuma integração ativa encontrada para a filial #{$order->branch_id}.");
        }

        return $integration;
    }

    private function log(string $message, Order $order, ?int $userId, array $context = []): void
    {
        Log::channel('ifood')->info("iFood: {$message}", array_merge([
            'order_id' => $order->id,
            'ifood_order_id' => $order->external_order_id,
            'user_id' => $userId,
        ], $context));
    }
}
