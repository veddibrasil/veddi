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

test('onboarding via cartão cria a assinatura recorrente antes de cobrar o 1º mês', function () {
    $this->mock(AsaasServiceInterface::class, function ($mock) {
        $mock->shouldReceive('createCustomer')->once()->andReturn('cus_new_001');
        $mock->shouldReceive('createSubscription')
            ->once()
            ->ordered()
            ->withArgs(fn ($customerId, $plan, $billingType, $creditCard, $holderInfo, $nextDueDate, $extraAmount = 0.0, $extraDescription = '', $creditCardToken = null) => $creditCard !== null
                && $billingType === 'CREDIT_CARD'
                && $nextDueDate === now()->addMonthNoOverflow()->toDateString()
            )
            ->andReturn([
                'id' => 'sub_new_001',
                'value' => 59.0,
                'nextDueDate' => now()->addMonth()->toDateString(),
            ]);
        $mock->shouldReceive('createCreditCardCharge')
            ->once()
            ->ordered()
            ->withArgs(fn ($customerId, $amount) => $amount === 99.0 + 59.0)
            ->andReturn([
                'status' => 'CONFIRMED',
                'id' => 'pay_setup_001',
                'creditCard' => ['creditCardToken' => 'tok_001'],
            ]);
    });

    $test = Livewire::test(RegisterForm::class);
    fillRegisterFormSteps($test)->call('submit');

    $company = Company::where('slug', 'like', 'empresa-essencial-%')->firstOrFail();

    // pendingCompanyId é #[Locked] e preenchido pelo próprio submit(); número/validade/CVV
    // vão como parâmetro de submitCard() (não são propriedades do componente).
    $test->assertSet('pendingCompanyId', $company->id)
        ->set('cardHolderName', 'FULANO ADMIN')
        ->set('cardCpfCnpj', '12345678909')
        ->set('cardPostalCode', '01310000')
        ->set('cardAddressNumber', '100')
        ->call('submitCard', ['number' => '4111 1111 1111 1111', 'expiry' => '12/30', 'cvv' => '123'])
        ->assertSet('cardError', null);

    $company->refresh();

    expect($company->asaas_subscription_id)->toBe('sub_new_001');
    expect($company->asaas_setup_charge_id)->toBe('pay_setup_001');
    expect($company->active)->toBeTrue();
});

test('onboarding com cartão recusado na cobrança apaga a assinatura recém-criada e deixa tentar de novo', function () {
    $this->mock(AsaasServiceInterface::class, function ($mock) {
        $mock->shouldReceive('createCustomer')->once()->andReturn('cus_new_005');
        $mock->shouldReceive('createSubscription')->once()->andReturn([
            'id' => 'sub_temporaria',
            'value' => 59.0,
            'nextDueDate' => now()->addMonth()->toDateString(),
        ]);
        $mock->shouldReceive('createCreditCardCharge')->once()->andReturn([
            'status' => 'DECLINED',
            'creditCard' => ['declineReason' => 'Saldo insuficiente'],
        ]);
        $mock->shouldReceive('cancelSubscription')->once()->with('sub_temporaria');
    });

    $test = Livewire::test(RegisterForm::class);
    fillRegisterFormSteps($test)->call('submit');

    $test->set('cardHolderName', 'FULANO ADMIN')
        ->set('cardCpfCnpj', '12345678909')
        ->set('cardPostalCode', '01310000')
        ->set('cardAddressNumber', '100')
        ->call('submitCard', ['number' => '4111111111111111', 'expiry' => '12/30', 'cvv' => '123'])
        ->assertSet('cardError', 'Pagamento não aprovado. Confira os dados do cartão ou use outro cartão.');

    $company = Company::where('slug', 'like', 'empresa-essencial-%')->firstOrFail();

    expect($company->status)->toBe('PENDING_PAYMENT')
        ->and($company->setup_fee_paid_at)->toBeNull()
        ->and($company->asaas_subscription_id)->toBeNull();
});

test('onboarding com cartão recusado na criação da assinatura não chega a cobrar', function () {
    $this->mock(AsaasServiceInterface::class, function ($mock) {
        $mock->shouldReceive('createCustomer')->once()->andReturn('cus_new_006');
        // Foi o que o Asaas devolveu no caso da empresa 5 (24/10): 400 invalid_creditCard.
        $mock->shouldReceive('createSubscription')->once()->andThrow(new RuntimeException(
            'Asaas subscription error: {"errors":[{"code":"invalid_creditCard","description":"Transação não autorizada."}]}',
            400,
        ));
        $mock->shouldNotReceive('createCreditCardCharge');
    });

    $test = Livewire::test(RegisterForm::class);
    fillRegisterFormSteps($test)->call('submit');

    $test->set('cardHolderName', 'FULANO ADMIN')
        ->set('cardCpfCnpj', '12345678909')
        ->set('cardPostalCode', '01310000')
        ->set('cardAddressNumber', '100')
        ->call('submitCard', ['number' => '4111111111111111', 'expiry' => '12/30', 'cvv' => '123'])
        ->assertSet('cardError', 'Pagamento não aprovado. Confira os dados do cartão ou use outro cartão.');

    expect(Company::where('slug', 'like', 'empresa-essencial-%')->firstOrFail()->setup_fee_paid_at)->toBeNull();
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

    // pendingCompanyId é #[Locked] e preenchido pelo próprio submit(); número/validade/CVV
    // vão como parâmetro de submitCard() (não são propriedades do componente).
    $test->assertSet('pendingCompanyId', $company->id)
        ->set('cardHolderName', 'FULANO ADMIN')
        ->set('cardCpfCnpj', '12345678909')
        ->set('cardPostalCode', '01310000')
        ->set('cardAddressNumber', '100')
        ->call('submitCard', ['number' => '4111 1111 1111 1111', 'expiry' => '12/30', 'cvv' => '123'])
        ->assertSet('cardError', null);

    expect($company->refresh()->asaas_subscription_id)->toBeNull();
});

test('cartão recusado no onboarding devolve mensagem genérica e o modal tem limite de tentativas', function () {
    $this->mock(AsaasServiceInterface::class, function ($mock) {
        $mock->shouldReceive('createCustomer')->once()->andReturn('cus_new_003');
        // 5 tentativas chegam ao gateway; a 6ª é barrada antes. Cada recusa apaga a
        // assinatura criada naquela tentativa.
        $mock->shouldReceive('createSubscription')->times(5)->andReturn([
            'id' => 'sub_tentativa',
            'value' => 59.0,
            'nextDueDate' => now()->addMonth()->toDateString(),
        ]);
        $mock->shouldReceive('createCreditCardCharge')->times(5)->andReturn([
            'status' => 'DECLINED',
            'creditCard' => ['declineReason' => 'Saldo insuficiente'],
        ]);
        $mock->shouldReceive('cancelSubscription')->times(5)->with('sub_tentativa');
    });

    $test = Livewire::test(RegisterForm::class);
    fillRegisterFormSteps($test)->call('submit');

    $test->set('cardHolderName', 'FULANO ADMIN')
        ->set('cardCpfCnpj', '12345678909')
        ->set('cardPostalCode', '01310000')
        ->set('cardAddressNumber', '100');

    foreach (range(1, 5) as $i) {
        $test->call('submitCard', ['number' => '4111111111111111', 'expiry' => '12/30', 'cvv' => '123']);

        expect($test->get('cardError'))->toStartWith('Pagamento não aprovado')
            ->not->toContain('Saldo insuficiente');
    }

    $test->call('submitCard', ['number' => '4111111111111111', 'expiry' => '12/30', 'cvv' => '123']);

    expect($test->get('cardError'))->toContain('Muitas tentativas');
    expect(Company::where('slug', 'like', 'empresa-essencial-%')->firstOrFail()->status)->toBe('PENDING_PAYMENT');
});

test('submitCard recusa quando o id pendente não veio do submit() desta sessão', function () {
    $this->mock(AsaasServiceInterface::class, function ($mock) {
        $mock->shouldReceive('createCustomer')->once()->andReturn('cus_new_004');
        $mock->shouldReceive('createCreditCardCharge')->never();
    });

    $test = Livewire::test(RegisterForm::class);
    fillRegisterFormSteps($test)->call('submit');

    // Sessão trocada/expirada (outro navegador reaproveitando o snapshot).
    session()->forget('onboarding.pending_company_id');

    $test->set('cardHolderName', 'FULANO ADMIN')
        ->set('cardCpfCnpj', '12345678909')
        ->set('cardPostalCode', '01310000')
        ->set('cardAddressNumber', '100')
        ->call('submitCard', ['number' => '4111111111111111', 'expiry' => '12/30', 'cvv' => '123']);

    expect($test->get('cardError'))->toContain('Não foi possível continuar o pagamento');
});

test('senha não é enviada a cada tecla e número/CVV do cartão não são estado do Livewire', function () {
    $html = Livewire::test(RegisterForm::class)
        ->set('currentStep', 3)
        ->html();

    expect($html)->toContain('wire:model="userPassword"')
        ->not->toContain('wire:model.live="userPassword"')
        ->not->toContain('wire:model="cardNumber"')
        ->not->toContain('wire:model="cardCvv"')
        ->not->toContain("\$wire.set('cardNumber'")
        ->not->toContain("\$wire.set('cardCvv'")
        ->toContain('$wire.submitCard(');

    $snapshot = Livewire::test(RegisterForm::class)->snapshot['data'];

    expect($snapshot)->not->toHaveKeys(['cardNumber', 'cardCvv', 'cardExpiry']);
});
