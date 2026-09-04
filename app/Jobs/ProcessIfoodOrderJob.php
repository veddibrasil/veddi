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
use App\Services\Ifood\IfoodOrderMapper;
use App\Services\Payment\PaymentOrchestrator;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
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
                Log::channel('ifood')->info('iFood: evento não é de novo pedido, ignorado nesta fase', [
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

            $orderDetails = $gateway->getOrderDetails($integration, $ifoodOrderId);
            $dto = IfoodOrderDTO::fromArray($orderDetails);

            $cart = $mapper->mapToCart($dto, $integration->branch_id);
            $customer = $this->resolveOrCreateCustomer($dto, $company->id);

            $order = $orderService->createOrder(
                customerId: $customer->id,
                branchId: $integration->branch_id,
                cart: $cart,
                notes: '',
                paymentMethod: 'ifood',
                orderType: $dto->orderType === 'TAKEOUT' ? 'pickup' : 'delivery',
                status: 'paid',
                deliveryFee: $dto->deliveryFee,
                channel: OrderChannel::Ifood->value,
                externalOrderId: $dto->ifoodOrderId,
                externalMetadata: [
                    'display_id' => $dto->displayId,
                    'order_type' => $dto->orderType,
                    'ifood_reported_subtotal' => $dto->subtotal,
                    'ifood_reported_total' => $dto->total,
                    'payment_type' => $dto->paymentType,
                ],
            );

            $paymentOrchestrator->processIfoodPrepaid($order);

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
            $event->update(['status' => 'failed']);

            Log::channel('ifood')->error('iFood: falha ao processar pedido', [
                'event_id' => $event->event_id,
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

            $event->update(['status' => 'processing']);

            return $event;
        });
    }

    private function resolveOrCreateCustomer(IfoodOrderDTO $dto, int $companyId): Customer
    {
        $phone = $dto->customerPhone ? preg_replace('/\D/', '', $dto->customerPhone) : null;

        $customer = $phone ? Customer::findByPhone($phone) : null;

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

        if ($customer) {
            if ($addressFields !== []) {
                $customer->fill($addressFields);
                $customer->save();
            }

            return $customer;
        }

        return Customer::create(array_merge([
            'company_id' => $companyId,
            'name' => $dto->customerName,
            'phone' => $phone ?? ('ifood-'.$dto->ifoodOrderId),
        ], $addressFields));
    }
}
