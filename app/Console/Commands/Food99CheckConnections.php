<?php

namespace App\Console\Commands;

use App\Services\Food99\Food99ConnectionMonitor;
use Illuminate\Console\Command;

class Food99CheckConnections extends Command
{
    protected $signature = 'food99:check-connections';

    protected $description = 'Avisa os restaurantes (sino do painel e e-mail) sobre integrações 99Food que pararam de receber pedidos e fecha a loja na 99 de empresa que não pode operar.';

    public function handle(Food99ConnectionMonitor $monitor): int
    {
        if (! config('food99.enabled')) {
            $this->info('99Food desligada (FOOD99_ENABLED=false).');

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
