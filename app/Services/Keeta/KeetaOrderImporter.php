<?php

namespace App\Services\Keeta;

use App\Contracts\KeetaEventHandler;
use App\Contracts\KeetaGatewayContract;
use App\Contracts\OrderServiceInterface;
use App\DTOs\KeetaOrderDTO;
use App\Enums\OrderChannel;
use App\Events\KeetaOrderNotImported;
use App\Events\NewOrderPlaced;
use App\Exceptions\KeetaApiException;
use App\Models\CompanyNotification;
use App\Models\Customer;
use App\Models\KeetaIntegration;
use App\Models\KeetaRequest;
use App\Models\KeetaWebhookEvent;
use App\Models\Order;
use App\Services\Payment\PaymentOrchestrator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Evento CREATED/PLACED: busca o detalhe do pedido na Keeta e cria o pedido "pendente" no
 * painel. A loja tem 5 minutos pra confirmar (KeetaOrderActionService::accept); com o aceite
 * automático da filial, o VEDDI confirma logo depois de importar.
 */
class KeetaOrderImporter implements KeetaEventHandler
{
    public function __construct(
        private readonly KeetaGatewayContract $gateway,
        private readonly KeetaOrderMapper $mapper,
        private readonly OrderServiceInterface $orderService,
        private readonly PaymentOrchestrator $payments,
    ) {}

    public function handles(string $type): bool
    {
        return in_array($type, ['CREATED', 'PLACED'], true);
    }

    public function handle(KeetaWebhookEvent $event): bool
    {
        $integration = $event->keetaIntegration;
        $keetaOrderId = (string) ($event->external_order_id ?? '');

        if ($integration === null || $keetaOrderId === '') {
            throw new RuntimeException("Keeta: evento de pedido novo sem integração ou orderId (evento {$event->id}).");
        }

        // Reentrega com o pedido já criado: só vincula o evento.
        $existing = Order::withoutGlobalScopes()
            ->where('company_id', $integration->company_id)
            ->where('channel', OrderChannel::Keeta->value)
            ->where('external_order_id', $keetaOrderId)
            ->first();
        if ($existing) {
            $event->update(['order_id' => $existing->id]);

            return true;
        }

        $raw = is_array($event->payload['order'] ?? null) ? $event->payload['order'] : $this->gateway->getOrder($integration, $keetaOrderId);

        if (isset($raw['merchant']['id']) && (string) $raw['merchant']['id'] !== $integration->merchant_ref) {
            // Pedido de outra loja: nunca cria na empresa errada.
            throw new RuntimeException("Keeta: pedido {$keetaOrderId} não pertence à loja da integração {$integration->id}.");
        }

        $dto = KeetaOrderDTO::fromArray($raw, $this->decrypt($integration, $raw));
        $cart = $this->mapper->mapToCart($dto, $integration->branch_id);

        if ($cart === []) {
            throw new RuntimeException("Keeta: pedido {$keetaOrderId} sem itens.");
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
                paymentMethod: OrderChannel::Keeta->value,
                orderType: $dto->localOrderType(),
                status: 'pending',
                deliveryFee: $dto->deliveryFee,
                scheduledAt: $dto->isScheduled() ? $dto->scheduledStart : null,
                extraDiscount: $dto->itemsDiscount,
                serviceFee: $dto->serviceFee,
                channel: OrderChannel::Keeta->value,
                externalOrderId: $dto->orderId,
                externalMetadata: $dto->toMetadata(),
            );

            $this->payments->processMarketplacePayments($order, OrderChannel::Keeta->value, $dto->prepaidAmount(), $dto->pendingAmount());
            $event->update(['order_id' => $order->id]);

            // Solicitação que chegou antes do pedido ficou órfã.
            KeetaRequest::withoutGlobalScopes()
                ->where('company_id', $integration->company_id)
                ->where('external_order_id', $dto->orderId)
                ->whereNull('order_id')
                ->update(['order_id' => $order->id]);

            return $order;
        });

        if (abs((float) $order->total - $dto->reportedTotal) > 0.01) {
            // Itens ligados são reprecificados pelo cadastro do VEDDI; a diferença fica no log e
            // o valor da Keeta no snapshot (reported_total) pra conferência.
            Log::channel('keeta')->warning('Keeta: total local diferente do informado pela Keeta', [
                'order_id' => $order->id,
                'keeta_order_id' => $dto->orderId,
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

        Log::channel('keeta')->info('Keeta: pedido criado a partir do evento', [
            'keeta_webhook_event_id' => $event->id,
            'order_id' => $order->id,
            'keeta_order_id' => $dto->orderId,
        ]);

        if ($integration->auto_accept) {
            $this->autoAccept($order);
        }

        return true;
    }

    /** Chamado pelo ProcessKeetaEventJob quando o evento esgota as tentativas. */
    public function failed(KeetaWebhookEvent $event, ?Throwable $e): void
    {
        $integration = $event->keetaIntegration;
        if (! $integration) {
            return;
        }

        $reason = 'Não conseguimos registrar o pedido depois de várias tentativas.';
        $event->update(['error' => mb_substr($reason, 0, 255)]);

        CompanyNotification::create([
            'company_id' => $integration->company_id,
            'type' => 'keeta_alert',
            'title' => 'Pedido da Keeta não entrou no painel',
            'subtitle' => mb_substr($reason.' Confirme ou cancele pelo portal da Keeta em até 5 minutos.', 0, 255),
            'link' => route('admin.orders.index'),
        ]);

        KeetaOrderNotImported::dispatch($integration->company_id, $event->id);
    }

    /**
     * Aceite automático da filial. Falha não desfaz a importação: o pedido fica aguardando
     * aceite no painel e o alarme toca normalmente.
     */
    private function autoAccept(Order $order): void
    {
        try {
            app(KeetaOrderActionService::class)->accept($order);
        } catch (Throwable $e) {
            Log::channel('keeta')->warning('Keeta: aceite automático falhou, pedido aguarda aceite no painel', [
                'order_id' => $order->id,
                'error' => KeetaApiException::forLog($e),
            ]);
        }
    }

    /**
     * Telefone e endereço vêm criptografados (ENC_). Só a entrega própria precisa deles e só
     * ela pode descriptografar; falha aqui não segura o pedido.
     *
     * @return array<string, string>
     */
    private function decrypt(KeetaIntegration $integration, array $raw): array
    {
        $isSelfDelivery = strtoupper((string) ($raw['delivery']['deliveredBy'] ?? '')) === KeetaOrderDTO::DELIVERED_BY_MERCHANT;
        $cipherTexts = KeetaOrderDTO::encryptedValues($raw);

        if (! $isSelfDelivery || $cipherTexts === []) {
            return [];
        }

        try {
            return $this->gateway->batchDecrypt($integration, $cipherTexts);
        } catch (Throwable $e) {
            Log::channel('keeta')->warning('Keeta: não foi possível descriptografar contato do pedido', [
                'keeta_integration_id' => $integration->id,
                'error' => KeetaApiException::forLog($e),
            ]);

            return [];
        }
    }

    /**
     * O telefone da Keeta vem criptografado e não identifica ninguém: o cliente é achado pelo
     * customer.id da Keeta (sem id = um cadastro por pedido). O telefone gravado é um marcador
     * interno fora do formato de telefone, pra nunca casar com cliente do chat nem receber mensagem.
     */
    private function resolveOrCreateCustomer(KeetaOrderDTO $dto, int $companyId): Customer
    {
        $keetaCustomerId = $dto->customerId ?? 'order-'.$dto->orderId;

        $customer = Customer::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('keeta_customer_id', $keetaCustomerId)
            ->first();

        if (! $customer) {
            try {
                $customer = Customer::withoutGlobalScopes()->create([
                    'company_id' => $companyId,
                    'keeta_customer_id' => $keetaCustomerId,
                    'name' => $dto->customerName,
                    'phone' => 'keeta:'.substr(hash('sha256', $keetaCustomerId), 0, 13),
                ]);
            } catch (UniqueConstraintViolationException) {
                $customer = Customer::withoutGlobalScopes()
                    ->where('company_id', $companyId)
                    ->where('keeta_customer_id', $keetaCustomerId)
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
