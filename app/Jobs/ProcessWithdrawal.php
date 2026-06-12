<?php

namespace App\Jobs;

use App\Models\CompanyTransaction;
use App\Models\CompanyWithdrawal;
use App\Services\Payment\VindiService;
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

    public function handle(VindiService $vindi): void
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

        // Comentado: saque via API Yapay desabilitado — endpoint não confirmado.
        // Saques são feitos diretamente no painel Yapay pelo operador.
        // Reabilitar e validar buildVindiTransferData() quando endpoint for confirmado.
        //
        // $withdrawal = CompanyWithdrawal::findOrFail($this->withdrawalId);
        // Log::channel('payments')->info('Processando saque via Vindi', [...]);
        // try {
        //     $transferData = $this->buildVindiTransferData($withdrawal);
        //     $transfer = $vindi->createTransfer($transferData);
        //     DB::transaction(function () use ($withdrawal, $transfer) { ... });
        //     Log::channel('payments')->info('Saque processado com sucesso via Vindi', [...]);
        // } catch (\Throwable $e) { ... throw $e; }
    }

    // Monta payload para a API Yapay de transferência. Mantido para quando o endpoint for confirmado.
    // Não é chamado enquanto o saque é feito manualmente no painel Yapay.
    private function buildVindiTransferData(CompanyWithdrawal $withdrawal): array
    {
        $company = $withdrawal->company;

        if (empty($company->vindi_affiliate_token)) {
            throw new \RuntimeException("Empresa #{$company->id} não possui vindi_affiliate_token configurado.");
        }

        $pixFee = $withdrawal->payout_type === 'PIX' ? (float) $withdrawal->pix_fee : 0.0;
        $transferAmount = (float) $withdrawal->amount;
        // Para PIX, subtrai a taxa fixa antes de enviar à Yapay — a taxa já foi debitada na carteira
        // pela WithdrawalService, mas o valor enviado ao gateway deve ser o líquido.
        if ($withdrawal->payout_type === 'PIX') {
            $transferAmount = max(0.0, round($transferAmount - $pixFee, 2));
        }

        $base = [
            'affiliate_token' => $company->vindi_affiliate_token,
            'amount' => $transferAmount,
            'owner_name' => $withdrawal->bank_owner_name ?? $company->default_bank_owner_name ?? $company->name,
            'owner_cpf_cnpj' => $withdrawal->bank_owner_cpf_cnpj ?? $company->default_bank_owner_cpf_cnpj ?? $company->owner_cpf_cnpj ?? '',
            'external_id' => 'withdrawal-'.$withdrawal->id,
        ];

        if ($withdrawal->payout_type === 'PIX') {
            return array_merge($base, [
                'pix_key' => $withdrawal->pix_key,
                'pix_key_type' => $withdrawal->pix_key_type,
            ]);
        }

        return array_merge($base, [
            'bank_code' => $withdrawal->bank_code,
            'bank_agency' => $withdrawal->bank_agency,
            'bank_account' => $withdrawal->bank_account,
            'account_type' => $withdrawal->bank_account_type ?? 'checking',
            'account_digit' => $withdrawal->bank_account_digit,
        ]);
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
