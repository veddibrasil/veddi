<?php

namespace App\Jobs;

use App\Contracts\WhatsAppProviderInterface;
use App\DTOs\WhatsAppSender;
use App\Exceptions\WhatsAppApiException;
use App\Exceptions\WhatsAppAuthException;
use App\Exceptions\WhatsAppBillingException;
use App\Exceptions\WhatsAppPermanentException;
use App\Exceptions\WhatsAppRetryableException;
use App\Models\Order;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Services\Messaging\WhatsAppCriticalLog;
use App\Services\Messaging\WhatsAppService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Envia a notificação de um evento do pedido por template do WhatsApp.
 *
 * Idempotência em três camadas: ShouldBeUnique (mesmo pedido+evento na fila), linha única
 * em whatsapp_messages (order_id+event) e checagem do status antes de enviar.
 */
class SendWhatsAppOrderNotificationJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** Só evita despacho duplicado enquanto o job está na fila; a garantia real é a linha única. */
    public int $uniqueFor = 900;

    public function __construct(
        public readonly int $orderId,
        public readonly string $event,
    ) {
        $this->onQueue('whatsapp');
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return "{$this->orderId}:{$this->event}";
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [30, 120, 300, 900];
    }

    public function handle(WhatsAppService $service, WhatsAppProviderInterface $provider): void
    {
        $order = Order::withoutGlobalScopes()->with('company')->find($this->orderId);

        if (! $order) {
            Log::channel('whatsapp')->warning('Notificação WhatsApp: pedido não encontrado', $this->context());

            return;
        }

        // Job roda sem request: fixa o tenant do pedido durante o envio e restaura depois
        // (em fila "sync" o current.company do chamador não pode ser perdido).
        $previousCompany = app()->bound('current.company') ? app('current.company') : null;
        app()->instance('current.company', $order->company);

        try {
            $this->deliver($order, $service, $provider);
        } finally {
            if ($previousCompany !== null) {
                app()->instance('current.company', $previousCompany);
            } else {
                app()->forgetInstance('current.company');
            }
        }
    }

    public function failed(Throwable $exception): void
    {
        $apiException = $exception instanceof WhatsAppApiException ? $exception : null;

        if ($apiException === null) {
            Log::channel('whatsapp')->error('Notificação WhatsApp: job falhou', $this->context() + [
                'error' => $exception->getMessage(),
            ]);
        }

        WhatsAppMessage::withoutGlobalScopes()
            ->where('order_id', $this->orderId)
            ->where('event', $this->event)
            ->whereNotIn('status', WhatsAppMessage::DELIVERED_STATUSES)
            ->update([
                'status' => WhatsAppMessage::STATUS_FAILED,
                'error_code' => $apiException?->getCode() ? (string) $apiException->getCode() : null,
                'error_message' => $apiException
                    ? Str::limit($apiException->getMessage(), 500)
                    : 'Erro interno ao enviar a mensagem.',
            ]);
    }

    private function deliver(Order $order, WhatsAppService $service, WhatsAppProviderInterface $provider): void
    {
        $existing = $this->findMessage();

        if ($existing && $this->isResolved($existing)) {
            return;
        }

        // Revalida no momento do envio: opt-out, desconexão ou toggle podem ter mudado.
        $prepared = $service->prepareNotification($order, $this->event);

        if ($prepared === null) {
            Log::channel('whatsapp')->info('Notificação WhatsApp cancelada: condições não atendidas no envio', $this->context());

            $existing?->update([
                'status' => WhatsAppMessage::STATUS_FAILED,
                'error_message' => 'Envio cancelado: condições não atendidas no momento do envio.',
            ]);

            return;
        }

        /** @var WhatsAppSender $sender */
        $sender = $prepared['sender'];
        $template = $prepared['template'];

        // createOrFirst: se outro worker criar a linha entre a checagem e o insert, o unique
        // (order_id, event) é capturado e a linha existente é devolvida.
        $message = $existing ?? WhatsAppMessage::withoutGlobalScopes()->createOrFirst(
            ['order_id' => $order->id, 'event' => $this->event],
            [
                'company_id' => $order->company_id,
                'whatsapp_connection_id' => $sender->connectionId,
                'template' => $template['name'],
                'to_phone' => $prepared['phone'],
                'status' => WhatsAppMessage::STATUS_QUEUED,
            ],
        );

        if ($this->isResolved($message)) {
            return;
        }

        try {
            $wamid = $provider->sendTemplate(
                $sender,
                $prepared['phone'],
                $template['name'],
                $template['params'],
                $template['language'],
            );
        } catch (WhatsAppRetryableException $e) {
            Log::channel('whatsapp')->warning('Notificação WhatsApp: falha temporária, será reenviada', $this->context() + [
                'attempt' => $this->attempts(),
                'code' => $e->getCode(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } catch (WhatsAppAuthException $e) {
            $this->markFailed($message, $e);
            $this->flagConnection($sender, WhatsAppConnection::STATUS_ERROR, 'Autorização do WhatsApp inválida ou expirada (código '.$e->getCode().'). Reconecte o número.');

            Log::channel('whatsapp')->error('Notificação WhatsApp: token recusado pela Meta', $this->context() + [
                'connection_id' => $sender->connectionId,
                'code' => $e->getCode(),
                'error' => $e->getMessage(),
            ]);

            WhatsAppCriticalLog::authRefused('envio de notificação', $sender->connectionId, $order->company_id, $e);

            return;
        } catch (WhatsAppBillingException $e) {
            $this->markFailed($message, $e);
            $this->flagConnection($sender, null, 'Problema de pagamento na conta do WhatsApp Business (código '.$e->getCode().'). Ajuste a forma de pagamento no WhatsApp Manager.');

            Log::channel('whatsapp')->error('Notificação WhatsApp: problema de cobrança na WABA', $this->context() + [
                'connection_id' => $sender->connectionId,
                'error' => $e->getMessage(),
            ]);

            return;
        } catch (WhatsAppPermanentException $e) {
            $this->markFailed($message, $e);

            Log::channel('whatsapp')->warning('Notificação WhatsApp: falha permanente, sem novas tentativas', $this->context() + [
                'code' => $e->getCode(),
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $message->update([
            'wamid' => $wamid,
            'status' => WhatsAppMessage::STATUS_SENT,
            'sent_at' => now(),
            'error_code' => null,
            'error_message' => null,
        ]);

        Log::channel('whatsapp')->info('Notificação WhatsApp enviada', $this->context() + [
            'template' => $template['name'],
            'wamid' => $wamid,
        ]);
    }

    private function findMessage(): ?WhatsAppMessage
    {
        return WhatsAppMessage::withoutGlobalScopes()
            ->where('order_id', $this->orderId)
            ->where('event', $this->event)
            ->first();
    }

    /** Aceita pela Meta ou falha terminal: nada mais a fazer. */
    private function isResolved(WhatsAppMessage $message): bool
    {
        return $message->wasAccepted() || $message->status === WhatsAppMessage::STATUS_FAILED;
    }

    private function markFailed(WhatsAppMessage $message, WhatsAppApiException $e): void
    {
        $message->update([
            'status' => WhatsAppMessage::STATUS_FAILED,
            'error_code' => $e->getCode() ? (string) $e->getCode() : null,
            'error_message' => Str::limit($e->getMessage(), 500),
        ]);
    }

    /** Registra o problema na conexão do restaurante (o número da plataforma não tem conexão). */
    private function flagConnection(WhatsAppSender $sender, ?string $status, string $lastError): void
    {
        if ($sender->connectionId === null) {
            return;
        }

        $attributes = ['last_error' => $lastError];

        if ($status !== null) {
            $attributes['status'] = $status;
        }

        WhatsAppConnection::withoutGlobalScopes()->whereKey($sender->connectionId)->update($attributes);
    }

    /** @return array{order_id: int, event: string} */
    private function context(): array
    {
        return ['order_id' => $this->orderId, 'event' => $this->event];
    }
}
