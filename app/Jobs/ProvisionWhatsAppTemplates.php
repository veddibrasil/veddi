<?php

namespace App\Jobs;

use App\Models\WhatsAppConnection;
use App\Services\Messaging\WhatsAppTemplateProvisioner;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cria na WABA do restaurante os templates que faltam e sincroniza o status dos existentes.
 * Idempotente: pode rodar várias vezes (retry, comando de suporte, reconexão).
 */
class ProvisionWhatsAppTemplates implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** A fila whatsapp mata o job em 120s; falha antes, de forma limpa, e o retry retoma. */
    public int $timeout = 100;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public int $uniqueFor = 300;

    public function __construct(public readonly int $connectionId)
    {
        $this->onQueue('whatsapp');
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return (string) $this->connectionId;
    }

    public function handle(WhatsAppTemplateProvisioner $provisioner): void
    {
        $connection = WhatsAppConnection::withoutGlobalScopes()->find($this->connectionId);

        // Desconectada, com erro ou ainda sem onboarding concluído: não há o que provisionar.
        if (! $connection || ! in_array($connection->status, [
            WhatsAppConnection::STATUS_PROVISIONING,
            WhatsAppConnection::STATUS_TEMPLATES_PENDING,
            WhatsAppConnection::STATUS_ACTIVE,
        ], true)) {
            return;
        }

        $provisioner->provision($connection);
    }

    public function failed(Throwable $exception): void
    {
        Log::channel('whatsapp')->error('Provisionamento de templates esgotou as tentativas', [
            'connection_id' => $this->connectionId,
            'error' => $exception->getMessage(),
        ]);

        $connection = WhatsAppConnection::withoutGlobalScopes()->find($this->connectionId);

        if ($connection && in_array($connection->status, [WhatsAppConnection::STATUS_PROVISIONING, WhatsAppConnection::STATUS_TEMPLATES_PENDING], true)) {
            $connection->update(['last_error' => 'Não foi possível criar os templates de mensagem agora. A sincronização será refeita; se persistir, fale com o suporte.']);
        }
    }
}
