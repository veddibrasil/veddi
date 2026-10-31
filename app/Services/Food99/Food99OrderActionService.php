<?php

namespace App\Services\Food99;

use App\Contracts\Food99GatewayContract;
use App\Enums\Food99CancelReason;
use App\Jobs\RefreshFood99OrderDetailsJob;
use App\Models\Food99Integration;
use App\Models\Order;
use App\Support\Food99\Food99OrderDetails;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/**
 * Ações da loja num pedido 99Food. Cada uma chama a API da 99 primeiro e só muda o pedido
 * local se a 99 aceitar (errno 0), pra tela nunca mostrar uma etapa que a 99 não tem.
 */
class Food99OrderActionService
{
    /** Previsão de entrega própria quando a 99 não informou expected_arrived_eta. */
    private const DEFAULT_DELIVERY_MINUTES = 40;

    public function __construct(
        private readonly Food99GatewayContract $gateway,
        private readonly Food99OrderStatusSync $sync,
    ) {}

    /** Confirma o pedido (prazo de 5 min). ETAs só vêm depois de confirmar: detalhe atualizado em seguida. */
    public function accept(Order $order, ?int $userId = null): void
    {
        $details = $this->details($order);
        if (! $details->awaitingConfirmation()) {
            throw new RuntimeException('Este pedido já foi confirmado ou encerrado na 99Food.');
        }

        $this->gateway->confirmOrder($this->resolveIntegration($order), $details->orderId());
        $this->sync->advance($order, 'CONFIRMED', $userId, 'veddi');
        RefreshFood99OrderDetailsJob::dispatch($order->id)->delay(now()->addSeconds(20));

        $this->log('pedido aceito', $order, $userId);
    }

    public function markReady(Order $order, ?int $userId = null): void
    {
        $details = $this->details($order);
        if (! $details->canMarkReady()) {
            throw new RuntimeException($details->awaitingConfirmation()
                ? 'Aceite o pedido antes de marcar como pronto.'
                : 'O pedido não está em preparo na 99Food.');
        }

        $this->gateway->markOrderReady($this->resolveIntegration($order), $details->orderId());
        $this->sync->advance($order, 'READY', $userId, 'veddi');

        $this->log('pedido pronto', $order, $userId);
    }

    /** Saiu para entrega (só entrega própria). */
    public function dispatch(Order $order, ?int $userId = null, ?string $courierName = null, ?string $courierPhone = null): void
    {
        $details = $this->details($order);
        if (! $details->canDispatch()) {
            throw new RuntimeException(match (true) {
                $details->isPickup() => 'Pedido de retirada não tem despacho: marque como retirado quando o cliente buscar.',
                $details->isDeliveredByPlatform() => 'A entrega deste pedido é da 99Food: o despacho acontece quando o entregador coleta.',
                $details->isFinished() => 'Este pedido já foi encerrado na 99Food.',
                default => 'Aceite o pedido antes de despachar.',
            });
        }

        $integration = $this->resolveIntegration($order);
        $arrival = $details->get('expected_arrived_eta');
        $deliveryTime = is_numeric($arrival) && (int) $arrival > now()->getTimestamp()
            ? (int) $arrival
            : now()->addMinutes(self::DEFAULT_DELIVERY_MINUTES)->getTimestamp();

        $this->gateway->dispatchSelfDelivery($integration, $details->orderId(), $this->courierInfo($order, $courierName, $courierPhone), [
            'pickup_time' => now()->getTimestamp(),
            'delivery_time' => $deliveryTime,
        ]);

        $this->sync->advance($order, 'DISPATCHED', $userId, 'veddi');
        $this->log('pedido despachado (entrega própria)', $order, $userId);
    }

    /** Entrega própria concluída; com o código que o cliente informou, valida na 99. */
    public function markDelivered(Order $order, ?int $userId = null, ?string $deliveryCode = null): void
    {
        $details = $this->details($order);
        if (! $details->canMarkDelivered()) {
            throw new RuntimeException($details->isDeliveredByPlatform()
                ? 'A entrega deste pedido é da 99Food: ela conclui o pedido após a entrega.'
                : 'Despache o pedido antes de marcar como entregue.');
        }

        $integration = $this->resolveIntegration($order);
        $code = $deliveryCode !== null ? trim($deliveryCode) : '';

        if ($code !== '') {
            $this->gateway->verifyDeliveryCode($integration, $details->orderId(), $code);
        } else {
            // "Entregue" para no status 500; sem o código, quem conclui na 99 é o order/finish.
            $this->gateway->markSelfDelivered($integration, $details->orderId());
            $this->gateway->finishOrder($integration, $details->orderId());
        }

        $this->sync->advance($order, 'FINISHED', $userId, 'veddi');
        $this->log('pedido entregue (entrega própria)', $order, $userId, ['com_codigo' => $code !== '']);
    }

    /** Retirada: cliente buscou o pedido (order/finish). Confere o código de retirada se informado. */
    public function finishPickup(Order $order, ?int $userId = null, ?string $takeawayCode = null): void
    {
        $details = $this->details($order);
        if (! $details->canFinishPickup()) {
            throw new RuntimeException($details->isPickup()
                ? 'Aceite o pedido antes de marcar como retirado.'
                : 'Só pedido de retirada é concluído pela loja.');
        }

        $expected = $details->takeawayCode();
        if ($takeawayCode !== null && trim($takeawayCode) !== '' && $expected !== null
            && strcasecmp(trim($takeawayCode), $expected) !== 0) {
            throw new InvalidArgumentException('Código de retirada não confere com o do pedido.');
        }

        $integration = $this->resolveIntegration($order);
        if ($details->stage() === 'CONFIRMED') {
            $this->gateway->markOrderReady($integration, $details->orderId());
            $this->sync->advance($order, 'READY', $userId, 'veddi');
        }

        $this->gateway->finishOrder($integration, $details->orderId());
        $this->sync->advance($order, 'FINISHED', $userId, 'veddi');
        $this->log('pedido retirado pelo cliente', $order, $userId);
    }

    /**
     * Recusa (antes do aceite) ou cancela (depois) pelo lado da loja. Com errno 0 a 99 já
     * cancelou: o pedido local é cancelado na hora (o callback orderCancel, se vier, é idempotente).
     */
    public function cancel(Order $order, int $reasonId, ?string $reasonText = null, ?int $userId = null): void
    {
        $details = $this->details($order);
        if ($details->isFinished()) {
            throw new RuntimeException('Este pedido já foi encerrado na 99Food.');
        }

        $reason = $this->assertValidReason($details, $reasonId, $reasonText);
        $text = $reason->requiresText() ? trim((string) $reasonText) : $reason->label();
        $wasAccepted = ! $details->awaitingConfirmation();

        $this->gateway->cancelOrder($this->resolveIntegration($order), $details->orderId(), $reason->value, $text);

        $this->sync->mergeMetadata($order, ['cancellation_request' => [
            'status' => 'requested',
            'reason_code' => $reason->value,
            'reason' => $text,
            'at' => now()->toIso8601String(),
            'user_id' => $userId,
        ]], broadcast: false);
        $this->sync->cancel($order, ['origin' => 'store', 'code' => 921, 'reason' => ($wasAccepted ? 'Cancelado pela loja' : 'Recusado pela loja').": {$text}"]);

        $this->log($wasAccepted ? 'pedido cancelado pela loja' : 'pedido recusado pela loja', $order, $userId, ['reason_id' => $reason->value]);
    }

    /**
     * Cancela parte dos itens (por linha da 99, mdu_id). Os itens do pedido local não são
     * reescritos aqui [VALIDAR 99Food]: o reembolso fica no snapshot e no detalhe do pedido.
     *
     * @param  array<string, int>  $quantities  mdu_id => quantidade a cancelar
     * @param  int|null  $soldOutType  1 = esgotado hoje, 2 = esgotado até reativar
     */
    public function partialCancel(Order $order, array $quantities, ?int $soldOutType = null, ?int $userId = null): void
    {
        $details = $this->details($order);
        if ($details->isFinished()) {
            throw new RuntimeException('Este pedido já foi encerrado na 99Food.');
        }

        $lines = collect($details->get('order_items', []))->keyBy('mdu_id');
        $items = [];
        foreach ($quantities as $mduId => $amount) {
            $line = $lines->get((string) $mduId);
            $amount = (int) $amount;
            if (! $line || $amount <= 0) {
                continue;
            }
            if ($amount > (int) $line['amount']) {
                throw new InvalidArgumentException("Quantidade a cancelar de {$line['name']} maior que a do pedido.");
            }
            $items[] = ['app_item_id' => (string) $line['app_item_id'], 'mdu_id' => (string) $mduId, 'cancel_amount' => $amount];
        }

        if ($items === []) {
            throw new InvalidArgumentException('Escolha ao menos um item para cancelar.');
        }
        if (count($items) === $lines->count() && collect($items)->every(fn ($item) => $item['cancel_amount'] === (int) $lines[$item['mdu_id']]['amount'])) {
            throw new InvalidArgumentException('Pra cancelar todos os itens, cancele o pedido inteiro.');
        }

        $soldOut = $soldOutType !== null ? array_column($items, 'app_item_id') : [];
        $this->gateway->partialCancel($this->resolveIntegration($order), $details->orderId(), $items, $soldOut, $soldOutType);

        $history = $details->get('partial_cancellations', []);
        $history[] = ['items' => $items, 'sold_out' => $soldOutType, 'at' => now()->toIso8601String(), 'user_id' => $userId, 'source' => 'veddi'];
        $this->sync->mergeMetadata($order, ['partial_cancellations' => $history]);

        $this->log('itens cancelados parcialmente', $order, $userId, ['linhas' => count($items)]);
    }

    /** Entrega 99 em dinheiro: a loja recebeu do entregador o valor do pedido. */
    public function confirmCashPayment(Order $order, ?int $userId = null): void
    {
        $details = $this->details($order);
        if (! $details->canConfirmCashPayment()) {
            throw new RuntimeException('Não há pagamento em dinheiro do entregador 99 a confirmar neste pedido.');
        }

        $this->gateway->confirmCashPayment($this->resolveIntegration($order), $details->orderId());
        $this->sync->mergeMetadata($order, ['cash_confirmed_at' => now()->toIso8601String(), 'cash_confirmed_by' => $userId]);
        $this->sync->settlePendingPayments($order);

        $this->log('dinheiro do entregador confirmado', $order, $userId);
    }

    /** @return array<int, Food99CancelReason> */
    public function cancelReasons(Order $order): array
    {
        return Food99CancelReason::forDelivery($this->details($order)->isSelfDelivery());
    }

    public function resolveIntegration(Order $order): Food99Integration
    {
        if ($order->channel !== '99food' || ! $order->external_order_id) {
            throw new InvalidArgumentException('Pedido não pertence à 99Food.');
        }

        // Suspensa não recebe pedido novo, mas os em andamento precisam terminar.
        $integration = Food99Integration::withoutGlobalScopes()
            ->where('company_id', $order->company_id)
            ->where('branch_id', $order->branch_id)
            ->whereIn('status', [Food99Integration::STATUS_ACTIVE, Food99Integration::STATUS_PAUSED])
            ->whereNotNull('shop_id')
            ->first();

        if (! $integration) {
            throw new RuntimeException('A 99Food não está conectada nesta filial. Reconecte a integração para operar o pedido.');
        }

        return $integration;
    }

    private function assertValidReason(Food99OrderDetails $details, int $reasonId, ?string $reasonText): Food99CancelReason
    {
        $reason = Food99CancelReason::tryFrom($reasonId);

        if (! $reason || ($reason->selfDeliveryOnly() && ! $details->isSelfDelivery())) {
            throw new InvalidArgumentException('Motivo de recusa/cancelamento inválido para este pedido.');
        }

        $text = trim((string) $reasonText);
        if ($reason->requiresText() && (mb_strlen($text) < 5 || mb_strlen($text) > 200)) {
            throw new InvalidArgumentException('Descreva o motivo (5 a 200 caracteres).');
        }

        return $reason;
    }

    /** A 99 exige nome, sobrenome e telefone; o painel não cadastra entregador, então vale o telefone da filial. */
    private function courierInfo(Order $order, ?string $name, ?string $phone): array
    {
        // Sem escopo porque o aviso à 99 também roda em job; a relação já limita à filial do pedido.
        $phone = preg_replace('/\D/', '', (string) ($phone ?: $order->branch()->withoutGlobalScopes()->value('phone')));

        if ($phone === '') {
            throw new RuntimeException('A 99Food exige um telefone para o entregador. Cadastre o telefone da filial e tente de novo.');
        }

        $name = trim((string) $name) ?: 'Entregador da loja';
        [$firstName, $lastName] = array_pad(explode(' ', $name, 2), 2, null);

        return [
            'courier_name' => $name,
            'courier_first_name' => $firstName,
            'courier_last_name' => $lastName ?: $firstName,
            'courier_phone_code' => '+55',
            'courier_phone' => $phone,
        ];
    }

    private function details(Order $order): Food99OrderDetails
    {
        return $order->food99Details() ?? throw new InvalidArgumentException('Pedido não pertence à 99Food.');
    }

    private function log(string $message, Order $order, ?int $userId, array $context = []): void
    {
        Log::channel('food99')->info("99Food: {$message}", array_merge([
            'order_id' => $order->id,
            'food99_order_id' => $order->external_order_id,
            'user_id' => $userId,
        ], $context));
    }
}
