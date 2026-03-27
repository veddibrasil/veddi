<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\Subscription;
use App\Services\AsaasService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class CreateAsaasSubscription implements ShouldQueue
{
    use Queueable;

    public int $tries  = 3;
    public int $backoff = 30;

    public function __construct(public Company $company) {}

    public function handle(AsaasService $asaasService): void
    {
        if (! $this->company->asaas_customer_id) {
            Log::channel('payments')->error('CreateAsaasSubscription: empresa sem asaas_customer_id', [
                'company_id' => $this->company->id,
            ]);
            return;
        }

        $plan        = $this->company->pending_plan ?? $this->company->plan;
        $billingType = $this->company->subscription_payment_method ?? 'PIX';

        $result = $asaasService->createSubscription(
            $this->company->asaas_customer_id,
            $plan,
            $billingType,
        );

        $this->company->update(['asaas_subscription_id' => $result['id']]);

        Subscription::create([
            'company_id'            => $this->company->id,
            'asaas_subscription_id' => $result['id'],
            'plan'                  => $this->company->plan,
            'status'                => 'active',
            'amount'                => $result['value'],
            'billing_cycle'         => 'MONTHLY',
            'next_due_date'         => $result['nextDueDate'],
        ]);

        Log::channel('payments')->info('Assinatura Asaas criada', [
            'company_id'            => $this->company->id,
            'asaas_subscription_id' => $result['id'],
            'amount'                => $result['value'],
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::channel('payments')->error('Falha ao criar assinatura Asaas', [
            'company_id' => $this->company->id,
            'error'      => $exception->getMessage(),
        ]);
    }
}
