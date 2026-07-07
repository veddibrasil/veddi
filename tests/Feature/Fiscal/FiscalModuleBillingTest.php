<?php

use App\Contracts\AsaasServiceInterface;
use App\Livewire\Admin\Settings\BillingSettings;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function fiscalBillingTestCompany(): Company
{
    return Company::create([
        'name' => 'Empresa Billing Fiscal',
        'slug' => 'empresa-billing-fiscal-'.uniqid(),
        'order_prefix' => 'BLF',
        'active' => true,
        'status' => 'ACTIVE',
        'plan' => 'essencial',
        'asaas_customer_id' => 'cus_000000000001',
        'asaas_subscription_id' => 'sub_000000000001',
    ]);
}

function fiscalBillingCreditCardFields($test)
{
    return $test
        ->set('cardNumber', '4111111111111111')
        ->set('cardExpiry', '12/30')
        ->set('cardCvv', '123')
        ->set('cardHolderName', 'FULANO DA SILVA')
        ->set('cardCpfCnpj', '12345678909')
        ->set('cardPostalCode', '01310000')
        ->set('cardAddressNumber', '100');
}

test('ativação do módulo fiscal combina valor do plano + fiscal em uma única assinatura', function () {
    $company = fiscalBillingTestCompany();
    app()->instance('current.company', $company);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    $this->mock(AsaasServiceInterface::class, function ($mock) {
        $mock->shouldReceive('getSubscriptionPayments')->andReturn([]);
        $mock->shouldReceive('createCreditCardCharge')
            ->once()
            ->withArgs(fn ($customerId, $amount, $description) => $amount === 59.0 + 65.0)
            ->andReturn(['status' => 'CONFIRMED']);
        $mock->shouldReceive('cancelSubscription')->once();
        $mock->shouldReceive('createSubscription')
            ->once()
            ->withArgs(fn ($customerId, $plan, $billingType, $creditCard, $holderInfo, $nextDueDate, $extraAmount, $extraDescription) => $extraAmount === 65.0 && $extraDescription === 'Módulo Fiscal')
            ->andReturn(['id' => 'sub_new_fiscal', 'status' => 'ACTIVE', 'value' => 59.0 + 65.0, 'nextDueDate' => now()->addMonth()->toDateString()]);
    });

    $test = Livewire::actingAs($admin)->test(BillingSettings::class);
    fiscalBillingCreditCardFields($test)
        ->call('activateFiscalModule')
        ->assertHasNoErrors();

    $company->refresh();
    expect($company->fiscal_notes_enabled)->toBeTrue();
    expect($company->asaas_subscription_id)->toBe('sub_new_fiscal');
});

test('cancelamento do módulo fiscal debita valor da assinatura mantendo apenas o plano', function () {
    $company = fiscalBillingTestCompany();
    $company->update(['fiscal_notes_enabled' => true]);
    app()->instance('current.company', $company);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    $this->mock(AsaasServiceInterface::class, function ($mock) {
        $mock->shouldReceive('getSubscriptionPayments')->andReturn([]);
        $mock->shouldReceive('updateSubscriptionValue')
            ->once()
            ->withArgs(fn ($subscriptionId, $value, $description) => $value === 59.0)
            ->andReturn(['value' => 59.0]);
    });

    Livewire::actingAs($admin)
        ->test(BillingSettings::class)
        ->call('cancelFiscalModule')
        ->assertHasNoErrors();

    expect($company->fresh()->fiscal_notes_enabled)->toBeFalse();
});

test('ativação do módulo fiscal soma ao módulo PDV já ativo', function () {
    $company = fiscalBillingTestCompany();
    $company->update(['pdv_module_enabled' => true]);
    app()->instance('current.company', $company);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    $this->mock(AsaasServiceInterface::class, function ($mock) {
        $mock->shouldReceive('getSubscriptionPayments')->andReturn([]);
        $mock->shouldReceive('createCreditCardCharge')
            ->once()
            ->withArgs(fn ($customerId, $amount) => $amount === 59.0 + 99.0 + 65.0)
            ->andReturn(['status' => 'CONFIRMED']);
        $mock->shouldReceive('cancelSubscription')->once();
        $mock->shouldReceive('createSubscription')
            ->once()
            ->withArgs(fn ($customerId, $plan, $billingType, $creditCard, $holderInfo, $nextDueDate, $extraAmount, $extraDescription) => $extraAmount === 99.0 + 65.0 && $extraDescription === 'Módulo PDV + Módulo Fiscal')
            ->andReturn(['id' => 'sub_new_combined', 'status' => 'ACTIVE', 'value' => 59.0 + 99.0 + 65.0, 'nextDueDate' => now()->addMonth()->toDateString()]);
    });

    $test = Livewire::actingAs($admin)->test(BillingSettings::class);
    fiscalBillingCreditCardFields($test)
        ->call('activateFiscalModule')
        ->assertHasNoErrors();

    expect($company->fresh()->fiscal_notes_enabled)->toBeTrue();
});
