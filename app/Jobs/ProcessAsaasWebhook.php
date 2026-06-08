<?php

namespace App\Jobs;

use App\Contracts\RefundServiceInterface;
use App\Contracts\TransactionServiceInterface;
use App\Contracts\WalletServiceInterface;
use App\Events\OrderStatusUpdated;
use App\Models\Company;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Services\Company\CompanyService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

class ProcessAsaasWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    public function __construct(
        public string $event,
        public array $payload,
    ) {
        $this->onQueue('critical');
    }

    public function handle(CompanyService $companyService): void
    {
        // Anticipation events — handled before payment routing
        if (str_starts_with($this->event, 'RECEIVABLE_ANTICIPATION_')) {
            $this->handleAnticipationEvent();

            return;
        }

        // Check if this is a fiscal add-on payment (externalReference = fiscal_addon:{company_id})
        $externalRef = $this->payload['payment']['externalReference'] ?? null;

        if ($externalRef && str_starts_with((string) $externalRef, 'fiscal_addon:')) {
            $this->handleFiscalAddonPayment($externalRef);

            return;
        }

        // Check if this is an order payment first (has externalReference = order_id)
        if ($externalRef && is_numeric($externalRef)) {
            if (in_array($this->event, ['PAYMENT_CONFIRMED', 'PAYMENT_RECEIVED'])) {
                $this->handleOrderPayment((int) $externalRef);
            } elseif (in_array($this->event, ['PAYMENT_REFUNDED', 'PAYMENT_PARTIALLY_REFUNDED'])) {
                $this->handleRefundConfirmed();
            }

            return;
        }

        // Otherwise, handle company billing (subscription / setup fee)
        $customerId = $this->payload['payment']['customer']
            ?? $this->payload['subscription']['customer']
            ?? null;

        if (! $customerId) {
            Log::channel('webhook')->warning('Asaas webhook: customer ID ausente', [
                'event' => $this->event,
                'payload' => $this->payload,
            ]);

            return;
        }

        $company = Company::where('asaas_customer_id', $customerId)->first();

        if (! $company) {
            Log::channel('webhook')->warning('Asaas webhook: empresa não encontrada', [
                'event' => $this->event,
                'customer_id' => $customerId,
            ]);

            return;
        }

        match ($this->event) {
            'PAYMENT_CONFIRMED', 'PAYMENT_RECEIVED' => $this->handlePaymentConfirmed($company, $companyService),
            'PAYMENT_OVERDUE' => $this->handlePaymentOverdue($company, $companyService),
            default => Log::channel('webhook')->debug('Asaas webhook: evento ignorado', [
                'event' => $this->event,
                'company_id' => $company->id,
            ]),
        };
    }

    private function handleOrderPayment(int $orderId): void
    {
        $asaasPaymentId = $this->payload['payment']['id'] ?? null;

        $order = Order::find($orderId);

        if (! $order || ! $asaasPaymentId) {
            Log::channel('webhook')->warning('Asaas webhook: pedido ou payment ID ausente', [
                'event' => $this->event,
                'order_id' => $orderId,
                'asaas_payment_id' => $asaasPaymentId,
            ]);

            return;
        }

        $idempotencyKey = hash('sha256', 'asaas:'.$asaasPaymentId.':paid');

        try {
            DB::transaction(function () use ($order, $orderId, $asaasPaymentId, $idempotencyKey) {
                // lockForUpdate serializes concurrent jobs on the same payment row
                $payment = Payment::where('asaas_payment_id', $asaasPaymentId)
                    ->lockForUpdate()
                    ->first();

                if (! $payment) {
                    Log::channel('webhook')->warning('Asaas webhook: payment não encontrado para pedido', [
                        'event' => $this->event,
                        'asaas_payment_id' => $asaasPaymentId,
                        'order_id' => $orderId,
                    ]);

                    return;
                }

                if ($payment->status === 'paid') {
                    Log::channel('webhook')->info('Asaas webhook: pagamento de pedido já confirmado (duplicado ignorado)', [
                        'order_id' => $orderId,
                        'asaas_payment_id' => $asaasPaymentId,
                    ]);

                    return;
                }

                $payment->update([
                    'status' => 'paid',
                    'paid_at' => now(),
                    'webhook_payload' => $this->payload,
                    'idempotency_key' => $idempotencyKey,
                ]);

                $newStatus = ($order->scheduled_at && $order->scheduled_at->isFuture()) ? 'scheduled' : 'paid';
                $order->update(['status' => $newStatus]);

                Log::channel('payments')->info('Pagamento de pedido confirmado via Asaas', [
                    'order_id' => $orderId,
                    'asaas_payment_id' => $asaasPaymentId,
                    'amount' => $payment->amount,
                ]);

                app(WalletServiceInterface::class)->creditForOrder($order, $payment);
                app(TransactionServiceInterface::class)->createForPayment($order, $payment);
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            Log::channel('webhook')->info('Asaas webhook: duplicado ignorado via idempotency_key', [
                'asaas_payment_id' => $asaasPaymentId,
            ]);

            return;
        }

        OrderStatusUpdated::dispatch(Order::find($orderId));
    }

    private function handleRefundConfirmed(): void
    {
        $asaasPaymentId = $this->payload['payment']['id'] ?? null;

        if (! $asaasPaymentId) {
            return;
        }

        $payment = Payment::where('asaas_payment_id', $asaasPaymentId)->first();

        if (! $payment) {
            Log::channel('webhook')->warning('Asaas webhook REFUND: payment não encontrado', [
                'event' => $this->event,
                'asaas_payment_id' => $asaasPaymentId,
            ]);

            return;
        }

        $refund = PaymentRefund::where('payment_id', $payment->id)
            ->where('status', 'in_progress')
            ->first();

        if (! $refund) {
            Log::channel('webhook')->warning('Asaas webhook REFUND: nenhum estorno in_progress encontrado', [
                'event' => $this->event,
                'payment_id' => $payment->id,
                'asaas_payment_id' => $asaasPaymentId,
            ]);

            return;
        }

        app(RefundServiceInterface::class)->markSucceeded($refund, [
            'external_refund_id' => $asaasPaymentId,
            'external_status' => $this->payload['payment']['status'] ?? 'REFUNDED',
            'raw' => $this->payload['payment'] ?? [],
        ]);
    }

    private function handlePaymentConfirmed(Company $company, CompanyService $companyService): void
    {
        $paymentId = $this->payload['payment']['id'] ?? null;
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
                'charge_id' => $paymentId,
            ]);

            // Apply pending plan (upgrade from free to paid via billing settings)
            $wasUpgradeFromFree = $company->pending_plan !== null;
            if ($wasUpgradeFromFree) {
                $company->update([
                    'plan' => $company->pending_plan->value,
                    'pending_plan' => null,
                ]);
                $company->refresh();
            }

            $companyService->activate($company);

            // For plans with monthly subscription, kick off subscription creation now.
            // When upgrading from free, start next month (first month already paid in setup fee charge).
            if ($company->hasMonthlySubscription() && $company->asaas_subscription_id === null) {
                $nextDueDate = $wasUpgradeFromFree ? now()->addMonth()->toDateString() : null;
                CreateAsaasSubscription::dispatch($company->fresh(), $nextDueDate);
            }

            return;
        }

        // Recurring subscription payment — apply pending plan change if any, then activate
        if ($company->pending_plan !== null) {
            $company->update([
                'plan' => $company->pending_plan->value,
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
                'company_id' => $company->id,
                'current_plan' => $company->plan->value,
            ]);

            return;
        }

        // Use the actual due date from the Asaas payload so the grace period is
        // measured from the real due date, not from when the webhook was delivered.
        $rawDueDate = $this->payload['payment']['dueDate'] ?? null;
        $dueDate = $rawDueDate
            ? \Carbon\Carbon::parse($rawDueDate)
            : now();

        $companyService->markOverdue($company, $dueDate);
    }

    private function handleAnticipationEvent(): void
    {
        $anticipation = $this->payload['anticipation'] ?? [];
        $anticipationId = $anticipation['id'] ?? null;
        $asaasPaymentId = $anticipation['payment'] ?? null;

        Log::channel('payments')->info('Asaas webhook: evento de antecipação recebido', [
            'event' => $this->event,
            'anticipation_id' => $anticipationId,
            'asaas_payment_id' => $asaasPaymentId,
        ]);

        if ($this->event !== 'RECEIVABLE_ANTICIPATION_CREDITED' || ! $asaasPaymentId) {
            return;
        }

        // Find refund awaiting this anticipation and redispatch ProcessRefund
        $refund = PaymentRefund::whereJsonContains('coverage_meta->asaas_payment_id', $asaasPaymentId)
            ->where('coverage_status', 'awaiting_anticipation')
            ->whereIn('status', ['requested', 'in_progress'])
            ->first();

        if (! $refund) {
            Log::channel('payments')->debug('Asaas webhook: nenhum estorno aguardando antecipação', [
                'asaas_payment_id' => $asaasPaymentId,
            ]);

            return;
        }

        $refund->update(['coverage_status' => 'covered']);

        Log::channel('payments')->info('Asaas webhook: antecipação creditada — redespachando estorno', [
            'refund_id' => $refund->id,
            'asaas_payment_id' => $asaasPaymentId,
        ]);

        // Reset to requested so ProcessRefund will proceed
        if ($refund->status === 'in_progress' && $refund->external_refund_id === null) {
            $refund->update(['status' => 'requested']);
        }

        Queue::later(10, new ProcessRefund($refund->fresh()));
    }

    private function handleFiscalAddonPayment(string $externalRef): void
    {
        $companyId = (int) str_replace('fiscal_addon:', '', $externalRef);

        if (! $companyId) {
            Log::channel('webhook')->warning('Asaas webhook: fiscal_addon ref inválida', [
                'event' => $this->event,
                'ref' => $externalRef,
            ]);

            return;
        }

        $company = Company::find($companyId);

        if (! $company) {
            Log::channel('webhook')->warning('Asaas webhook: empresa do fiscal addon não encontrada', [
                'company_id' => $companyId,
            ]);

            return;
        }

        match ($this->event) {
            'PAYMENT_CONFIRMED', 'PAYMENT_RECEIVED' => $company->update(['fiscal_notes_enabled' => true]),
            'PAYMENT_OVERDUE', 'PAYMENT_DELETED' => $company->update(['fiscal_notes_enabled' => false]),
            default => null,
        };

        Log::channel('payments')->info('Asaas webhook: fiscal addon processado', [
            'event' => $this->event,
            'company_id' => $companyId,
            'fiscal_notes_enabled' => $company->fresh()->fiscal_notes_enabled,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::channel('discord')->error('Falha ao processar webhook Asaas', [
            'type' => 'webhook',
            'event' => $this->event,
            'error' => $exception->getMessage(),
        ]);
    }
}
