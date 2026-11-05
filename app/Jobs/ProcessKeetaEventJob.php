<?php

namespace App\Jobs;

use App\Contracts\KeetaEventHandler;
use App\Contracts\KeetaGatewayContract;
use App\Exceptions\KeetaApiException;
use App\Exceptions\KeetaRateLimitedException;
use App\Models\KeetaIntegration;
use App\Models\KeetaWebhookEvent;
use App\Services\Keeta\KeetaAuthorizationHandler;
use App\Services\Keeta\KeetaOrderEventProcessor;
use App\Services\Keeta\KeetaOrderImporter;
use App\Services\Keeta\KeetaRequestService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Processa um evento da Keeta já persistido (webhook, polling ou autorização). Claim com
 * lockForUpdate (além do ShouldBeUnique, que só cobre a fila): o mesmo evento nunca roda duas
 * vezes ao mesmo tempo. Falha volta o evento pra 'pending' até MAX_ATTEMPTS; depois 'failed'.
 *
 * Evento do polling chega sem loja (a Keeta não manda o merchantId nele): a loja sai do
 * merchant.id do detalhe do pedido, que fica guardado no payload pra importação não buscar de novo.
 */
class ProcessKeetaEventJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const MAX_ATTEMPTS = 5;

    /** Tratadores por grupo de eventType; tipo que nenhum trata fica registrado como ignored. */
    public const HANDLERS = [
        KeetaOrderImporter::class,
        KeetaOrderEventProcessor::class,
        KeetaRequestService::class,
        KeetaAuthorizationHandler::class,
    ];

    public array $backoff = [10, 30, 60, 120];

    public int $uniqueFor = 300;

    public function __construct(public int $eventId)
    {
        $this->onQueue('critical');
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(30);
    }

    public function uniqueId(): string
    {
        return "keeta-event:{$this->eventId}";
    }

    public function handle(): void
    {
        $event = $this->claimEvent();

        if ($event === null) {
            return;
        }

        $previousCompany = app()->bound('current.company') ? app('current.company') : null;

        try {
            $integration = $event->keetaIntegration ?? $this->resolveIntegration($event);

            if ($integration) {
                app()->instance('current.company', $integration->company);
            }

            $handler = $this->handlerFor($event->event_type);
            $handled = $handler?->handle($event) ?? false;

            $event->update([
                'status' => $handled ? KeetaWebhookEvent::STATUS_PROCESSED : KeetaWebhookEvent::STATUS_IGNORED,
                'processed_at' => now(),
            ]);

            if (! $handled) {
                Log::channel('keeta')->info('Keeta: evento sem efeito no VEDDI', [
                    'keeta_webhook_event_id' => $event->id,
                    'event_type' => $event->event_type,
                ]);
            }
        } catch (KeetaRateLimitedException $e) {
            // Limite da Keeta não conta como tentativa: volta pra fila depois da janela.
            $event->update(['status' => KeetaWebhookEvent::STATUS_PENDING, 'attempts' => max(0, $event->attempts - 1)]);
            $this->release(max(5, $e->retryAfter));
        } catch (Throwable $e) {
            $retryable = $event->attempts < self::MAX_ATTEMPTS;
            $event->update(['status' => $retryable ? KeetaWebhookEvent::STATUS_PENDING : KeetaWebhookEvent::STATUS_FAILED]);

            Log::channel('keeta')->error('Keeta: falha ao processar evento', [
                'keeta_webhook_event_id' => $event->id,
                'event_type' => $event->event_type,
                'attempts' => $event->attempts,
                'retry' => $retryable,
                'error' => KeetaApiException::forLog($e),
            ]);

            if (! $retryable) {
                $this->notifyFailure($event, $e);
            }

            throw $e;
        } finally {
            $previousCompany ? app()->instance('current.company', $previousCompany) : app()->forgetInstance('current.company');
        }
    }

    /** A fila desistiu antes de MAX_ATTEMPTS: sem isto o evento ficava 'pending' pra sempre. */
    public function failed(?Throwable $e): void
    {
        $event = KeetaWebhookEvent::find($this->eventId);

        if (! $event || $event->status !== KeetaWebhookEvent::STATUS_PENDING) {
            return;
        }

        $event->update(['status' => KeetaWebhookEvent::STATUS_FAILED]);
        $this->notifyFailure($event, $e);
    }

    /**
     * Loja de um evento do polling: merchant.id do pedido = merchant_ref da integração. Só
     * integração operacional (suspensa vale fora de pedido novo); pedido de loja desconhecida
     * fica sem integração e é ignorado.
     */
    private function resolveIntegration(KeetaWebhookEvent $event): ?KeetaIntegration
    {
        if ($event->source !== KeetaWebhookEvent::SOURCE_POLLING || ! $event->external_order_id) {
            return null;
        }

        $order = app(KeetaGatewayContract::class)->getOrder(null, $event->external_order_id);
        $merchantRef = (string) ($order['merchant']['id'] ?? '');
        $includePaused = ! in_array($event->event_type, ['CREATED', 'PLACED'], true);

        $integration = $merchantRef === '' ? null : KeetaIntegration::operational($includePaused)
            ->with('company')
            ->where('merchant_ref', $merchantRef)
            ->get()
            ->first(fn (KeetaIntegration $candidate) => $candidate->isOperational($includePaused));

        if (! $integration) {
            return null;
        }

        $event->update([
            'keeta_integration_id' => $integration->id,
            'payload' => array_merge($event->payload ?? [], ['order' => $order]),
        ]);
        $event->setRelation('keetaIntegration', $integration);

        return $integration;
    }

    private function handlerFor(string $type): ?KeetaEventHandler
    {
        return collect(self::HANDLERS)
            ->map(fn (string $class) => app($class))
            ->first(fn (KeetaEventHandler $handler) => $handler->handles($type));
    }

    private function notifyFailure(KeetaWebhookEvent $event, ?Throwable $e): void
    {
        $handler = $this->handlerFor($event->event_type);

        if ($handler && method_exists($handler, 'failed')) {
            try {
                $handler->failed($event, $e);
            } catch (Throwable $notifyError) {
                report($notifyError);
            }
        }
    }

    private function claimEvent(): ?KeetaWebhookEvent
    {
        return DB::transaction(function () {
            $event = KeetaWebhookEvent::lockForUpdate()->find($this->eventId);

            if (! $event || $event->status !== KeetaWebhookEvent::STATUS_PENDING) {
                return null;
            }

            $event->update(['status' => KeetaWebhookEvent::STATUS_PROCESSING, 'attempts' => $event->attempts + 1]);

            return $event;
        });
    }
}
