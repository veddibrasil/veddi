<?php

namespace App\Console\Commands;

use App\Services\Messaging\WhatsAppConnectionMonitor;
use Illuminate\Console\Command;

class WhatsAppCheckConnections extends Command
{
    protected $signature = 'whatsapp:check-connections';

    protected $description = 'Avisa os restaurantes (sino do painel e e-mail) sobre WhatsApp inativo, com erro ou com qualidade baixa.';

    public function handle(WhatsAppConnectionMonitor $monitor): int
    {
        $summary = $monitor->run();

        $this->info(sprintf(
            '%d conexão(ões) verificada(s), %d restaurante(s) avisado(s), %d alerta(s) enviado(s).',
            $summary['checked'],
            $summary['alerted'],
            $summary['alerts'],
        ));

        return self::SUCCESS;
    }
}
