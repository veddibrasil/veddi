<?php

namespace App\Jobs;

use App\Contracts\AsaasServiceInterface;
use App\Exceptions\AsaasCircuitOpenException;
use App\Models\Company;
use App\Models\Subscription;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class CreateAsaasSubscription implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public array $backoff = [60, 300, 900];

    public int $uniqueFor = 3600;

    public function uniqueId(): string
    {
        return (string) $this->company->id;
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(24);
    }

    public function __construct(
        public Company $company,
        public ?string $nextDueDate = null,
    ) {
        $this->onQueue('default');
    }

    public function handle(AsaasServiceInterface $asaasService): void
    {
        if (! $this->company->asaas_customer_id) {
            Log::channel('discord')->error('CreateAsaasSubscription: empresa sem asaas_customer_id', [
                'type' => 'payments',
                'company_id' => $this->company->id,
            ]);

            return;
        }

        $plan = $this->company->pending_plan ?? $this->company->plan;
        $billingType = $this->company->subscription_payment_method ?? 'PIX';

        try {
            $result = $asaasService->createSubscription(
                $this->company->asaas_customer_id,
                $plan,
                $billingType,
                nextDueDate: $this->nextDueDate,
            );
        } catch (AsaasCircuitOpenException $e) {
            Log::channel('payments')->warning('Circuit aberto — CreateAsaasSubscription adiado 15 min', [
                'company_id' => $this->company->id,
            ]);
            $this->release(900);

            return;
        }

        $this->company->update(['asaas_subscription_id' => $result['id']]);

        Subscription::create([
            'company_id' => $this->company->id,
            'asaas_subscription_id' => $result['id'],
            'plan' => $this->company->plan,
            'status' => 'active',
            'amount' => $result['value'],
            'billing_cycle' => 'MONTHLY',
            'next_due_date' => $result['nextDueDate'],
        ]);

        Log::channel('payments')->info('Assinatura Asaas criada', [
            'company_id' => $this->company->id,
            'asaas_subscription_id' => $result['id'],
            'amount' => $result['value'],
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::channel('discord')->error('Falha ao criar assinatura Asaas', [
            'type' => 'payments',
            'company_id' => $this->company->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
