<?php

namespace App\Jobs;

use App\Models\WhatsAppConnection;
use App\Services\Messaging\WhatsAppOnboardingService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Conclui o Embedded Signup: troca o code por token, confere WABA/número, inscreve o app e
 * registra o número. O code é de uso único e expira em segundos, por isso:
 * - fila "critical" (mais workers, pega o job na hora);
 * - payload criptografado (ShouldBeEncrypted): o code não fica legível na fila nem em failed_jobs;
 * - a troca do code nunca é retentada — só as etapas seguintes, que retomam do token já salvo.
 */
class CompleteWhatsAppOnboarding implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60];

    /** Evita dois onboardings simultâneos da mesma conexão (duplo clique). */
    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $connectionId,
        #[\SensitiveParameter]
        public readonly string $code,
    ) {
        $this->onQueue('critical');
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return (string) $this->connectionId;
    }

    public function handle(WhatsAppOnboardingService $onboarding): void
    {
        $connection = WhatsAppConnection::withoutGlobalScopes()->find($this->connectionId);

        if (! $connection) {
            return;
        }

        $onboarding->complete($connection, $this->code);
    }

    /** Tentativas esgotadas por falha temporária: deixa a conexão em erro, com instrução clara. */
    public function failed(Throwable $exception): void
    {
        $connection = WhatsAppConnection::withoutGlobalScopes()->find($this->connectionId);

        if ($connection?->status === WhatsAppConnection::STATUS_PENDING) {
            app(WhatsAppOnboardingService::class)->fail($connection, WhatsAppOnboardingService::MSG_TEMPORARY);
        }
    }
}
