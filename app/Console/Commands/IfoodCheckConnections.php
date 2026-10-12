<?php

namespace App\Console\Commands;

use App\Services\Ifood\IfoodConnectionMonitor;
use Illuminate\Console\Command;

class IfoodCheckConnections extends Command
{
    protected $signature = 'ifood:check-connections';

    protected $description = 'Avisa os restaurantes (sino do painel e e-mail) sobre integrações iFood que pararam de receber pedidos.';

    public function handle(IfoodConnectionMonitor $monitor): int
    {
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
