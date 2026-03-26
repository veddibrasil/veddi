<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\CompanyService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessAsaasWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries  = 3;
    public int $backoff = 10;

    public function __construct(
        public string $event,
        public array  $payload,
    ) {}

    public function handle(CompanyService $companyService): void
    {
        // Asaas sends the customer ID in different paths depending on the event type
        $customerId = $this->payload['payment']['customer']
            ?? $this->payload['subscription']['customer']
            ?? null;

        if (! $customerId) {
            Log::channel('webhook')->warning('Asaas webhook: customer ID ausente', [
                'event'   => $this->event,
                'payload' => $this->payload,
            ]);
            return;
        }

        $company = Company::where('asaas_customer_id', $customerId)->first();

        if (! $company) {
            Log::channel('webhook')->warning('Asaas webhook: empresa não encontrada', [
                'event'       => $this->event,
                'customer_id' => $customerId,
            ]);
            return;
        }

        match ($this->event) {
            'PAYMENT_CONFIRMED', 'PAYMENT_RECEIVED' => $this->handlePaymentConfirmed($company, $companyService),
            'PAYMENT_OVERDUE'                        => $companyService->block($company),
            default => Log::channel('webhook')->debug('Asaas webhook: evento ignorado', [
                'event'      => $this->event,
                'company_id' => $company->id,
            ]),
        };
    }

    private function handlePaymentConfirmed(Company $company, CompanyService $companyService): void
    {
        $paymentId      = $this->payload['payment']['id'] ?? null;
        $subscriptionId = $this->payload['payment']['subscription'] ?? null;

        // Identify setup fee payment: charge ID matches stored setup charge,
        // OR fallback when job hasn't stored the ID yet (no subscription, setup not yet paid).
        $isSetupFee = $paymentId && (
            $paymentId === $company->asaas_setup_charge_id
            || (
                $company->asaas_setup_charge_id === null
                && $subscriptionId === null
                && ! $company->hasSetupFeePaid()
            )
        );

        if ($isSetupFee) {
            if ($company->asaas_setup_charge_id === null) {
                Log::channel('webhook')->warning('Asaas webhook: setup fee pago mas charge ID não registrado (race condition)', [
                    'company_id' => $company->id,
                    'payment_id' => $paymentId,
                ]);
            }

            $company->update(['setup_fee_paid_at' => now()]);

            Log::channel('payments')->info('Taxa de ativação confirmada', [
                'company_id' => $company->id,
                'charge_id'  => $paymentId,
            ]);

            $companyService->activate($company);

            // For plans with monthly subscription, kick off subscription creation now
            if ($company->hasMonthlySubscription()) {
                CreateAsaasSubscription::dispatch($company->fresh());
            }

            return;
        }

        // Recurring subscription payment — keep company active
        $companyService->activate($company);
    }

    public function failed(\Throwable $exception): void
    {
        Log::channel('webhook')->error('Falha ao processar webhook Asaas', [
            'event' => $this->event,
            'error' => $exception->getMessage(),
        ]);
    }
}
