<?php

namespace App\Services\Finance;

use App\Contracts\AsaasServiceInterface;
use App\Models\PaymentRefund;
use Illuminate\Support\Facades\Log;

class RefundCoverageService
{
    private const RESERVE_RATIO = 0.10;

    public function __construct(
        private readonly AsaasServiceInterface $asaas,
    ) {}

    /**
     * Garante que a conta Asaas tem saldo para processar o estorno.
     *
     * Retorna true se pronto para processar. Retorna false e agenda cobertura
     * (antecipação) quando saldo insuficiente.
     *
     * Possíveis valores de coverage_status em PaymentRefund:
     *   null                    → ainda não verificado
     *   'covered'               → saldo confirmado, pode processar
     *   'awaiting_anticipation' → antecipação solicitada no Asaas, aguardando webhook
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

        return $this->arrangeAnticipation($refund, $deficit);
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
}
