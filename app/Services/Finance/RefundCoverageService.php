<?php

namespace App\Services\Finance;

use App\Contracts\AsaasServiceInterface;
use App\Models\PaymentRefund;
use App\Services\Payment\StarkService;
use Illuminate\Support\Facades\Log;

class RefundCoverageService
{
    private const RESERVE_RATIO = 0.10;

    private const MIN_TRANSFER_AMOUNT = 1.00;

    public function __construct(
        private readonly AsaasServiceInterface $asaas,
        private readonly StarkService $stark,
    ) {}

    /**
     * Garante que a conta Asaas tem saldo para processar o estorno.
     *
     * Retorna true se pronto para processar. Retorna false e agenda cobertura
     * (antecipação ou transferência Stark→Asaas) quando saldo insuficiente.
     *
     * Possíveis valores de coverage_status em PaymentRefund:
     *   null                    → ainda não verificado
     *   'covered'               → saldo confirmado, pode processar
     *   'awaiting_anticipation' → antecipação solicitada no Asaas, aguardando webhook
     *   'awaiting_stark_transfer' → transferência Stark→Asaas iniciada, aguardando chegada
     */
    public function ensureCoverage(PaymentRefund $refund): bool
    {
        if ($refund->coverage_status === 'covered') {
            return true;
        }

        $balance = $this->asaas->getBalance();
        $available = $balance['balance'];
        $reserve = round($available * self::RESERVE_RATIO, 2);
        $usable = max(0.0, round($available - $reserve, 2));
        $amount = (float) $refund->amount;

        if ($usable >= $amount) {
            $refund->update(['coverage_status' => 'covered']);

            return true;
        }

        $deficit = round($amount - $usable, 2);

        Log::channel('payments')->info('RefundCoverage: saldo Asaas insuficiente para estorno', [
            'refund_id' => $refund->id,
            'available' => $available,
            'usable' => $usable,
            'needed' => $amount,
            'deficit' => $deficit,
        ]);

        return $this->arrangeAnticipation($refund, $deficit)
            || $this->arrangeStarkTransfer($refund, $deficit);
    }

    private function arrangeAnticipation(PaymentRefund $refund, float $deficit): bool
    {
        $refund->loadMissing('payment');
        $asaasPaymentId = $refund->payment?->asaas_payment_id;

        if (! $asaasPaymentId) {
            return false;
        }

        try {
            $simulation = $this->asaas->simulateAnticipation($asaasPaymentId);

            if (! $simulation['eligible'] || $simulation['netValue'] < $deficit) {
                Log::channel('payments')->info('RefundCoverage: antecipação insuficiente ou inelegível', [
                    'refund_id' => $refund->id,
                    'simulation' => $simulation,
                    'deficit' => $deficit,
                ]);

                return false;
            }

            $anticipation = $this->asaas->createAnticipation($asaasPaymentId);

            $refund->update([
                'coverage_status' => 'awaiting_anticipation',
                'coverage_meta' => array_merge((array) ($refund->coverage_meta ?? []), [
                    'deficit' => $deficit,
                    'asaas_payment_id' => $asaasPaymentId,
                    'anticipation_id' => $anticipation['id'] ?? null,
                    'anticipation_requested_at' => now()->toIso8601String(),
                ]),
            ]);

            Log::channel('payments')->info('RefundCoverage: antecipação solicitada', [
                'refund_id' => $refund->id,
                'anticipation_id' => $anticipation['id'] ?? null,
            ]);

            return false; // not ready yet — webhook will trigger retry
        } catch (\Throwable $e) {
            Log::channel('payments')->warning('RefundCoverage: falha ao solicitar antecipação', [
                'refund_id' => $refund->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function arrangeStarkTransfer(PaymentRefund $refund, float $deficit): bool
    {
        $pixKey = config('services.asaas.platform_asaas_pix_key');
        $pixKeyType = config('services.asaas.platform_asaas_pix_key_type', 'CNPJ');
        $ownerName = config('services.asaas.platform_company_name');
        $ownerCnpj = config('services.asaas.platform_company_cnpj');

        if (! $pixKey || ! $ownerName || ! $ownerCnpj) {
            Log::channel('discord')->error('RefundCoverage: PLATFORM_ASAAS_PIX_KEY/COMPANY_NAME/CNPJ não configurados', [
                'type' => 'payments',
                'refund_id' => $refund->id,
            ]);

            return false;
        }

        // Transfer enough to cover deficit plus reserve buffer
        $transferAmount = round($deficit * 1.15, 2); // 15% buffer to account for fees/timing
        $transferAmount = max($transferAmount, self::MIN_TRANSFER_AMOUNT);

        try {
            $transfer = $this->stark->createTransfer([
                'amount' => $transferAmount,
                'pix_key' => $pixKey,
                'pix_key_type' => strtolower($pixKeyType),
                'owner_name' => $ownerName,
                'owner_cpf_cnpj' => $ownerCnpj,
                'external_id' => 'refund_coverage_'.$refund->id,
            ]);

            $refund->update([
                'coverage_status' => 'awaiting_stark_transfer',
                'coverage_meta' => array_merge((array) ($refund->coverage_meta ?? []), [
                    'deficit' => $deficit,
                    'stark_transfer_id' => $transfer['id'] ?? null,
                    'stark_transfer_amount' => $transferAmount,
                    'stark_transfer_requested_at' => now()->toIso8601String(),
                ]),
            ]);

            Log::channel('payments')->info('RefundCoverage: transferência Stark→Asaas iniciada', [
                'refund_id' => $refund->id,
                'transfer_id' => $transfer['id'] ?? null,
                'amount' => $transferAmount,
            ]);

            return false; // not ready yet — retry will confirm when Asaas receives funds
        } catch (\Throwable $e) {
            Log::channel('discord')->error('RefundCoverage: falha na transferência Stark→Asaas', [
                'type' => 'payments',
                'refund_id' => $refund->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
