<?php

namespace App\Services;

use App\Events\CompanyActivated;
use App\Events\CompanyBlocked;
use App\Models\Company;
use App\Models\Subscription;
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
        $company->update([
            'status' => 'ACTIVE',
            'active' => true,
        ]);

        if ($company->asaas_subscription_id) {
            Subscription::where('company_id', $company->id)
                ->where('asaas_subscription_id', $company->asaas_subscription_id)
                ->update(['status' => 'active', 'last_payment_at' => now()]);
        }

        Log::channel('payments')->info('Empresa ativada', ['company_id' => $company->id]);

        CompanyActivated::dispatch($company->fresh());
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
