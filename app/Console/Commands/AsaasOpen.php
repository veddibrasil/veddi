<?php

namespace App\Console\Commands;

use App\Services\Payment\AsaasCircuitBreaker;
use Illuminate\Console\Command;

class AsaasOpen extends Command
{
    protected $signature = 'asaas:open';

    protected $description = 'Força a abertura do circuit breaker do Asaas (bloqueia todas as chamadas)';

    public function handle(AsaasCircuitBreaker $cb): int
    {
        $cb->forceOpen();

        $this->warn('Circuit ABERTO manualmente. Nenhuma chamada ao Asaas será processada.');
        $this->line('  Jobs em fila serão liberados novamente em 15 min automaticamente.');
        $this->line('  Para fechar: php artisan asaas:close');

        return self::SUCCESS;
    }
}
