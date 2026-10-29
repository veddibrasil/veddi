<?php

namespace App\Jobs;

use App\Contracts\Food99EventHandler;
use App\Exceptions\Food99RateLimitedException;
use App\Models\Food99WebhookEvent;
use App\Services\Food99\Food99OrderEventProcessor;
use App\Services\Food99\Food99OrderImporter;
use App\Services\Food99\Food99ShopEventHandler;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Processa um callback da 99Food já persistido pelo Food99WebhookController. Claim com
 * lockForUpdate (além do ShouldBeUnique, que só cobre a fila): o mesmo evento nunca roda duas
 * vezes ao mesmo tempo. Falha volta o evento pra 'pending' até MAX_ATTEMPTS; depois 'failed'.
 */
class ProcessFood99EventJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const MAX_ATTEMPTS = 5;

    /** Tratadores por grupo de "type"; tipo que nenhum trata fica registrado como ignored. */
    public const HANDLERS = [
        Food99OrderImporter::class,
        Food99OrderEventProcessor::class,
        Food99ShopEventHandler::class,
    ];

    public int $tries = 5;

    public array $backoff = [10, 30, 60, 120];

    public int $uniqueFor = 300;

    public function __construct(public int $eventId)
    {
        $this->onQueue('critical');
    }

    public function uniqueId(): string
    {
        return "food99-event:{$this->eventId}";
    }

    public function handle(): void
    {
        $event = $this->claimEvent();

        if ($event === null) {
            return;
        }

        $integration = $event->food99Integration;

        try {
            if ($integration) {
                app()->instance('current.company', $integration->company);
            }

            $handler = collect(self::HANDLERS)
                ->map(fn (string $class) => app($class))
                ->first(fn (Food99EventHandler $handler) => $handler->handles($event->event_type));

            $handled = $handler?->handle($event) ?? false;

            $event->update([
                'status' => $handled ? Food99WebhookEvent::STATUS_PROCESSED : Food99WebhookEvent::STATUS_IGNORED,
                'processed_at' => now(),
            ]);

            if (! $handled) {
                Log::channel('food99')->info('99Food: evento sem efeito no VEDDI', [
                    'food99_webhook_event_id' => $event->id,
                    'type' => $event->event_type,
                ]);
            }
        } catch (Food99RateLimitedException $e) {
            // Limite da 99 não conta como tentativa: volta pra fila depois da janela.
            $event->update(['status' => Food99WebhookEvent::STATUS_PENDING, 'attempts' => max(0, $event->attempts - 1)]);
            $this->release(max(5, $e->retryAfter));
        } catch (Throwable $e) {
            $retryable = $event->attempts < self::MAX_ATTEMPTS;
            $event->update(['status' => $retryable ? Food99WebhookEvent::STATUS_PENDING : Food99WebhookEvent::STATUS_FAILED]);

            Log::channel('food99')->error('99Food: falha ao processar evento', [
                'food99_webhook_event_id' => $event->id,
                'type' => $event->event_type,
                'attempts' => $event->attempts,
                'retry' => $retryable,
                'error' => $e->getMessage(),
            ]);

            if (! $retryable) {
                $this->notifyFailure($event, $e);
            }

            throw $e;
        } finally {
            app()->forgetInstance('current.company');
        }
    }

    /** A fila desistiu antes de MAX_ATTEMPTS: sem isto o evento ficava 'pending' pra sempre. */
    public function failed(?Throwable $e): void
    {
        $event = Food99WebhookEvent::find($this->eventId);

        if (! $event || $event->status !== Food99WebhookEvent::STATUS_PENDING) {
            return;
        }

        $event->update(['status' => Food99WebhookEvent::STATUS_FAILED]);
        $this->notifyFailure($event, $e);
    }

    private function notifyFailure(Food99WebhookEvent $event, ?Throwable $e): void
    {
        $handler = collect(self::HANDLERS)
            ->map(fn (string $class) => app($class))
            ->first(fn (Food99EventHandler $handler) => $handler->handles($event->event_type));

        if ($handler && method_exists($handler, 'failed')) {
            try {
                $handler->failed($event, $e);
            } catch (Throwable $notifyError) {
                report($notifyError);
            }
        }
    }

    private function claimEvent(): ?Food99WebhookEvent
    {
        return DB::transaction(function () {
            $event = Food99WebhookEvent::lockForUpdate()->find($this->eventId);

            if (! $event || $event->status !== Food99WebhookEvent::STATUS_PENDING) {
                return null;
            }

            $event->update(['status' => Food99WebhookEvent::STATUS_PROCESSING, 'attempts' => $event->attempts + 1]);

            return $event;
        });
    }
}
