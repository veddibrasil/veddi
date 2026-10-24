<?php

use App\Contracts\AsaasServiceInterface;
use App\DTOs\CreditCardDTO;
use App\DTOs\CreditCardHolderDTO;
use App\Enums\Plan;
use App\Exceptions\CardPaymentDeclinedException;
use App\Exceptions\SubscriptionBillingException;
use App\Jobs\CreateAsaasSubscription;
use App\Livewire\Admin\Settings\BillingSettings;
use App\Models\Company;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Company\SubscriptionBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Empresa no estado da MISTER COXINHA APUCARANA logo após o cadastro de 24/10:
 * Essencial pago, assinatura no cartão com a 1ª fatura (já gerada) vencendo em 24/11.
 */
function sbsCompany(array $attributes = []): Company
{
    $company = Company::create(array_merge([
        'name' => 'Empresa Cobrança',
        'slug' => 'empresa-cobranca-'.uniqid(),
        'order_prefix' => 'COB',
        'active' => true,
        'status' => 'ACTIVE',
        'plan' => 'essencial',
        'asaas_customer_id' => 'cus_cobranca',
        'asaas_subscription_id' => 'sub_atual',
    ], $attributes));

    if ($company->asaas_subscription_id) {
        Subscription::create([
            'company_id' => $company->id,
            'asaas_subscription_id' => $company->asaas_subscription_id,
            'plan' => $company->plan->value,
            'status' => 'active',
            'amount' => 59.0,
            'billing_cycle' => 'MONTHLY',
            'next_due_date' => '2026-11-24',
        ]);
    }

    return $company;
}

function sbsActiveSubscription($mock, string $nextDueDate = '2026-11-24', string $id = 'sub_atual'): void
{
    $mock->shouldReceive('getSubscription')->with($id)->andReturn([
        'id' => $id, 'status' => 'ACTIVE', 'deleted' => false, 'value' => 59.0,
        'nextDueDate' => now()->parse($nextDueDate)->addMonth()->toDateString(), 'billingType' => 'CREDIT_CARD',
    ]);
    $mock->shouldReceive('getPendingSubscriptionPayments')->with($id)->andReturn([
        ['id' => 'pay_proxima', 'status' => 'PENDING', 'value' => 59.0, 'dueDate' => $nextDueDate],
    ]);
}

function sbsCard(): array
{
    return [
        new CreditCardDTO(holderName: 'FULANO', number: '4111111111111111', expiryMonth: '12', expiryYear: '2030', ccv: '123'),
        new CreditCardHolderDTO(name: 'Fulano', email: 'fulano@example.com', cpfCnpj: '12345678909', postalCode: '01310000', addressNumber: '100', mobilePhone: '11999990000', phone: '11999990000'),
    ];
}

function sbsActivate(Company $company, string $module): array
{
    [$card, $holder] = sbsCard();

    return app(SubscriptionBillingService::class)->activateModule($company, $module, $card, $holder);
}

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 24)->setTime(12, 25));
});

// ─── Ativação com assinatura ativa ──────────────────────────────────────────

test('caso de 24/10: PDV e Garçom ativados logo após o cadastro cobram só os módulos, sem recriar a assinatura', function () {
    $company = sbsCompany();
    $charged = [];

    $this->mock(AsaasServiceInterface::class, function ($mock) use (&$charged) {
        sbsActiveSubscription($mock);
        $mock->shouldReceive('updateSubscriptionValue')->once()->ordered()
            ->with('sub_atual', 158.0, 'Assinatura Plano VEDDI ESSENCIAL + Módulo PDV')
            ->andReturn(['id' => 'sub_atual', 'status' => 'ACTIVE', 'value' => 158.0]);
        $mock->shouldReceive('updateSubscriptionValue')->once()->ordered()
            ->with('sub_atual', 257.0, 'Assinatura Plano VEDDI ESSENCIAL + Módulo PDV + Módulo Garçom')
            ->andReturn(['id' => 'sub_atual', 'status' => 'ACTIVE', 'value' => 257.0]);
        $mock->shouldReceive('createCreditCardCharge')->twice()
            ->andReturnUsing(function ($customerId, $amount) use (&$charged) {
                $charged[] = $amount;

                return ['id' => 'pay_'.count($charged), 'status' => 'CONFIRMED'];
            });
        $mock->shouldNotReceive('createSubscription');
        $mock->shouldNotReceive('cancelSubscription');
    });

    sbsActivate($company, 'pdv');
    $result = sbsActivate($company, 'waiter');

    // Antes: 158 (plano + PDV) e 257 (plano + PDV + Garçom). Agora só o mês de cada módulo.
    expect($charged)->toBe([99.0, 99.0])
        ->and($result['monthly_total'])->toBe(257.0)
        ->and($result['next_due_date']->toDateString())->toBe('2026-11-24');

    $company->refresh();
    expect($company->pdv_module_enabled)->toBeTrue()
        ->and($company->waiter_module_enabled)->toBeTrue()
        ->and($company->asaas_subscription_id)->toBe('sub_atual');

    $subscription = Subscription::where('asaas_subscription_id', 'sub_atual')->first();
    expect((float) $subscription->amount)->toBe(257.0)
        ->and($subscription->next_due_date->toDateString())->toBe('2026-11-24');
});

test('no meio do ciclo cobra o proporcional do módulo até o próximo vencimento', function () {
    $this->travelTo(now()->setDate(2026, 11, 9)->setTime(9, 0));
    $company = sbsCompany();

    $this->mock(AsaasServiceInterface::class, function ($mock) {
        sbsActiveSubscription($mock);
        $mock->shouldReceive('updateSubscriptionValue')->once()->andReturn(['id' => 'sub_atual', 'status' => 'ACTIVE', 'value' => 158.0]);
        // 15 dos 31 dias do ciclo 24/10 → 24/11: 99 × 15/31 = 47,90.
        $mock->shouldReceive('createCreditCardCharge')->once()
            ->withArgs(fn ($customerId, $amount) => $amount === 47.90)
            ->andReturn(['id' => 'pay_pdv', 'status' => 'CONFIRMED']);
    });

    expect(sbsActivate($company, 'pdv')['charged'])->toBe(47.90);
});

test('proporcional abaixo do mínimo do Asaas não é cobrado: o módulo entra direto na próxima fatura', function () {
    $this->travelTo(now()->setDate(2026, 11, 23)->setTime(9, 0));
    $company = sbsCompany();

    $this->mock(AsaasServiceInterface::class, function ($mock) {
        sbsActiveSubscription($mock);
        $mock->shouldReceive('updateSubscriptionValue')->once()->with('sub_atual', 158.0, Mockery::any())
            ->andReturn(['id' => 'sub_atual', 'status' => 'ACTIVE', 'value' => 158.0]);
        // 1 dia de 31: 99/31 = 3,19 < 5,00.
        $mock->shouldNotReceive('createCreditCardCharge');
    });

    $result = sbsActivate($company, 'pdv');

    expect($result['charged'])->toBe(0.0)
        ->and($company->fresh()->pdv_module_enabled)->toBeTrue();
});

test('cartão recusado devolve o valor antigo à assinatura e não ativa o módulo', function () {
    $company = sbsCompany();

    $this->mock(AsaasServiceInterface::class, function ($mock) {
        sbsActiveSubscription($mock);
        $mock->shouldReceive('updateSubscriptionValue')->once()->ordered()->with('sub_atual', 158.0, Mockery::any())
            ->andReturn(['id' => 'sub_atual', 'status' => 'ACTIVE', 'value' => 158.0]);
        $mock->shouldReceive('createCreditCardCharge')->once()->ordered()->andReturn([
            'status' => 'DECLINED',
            'creditCard' => ['declineReason' => 'Transação não autorizada'],
        ]);
        $mock->shouldReceive('updateSubscriptionValue')->once()->ordered()
            ->with('sub_atual', 59.0, 'Assinatura Plano VEDDI ESSENCIAL')
            ->andReturn(['id' => 'sub_atual', 'status' => 'ACTIVE', 'value' => 59.0]);
    });

    expect(fn () => sbsActivate($company, 'pdv'))
        ->toThrow(CardPaymentDeclinedException::class, 'Transação não autorizada');

    expect($company->fresh()->pdv_module_enabled)->toBeFalse()
        ->and((float) Subscription::where('asaas_subscription_id', 'sub_atual')->value('amount'))->toBe(59.0);
});

test('erro de comunicação na cobrança também devolve o valor antigo à assinatura', function () {
    $company = sbsCompany();

    $this->mock(AsaasServiceInterface::class, function ($mock) {
        sbsActiveSubscription($mock);
        $mock->shouldReceive('updateSubscriptionValue')->once()->ordered()->with('sub_atual', 158.0, Mockery::any())->andReturn(['value' => 158.0]);
        $mock->shouldReceive('createCreditCardCharge')->once()->ordered()->andThrow(new RuntimeException('Asaas connection error: timeout'));
        $mock->shouldReceive('updateSubscriptionValue')->once()->ordered()->with('sub_atual', 59.0, Mockery::any())->andReturn(['value' => 59.0]);
    });

    expect(fn () => sbsActivate($company, 'pdv'))->toThrow(RuntimeException::class, 'timeout');
    expect($company->fresh()->pdv_module_enabled)->toBeFalse();
});

test('falha ao gravar depois da cobrança aprovada estorna a cobrança e devolve o valor da assinatura', function () {
    $company = sbsCompany();

    $this->mock(AsaasServiceInterface::class, function ($mock) {
        sbsActiveSubscription($mock);
        $mock->shouldReceive('updateSubscriptionValue')->once()->ordered()->with('sub_atual', 158.0, Mockery::any())->andReturn(['value' => 158.0]);
        $mock->shouldReceive('createCreditCardCharge')->once()->ordered()->andReturn(['id' => 'pay_pdv', 'status' => 'CONFIRMED']);
        $mock->shouldReceive('refundPayment')->once()->with('pay_pdv')->andReturn(['status' => 'REFUNDED']);
        $mock->shouldReceive('updateSubscriptionValue')->once()->with('sub_atual', 59.0, Mockery::any())->andReturn(['value' => 59.0]);
    });

    Company::updating(fn () => throw new RuntimeException('falha ao gravar'));

    expect(fn () => sbsActivate($company, 'pdv'))->toThrow(RuntimeException::class, 'falha ao gravar');
    expect($company->fresh()->pdv_module_enabled)->toBeFalse();
});

test('assinatura apagada no Asaas (estado atual da empresa 5) bloqueia a ativação antes de cobrar', function () {
    $company = sbsCompany(['asaas_subscription_id' => 'sub_1ng40jnk9rpx2z5n']);

    $this->mock(AsaasServiceInterface::class, function ($mock) {
        $mock->shouldReceive('getSubscription')->andReturn([
            'id' => 'sub_1ng40jnk9rpx2z5n', 'status' => 'INACTIVE', 'deleted' => true,
            'value' => 158.0, 'nextDueDate' => '2026-12-24', 'billingType' => 'CREDIT_CARD',
        ]);
        $mock->shouldNotReceive('updateSubscriptionValue');
        $mock->shouldNotReceive('createCreditCardCharge');
    });

    expect(fn () => sbsActivate($company, 'waiter'))->toThrow(SubscriptionBillingException::class);
});

test('Asaas fora do ar na consulta da assinatura não cobra nem altera nada', function () {
    $company = sbsCompany();

    $this->mock(AsaasServiceInterface::class, function ($mock) {
        $mock->shouldReceive('getSubscription')->andThrow(new RuntimeException('Asaas connection error'));
        $mock->shouldNotReceive('updateSubscriptionValue');
        $mock->shouldNotReceive('createCreditCardCharge');
    });

    expect(fn () => sbsActivate($company, 'pdv'))->toThrow(RuntimeException::class);
    expect($company->fresh()->pdv_module_enabled)->toBeFalse();
});

test('módulo já ativo não é cobrado de novo (clique duplo)', function () {
    $company = sbsCompany(['pdv_module_enabled' => true]);

    $this->mock(AsaasServiceInterface::class, function ($mock) {
        $mock->shouldNotReceive('getSubscription', 'updateSubscriptionValue', 'createCreditCardCharge');
    });

    expect(fn () => sbsActivate($company, 'pdv'))->toThrow(SubscriptionBillingException::class, 'Este módulo já está ativo.');
});

test('segunda cobrança simultânea para a mesma empresa é recusada', function () {
    $company = sbsCompany();

    $this->mock(AsaasServiceInterface::class, function ($mock) {
        $mock->shouldNotReceive('getSubscription', 'updateSubscriptionValue', 'createCreditCardCharge');
    });

    $lock = Cache::lock("subscription-billing:company:{$company->id}", 120);
    $lock->get();

    try {
        expect(fn () => sbsActivate($company, 'pdv'))->toThrow(SubscriptionBillingException::class);
    } finally {
        $lock->release();
    }
});

// ─── Ativação sem assinatura ────────────────────────────────────────────────

test('sem assinatura cria a assinatura antes e só então cobra o 1º mês', function () {
    $company = sbsCompany(['plan' => 'free', 'asaas_subscription_id' => null]);

    $this->mock(AsaasServiceInterface::class, function ($mock) {
        $mock->shouldReceive('createSubscription')->once()->ordered()
            ->withArgs(fn ($customerId, $plan, $billingType, $creditCard, $holderInfo, $nextDueDate, $extraAmount, $extraDescription) => $plan === Plan::Free
                && $extraAmount === 99.0
                && $nextDueDate === '2026-11-24')
            ->andReturn(['id' => 'sub_nova', 'status' => 'ACTIVE', 'value' => 99.0, 'nextDueDate' => '2026-11-24']);
        $mock->shouldReceive('createCreditCardCharge')->once()->ordered()
            ->withArgs(fn ($customerId, $amount) => $amount === 99.0)
            ->andReturn(['id' => 'pay_pdv', 'status' => 'CONFIRMED']);
    });

    $result = sbsActivate($company, 'pdv');

    $company->refresh();
    expect($result['subscription_id'])->toBe('sub_nova')
        ->and($company->pdv_module_enabled)->toBeTrue()
        ->and($company->asaas_subscription_id)->toBe('sub_nova')
        ->and(Subscription::where('asaas_subscription_id', 'sub_nova')->exists())->toBeTrue();
});

test('sem assinatura e cartão recusado na cobrança apaga a assinatura recém-criada', function () {
    $company = sbsCompany(['plan' => 'free', 'asaas_subscription_id' => null]);

    $this->mock(AsaasServiceInterface::class, function ($mock) {
        $mock->shouldReceive('createSubscription')->once()->andReturn(['id' => 'sub_nova', 'value' => 99.0, 'nextDueDate' => '2026-11-24']);
        $mock->shouldReceive('createCreditCardCharge')->once()->andReturn(['status' => 'DECLINED']);
        $mock->shouldReceive('cancelSubscription')->once()->with('sub_nova');
    });

    expect(fn () => sbsActivate($company, 'pdv'))->toThrow(CardPaymentDeclinedException::class);

    $company->refresh();
    expect($company->pdv_module_enabled)->toBeFalse()
        ->and($company->asaas_subscription_id)->toBeNull()
        ->and(Subscription::where('company_id', $company->id)->exists())->toBeFalse();
});

test('cartão recusado pelo Asaas ao criar a assinatura (400 invalid_creditCard) não chega a cobrar', function () {
    $company = sbsCompany(['plan' => 'free', 'asaas_subscription_id' => null]);

    $this->mock(AsaasServiceInterface::class, function ($mock) {
        $mock->shouldReceive('createSubscription')->once()->andThrow(new RuntimeException('Asaas subscription error: invalid_creditCard', 400));
        $mock->shouldNotReceive('createCreditCardCharge');
    });

    expect(fn () => sbsActivate($company, 'pdv'))->toThrow(CardPaymentDeclinedException::class);
});

// ─── Troca de plano ─────────────────────────────────────────────────────────

test('troca de plano mantém a troca se o cancelamento da assinatura antiga falhar', function () {
    $company = sbsCompany();
    [$card, $holder] = sbsCard();

    $this->mock(AsaasServiceInterface::class, function ($mock) {
        $mock->shouldReceive('createSubscription')->once()->andReturn(['id' => 'sub_pro', 'value' => 119.0, 'nextDueDate' => '2026-11-24']);
        $mock->shouldReceive('createCreditCardCharge')->once()->andReturn(['id' => 'pay_pro', 'status' => 'CONFIRMED']);
        $mock->shouldReceive('cancelSubscription')->once()->with('sub_atual')->andThrow(new RuntimeException('Asaas cancel error'));
    });

    app(SubscriptionBillingService::class)->changePlanByCard($company, Plan::Pro, $card, $holder);

    $company->refresh();
    expect($company->plan)->toBe(Plan::Pro)
        ->and($company->asaas_subscription_id)->toBe('sub_pro')
        // A antiga continua marcada como ativa: o alerta no Discord pede o cancelamento manual.
        ->and(Subscription::where('asaas_subscription_id', 'sub_atual')->value('status'))->toBe('active');
});

// ─── Tela ───────────────────────────────────────────────────────────────────

test('modal de ativação mostra o valor cobrado agora e a nova mensalidade', function () {
    $this->travelTo(now()->setDate(2026, 11, 9)->setTime(9, 0));
    $company = sbsCompany();
    app()->instance('current.company', $company);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    $this->mock(AsaasServiceInterface::class, function ($mock) {
        $mock->shouldReceive('getSubscriptionPayments')->andReturn([]);
        sbsActiveSubscription($mock);
    });

    Livewire::actingAs($admin)
        ->test(BillingSettings::class)
        ->call('confirmPdvActivation')
        ->assertSet('confirmingPdvActivation', true)
        ->assertSet('activationChargeNow', 47.90)
        ->assertSet('activationMonthlyTotal', 158.0)
        ->assertSet('activationNextDueDate', '24/11/2026')
        ->assertSeeText('Cobrado agora no cartão: R$ 47,90, proporcional aos dias até 24/11/2026.')
        ->assertSeeText('A partir de 24/11/2026, sua mensalidade passa a ser R$ 158,00/mês.')
        ->assertSee('Pagar R$ 47,90 e ativar')
        ->assertDontSee('Sua assinatura atual será recriada');
});

test('modal de ativação não abre quando a assinatura não está ativa no Asaas', function () {
    $company = sbsCompany();
    app()->instance('current.company', $company);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    $this->mock(AsaasServiceInterface::class, function ($mock) {
        $mock->shouldReceive('getSubscriptionPayments')->andReturn([]);
        $mock->shouldReceive('getSubscription')->andReturn([
            'id' => 'sub_atual', 'status' => 'INACTIVE', 'deleted' => true, 'value' => 0.0, 'nextDueDate' => null, 'billingType' => null,
        ]);
    });

    Livewire::actingAs($admin)
        ->test(BillingSettings::class)
        ->call('confirmWaiterActivation')
        ->assertSet('confirmingWaiterActivation', false);
});

// ─── Job de assinatura ──────────────────────────────────────────────────────

test('CreateAsaasSubscription não cria segunda assinatura se o checkout já criou uma', function () {
    $company = sbsCompany();

    $this->mock(AsaasServiceInterface::class, fn ($mock) => $mock->shouldNotReceive('createSubscription'));

    (new CreateAsaasSubscription($company))->handle(app(AsaasServiceInterface::class));

    expect(Subscription::where('company_id', $company->id)->count())->toBe(1);
});
