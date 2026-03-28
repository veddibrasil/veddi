<?php

namespace App\Services;

use App\DTOs\OnboardingDTO;
use App\Enums\Plan;
use App\Jobs\CreateAsaasSetupFee;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Services\UserPermissionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class OnboardingService
{
    public function __construct(private AsaasService $asaasService) {}

    /**
     * Handle the full onboarding flow atomically:
     * Company + Branch + User + Asaas customer + setup fee job.
     *
     * All plans start as PENDING_PAYMENT. The company is activated only
     * after the R$99 setup fee is confirmed via Asaas webhook.
     * For plans with monthly subscription, the subscription is created
     * automatically by ProcessAsaasWebhook after the setup fee is paid.
     *
     * The Asaas customer creation is intentionally called BEFORE the DB
     * transaction to fail fast without holding an open connection during
     * a slow HTTP request.
     */
    public function handle(OnboardingDTO $dto): Company
    {
        // 1. Create Asaas customer (outside transaction — fail fast)
        $asaasCustomerId = $this->asaasService->createCustomer([
            'name'    => $dto->companyName,
            'email'   => $dto->userEmail,
            'cpfCnpj' => $dto->asaasCpfCnpj,
        ]);

        // 2. Create all DB records atomically — all plans start as PENDING_PAYMENT
        $company = DB::transaction(function () use ($dto, $asaasCustomerId) {
            $company = Company::create([
                'name'                        => $dto->companyName,
                'slug'                        => $dto->slug,
                'plan'                        => $dto->plan,
                'status'                      => 'PENDING_PAYMENT',
                'active'                      => false,
                'asaas_customer_id'           => $asaasCustomerId,
                'order_prefix'                => strtoupper(substr(preg_replace('/[^a-z0-9]/i', '', $dto->slug), 0, 3)) ?: 'ORD',
                'subscription_payment_method' => $dto->paymentMethod,
                'owner_cpf_cnpj'              => preg_replace('/\D/', '', $dto->asaasCpfCnpj),
            ]);

            // withoutGlobalScopes() prevents CompanyScope from filtering the create call,
            // since app('current.company') is null during onboarding.
            Branch::withoutGlobalScopes()->create([
                'company_id' => $company->id,
                'name'       => $dto->branchName,
                'phone'      => $dto->branchPhone,
                'address'    => '',
                'city'       => '',
                'active'     => true,
            ]);

            $user = User::create([
                'name'     => $dto->userName,
                'email'    => $dto->userEmail,
                'password' => Hash::make($dto->userPassword),
            ]);

            $company->users()->attach($user->id, [
                'role'      => 'company_admin',
                'branch_id' => null,
            ]);

            UserPermissionService::assignRolePermissions($user, $company, 'company_admin');

            return $company;
        });

        // 3. Dispatch setup fee charge creation (after commit)
        CreateAsaasSetupFee::dispatch($company);

        Log::channel('payments')->info('Onboarding concluído — aguardando taxa de ativação', [
            'company_id' => $company->id,
            'plan'       => $dto->plan,
            'status'     => $company->status,
        ]);

        return $company;
    }
}
