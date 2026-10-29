<?php

namespace App\Console\Commands;

use App\Services\Marketing\AutomationService;
use Illuminate\Console\Command;

class MarketingRunAutomations extends Command
{
    protected $signature = 'marketing:run-automations';

    protected $description = 'Inscreve empresas nas automações de e-mail (cadastro abandonado, sem pedidos) e envia os passos vencidos.';

    public function handle(AutomationService $automations): int
    {
        $result = $automations->run();

        $this->info("Inscritos: {$result['enrolled']} · e-mails enfileirados: {$result['queued']} · saíram: {$result['exited']}");

        return self::SUCCESS;
    }
}
