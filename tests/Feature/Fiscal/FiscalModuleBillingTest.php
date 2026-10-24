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

/**
 * Assinatura ativa no Asaas com a próxima fatura (já gerada) vencendo em $nextDueDate.
 */
function fiscalBillingActiveSubscription($mock, string $nextDueDate): void
{
    $mock->shouldReceive('getSubscription')->andReturn([
        'id' => 'sub_000000000001', 'status' => 'ACTIVE', 'deleted' => false,
        'value' => 59.0, 'nextDueDate' => now()->parse($nextDueDate)->addMonth()->toDateString(), 'billingType' => 'CREDIT_CARD',
    ]);
    $mock->shouldReceive('getPendingSubscriptionPayments')->andReturn([
        ['id' => 'pay_next', 'status' => 'PENDING', 'value' => 59.0, 'dueDate' => $nextDueDate],
    ]);
}

test('ativação do módulo fiscal no meio do ciclo cobra só o proporcional do módulo', function () {
    // Faltam 15 dos 31 dias do ciclo 24/10 → 24/11: 149 × 15/31 = 72,10.
    $this->travelTo(now()->setDate(2026, 11, 9)->setTime(10, 0));

    $company = fiscalBillingTestCompany();
    app()->instance('current.company', $company);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    $this->mock(AsaasServiceInterface::class, function ($mock) {
        $mock->shouldReceive('getSubscriptionPayments')->andReturn([]);
        fiscalBillingActiveSubscription($mock, '2026-11-24');
        $mock->shouldReceive('updateSubscriptionValue')
            ->once()
            ->with('sub_000000000001', 59.0 + 149.0, 'Assinatura Plano VEDDI ESSENCIAL + Módulo Fiscal')
            ->andReturn(['id' => 'sub_000000000001', 'status' => 'ACTIVE', 'value' => 208.0]);
        $mock->shouldReceive('createCreditCardCharge')
            ->once()
            ->withArgs(fn ($customerId, $amount, $description) => $amount === 72.10
                && str_contains($description, 'proporcional até 24/11/2026'))
            ->andReturn(['id' => 'pay_fiscal', 'status' => 'CONFIRMED']);
        $mock->shouldNotReceive('cancelSubscription');
        $mock->shouldNotReceive('createSubscription');
    });

    $test = Livewire::actingAs($admin)->test(BillingSettings::class);
    fiscalBillingCreditCardFields($test)
        ->call('activateFiscalModule')
        ->assertHasNoErrors()
        ->assertSet('fiscalError', null);

    $company->refresh();
    expect($company->fiscal_notes_enabled)->toBeTrue();
    expect($company->asaas_subscription_id)->toBe('sub_000000000001');
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

test('ativação do módulo fiscal soma ao módulo PDV já ativo sem cobrar o PDV de novo', function () {
    $this->travelTo(now()->setDate(2026, 10, 24)->setTime(12, 0));

    $company = fiscalBillingTestCompany();
    $company->update(['pdv_module_enabled' => true]);
    app()->instance('current.company', $company);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    $this->mock(AsaasServiceInterface::class, function ($mock) {
        $mock->shouldReceive('getSubscriptionPayments')->andReturn([]);
        fiscalBillingActiveSubscription($mock, '2026-11-24');
        $mock->shouldReceive('updateSubscriptionValue')
            ->once()
            ->with('sub_000000000001', 59.0 + 99.0 + 149.0, 'Assinatura Plano VEDDI ESSENCIAL + Módulo PDV + Módulo Fiscal')
            ->andReturn(['id' => 'sub_000000000001', 'status' => 'ACTIVE', 'value' => 307.0]);
        $mock->shouldReceive('createCreditCardCharge')
            ->once()
            ->withArgs(fn ($customerId, $amount) => $amount === 149.0)
            ->andReturn(['id' => 'pay_fiscal', 'status' => 'CONFIRMED']);
        $mock->shouldNotReceive('createSubscription');
    });

    $test = Livewire::actingAs($admin)->test(BillingSettings::class);
    fiscalBillingCreditCardFields($test)
        ->call('activateFiscalModule')
        ->assertHasNoErrors();

    expect($company->fresh()->fiscal_notes_enabled)->toBeTrue();
});
