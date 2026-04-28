<?php

namespace App\Jobs;

use App\Contracts\AsaasServiceInterface;
use App\Exceptions\AsaasCircuitOpenException;
use App\Models\Company;
use App\Services\Company\CompanyService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class CreateAsaasSetupFee implements ShouldQueue
{
    use Queueable;

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(24);
    }

    public function __construct(public Company $company)
    {
        $this->onQueue('default');
    }

    public function handle(AsaasServiceInterface $asaasService): void
    {
        $plan = $this->company->plan;
        $setupFee = $plan?->setupFee() ?? 99.00;
        $monthlyPrice = ($plan?->hasMonthlySubscription()) ? ($plan?->monthlyPrice() ?? 0.0) : 0.0;
        $firstAmount = $setupFee + $monthlyPrice;
        $description = $monthlyPrice > 0
            ? "Ativação + 1º mês ({$plan?->label()}) — {$this->company->name}"
            : "Taxa de ativação — {$plan?->label()} — {$this->company->name}";
        $billingType = $this->company->subscription_payment_method ?? 'PIX';

        // Safety net: plano sem taxa de ativação (ex: free) — ativar imediatamente
        if ($firstAmount <= 0) {
            $this->company->update(['setup_fee_paid_at' => now()]);
            app(CompanyService::class)->activate($this->company);
            Log::channel('payments')->info('Taxa de ativação zero — empresa ativada imediatamente', [
                'company_id' => $this->company->id,
            ]);

            return;
        }

        // Cartão de crédito é processado diretamente na página de pendente (checkout transparente)
        if ($billingType === 'CREDIT_CARD') {
            Log::channel('payments')->info('Taxa de ativação via cartão — aguardando processamento na página pendente', [
                'company_id' => $this->company->id,
            ]);

            return;
        }

        try {
            $charge = $asaasService->createCharge(
                $this->company->asaas_customer_id,
                $firstAmount,
                $description,
                $billingType,
            );
        } catch (AsaasCircuitOpenException $e) {
            Log::channel('payments')->warning('Circuit aberto — CreateAsaasSetupFee adiado 15 min', [
                'company_id' => $this->company->id,
            ]);
            $this->release(900);

            return;
        }

        $updates = [
            'asaas_setup_charge_id' => $charge['id'],
            'asaas_setup_invoice_url' => $charge['invoiceUrl'] ?? null,
        ];

        if ($billingType === 'PIX') {
            $qr = $asaasService->getPaymentPixQrCode($charge['id']);
            $updates['asaas_setup_pix_qr_code'] = $qr['encodedImage'];
            $updates['asaas_setup_pix_copy_paste'] = $qr['payload'];
        } elseif ($billingType === 'BOLETO') {
            $updates['asaas_setup_bank_slip_url'] = $charge['bankSlipUrl'] ?? $charge['invoiceUrl'] ?? null;
        }

        $this->company->update($updates);

        Log::channel('payments')->info('Taxa de ativação criada no Asaas', [
            'company_id' => $this->company->id,
            'charge_id' => $charge['id'],
            'amount' => $firstAmount,
            'billing_type' => $billingType,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::channel('payments')->error('Falha ao criar taxa de ativação no Asaas', [
            'company_id' => $this->company->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
