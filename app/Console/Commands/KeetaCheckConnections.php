<?php

namespace App\Console\Commands;

use App\Services\Keeta\KeetaConnectionMonitor;
use Illuminate\Console\Command;

class KeetaCheckConnections extends Command
{
    protected $signature = 'keeta:check-connections';

    protected $description = 'Avisa os restaurantes (sino do painel e e-mail) sobre integrações Keeta que pararam de receber pedidos e fecha a loja na Keeta de empresa que não pode operar.';

    public function handle(KeetaConnectionMonitor $monitor): int
    {
        if (! config('keeta.enabled')) {
            $this->info('Keeta desligada (KEETA_ENABLED=false).');

            return self::SUCCESS;
        }

        $summary = $monitor->run();

        $this->info(sprintf(
            '%d integração(ões) verificada(s), %d restaurante(s) avisado(s), %d alerta(s) enviado(s).',
            $summary['checked'],
            $summary['alerted'],
            $summary['alerts'],
        ));

        return self::SUCCESS;
    }
}
