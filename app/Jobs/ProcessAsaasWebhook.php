<?php

namespace App\Jobs;

use App\Events\OrderStatusUpdated;
use App\Models\Company;
use App\Models\Order;
use App\Models\Payment;
use App\Services\CompanyService;
use App\Services\WalletService;
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
        // Check if this is an order payment first (has externalReference = order_id)
        $externalRef = $this->payload['payment']['externalReference'] ?? null;

        if ($externalRef && is_numeric($externalRef)) {
            if (in_array($this->event, ['PAYMENT_CONFIRMED', 'PAYMENT_RECEIVED'])) {
                $this->handleOrderPayment((int) $externalRef);
            }
            return;
        }

        // Otherwise, handle company billing (subscription / setup fee)
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
            'PAYMENT_OVERDUE'                        => $this->handlePaymentOverdue($company, $companyService),
            default => Log::channel('webhook')->debug('Asaas webhook: evento ignorado', [
                'event'      => $this->event,
                'company_id' => $company->id,
            ]),
        };
    }

    private function handleOrderPayment(int $orderId): void
    {
        $asaasPaymentId = $this->payload['payment']['id'] ?? null;

        $order = Order::find($orderId);

        if (! $order) {
            Log::channel('webhook')->warning('Asaas webhook: pedido não encontrado', [
                'event'    => $this->event,
                'order_id' => $orderId,
            ]);
            return;
        }

        $payment = Payment::where('asaas_payment_id', $asaasPaymentId)->first();

        if (! $payment) {
            Log::channel('webhook')->warning('Asaas webhook: payment não encontrado para pedido', [
                'event'           => $this->event,
                'asaas_payment_id' => $asaasPaymentId,
                'order_id'        => $orderId,
            ]);
            return;
        }

        if ($payment->status === 'paid') {
            Log::channel('webhook')->info('Asaas webhook: pagamento de pedido já confirmado (duplicado ignorado)', [
                'order_id'         => $orderId,
                'asaas_payment_id' => $asaasPaymentId,
            ]);
            return;
        }

        $payment->update([
            'status'          => 'paid',
            'paid_at'         => now(),
            'webhook_payload' => $this->payload,
        ]);

        $order->update(['status' => 'paid']);

        Log::channel('payments')->info('Pagamento de pedido confirmado via Asaas', [
            'order_id'         => $orderId,
            'asaas_payment_id' => $asaasPaymentId,
            'amount'           => $payment->amount,
        ]);

        app(WalletService::class)->creditForOrder($order, $payment);

        OrderStatusUpdated::dispatch($order->fresh());
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

        // Recurring subscription payment — apply pending plan change if any, then activate
        if ($company->pending_plan !== null) {
            $company->update([
                'plan'         => $company->pending_plan->value,
                'pending_plan' => null,
            ]);
        }

        $companyService->activate($company);
    }

    private function handlePaymentOverdue(Company $company, CompanyService $companyService): void
    {
        // If there's a pending plan change, the overdue is for the new plan's subscription.
        // Cancel the pending change and keep the company on the current plan.
        if ($company->pending_plan !== null) {
            $company->update(['pending_plan' => null]);

            Log::channel('payments')->warning('Pagamento da troca de plano não confirmado — empresa mantida no plano atual', [
                'company_id'   => $company->id,
                'current_plan' => $company->plan->value,
            ]);

            return;
        }

        $companyService->block($company);
    }

    public function failed(\Throwable $exception): void
    {
        Log::channel('webhook')->error('Falha ao processar webhook Asaas', [
            'event' => $this->event,
            'error' => $exception->getMessage(),
        ]);
    }
}
