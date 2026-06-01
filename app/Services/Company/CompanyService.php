<?php

namespace App\Services\Company;

use App\Enums\Plan;
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
            ->update(['status' => 'ACTIVE', 'active' => true]);

        if ($company->asaas_subscription_id) {
            Subscription::where('company_id', $company->id)
                ->where('asaas_subscription_id', $company->asaas_subscription_id)
                ->update(['status' => 'active', 'last_payment_at' => now()]);
        }

        Log::channel('payments')->info('Empresa ativada', ['company_id' => $company->id]);

        if ($updated > 0) {
            $fresh = $company->fresh();

            if ($fresh->plan === Plan::Pdv) {
                UserPermissionService::grantPdvPermissions($fresh);
            }

            CompanyActivated::dispatch($fresh);
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
        $company->update([
            'status' => 'OVERDUE',
            'overdue_since' => $dueDate->toDateString(),
        ]);

        if ($company->asaas_subscription_id) {
            Subscription::where('company_id', $company->id)
                ->where('asaas_subscription_id', $company->asaas_subscription_id)
                ->update(['status' => 'overdue']);
        }

        Log::channel('payments')->warning('Empresa em atraso — carência de 3 dias úteis iniciada', [
            'company_id' => $company->id,
            'overdue_since' => $dueDate->toDateString(),
        ]);
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
