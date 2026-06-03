<?php

use App\Jobs\ProcessAsaasWebhook;
use App\Models\Company;
use App\Services\Company\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function fiscalAddonCompany(): Company
{
    return Company::create([
        'name' => 'Empresa Addon',
        'slug' => 'empresa-addon-'.uniqid(),
        'order_prefix' => 'ADN',
        'active' => true,
        'status' => 'ACTIVE',
        'asaas_customer_id' => 'cus_addon_test',
        'fiscal_notes_enabled' => false,
    ]);
}

function fiscalAddonPayload(int $companyId, string $event, string $paymentId = 'pay_addon_001'): array
{
    return [
        'event' => $event,
        'payment' => [
            'id' => $paymentId,
            'externalReference' => "fiscal_addon:{$companyId}",
            'status' => 'CONFIRMED',
            'customer' => 'cus_addon_test',
        ],
    ];
}

test('PAYMENT_CONFIRMED com fiscal_addon ref ativa fiscal_notes_enabled', function () {
    $company = fiscalAddonCompany();

    $job = new ProcessAsaasWebhook(
        'PAYMENT_CONFIRMED',
        fiscalAddonPayload($company->id, 'PAYMENT_CONFIRMED'),
    );
    $job->handle(app(CompanyService::class));

    expect($company->fresh()->fiscal_notes_enabled)->toBeTrue();
});

test('PAYMENT_OVERDUE com fiscal_addon ref desativa fiscal_notes_enabled', function () {
    $company = fiscalAddonCompany();
    $company->update(['fiscal_notes_enabled' => true]);

    $job = new ProcessAsaasWebhook(
        'PAYMENT_OVERDUE',
        fiscalAddonPayload($company->id, 'PAYMENT_OVERDUE'),
    );
    $job->handle(app(CompanyService::class));

    expect($company->fresh()->fiscal_notes_enabled)->toBeFalse();
});

test('PAYMENT_DELETED com fiscal_addon ref desativa fiscal_notes_enabled', function () {
    $company = fiscalAddonCompany();
    $company->update(['fiscal_notes_enabled' => true]);

    $job = new ProcessAsaasWebhook(
        'PAYMENT_DELETED',
        fiscalAddonPayload($company->id, 'PAYMENT_DELETED'),
    );
    $job->handle(app(CompanyService::class));

    expect($company->fresh()->fiscal_notes_enabled)->toBeFalse();
});
