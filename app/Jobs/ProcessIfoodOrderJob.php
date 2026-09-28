<?php

namespace App\Jobs;

use App\Contracts\IfoodGatewayContract;
use App\Contracts\OrderServiceInterface;
use App\DTOs\IfoodOrderDTO;
use App\Enums\OrderChannel;
use App\Events\NewOrderPlaced;
use App\Exceptions\IfoodMappingException;
use App\Models\Customer;
use App\Models\IfoodOrderEvent;
use App\Models\Order;
use App\Services\Ifood\IfoodOrderEventProcessor;
use App\Services\Ifood\IfoodOrderMapper;
use App\Services\Payment\PaymentOrchestrator;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessIfoodOrderJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Código de evento do iFood que representa um novo pedido colocado. */
    private const EVENT_TYPE_PLACED = 'PLC';

    public int $tries = 3;

    /**
     * Limite de processamentos do mesmo evento somando retries da fila e reentregas do
     * polling. Até lá a falha devolve o evento pra 'pending'; depois fica 'failed'.
     */
    public const MAX_ATTEMPTS = 5;

    public array $backoff = [10, 60, 300];

    public int $uniqueFor = 300;

    public function uniqueId(): string
    {
        return "ifood-order-event:{$this->ifoodOrderEventId}";
    }

    public function __construct(public int $ifoodOrderEventId)
    {
        $this->onQueue('critical');
    }

    public function handle(
        IfoodGatewayContract $gateway,
        IfoodOrderMapper $mapper,
        OrderServiceInterface $orderService,
        PaymentOrchestrator $paymentOrchestrator,
    ): void {
        $event = $this->claimEvent();

        if ($event === null) {
            // Já processado (ou em processamento por outra tentativa) — idempotência.
            return;
        }

        $integration = $event->ifoodIntegration;
        $company = $integration->company;

        try {
            app()->instance('current.company', $company);

            if ($event->event_type !== self::EVENT_TYPE_PLACED) {
                if (IfoodOrderEventProcessor::handles($event->event_type)) {
                    app(IfoodOrderEventProcessor::class)->process($event);

                    return;
                }

                Log::channel('ifood')->info('iFood: evento sem efeito no pedido local, apenas confirmado', [
                    'event_id' => $event->event_id,
                    'event_type' => $event->event_type,
                ]);
                $event->update(['status' => 'processed', 'processed_at' => now()]);

                return;
            }

            $ifoodOrderId = $event->payload['orderId'] ?? null;
            if (! $ifoodOrderId) {
                throw new \RuntimeException("iFood: evento {$event->event_id} sem orderId no payload.");
            }

            // Reentrega do PLACED com o pedido já criado (ex.: evento veio pelo webhook e pelo
            // polling com ids diferentes): não cria de novo, só vincula o evento.
            $existing = Order::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('channel', OrderChannel::Ifood->value)
                ->where('external_order_id', $ifoodOrderId)
                ->first();
            if ($existing) {
                $event->update(['status' => 'processed', 'order_id' => $existing->id, 'processed_at' => now()]);

                return;
            }

            $orderDetails = $gateway->getOrderDetails($integration, $ifoodOrderId);
            $dto = IfoodOrderDTO::fromArray($orderDetails);

            $cart = $mapper->mapToCart($dto, $integration->branch_id);
            $customer = $this->resolveOrCreateCustomer($dto, $company->id);

            // Pedido novo entra aguardando aceite (o iFood cancela sozinho se a loja não
            // confirmar no prazo); agendado vai pra coluna de agendados com o horário do iFood.
            $order = $orderService->createOrder(
                customerId: $customer->id,
                branchId: $integration->branch_id,
                cart: $cart,
                notes: $dto->extraInfo ?? '',
                paymentMethod: 'ifood',
                orderType: $dto->localOrderType(),
                status: $dto->isScheduled() ? 'scheduled' : 'pending',
                deliveryFee: $dto->deliveryFee,
                scheduledAt: $dto->isScheduled() ? $dto->scheduledStart : null,
                extraDiscount: $dto->discount,
                serviceFee: $dto->additionalFees,
                channel: OrderChannel::Ifood->value,
                externalOrderId: $dto->ifoodOrderId,
                externalMetadata: $dto->toMetadata(),
            );

            $paymentOrchestrator->processIfoodPayments($order, $dto->prepaidAmount, $dto->pendingAmount);

            $event->update(['status' => 'processed', 'order_id' => $order->id, 'processed_at' => now()]);

            NewOrderPlaced::dispatch($order->load('customer'));

            Log::channel('ifood')->info('iFood: pedido criado a partir de evento PLC', [
                'event_id' => $event->event_id,
                'order_id' => $order->id,
                'ifood_order_id' => $dto->ifoodOrderId,
            ]);
        } catch (IfoodMappingException $e) {
            // Erro de mapeamento (item/opção não cadastrado) não é transitório — não
            // adianta tentar de novo, e não deve criar pedido malformado. Marca failed
            // e não relança (não queremos retry automático de algo que vai falhar sempre).
            $event->update(['status' => 'failed']);

            Log::channel('ifood')->error('iFood: falha ao mapear pedido — item/opção não cadastrado', [
                'event_id' => $event->event_id,
                'error' => $e->getMessage(),
            ]);
        } catch (Throwable $e) {
            $retryable = $event->attempts < self::MAX_ATTEMPTS;
            $event->update(['status' => $retryable ? 'pending' : 'failed']);

            Log::channel('ifood')->error('iFood: falha ao processar evento', [
                'event_id' => $event->event_id,
                'event_type' => $event->event_type,
                'attempts' => $event->attempts,
                'retry' => $retryable,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            app()->forgetInstance('current.company');
        }
    }

    /**
     * Marca o evento como 'processing' dentro de lock, evitando duas execuções
     * concorrentes do mesmo evento (além do ShouldBeUnique, que cobre só a fila).
     * Retorna null se o evento já foi processado ou está sendo processado agora.
     */
    private function claimEvent(): ?IfoodOrderEvent
    {
        return DB::transaction(function () {
            $event = IfoodOrderEvent::lockForUpdate()->find($this->ifoodOrderEventId);

            if (! $event || $event->status !== 'pending') {
                return null;
            }

            $event->update(['status' => 'processing', 'attempts' => $event->attempts + 1]);

            return $event;
        });
    }

    /**
     * O iFood manda o mesmo 0800 pra todo cliente (com localizador por pedido), então o
     * telefone não identifica ninguém: o cliente é achado pelo customer.id do iFood. O
     * 0800 e o localizador ficam no snapshot do pedido. O telefone gravado no cadastro é
     * um marcador interno único, fora do formato de telefone, pra nunca casar com cliente
     * do chat nem receber mensagem.
     */
    private function resolveOrCreateCustomer(IfoodOrderDTO $dto, int $companyId): Customer
    {
        $ifoodCustomerId = $dto->customerId ?? 'order-'.$dto->ifoodOrderId;

        $addressFields = $dto->deliveryAddress ? [
            'address' => $dto->deliveryAddress['street'],
            'number' => $dto->deliveryAddress['number'],
            'complement' => $dto->deliveryAddress['complement'],
            'neighborhood' => $dto->deliveryAddress['neighborhood'],
            'city' => $dto->deliveryAddress['city'],
            'state' => $dto->deliveryAddress['state'],
            'cep' => $dto->deliveryAddress['cep'],
            'latitude' => $dto->deliveryAddress['latitude'],
            'longitude' => $dto->deliveryAddress['longitude'],
        ] : [];

        $customer = Customer::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('ifood_customer_id', $ifoodCustomerId)
            ->first();

        if (! $customer) {
            try {
                $customer = Customer::withoutGlobalScopes()->create([
                    'company_id' => $companyId,
                    'ifood_customer_id' => $ifoodCustomerId,
                    'name' => $dto->customerName,
                    'phone' => 'ifood:'.substr(hash('sha256', $ifoodCustomerId), 0, 14),
                ]);
            } catch (UniqueConstraintViolationException) {
                // Outro pedido do mesmo cliente criou o cadastro ao mesmo tempo.
                $customer = Customer::withoutGlobalScopes()
                    ->where('company_id', $companyId)
                    ->where('ifood_customer_id', $ifoodCustomerId)
                    ->firstOrFail();
            }
        }

        // Nome e endereço mais recentes do iFood; createOrder copia o endereço do cadastro
        // pro snapshot de entrega do pedido.
        $customer->name = $dto->customerName;
        if ($addressFields !== []) {
            $customer->fill($addressFields);
        }
        $customer->save();

        return $customer;
    }
}
