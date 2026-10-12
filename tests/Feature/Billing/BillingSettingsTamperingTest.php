<?php

use App\Contracts\AsaasServiceInterface;
use App\Livewire\Admin\Settings\BillingSettings;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function billingTamperingActors(array $companyAttributes = []): array
{
    $company = Company::create(array_merge([
        'name' => 'Empresa Assinatura',
        'slug' => 'empresa-assinatura-'.uniqid(),
        'order_prefix' => 'ASS',
        'active' => true,
        'status' => 'ACTIVE',
        'plan' => 'essencial',
        'asaas_customer_id' => 'cus_assinatura_001',
        'asaas_subscription_id' => 'sub_assinatura_001',
    ], $companyAttributes));
    app()->instance('current.company', $company);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    return [$company, $admin];
}

function billingCardFields($test)
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

test('navegador não consegue alterar preço, plano ou módulos exibidos na tela', function (string $property, mixed $value) {
    [, $admin] = billingTamperingActors();

    $this->mock(AsaasServiceInterface::class, fn ($mock) => $mock->shouldReceive('getSubscriptionPayments')->andReturn([]));

    expect(fn () => Livewire::actingAs($admin)->test(BillingSettings::class)->set($property, $value))
        ->toThrow(CannotUpdateLockedPropertyException::class);
})->with([
    ['pdvAddonAmount', 0.0],
    ['fiscalAddonAmount', 0.0],
    ['fiscalModuleEnabled', false],
    ['plan', 'pro'],
]);

test('upgrade por PIX a partir do gratuito sempre cobra a taxa de ativação', function () {
    [$company, $admin] = billingTamperingActors(['plan' => 'free', 'asaas_subscription_id' => null]);

    $this->mock(AsaasServiceInterface::class, function ($mock) {
        $mock->shouldReceive('createCharge')
            ->once()
            ->withArgs(fn ($customerId, $amount) => $amount === 99.0 + 59.0)
            ->andReturn(['id' => 'pay_setup_001']);
    });

    Livewire::actingAs($admin)
        ->test(BillingSettings::class)
        ->call('confirmChangePlan', 'essencial')
        ->set('acceptedTerms', true)
        ->set('paymentMethod', 'pix')
        ->call('changePlan');

    expect($company->fresh()->asaas_setup_charge_id)->toBe('pay_setup_001');
});

test('empresa com fatura em atraso não sai do bloqueio mudando para o gratuito', function () {
    [$company, $admin] = billingTamperingActors(['status' => 'OVERDUE']);

    $this->mock(AsaasServiceInterface::class, function ($mock) {
        $mock->shouldReceive('getSubscriptionPayments')->andReturn([]);
        $mock->shouldNotReceive('cancelSubscription');
    });

    Livewire::actingAs($admin)
        ->test(BillingSettings::class)
        ->call('confirmChangePlan', 'free')
        ->call('changePlan')
        ->assertSet('planChangeError', 'Regularize a fatura em aberto antes de mudar para o plano gratuito.')
        ->assertSet('confirmingPlanChange', true);

    $company->refresh();
    expect($company->plan->value)->toBe('essencial')
        ->and($company->status)->toBe('OVERDUE')
        ->and($company->asaas_subscription_id)->toBe('sub_assinatura_001');
});

test('troca de plano no cartão só cancela a assinatura atual depois que o cartão é aprovado', function () {
    Queue::fake();
    [$company, $admin] = billingTamperingActors();

    $this->mock(AsaasServiceInterface::class, function ($mock) {
        $mock->shouldReceive('getSubscriptionPayments')->andReturn([]);
        $mock->shouldReceive('createCreditCardCharge')->once()->andReturn([
            'status' => 'DECLINED',
            'creditCard' => ['declineReason' => 'saldo insuficiente'],
        ]);
        $mock->shouldNotReceive('cancelSubscription');
        $mock->shouldNotReceive('createSubscription');
    });

    $test = Livewire::actingAs($admin)
        ->test(BillingSettings::class)
        ->call('confirmChangePlan', 'pro')
        ->set('acceptedTerms', true)
        ->set('paymentMethod', 'credit_card')
        ->call('changePlan');

    $company->refresh();
    expect($company->asaas_subscription_id)->toBe('sub_assinatura_001')
        ->and($company->pending_plan->value)->toBe('pro');

    billingCardFields($test)
        ->call('submitCardForSubscription')
        ->assertSet('cardError', 'Pagamento recusado: saldo insuficiente.')
        ->assertSet('cardNumber', '')
        ->assertSet('cardCvv', '');

    $company->refresh();
    expect($company->plan->value)->toBe('essencial')
        ->and($company->asaas_subscription_id)->toBe('sub_assinatura_001');
});

test('troca de plano no cartão aprovada substitui a assinatura antiga', function () {
    [$company, $admin] = billingTamperingActors(['pending_plan' => 'pro']);

    $this->mock(AsaasServiceInterface::class, function ($mock) {
        $mock->shouldReceive('getSubscriptionPayments')->andReturn([]);
        $mock->shouldReceive('createCreditCardCharge')
            ->once()
            ->withArgs(fn ($customerId, $amount) => $amount === 119.0)
            ->andReturn(['status' => 'CONFIRMED']);
        $mock->shouldReceive('cancelSubscription')->once()->with('sub_assinatura_001');
        $mock->shouldReceive('createSubscription')->once()->andReturn([
            'id' => 'sub_assinatura_pro',
            'value' => 119.0,
            'nextDueDate' => now()->addMonth()->toDateString(),
        ]);
    });

    $test = Livewire::actingAs($admin)
        ->test(BillingSettings::class)
        ->set('acceptedTerms', true);

    billingCardFields($test)
        ->call('submitCardForSubscription')
        ->assertSet('cardSuccess', true)
        ->assertSet('cardNumber', '');

    $company->refresh();
    expect($company->plan->value)->toBe('pro')
        ->and($company->asaas_subscription_id)->toBe('sub_assinatura_pro');
});

test('tela de assinatura abre mesmo com o Asaas fora do ar', function () {
    [, $admin] = billingTamperingActors();

    $this->mock(AsaasServiceInterface::class, function ($mock) {
        $mock->shouldReceive('getSubscriptionPayments')->andThrow(new RuntimeException('Asaas indisponível'));
    });

    Livewire::actingAs($admin)
        ->test(BillingSettings::class)
        ->assertOk()
        ->assertSet('payments', []);
});
