<?php

namespace App\Services\Payment;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AsaasCircuitBreaker
{
    private const STATE_CLOSED = 'closed';

    private const STATE_OPEN = 'open';

    private const STATE_HALF_OPEN = 'half-open';

    private const FAILURE_THRESHOLD = 5;

    private const HALF_OPEN_AFTER_SEC = 600;  // 10 min

    private const FAILURE_TTL_SEC = 3600; // 1 hora

    private const KEY_STATE = 'asaas:circuit:state';

    private const KEY_FAILURES = 'asaas:circuit:failures';

    private const KEY_OPENED_AT = 'asaas:circuit:opened_at';

    private const KEY_LAST_ALERT = 'asaas:circuit:last_alert';

    private const ASAAS_JOB_CLASSES = [
        'ProcessOrder',
        'ProcessAsaasWebhook',
        'CreateAsaasSetupFee',
        'CreateAsaasSubscription',
        'RefundPayment',
        'ProcessWithdrawal',
    ];

    /**
     * Retorna true se o circuit está aberto (requisições bloqueadas).
     * Faz a transição automática para half-open se já passou o tempo de espera.
     */
    public function isOpen(): bool
    {
        $state = $this->getState();

        if ($state === self::STATE_CLOSED) {
            return false;
        }

        if ($state === self::STATE_OPEN) {
            if ($this->shouldTransitionToHalfOpen()) {
                Cache::put(self::KEY_STATE, self::STATE_HALF_OPEN, self::FAILURE_TTL_SEC);

                return false; // permite uma requisição de probe passar
            }

            return true;
        }

        // half-open: permite probe passar
        return false;
    }

    public function getState(): string
    {
        return Cache::get(self::KEY_STATE, self::STATE_CLOSED);
    }

    public function getFailureCount(): int
    {
        return (int) Cache::get(self::KEY_FAILURES, 0);
    }

    public function getOpenedAt(): ?int
    {
        return Cache::get(self::KEY_OPENED_AT);
    }

    /**
     * Registra uma chamada bem-sucedida ao Asaas.
     * Se estava em half-open, fecha o circuit e alerta o Discord.
     */
    public function recordSuccess(): void
    {
        $state = $this->getState();

        if ($state === self::STATE_HALF_OPEN || $state === self::STATE_OPEN) {
            $this->closeCircuit();
        }
    }

    /**
     * Registra uma falha na chamada ao Asaas (5xx ou timeout).
     * Incrementa o contador e abre o circuit se atingir o threshold.
     */
    public function recordFailure(): void
    {
        $state = $this->getState();

        // Se já está aberto, reseta o timer para evitar transição prematura para half-open
        if ($state === self::STATE_OPEN) {
            Cache::put(self::KEY_OPENED_AT, time(), 86400);

            return;
        }

        // Incremento atômico — cria a chave com TTL se não existir
        Cache::add(self::KEY_FAILURES, 0, self::FAILURE_TTL_SEC);
        $count = Cache::increment(self::KEY_FAILURES);

        if ($count >= self::FAILURE_THRESHOLD && $state !== self::STATE_OPEN) {
            // Garante que só abre uma vez mesmo com workers concorrentes
            if (Cache::add(self::KEY_STATE, self::STATE_OPEN, 86400)) {
                Cache::put(self::KEY_OPENED_AT, time(), 86400);
                $this->alertDiscordOpen($this->countFailedJobs());
            }
        }
    }

    /**
     * Força a abertura do circuit manualmente.
     */
    public function forceOpen(): void
    {
        Cache::put(self::KEY_STATE, self::STATE_OPEN, 86400);
        Cache::put(self::KEY_OPENED_AT, time(), 86400);
        $this->alertDiscordOpen($this->countFailedJobs());
    }

    /**
     * Fecha o circuit manualmente.
     */
    public function forceClose(): void
    {
        $this->closeCircuit();
    }

    /**
     * Conta failed_jobs relacionados ao Asaas.
     */
    public function countFailedJobs(): int
    {
        return DB::table('failed_jobs')
            ->where(function ($q) {
                foreach (self::ASAAS_JOB_CLASSES as $class) {
                    $q->orWhere('payload', 'like', "%{$class}%");
                }
            })
            ->count();
    }

    private function closeCircuit(): void
    {
        Cache::forget(self::KEY_STATE);
        Cache::forget(self::KEY_FAILURES);
        Cache::forget(self::KEY_OPENED_AT);
        $this->alertDiscordClosed();
    }

    private function shouldTransitionToHalfOpen(): bool
    {
        $openedAt = $this->getOpenedAt();

        return $openedAt !== null && (time() - $openedAt) >= self::HALF_OPEN_AFTER_SEC;
    }

    private function alertDiscordOpen(int $failedJobCount): void
    {
        if (Cache::has(self::KEY_LAST_ALERT)) {
            return; // rate-limit: 1 alerta a cada 10 min
        }

        Cache::put(self::KEY_LAST_ALERT, time(), 600);

        Log::channel('discord')->critical('🔴 Asaas OFFLINE detectado — circuit aberto', [
            'type' => 'payments',
            'failures' => $this->getFailureCount(),
            'threshold' => self::FAILURE_THRESHOLD,
            'pending_jobs' => $failedJobCount,
            'recovery_in' => self::HALF_OPEN_AFTER_SEC.'s (automático)',
            'manual_close' => 'php artisan asaas:close',
        ]);
    }

    private function alertDiscordClosed(): void
    {
        Log::channel('discord')->info('🟢 Asaas ONLINE — circuit fechado', [
            'type' => 'payments',
        ]);
    }
}
