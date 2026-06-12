<?php

namespace App\Jobs;

use App\Contracts\AsaasServiceInterface;
use App\Services\Finance\YieldTrackingService;
use App\Services\Payment\VindiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RecordPlatformYieldSnapshot implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [300, 600];

    public function handle(VindiService $vindi, AsaasServiceInterface $asaas, YieldTrackingService $yield): void
    {
        // Comentado: depende de endpoint de saldo Yapay não confirmado.
        // Reabilitar quando endpoints de saldo da plataforma/afiliado forem validados.
        //
        // $vindiBalance = $vindi->getBalance();
        // $asaasBalance = (float) $asaas->getBalance()['balance'];
        // $snapshot = $yield->recordSnapshot($vindiBalance, $asaasBalance);
        // Log::channel('payments')->info('RecordPlatformYieldSnapshot: snapshot registrado', [
        //     'date' => $snapshot->snapshot_date,
        //     'total_float' => $snapshot->total_float,
        //     'yield_amount' => $snapshot->yield_amount,
        //     'cumulative_month' => $snapshot->cumulative_yield_month,
        // ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::channel('discord')->error('Falha ao registrar snapshot de rendimento CDI', [
            'type' => 'payments',
            'error' => $exception->getMessage(),
        ]);
    }
}
