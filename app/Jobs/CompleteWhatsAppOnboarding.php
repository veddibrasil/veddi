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

    /**
     * Abaixo do retry_after do Redis (90 s): sem isso vale o timeout do worker (3600 s na fila
     * critical) e um job lento (várias chamadas à Graph API de até 15 s) seria pego de novo por
     * outro worker enquanto ainda roda. Estourando, o retry retoma do token já salvo.
     */
    public int $timeout = 80;

    /** @var array<int, int> */
    public array $backoff = [10, 60];

    /** Evita dois jobs do mesmo signup (duplo clique); um signup novo da mesma conexão não fica preso no lock. */
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
        return $this->connectionId.':'.substr(WhatsAppOnboardingService::attemptHash($this->code), 0, 16);
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

        $onboarding = app(WhatsAppOnboardingService::class);

        if ($connection?->status === WhatsAppConnection::STATUS_PENDING && $onboarding->isCurrentAttempt($connection->id, $this->code)) {
            $onboarding->fail($connection, WhatsAppOnboardingService::MSG_TEMPORARY);
        }
    }
}
