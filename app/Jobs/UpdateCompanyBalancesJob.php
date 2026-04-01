<?php

namespace App\Jobs;

use App\Services\BalanceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class UpdateCompanyBalancesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('low');
    }

    public function handle(BalanceService $balanceService): void
    {
        Log::channel('payments')->info('Iniciando atualização de snapshots de saldo');

        $balanceService->updateAllSnapshots();

        Log::channel('payments')->info('Snapshots de saldo atualizados');
    }

    public function failed(\Throwable $exception): void
    {
        Log::channel('discord')->error('Job de snapshots de saldo falhou', [
            'type'       => 'payments',
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
