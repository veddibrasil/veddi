<?php

namespace App\Console\Commands;

use App\Services\AsaasCircuitBreaker;
use Illuminate\Console\Command;

class AsaasClose extends Command
{
    protected $signature   = 'asaas:close';
    protected $description = 'Fecha o circuit breaker do Asaas e opcionalmente reprocessa failed jobs';

    public function handle(AsaasCircuitBreaker $cb): int
    {
        $cb->forceClose();

        $this->info('Circuit FECHADO. Chamadas ao Asaas liberadas.');

        if ($this->confirm('Reprocessar todos os failed jobs relacionados ao Asaas?', true)) {
            $this->call('asaas:retry-failed');
        }

        return self::SUCCESS;
    }
}
