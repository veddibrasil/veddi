<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\IfoodIntegration;
use App\Models\IfoodOrderEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function ifoodTestContext(string $suffix = 'A'): array
{
    $company = Company::create([
        'name' => "Empresa iFood {$suffix}",
        'slug' => "empresa-ifood-{$suffix}-".uniqid(),
        'order_prefix' => 'IFD',
        'active' => true,
    ]);

    app()->instance('current.company', $company);

    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => "Filial {$suffix}",
        'address' => 'Rua X, 1',
        'city' => 'SP',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    return compact('company', 'branch');
}

test('cria IfoodIntegration vinculada a empresa e filial', function () {
    ['company' => $company, 'branch' => $branch] = ifoodTestContext();

    $integration = IfoodIntegration::create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'merchant_id' => 'merchant-001',
        'status' => 'active',
    ]);

    $integration->refresh();

    expect($integration->id)->not->toBeNull()
        ->and($integration->company_id)->toBe($company->id)
        ->and($integration->webhook_status)->toBe('unknown')
        ->and($integration->isTokenExpired())->toBeTrue();
});

test('IfoodIntegration é escopada por empresa (CompanyScope)', function () {
    ['company' => $companyA, 'branch' => $branchA] = ifoodTestContext('A');
    IfoodIntegration::create([
        'company_id' => $companyA->id,
        'branch_id' => $branchA->id,
        'merchant_id' => 'merchant-a',
        'status' => 'active',
    ]);

    ['company' => $companyB, 'branch' => $branchB] = ifoodTestContext('B');
    IfoodIntegration::create([
        'company_id' => $companyB->id,
        'branch_id' => $branchB->id,
        'merchant_id' => 'merchant-b',
        'status' => 'active',
    ]);

    app()->instance('current.company', $companyA);
    expect(IfoodIntegration::count())->toBe(1)
        ->and(IfoodIntegration::first()->merchant_id)->toBe('merchant-a');

    app()->instance('current.company', $companyB);
    expect(IfoodIntegration::count())->toBe(1)
        ->and(IfoodIntegration::first()->merchant_id)->toBe('merchant-b');
});

test('cria IfoodOrderEvent vinculado a uma integração e dedup por event_id', function () {
    ['company' => $company, 'branch' => $branch] = ifoodTestContext();

    $integration = IfoodIntegration::create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'merchant_id' => 'merchant-001',
        'status' => 'active',
    ]);

    $event = IfoodOrderEvent::create([
        'event_id' => 'evt-001',
        'event_type' => 'PLC',
        'source' => 'webhook',
        'ifood_integration_id' => $integration->id,
        'payload' => ['orderId' => 'ord-001'],
        'status' => 'pending',
    ]);

    expect($event->payload)->toBe(['orderId' => 'ord-001']);

    expect(fn () => IfoodOrderEvent::create([
        'event_id' => 'evt-001',
        'event_type' => 'PLC',
        'source' => 'webhook',
        'ifood_integration_id' => $integration->id,
        'payload' => ['orderId' => 'ord-001'],
        'status' => 'pending',
    ]))->toThrow(\Illuminate\Database\UniqueConstraintViolationException::class);
});

test('isPendingAuthorization e isUserCodeExpired refletem o estado da autorização', function () {
    ['company' => $company, 'branch' => $branch] = ifoodTestContext();

    $integration = IfoodIntegration::create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'status' => 'disconnected',
        'user_code' => 'ABCD-1234',
        'user_code_expires_at' => now()->addMinutes(10),
    ]);

    expect($integration->isPendingAuthorization())->toBeTrue()
        ->and($integration->isUserCodeExpired())->toBeFalse();

    $integration->user_code_expires_at = now()->subMinute();
    expect($integration->isUserCodeExpired())->toBeTrue();

    $integration->merchant_id = 'merchant-001';
    expect($integration->isPendingAuthorization())->toBeFalse();
});
