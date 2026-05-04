<?php

use App\Jobs\ProcessAsaasWebhook;
use App\Models\Company;
use App\Services\Company\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeOnboardingCompany(string $status = 'PENDING_PAYMENT', ?string $setupChargeId = 'pay_setup_001'): Company
{
    return Company::create([
        'name' => 'Empresa Onboarding',
        'slug' => 'empresa-onboarding',
        'order_prefix' => 'ONB',
        'active' => false,
        'status' => $status,
        'asaas_customer_id' => 'cus_test_001',
        'asaas_setup_charge_id' => $setupChargeId,
    ]);
}

function dispatchAsaasCompany(string $event, array $payload): void
{
    (new ProcessAsaasWebhook($event, $payload))->handle(app(CompanyService::class));
}

// ─── Testes ───────────────────────────────────────────────────────────────────

test('onboarding: webhook de setup fee ativa empresa', function () {
    $company = makeOnboardingCompany();

    dispatchAsaasCompany('PAYMENT_CONFIRMED', [
        'payment' => [
            'id' => 'pay_setup_001',
            'customer' => 'cus_test_001',
        ],
    ]);

    $company->refresh();

    expect($company->active)->toBeTrue();
    expect($company->setup_fee_paid_at)->not->toBeNull();
});

test('onboarding: webhook PAYMENT_OVERDUE suspende empresa ativa', function () {
    $company = makeOnboardingCompany(status: 'ACTIVE');
    $company->update(['active' => true]);

    dispatchAsaasCompany('PAYMENT_OVERDUE', [
        'payment' => [
            'id' => 'pay_sub_001',
            'customer' => 'cus_test_001',
            'dueDate' => now()->toDateString(),
        ],
    ]);

    $company->refresh();

    expect($company->isActive())->toBeFalse();
    expect($company->isOverdue())->toBeTrue();
});

test('onboarding: webhook de empresa desconhecida é ignorado sem erro', function () {
    expect(fn () => dispatchAsaasCompany('PAYMENT_CONFIRMED', [
        'payment' => [
            'id' => 'pay_ghost',
            'customer' => 'cus_inexistente_999',
        ],
    ]))->not->toThrow(\Throwable::class);
});
