<?php

use App\Contracts\AsaasServiceInterface;
use App\Livewire\Admin\Settings\BillingSettings;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Portal;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function billingCompany(array $overrides = []): Company
{
    $company = Company::create(array_merge([
        'name' => 'Empresa Billing',
        'slug' => 'empresa-billing-'.uniqid(),
        'order_prefix' => 'BIL',
        'active' => true,
        'plan' => 'essencial',
        'status' => 'ACTIVE',
        'asaas_customer_id' => 'cus_123',
    ], $overrides));

    app()->instance('current.company', $company);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin', 'branch_id' => null]);
    test()->actingAs($admin);

    return $company;
}

function fillCardFields($component)
{
    return $component
        ->set('cardNumber', '4111111111111111')
        ->set('cardExpiry', '12/30')
        ->set('cardCvv', '123')
        ->set('cardHolderName', 'JOAO DA SILVA')
        ->set('cardCpfCnpj', '12345678901')
        ->set('cardPostalCode', '01310-100')
        ->set('cardAddressNumber', '100');
}

test('activatePortalsModule cobra plano + addon Portais e ativa o módulo', function () {
    $company = billingCompany();

    $mock = $this->mock(AsaasServiceInterface::class);
    $mock->shouldReceive('createCreditCardCharge')
        ->once()
        ->andReturnUsing(function (string $customerId, float $amount) {
            expect($customerId)->toBe('cus_123');
            expect($amount)->toBe(59.00 + 79.00);

            return ['status' => 'CONFIRMED'];
        });
    $mock->shouldReceive('createSubscription')
        ->once()
        ->andReturn(['id' => 'sub_new', 'value' => 138.00, 'nextDueDate' => now()->addMonth()->toDateString()]);

    $component = Livewire::test(BillingSettings::class);
    fillCardFields($component)->call('activatePortalsModule');

    expect($company->fresh()->portals_module_enabled)->toBeTrue();
    expect($company->fresh()->asaas_subscription_id)->toBe('sub_new');
    expect(Subscription::where('company_id', $company->id)->where('asaas_subscription_id', 'sub_new')->exists())->toBeTrue();

    $permission = Permission::where('name', 'portals.manage')->first();
    expect(UserPermission::where('user_id', auth()->id())
        ->where('company_id', $company->id)
        ->where('permission_id', $permission->id)
        ->value('granted'))->toBeTrue();
});

test('cancelPortalsModule debita só o addon Portais quando PDV não está ativo', function () {
    $company = billingCompany([
        'portals_module_enabled' => true,
        'asaas_subscription_id' => 'sub_1',
    ]);

    $mock = $this->mock(AsaasServiceInterface::class);
    $mock->shouldReceive('getSubscriptionPayments')->andReturn([]);
    $mock->shouldReceive('updateSubscriptionValue')
        ->once()
        ->withArgs(function (string $subscriptionId, float $value, string $description) {
            return $subscriptionId === 'sub_1' && $value === 59.00;
        })
        ->andReturn(['value' => 59.00]);

    Livewire::test(BillingSettings::class)->call('cancelPortalsModule');

    expect($company->fresh()->portals_module_enabled)->toBeFalse();

    $permission = Permission::where('name', 'portals.manage')->first();
    expect(UserPermission::where('user_id', auth()->id())
        ->where('company_id', $company->id)
        ->where('permission_id', $permission->id)
        ->value('granted'))->toBeFalse();
});

test('cancelPortalsModule mantém cobrança do PDV quando os dois addons estão ativos', function () {
    $company = billingCompany([
        'pdv_module_enabled' => true,
        'portals_module_enabled' => true,
        'asaas_subscription_id' => 'sub_1',
    ]);

    $branch = \App\Models\Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial',
        'address' => 'Rua A',
        'city' => 'SP',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    $portal = Portal::create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'channel' => 'ifood',
        'external_merchant_id' => 'merchant-1',
        'status' => 'connected',
    ]);

    $mock = $this->mock(AsaasServiceInterface::class);
    $mock->shouldReceive('getSubscriptionPayments')->andReturn([]);
    $mock->shouldReceive('updateSubscriptionValue')
        ->once()
        ->withArgs(function (string $subscriptionId, float $value, string $description) {
            // Plano essencial (59) + PDV (99) — Portais removido, PDV continua cobrado.
            return $value === 59.00 + 99.00;
        })
        ->andReturn(['value' => 158.00]);

    Livewire::test(BillingSettings::class)->call('cancelPortalsModule');

    expect($company->fresh()->portals_module_enabled)->toBeFalse();
    expect($company->fresh()->pdv_module_enabled)->toBeTrue();
    expect($portal->fresh()->status)->toBe('disconnected');
});
