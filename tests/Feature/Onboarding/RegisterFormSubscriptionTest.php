<?php

use App\Contracts\AsaasServiceInterface;
use App\Livewire\Onboarding\RegisterForm;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function fillRegisterFormSteps($test)
{
    return $test
        ->set('companyName', 'Empresa Essencial')
        ->set('slug', 'empresa-essencial-'.uniqid())
        ->set('branchName', 'Matriz')
        ->set('branchPhone', '(11) 91234-5678')
        ->set('userName', 'Fulano Admin')
        ->set('userEmail', 'fulano-'.uniqid().'@example.com')
        ->set('userPassword', 'SenhaForte1!')
        ->set('plan', 'essencial')
        ->set('paymentMethod', 'CREDIT_CARD')
        ->set('asaasCpfCnpj', '123.456.789-09');
}

test('onboarding via cartão cria assinatura recorrente além da cobrança do 1º mês', function () {
    $this->mock(AsaasServiceInterface::class, function ($mock) {
        $mock->shouldReceive('createCustomer')->once()->andReturn('cus_new_001');
        $mock->shouldReceive('createCreditCardCharge')
            ->once()
            ->andReturn([
                'status' => 'CONFIRMED',
                'id' => 'pay_setup_001',
                'creditCard' => ['creditCardToken' => 'tok_001'],
            ]);
        $mock->shouldReceive('createSubscription')
            ->once()
            ->withArgs(fn ($customerId, $plan, $billingType, $creditCard, $holderInfo, $nextDueDate, $extraAmount = 0.0, $extraDescription = '', $creditCardToken = null) => $creditCardToken === 'tok_001'
                && $billingType === 'CREDIT_CARD'
            )
            ->andReturn([
                'id' => 'sub_new_001',
                'value' => 59.0,
                'nextDueDate' => now()->addMonth()->toDateString(),
            ]);
    });

    $test = Livewire::test(RegisterForm::class);
    fillRegisterFormSteps($test)->call('submit');

    $company = Company::where('slug', 'like', 'empresa-essencial-%')->firstOrFail();

    $test->set('pendingCompanyId', $company->id)
        ->set('cardNumber', '4111111111111111')
        ->set('cardExpiry', '12/30')
        ->set('cardCvv', '123')
        ->set('cardHolderName', 'FULANO ADMIN')
        ->set('cardCpfCnpj', '12345678909')
        ->set('cardPostalCode', '01310000')
        ->set('cardAddressNumber', '100')
        ->call('submitCard')
        ->assertSet('cardError', null);

    $company->refresh();

    expect($company->asaas_subscription_id)->toBe('sub_new_001');
    expect($company->active)->toBeTrue();
});

test('onboarding via cartão não cria assinatura para plano sem cobrança recorrente', function () {
    config(['plans.essencial.has_monthly_subscription' => false]);

    $this->mock(AsaasServiceInterface::class, function ($mock) {
        $mock->shouldReceive('createCustomer')->once()->andReturn('cus_new_002');
        $mock->shouldReceive('createCreditCardCharge')->once()->andReturn([
            'status' => 'CONFIRMED',
            'id' => 'pay_setup_002',
        ]);
        $mock->shouldReceive('createSubscription')->never();
    });

    $test = Livewire::test(RegisterForm::class);
    fillRegisterFormSteps($test)->call('submit');

    $company = Company::where('slug', 'like', 'empresa-essencial-%')->firstOrFail();

    $test->set('pendingCompanyId', $company->id)
        ->set('cardNumber', '4111111111111111')
        ->set('cardExpiry', '12/30')
        ->set('cardCvv', '123')
        ->set('cardHolderName', 'FULANO ADMIN')
        ->set('cardCpfCnpj', '12345678909')
        ->set('cardPostalCode', '01310000')
        ->set('cardAddressNumber', '100')
        ->call('submitCard')
        ->assertSet('cardError', null);

    expect($company->refresh()->asaas_subscription_id)->toBeNull();
});
