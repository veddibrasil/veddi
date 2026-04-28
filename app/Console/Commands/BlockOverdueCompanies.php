<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Company\CompanyService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class BlockOverdueCompanies extends Command
{
    protected $signature = 'companies:block-overdue';

    protected $description = 'Bloqueia empresas inadimplentes após 3 dias úteis do vencimento';

    public function handle(CompanyService $companyService): int
    {
        $companies = Company::where('status', 'OVERDUE')
            ->whereNotNull('overdue_since')
            ->get();

        if ($companies->isEmpty()) {
            $this->info('Nenhuma empresa em atraso.');

            return self::SUCCESS;
        }

        $blocked = 0;

        foreach ($companies as $company) {
            $deadline = $this->addBusinessDays(Carbon::parse($company->overdue_since), 3);

            if (now()->startOfDay()->gte($deadline->startOfDay())) {
                $companyService->block($company);
                $this->info("Empresa {$company->name} (#{$company->id}) bloqueada.");
                $blocked++;
            }
        }

        $remaining = $companies->count() - $blocked;
        $this->info("Concluído: {$blocked} bloqueada(s), {$remaining} ainda na carência.");

        return self::SUCCESS;
    }

    /**
     * Add N business days to a date, skipping Saturdays and Sundays.
     */
    private function addBusinessDays(Carbon $date, int $days): Carbon
    {
        $result = $date->copy();
        $added = 0;

        while ($added < $days) {
            $result->addDay();

            if (! $result->isWeekend()) {
                $added++;
            }
        }

        return $result;
    }
}
