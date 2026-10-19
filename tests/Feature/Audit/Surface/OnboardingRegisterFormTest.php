<?php

use App\Contracts\AsaasServiceInterface;
use App\Livewire\Onboarding\RegisterForm;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Auditoria 15/10 (superfície) — onboarding público (/cadastro) é o componente Livewire
 * Onboarding\RegisterForm, não o POST /cadastro (o throttle:5,1 da rota não vale para
 * /livewire/update).
 */
function fakeAsaasForOnboarding(): void
{
    $asaas = Mockery::mock(AsaasServiceInterface::class);
    $asaas->shouldReceive('createCustomer')->andReturn('cus_fake_'.uniqid());
    $asaas->shouldReceive('createCreditCardCharge')->andReturn([
        'id' => 'pay_atacante',
        'status' => 'CONFIRMED',
        'creditCard' => [
            'creditCardToken' => 'tok_cartao_do_atacante',
            'creditCardNumber' => '1111',
            'creditCardBrand' => 'VISA',
        ],
    ]);
    $asaas->shouldReceive('createSubscription')->andReturn([
        'id' => 'sub_criada_pelo_atacante',
        'value' => 119.0,
        'nextDueDate' => now()->addMonth()->toDateString(),
    ]);

    app()->instance(AsaasServiceInterface::class, $asaas);
}

test('visitante anônimo não reativa empresa bloqueada de terceiro trocando pendingCompanyId', function () {
    fakeAsaasForOnboarding();

    $victim = Company::create([
        'name' => 'Empresa Bloqueada',
        'slug' => 'empresa-bloqueada-'.uniqid(),
        'order_prefix' => 'BLQ',
        'plan' => 'pro',
        'status' => 'BLOCKED',
        'active' => false,
        'asaas_customer_id' => 'cus_vitima',
        'asaas_subscription_id' => 'sub_original_da_vitima',
    ]);

    try {
        Livewire::test(RegisterForm::class)
            ->set('pendingCompanyId', $victim->id)
            ->set('cardNumber', '4111 1111 1111 1111')
            ->set('cardExpiry', '12/30')
            ->set('cardCvv', '123')
            ->set('cardHolderName', 'Qualquer Pessoa')
            ->set('cardCpfCnpj', '529.982.247-25')
            ->set('cardPostalCode', '01001-000')
            ->set('cardAddressNumber', '1')
            ->call('submitCard');
    } catch (CannotUpdateLockedPropertyException) {
        // comportamento esperado após a correção (#[Locked])
    }

    $victim->refresh();

    expect($victim->status)->toBe('BLOCKED')
        ->and((bool) $victim->active)->toBeFalse()
        ->and($victim->asaas_subscription_id)->toBe('sub_original_da_vitima')
        ->and($victim->asaas_credit_card_token)->not->toBe('tok_cartao_do_atacante');
});

test('submit() revalida o passo 3 (e-mail e senha) e não aceita dados que o nextStep recusaria', function () {
    fakeAsaasForOnboarding();

    // Chamando submit() direto, sem passar pelos nextStep() que validam os passos 1-3.
    Livewire::test(RegisterForm::class)
        ->set('companyName', 'Loja Pulando Passos')
        ->set('slug', 'loja-pulando-passos')
        ->set('branchName', 'Matriz')
        ->set('branchPhone', '(11) 99999-0000')
        ->set('userName', 'Fulano')
        ->set('userEmail', 'isto-nao-e-email')
        ->set('userPassword', '1')
        ->set('plan', 'free')
        ->set('asaasCpfCnpj', '529.982.247-25')
        ->call('submit');

    // (o slug é normalizado pelo hook updatedSlug, então não é bypass; e-mail e senha não são)
    expect(User::where('email', 'isto-nao-e-email')->exists())->toBeFalse()
        ->and(Company::where('slug', 'loja-pulando-passos')->exists())->toBeFalse();
});

test('criação de conta pelo componente de onboarding tem limite de tentativas', function () {
    fakeAsaasForOnboarding();

    $created = 0;
    foreach (range(1, 8) as $i) {
        Livewire::test(RegisterForm::class)
            ->set('companyName', "Loja Spam {$i}")
            ->set('slug', "loja-spam-{$i}")
            ->set('branchName', 'Matriz')
            ->set('branchPhone', '(11) 99999-0000')
            ->set('userName', 'Spammer')
            ->set('userEmail', "spam{$i}@example.com")
            ->set('userPassword', 'senha12345')
            ->set('plan', 'free')
            ->set('asaasCpfCnpj', '529.982.247-25')
            ->call('submit');

        $created = Company::where('slug', 'like', 'loja-spam-%')->count();
    }

    // A rota POST /cadastro tem throttle:5,1; o componente que a tela usa de fato não tem nenhum.
    expect($created)->toBeLessThanOrEqual(5);
});
