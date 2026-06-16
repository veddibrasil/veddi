<?php

namespace App\Jobs;

use App\Models\CompanyTransaction;
use App\Models\CompanyWithdrawal;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessWithdrawal implements ShouldQueue
{
    use Queueable;

    public array $backoff = [60, 300, 900];

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(24);
    }

    public function __construct(
        public int $withdrawalId,
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        // Idempotency guard: atomic check-and-set prevents concurrent or retried runs
        // from processing the same withdrawal twice (double debit).
        $canProcess = DB::transaction(function () {
            $withdrawal = CompanyWithdrawal::lockForUpdate()->findOrFail($this->withdrawalId);

            if ($withdrawal->status === 'done') {
                Log::channel('payments')->info('Saque já processado, ignorando execução duplicada', [
                    'withdrawal_id' => $withdrawal->id,
                ]);

                return false;
            }

            $withdrawal->update(['status' => 'processing']);

            return true;
        });

        if (! $canProcess) {
            return;
        }

    }

    public function failed(\Throwable $exception): void
    {
        Log::channel('discord')->error('Job de saque falhou definitivamente', [
            'type' => 'payments',
            'withdrawal_id' => $this->withdrawalId,
            'error' => $exception->getMessage(),
        ]);

        CompanyWithdrawal::where('id', $this->withdrawalId)
            ->where('status', 'processing')
            ->update(['status' => 'failed']);

        CompanyTransaction::withoutGlobalScopes()
            ->where('withdrawal_id', $this->withdrawalId)
            ->where('withdrawn', false)
            ->update([
                'withdrawal_id' => null,
                'updated_at' => now(),
            ]);
    }
}
