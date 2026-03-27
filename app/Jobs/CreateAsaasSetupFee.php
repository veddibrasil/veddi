<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\AsaasService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class CreateAsaasSetupFee implements ShouldQueue
{
    use Queueable;

    public int $tries  = 3;
    public int $backoff = 30;

    public function __construct(public Company $company) {}

    public function handle(AsaasService $asaasService): void
    {
        $plan        = $this->company->plan;
        $setupFee    = $plan?->setupFee() ?? 99.00;
        $description = "Taxa de ativação — {$plan?->label()} — {$this->company->name}";
        $billingType = $this->company->subscription_payment_method ?? 'PIX';

        $charge = $asaasService->createCharge(
            $this->company->asaas_customer_id,
            $setupFee,
            $description,
            $billingType,
        );

        $this->company->update([
            'asaas_setup_charge_id'   => $charge['id'],
            'asaas_setup_invoice_url' => $charge['invoiceUrl'] ?? null,
        ]);

        Log::channel('payments')->info('Taxa de ativação criada no Asaas', [
            'company_id'   => $this->company->id,
            'charge_id'    => $charge['id'],
            'amount'       => $setupFee,
            'billing_type' => $billingType,
            'invoice_url'  => $charge['invoiceUrl'] ?? null,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::channel('payments')->error('Falha ao criar taxa de ativação no Asaas', [
            'company_id' => $this->company->id,
            'error'      => $exception->getMessage(),
        ]);
    }
}
