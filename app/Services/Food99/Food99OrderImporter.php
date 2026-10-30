<?php

namespace App\Services\Food99;

use App\Contracts\Food99EventHandler;
use App\Contracts\Food99GatewayContract;
use App\Contracts\OrderServiceInterface;
use App\DTOs\Food99OrderDTO;
use App\Enums\OrderChannel;
use App\Events\Food99OrderNotImported;
use App\Events\NewOrderPlaced;
use App\Models\CompanyNotification;
use App\Models\Customer;
use App\Models\Food99WebhookEvent;
use App\Models\Order;
use App\Services\Payment\PaymentOrchestrator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Callback orderNew: pedido criado e pago na 99Food vira pedido "pendente" no painel. A loja
 * tem 5 minutos pra aceitar (Food99OrderActionService::accept) antes do cancelamento automático.
 */
class Food99OrderImporter implements Food99EventHandler
{
    public function __construct(
        private readonly Food99GatewayContract $gateway,
        private readonly Food99OrderMapper $mapper,
        private readonly OrderServiceInterface $orderService,
        private readonly PaymentOrchestrator $payments,
    ) {}

    public function handles(string $type): bool
    {
        return $type === 'orderNew';
    }

    public function handle(Food99WebhookEvent $event): bool
    {
        $integration = $event->food99Integration;
        $data = $event->data();
        $food99OrderId = (string) ($data['order_id'] ?? $event->external_order_id ?? '');

        if ($integration === null || $food99OrderId === '') {
            throw new RuntimeException("99Food: orderNew sem integração ou order_id (evento {$event->id}).");
        }

        // Reentrega com o pedido já criado: só vincula o evento.
        $existing = Order::withoutGlobalScopes()
            ->where('company_id', $integration->company_id)
            ->where('channel', OrderChannel::Food99->value)
            ->where('external_order_id', $food99OrderId)
            ->first();
        if ($existing) {
            $event->update(['order_id' => $existing->id]);

            return true;
        }

        $info = is_array($data['order_info'] ?? null) ? $data['order_info'] : [];
        if (! $this->isComplete($info)) {
            $info = $this->gateway->getOrderDetails($integration, $food99OrderId);
        }
        $info['order_id'] ??= $food99OrderId;

        $dto = Food99OrderDTO::fromArray($info);
        $cart = $this->mapper->mapToCart($dto, $integration->branch_id);

        if ($cart === []) {
            throw new RuntimeException("99Food: pedido {$food99OrderId} sem itens.");
        }

        $customer = $this->resolveOrCreateCustomer($dto, $integration->company_id);

        // Pedido e pagamento juntos: com o pedido gravado sem Payment, a reentrega acharia o
        // pedido pronto e nunca registraria o pagamento.
        $order = DB::transaction(function () use ($dto, $cart, $customer, $integration, $event) {
            $order = $this->orderService->createOrder(
                customerId: $customer->id,
                branchId: $integration->branch_id,
                cart: $cart,
                notes: $dto->remark ?? '',
                paymentMethod: OrderChannel::Food99->value,
                orderType: $dto->localOrderType(),
                status: 'pending',
                deliveryFee: $dto->deliveryFee,
                extraDiscount: $dto->itemsDiscount,
                serviceFee: $dto->serviceFee,
                channel: OrderChannel::Food99->value,
                externalOrderId: $dto->orderId,
                externalMetadata: $dto->toMetadata(),
            );

            $this->payments->processMarketplacePayments($order, OrderChannel::Food99->value, $dto->prepaidAmount(), $dto->pendingAmount);
            $event->update(['order_id' => $order->id]);

            return $order;
        });

        if (abs((float) $order->total - $dto->reportedTotal) > 0.01) {
            // Itens ligados são reprecificados pelo cadastro do VEDDI; a diferença fica no log e
            // o valor da 99 no snapshot (reported_total) pra conferência.
            Log::channel('food99')->warning('99Food: total local diferente do informado pela 99', [
                'order_id' => $order->id,
                'food99_order_id' => $dto->orderId,
                'local_total' => (float) $order->total,
                'reported_total' => $dto->reportedTotal,
            ]);
        }

        try {
            NewOrderPlaced::dispatch($order->load('customer'));
        } catch (Throwable $e) {
            // O pedido já está no painel: falha no aviso não pode reprocessar (e duplicar) a importação.
            report($e);
        }

        Log::channel('food99')->info('99Food: pedido criado a partir do orderNew', [
            'food99_webhook_event_id' => $event->id,
            'order_id' => $order->id,
            'food99_order_id' => $dto->orderId,
        ]);

        return true;
    }

    /** Chamado pelo ProcessFood99EventJob quando o evento esgota as tentativas. */
    public function failed(Food99WebhookEvent $event, ?Throwable $e): void
    {
        $integration = $event->food99Integration;
        if (! $integration) {
            return;
        }

        $index = $event->data()['order_info']['order_index'] ?? null;
        $label = $index !== null ? "#{$index}" : 'da 99Food';
        $reason = 'Não conseguimos registrar o pedido depois de várias tentativas.';
        $event->update(['error' => mb_substr(($index !== null ? "Pedido #{$index}: " : '').$reason, 0, 255)]);

        CompanyNotification::create([
            'company_id' => $integration->company_id,
            'type' => 'food99_alert',
            'title' => "Pedido {$label} não entrou no painel",
            'subtitle' => mb_substr($reason.' Aceite ou cancele pelo app da 99Food em até 5 minutos.', 0, 255),
            'link' => route('admin.orders.index'),
        ]);

        Food99OrderNotImported::dispatch($integration->company_id, $event->id);
    }

    /** order_info do callback costuma vir completo; sem itens ou preço, busca o detalhe. */
    private function isComplete(array $info): bool
    {
        return ! empty($info['order_items']) && (isset($info['order_price']) || isset($info['price']));
    }

    /**
     * O telefone da 99 vem mascarado/virtual e não identifica ninguém: o cliente é achado pelo uid
     * da 99 (uid 0 = mascarado → um cadastro por pedido). O telefone gravado é um marcador interno
     * fora do formato de telefone, pra nunca casar com cliente do chat nem receber mensagem.
     */
    private function resolveOrCreateCustomer(Food99OrderDTO $dto, int $companyId): Customer
    {
        $food99CustomerId = $dto->customerUid ?? 'order-'.$dto->orderId;

        $customer = Customer::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('food99_customer_id', $food99CustomerId)
            ->first();

        if (! $customer) {
            try {
                $customer = Customer::withoutGlobalScopes()->create([
                    'company_id' => $companyId,
                    'food99_customer_id' => $food99CustomerId,
                    'name' => $dto->customerName,
                    'phone' => '99food:'.substr(hash('sha256', $food99CustomerId), 0, 13),
                ]);
            } catch (UniqueConstraintViolationException) {
                $customer = Customer::withoutGlobalScopes()
                    ->where('company_id', $companyId)
                    ->where('food99_customer_id', $food99CustomerId)
                    ->firstOrFail();
            }
        }

        $customer->name = $dto->customerName;
        if ($dto->deliveryAddress) {
            $customer->fill([
                'address' => $dto->deliveryAddress['street'],
                'number' => $dto->deliveryAddress['number'],
                'complement' => $dto->deliveryAddress['complement'],
                'neighborhood' => $dto->deliveryAddress['neighborhood'],
                'city' => $dto->deliveryAddress['city'],
                'state' => $dto->deliveryAddress['state'],
                'cep' => $dto->deliveryAddress['cep'],
                'latitude' => $dto->deliveryAddress['latitude'],
                'longitude' => $dto->deliveryAddress['longitude'],
            ]);
        }
        $customer->save();

        return $customer;
    }
}
