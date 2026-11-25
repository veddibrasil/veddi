<?php

namespace App\Services\Company;

use App\Contracts\AsaasServiceInterface;
use App\Events\CompanyActivated;
use App\Events\CompanyBlocked;
use App\Models\Company;
use App\Models\Subscription;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class CompanyService
{
    /**
     * Activate a company after successful Asaas payment confirmation.
     *
     * Syncs both the new `status` field and the legacy `active` boolean
     * so that IdentifyCompany middleware continues to work correctly.
     */
    public function activate(Company $company): void
    {
        // Atomic update: only updates (and returns 1) if the company is not already ACTIVE.
        // This prevents a race condition where two simultaneous webhooks (e.g. PAYMENT_CONFIRMED
        // and PAYMENT_RECEIVED) both read status as non-ACTIVE and dispatch duplicate events.
        $updated = Company::where('id', $company->id)
            ->where('status', '!=', 'ACTIVE')
            ->update(['status' => 'ACTIVE', 'active' => true, 'overdue_since' => null]);

        if ($company->asaas_subscription_id) {
            Subscription::where('company_id', $company->id)
                ->where('asaas_subscription_id', $company->asaas_subscription_id)
                ->update(['status' => 'active', 'last_payment_at' => now()]);
        }

        Log::channel('payments')->info('Empresa ativada', ['company_id' => $company->id]);

        if ($updated > 0) {
            CompanyActivated::dispatch($company->fresh());
        }
    }

    /**
     * Mark a company as overdue, starting the 3-business-day grace period.
     *
     * The company remains accessible during this window. A scheduled command
     * (companies:block-overdue) will call block() once the grace period expires.
     */
    public function markOverdue(Company $company, Carbon $dueDate): void
    {
        // Bloqueada continua bloqueada: outra fatura vencida não pode devolver a carência.
        if ($company->isBlocked()) {
            Log::channel('payments')->info('Fatura vencida para empresa já bloqueada — mantida bloqueada', [
                'company_id' => $company->id,
                'due_date' => $dueDate->toDateString(),
            ]);

            return;
        }

        // Já em atraso: a carência segue contando da fatura vencida mais antiga.
        $overdueSince = $company->isOverdue() && $company->overdue_since?->lt($dueDate)
            ? $company->overdue_since
            : $dueDate;

        $company->update([
            'status' => 'OVERDUE',
            'overdue_since' => $overdueSince->toDateString(),
        ]);

        if ($company->asaas_subscription_id) {
            Subscription::where('company_id', $company->id)
                ->where('asaas_subscription_id', $company->asaas_subscription_id)
                ->update(['status' => 'overdue']);
        }

        Log::channel('payments')->warning('Empresa em atraso — carência de 3 dias úteis iniciada', [
            'company_id' => $company->id,
            'overdue_since' => $overdueSince->toDateString(),
        ]);
    }

    /**
     * Tira a empresa do atraso ou do bloqueio depois de um pagamento, mas só quando o Asaas
     * não tem mais nenhuma fatura vencida da assinatura atual. No cartão o Asaas manda
     * PAYMENT_RECEIVED uns 30 dias depois da aprovação (liquidação): sem esta conferência,
     * a liquidação de uma cobrança antiga reativava empresa com a fatura do mês em aberto.
     * Só a assinatura atual conta: cobrança avulsa antiga esquecida no Asaas (ex.: PIX de
     * cadastro abandonado) não pode prender quem pagou a mensalidade.
     *
     * @param  array<int, string>  $paidPaymentIds  cobranças que acabaram de ser pagas (o Asaas
     *                                              pode ainda listá-las como vencidas)
     * @return bool true quando reativou
     *
     * @throws \Throwable quando o Asaas não responde — quem chama decide se tenta de novo
     */
    public function reactivateIfNothingOverdue(Company $company, AsaasServiceInterface $asaas, array $paidPaymentIds = []): bool
    {
        $subscriptionId = $company->asaas_subscription_id;

        $stillOverdue = $subscriptionId === null ? collect() : collect($asaas->getCustomerOverduePayments($company->asaas_customer_id))
            ->filter(fn (array $payment) => ($payment['subscription'] ?? null) === $subscriptionId)
            ->reject(fn (array $payment) => in_array($payment['id'] ?? null, $paidPaymentIds, true));

        if ($stillOverdue->isNotEmpty()) {
            Log::channel('payments')->info('Pagamento recebido, mas a empresa segue com cobrança vencida', [
                'company_id' => $company->id,
                'paid_payment_ids' => $paidPaymentIds,
                'overdue_payment_ids' => $stillOverdue->pluck('id')->values()->all(),
            ]);

            return false;
        }

        $this->activate($company);

        return true;
    }

    /**
     * Block a company after an overdue Asaas payment.
     *
     * Syncs both the new `status` field and the legacy `active` boolean
     * so that IdentifyCompany middleware stops resolving this company.
     */
    public function block(Company $company): void
    {
        $company->update([
            'status' => 'BLOCKED',
            'active' => false,
        ]);

        if ($company->asaas_subscription_id) {
            Subscription::where('company_id', $company->id)
                ->where('asaas_subscription_id', $company->asaas_subscription_id)
                ->update(['status' => 'overdue']);
        }

        Log::channel('payments')->warning('Empresa bloqueada por inadimplência', ['company_id' => $company->id]);

        CompanyBlocked::dispatch($company->fresh());
    }
}
