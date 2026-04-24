<?php

namespace App\Console\Commands;

use App\Services\AsaasCircuitBreaker;
use Illuminate\Console\Command;

class AsaasStatus extends Command
{
    protected $signature = 'asaas:status';

    protected $description = 'Exibe o estado atual do circuit breaker do Asaas';

    public function handle(AsaasCircuitBreaker $cb): int
    {
        $state = $cb->getState();
        $failures = $cb->getFailureCount();
        $openedAt = $cb->getOpenedAt();
        $failedJobs = $cb->countFailedJobs();

        $this->table(['Chave', 'Valor'], [
            ['Estado do Circuit',    $state],
            ['Falhas consecutivas',  $failures],
            ['Aberto em',            $openedAt ? date('Y-m-d H:i:s', $openedAt).' UTC' : '—'],
            ['Failed jobs (Asaas)',  $failedJobs],
        ]);

        if ($state === 'open') {
            $this->warn('Circuit ABERTO — chamadas ao Asaas estão bloqueadas.');
            $this->line('  Para fechar manualmente: php artisan asaas:close');
        } elseif ($state === 'half-open') {
            $this->info('Circuit HALF-OPEN — aguardando probe de recovery.');
        } else {
            $this->info('Circuit FECHADO — Asaas operacional.');
        }

        return self::SUCCESS;
    }
}
