<?php

namespace App\Jobs;

use App\Contracts\AsaasServiceInterface;
use App\Services\Finance\YieldTrackingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RecordPlatformYieldSnapshot implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [300, 600];

    public function handle(AsaasServiceInterface $asaas, YieldTrackingService $yield): void
    {
        // Snapshot de saldo Yapay removido — endpoint não confirmado.
        // Reabilitar quando integração de saldo for validada com suporte Yapay.
    }

    public function failed(\Throwable $exception): void
    {
        Log::channel('discord')->error('Falha ao registrar snapshot de rendimento CDI', [
            'type' => 'payments',
            'error' => $exception->getMessage(),
        ]);
    }
}
