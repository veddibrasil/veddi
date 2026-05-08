<?php

namespace App\Jobs;

use App\Services\Finance\ReleaseService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ReleaseCompanyTransactionsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(ReleaseService $releaseService): void
    {
        Log::channel('payments')->info('Iniciando liberação de transações');

        $count = $releaseService->releaseTransactions();

        Log::channel('payments')->info('Liberação de transações concluída', ['released' => $count]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::channel('discord')->error('Job de liberação de transações falhou', [
            'type' => 'payments',
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
