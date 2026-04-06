<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AsaasRetryFailed extends Command
{
    protected $signature   = 'asaas:retry-failed';
    protected $description = 'Reprocessa todos os failed jobs relacionados ao Asaas';

    private const ASAAS_JOB_CLASSES = [
        'ProcessOrder',
        'ProcessAsaasWebhook',
        'CreateAsaasSetupFee',
        'CreateAsaasSubscription',
        'RefundPayment',
        'ProcessWithdrawal',
    ];

    public function handle(): int
    {
        $jobs = DB::table('failed_jobs')
            ->where(function ($q) {
                foreach (self::ASAAS_JOB_CLASSES as $class) {
                    $q->orWhere('payload', 'like', "%{$class}%");
                }
            })
            ->get(['uuid']);

        if ($jobs->isEmpty()) {
            $this->info('Nenhum failed job relacionado ao Asaas encontrado.');
            return self::SUCCESS;
        }

        $uuids = $jobs->pluck('uuid')->all();
        $this->info("Reprocessando {$jobs->count()} job(s) falhado(s)...");

        $this->call('queue:retry', ['id' => $uuids]);

        return self::SUCCESS;
    }
}
