<?php

namespace App\Console\Commands;

use App\Services\Sales\SalesNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class SalesNotify extends Command
{
    protected $signature = 'sales:notify
        {--weekly : Resumo da semana anterior em vez dos alertas urgentes do dia}
        {--company= : Só esta empresa (id)}';

    protected $description = 'Avisa o administrador (sino do painel) sobre vendas: alertas urgentes do dia ou resumo semanal.';

    public function handle(SalesNotifier $notifier): int
    {
        $weekly = (bool) $this->option('weekly');
        $companies = $notifier->companies($weekly ? 14 : 28, $this->option('company') ? (int) $this->option('company') : null);
        $created = 0;
        $failed = 0;

        // Best effort: falha de uma empresa não impede os avisos das demais.
        foreach ($companies as $company) {
            try {
                $created += $weekly ? $notifier->weekly($company) : $notifier->daily($company);
            } catch (Throwable $e) {
                $failed++;
                Log::warning('Vendas: falha ao gerar avisos do sino', [
                    'company_id' => $company->id,
                    'weekly' => $weekly,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info(sprintf('%d empresa(s) verificada(s), %d aviso(s) criado(s), %d falha(s).', $companies->count(), $created, $failed));

        // Falha de qualquer empresa marca a execução como falha (agendador/monitoramento enxergam).
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
