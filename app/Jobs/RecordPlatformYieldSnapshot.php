<?php

namespace App\Jobs;

use App\Contracts\AsaasServiceInterface;
use App\Services\Finance\YieldTrackingService;
use App\Services\Payment\StarkService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RecordPlatformYieldSnapshot implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [300, 600];

    public function handle(StarkService $stark, AsaasServiceInterface $asaas, YieldTrackingService $yield): void
    {
        // if (! app()->environment('production') && ! config('services.asaas.allow_transfers_in_non_production', false)) {
        //     Log::channel('payments')->info('RecordPlatformYieldSnapshot: ignorado fora de production', [
        //         'env' => app()->environment(),
        //     ]);

        //     return;
        // }

        $starkBalance = $stark->getBalance();
        $asaasBalance = (float) $asaas->getBalance()['balance'];

        $snapshot = $yield->recordSnapshot($starkBalance, $asaasBalance);

        Log::channel('payments')->info('RecordPlatformYieldSnapshot: snapshot registrado', [
            'date' => $snapshot->snapshot_date,
            'total_float' => $snapshot->total_float,
            'yield_amount' => $snapshot->yield_amount,
            'cumulative_month' => $snapshot->cumulative_yield_month,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::channel('discord')->error('Falha ao registrar snapshot de rendimento CDI', [
            'type' => 'payments',
            'error' => $exception->getMessage(),
        ]);
    }
}
