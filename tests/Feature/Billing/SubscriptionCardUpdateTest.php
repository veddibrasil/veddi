<?php

use App\Contracts\AsaasServiceInterface;
use App\DTOs\CreditCardDTO;
use App\DTOs\CreditCardHolderDTO;
use App\Exceptions\CardPaymentDeclinedException;
use App\Exceptions\SubscriptionBillingException;
use App\Livewire\Admin\Settings\BillingSettings;
use App\Models\Company;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Company\SubscriptionBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Estado da MISTER COXINHA APUCARANA em 25/11/2026: assinatura de R$ 406 criada no painel do
 * Asaas sem cartão, fatura de 24/11 vencida e empresa em atraso.
 */
function cucCompany(array $attributes = []): Company
{
    $company = Company::create(array_merge([
        'name' => 'Empresa Cartão',
        'slug' => 'empresa-cartao-'.uniqid(),
        'order_prefix' => 'CAR',
        'active' => true,
        'status' => 'OVERDUE',
        'overdue_since' => '2026-11-24',
        'plan' => 'essencial',
        'asaas_customer_id' => 'cus_cartao',
        'asaas_subscription_id' => 'sub_cartao',
        'subscription_payment_method' => 'CREDIT_CARD',
        'pdv_module_enabled' => true,
        'fiscal_notes_enabled' => true,
        'waiter_module_enabled' => true,
    ], $attributes));

    Subscription::create([
        'company_id' => $company->id,
        'asaas_subscription_id' => 'sub_cartao',
        'plan' => 'essencial',
        'status' => 'overdue',
        'amount' => 406.0,
        'billing_cycle' => 'MONTHLY',
        'next_due_date' => '2026-11-24',
    ]);

    return $company;
}

function cucCard(): array
{
    return [
        new CreditCardDTO(holderName: 'FULANO', number: '4111111111111111', expiryMonth: '12', expiryYear: '2030', ccv: '123'),
        new CreditCardHolderDTO(name: 'Fulano', email: 'fulano@example.com', cpfCnpj: '12345678909', postalCode: '01310000', addressNumber: '100', mobilePhone: '11999990000', phone: '11999990000'),
    ];
}

function cucSubscription($mock, string $billingType = 'CREDIT_CARD'): void
{
    $mock->shouldReceive('getSubscription')->with('sub_cartao')->andReturn([
        'id' => 'sub_cartao', 'status' => 'ACTIVE', 'deleted' => false, 'value' => 406.0,
        'nextDueDate' => '2027-01-24', 'billingType' => $billingType,
    ]);
}

function cucOverdueInvoice(string $id = 'pay_nov', float $value = 406.0, string $dueDate = '2026-11-24', ?string $subscription = 'sub_cartao'): array
{
    return ['id' => $id, 'status' => 'OVERDUE', 'value' => $value, 'dueDate' => $dueDate, 'subscription' => $subscription];
}

function cucUpdateCard(Company $company): array
{
    [$card, $holder] = cucCard();

    return app(SubscriptionBillingService::class)->updateCard($company, $card, $holder, '200.100.50.25');
}

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 11, 25)->setTime(10, 0));
});

// ─── Serviço ────────────────────────────────────────────────────────────────

test('caso de 25/11: paga a fatura vencida com o cartão novo, põe o cartão na assinatura e libera a empresa', function () {
    $company = cucCompany();

    $this->mock(AsaasServiceInterface::class, function ($mock) {
        cucSubscription($mock);
        // 1ª consulta: o que cobrar. 2ª: conferência antes de liberar — o Asaas ainda pode
        // listar a fatura recém-paga como vencida, e ela não pode segurar o bloqueio.
        $mock->shouldReceive('getCustomerOverduePayments')->with('cus_cartao')->twice()
            ->andReturn([cucOverdueInvoice()], [cucOverdueInvoice()]);
        $mock->shouldReceive('payWithCreditCard')->once()->ordered()
            ->withArgs(fn ($paymentId) => $paymentId === 'pay_nov')
            ->andReturn(['id' => 'pay_nov', 'status' => 'CONFIRMED']);
        $mock->shouldReceive('updateSubscriptionCreditCard')->once()->ordered()
            ->withArgs(fn ($subscriptionId, $card, $holder, $remoteIp) => $subscriptionId === 'sub_cartao' && $remoteIp === '200.100.50.25')
            ->andReturn(['id' => 'sub_cartao']);
        $mock->shouldNotReceive('createCreditCardCharge');
        $mock->shouldNotReceive('createSubscription');
    });

    $result = cucUpdateCard($company);

    expect($result)->toBe(['paid_count' => 1, 'paid_total' => 406.0, 'card_updated' => true, 'reactivated' => true]);

    $company->refresh();
    expect($company->status)->toBe('ACTIVE')
        ->and($company->active)->toBeTrue()
        ->and($company->overdue_since)->toBeNull();
});

test('cartão recusado na fatura vencida não troca o cartão nem libera a empresa', function () {
    $company = cucCompany();

    $this->mock(AsaasServiceInterface::class, function ($mock) {
        cucSubscription($mock);
        $mock->shouldReceive('getCustomerOverduePayments')->andReturn([cucOverdueInvoice()]);
        $mock->shouldReceive('payWithCreditCard')->once()->andThrow(new CardPaymentDeclinedException('Transação não autorizada'));
        $mock->shouldNotReceive('updateSubscriptionCreditCard');
    });

    expect(fn () => cucUpdateCard($company))->toThrow(CardPaymentDeclinedException::class);

    expect($company->fresh()->status)->toBe('OVERDUE');
});

test('cobrança aprovada com status diferente de confirmado conta como recusa', function () {
    $company = cucCompany();

    $this->mock(AsaasServiceInterface::class, function ($mock) {
        cucSubscription($mock);
        $mock->shouldReceive('getCustomerOverduePayments')->andReturn([cucOverdueInvoice()]);
        $mock->shouldReceive('payWithCreditCard')->once()
            ->andReturn(['id' => 'pay_nov', 'status' => 'OVERDUE', 'creditCard' => ['declineReason' => 'Saldo insuficiente']]);
        $mock->shouldNotReceive('updateSubscriptionCreditCard');
    });

    expect(fn () => cucUpdateCard($company))->toThrow(CardPaymentDeclinedException::class, 'Saldo insuficiente');
});

test('segunda fatura recusada depois da primeira paga avisa o que ficou pago e não troca o cartão', function () {
    $company = cucCompany();

    $this->mock(AsaasServiceInterface::class, function ($mock) {
        cucSubscription($mock);
        $mock->shouldReceive('getCustomerOverduePayments')->andReturn([
            cucOverdueInvoice('pay_dez', 406.0, '2026-12-24'),
            cucOverdueInvoice('pay_nov', 406.0, '2026-11-24'),
        ]);
        $mock->shouldReceive('payWithCreditCard')->once()->ordered()
            ->withArgs(fn ($paymentId) => $paymentId === 'pay_nov')
            ->andReturn(['id' => 'pay_nov', 'status' => 'CONFIRMED']);
        $mock->shouldReceive('payWithCreditCard')->once()->ordered()
            ->withArgs(fn ($paymentId) => $paymentId === 'pay_dez')
            ->andThrow(new CardPaymentDeclinedException('Limite excedido'));
        $mock->shouldNotReceive('updateSubscriptionCreditCard');
    });

    expect(fn () => cucUpdateCard($company))->toThrow(
        SubscriptionBillingException::class,
        'Pagamos R$ 406,00 em faturas vencidas, mas a fatura de 24/12/2026 foi recusada: Limite excedido.',
    );
});

test('erro de comunicação ao pagar não é retentado e pede para conferir antes de tentar de novo', function () {
    $company = cucCompany();

    $this->mock(AsaasServiceInterface::class, function ($mock) {
        cucSubscription($mock);
        $mock->shouldReceive('getCustomerOverduePayments')->andReturn([cucOverdueInvoice()]);
        $mock->shouldReceive('payWithCreditCard')->once()->andThrow(new RuntimeException('Asaas connection error: timeout'));
        $mock->shouldNotReceive('updateSubscriptionCreditCard');
    });

    expect(fn () => cucUpdateCard($company))->toThrow(SubscriptionBillingException::class, 'Aguarde alguns minutos e recarregue a página');
});

test('sem fatura vencida só troca o cartão, sem cobrar nada', function () {
    $company = cucCompany(['status' => 'ACTIVE', 'overdue_since' => null]);

    $this->mock(AsaasServiceInterface::class, function ($mock) {
        cucSubscription($mock);
        // Vencida avulsa (outra assinatura/cobrança) não é paga pela troca de cartão.
        $mock->shouldReceive('getCustomerOverduePayments')->once()->andReturn([cucOverdueInvoice('pay_avulsa', 89.42, '2026-10-27', null)]);
        $mock->shouldNotReceive('payWithCreditCard');
        $mock->shouldReceive('updateSubscriptionCreditCard')->once()->andReturn(['id' => 'sub_cartao']);
    });

    expect(cucUpdateCard($company))->toBe(['paid_count' => 0, 'paid_total' => 0.0, 'card_updated' => true, 'reactivated' => false]);
});

test('fatura paga e falha ao pôr o cartão na assinatura: o pagamento vale e a empresa é liberada', function () {
    $company = cucCompany();

    $this->mock(AsaasServiceInterface::class, function ($mock) {
        cucSubscription($mock);
        $mock->shouldReceive('getCustomerOverduePayments')->andReturn([cucOverdueInvoice()], []);
        $mock->shouldReceive('payWithCreditCard')->once()->andReturn(['id' => 'pay_nov', 'status' => 'CONFIRMED']);
        $mock->shouldReceive('updateSubscriptionCreditCard')->once()->andThrow(new RuntimeException('Asaas 500'));
        $mock->shouldNotReceive('refundPayment');
    });

    $result = cucUpdateCard($company);

    expect($result['card_updated'])->toBeFalse()
        ->and($result['reactivated'])->toBeTrue()
        ->and($company->fresh()->status)->toBe('ACTIVE');
});

test('fatura da assinatura que vence no meio da troca mantém a empresa em atraso', function () {
    $company = cucCompany();

    $this->mock(AsaasServiceInterface::class, function ($mock) {
        cucSubscription($mock);
        $mock->shouldReceive('getCustomerOverduePayments')->twice()->andReturn(
            [cucOverdueInvoice()],
            [cucOverdueInvoice(), cucOverdueInvoice('pay_dez', 406.0, '2026-12-24')],
        );
        $mock->shouldReceive('payWithCreditCard')->once()->andReturn(['id' => 'pay_nov', 'status' => 'CONFIRMED']);
        $mock->shouldReceive('updateSubscriptionCreditCard')->once()->andReturn(['id' => 'sub_cartao']);
    });

    expect(cucUpdateCard($company)['reactivated'])->toBeFalse()
        ->and($company->fresh()->status)->toBe('OVERDUE');
});

test('assinatura que não é no cartão não aceita troca de cartão', function () {
    $company = cucCompany(['subscription_payment_method' => 'PIX']);

    $this->mock(AsaasServiceInterface::class, function ($mock) {
        cucSubscription($mock, 'PIX');
        $mock->shouldNotReceive('getCustomerOverduePayments');
        $mock->shouldNotReceive('payWithCreditCard');
        $mock->shouldNotReceive('updateSubscriptionCreditCard');
    });

    expect(fn () => cucUpdateCard($company))->toThrow(SubscriptionBillingException::class, 'não é cobrada no cartão');
});

// ─── Tela ───────────────────────────────────────────────────────────────────

test('tela: empresa em atraso vê o aviso, abre o modal com o valor vencido e paga', function () {
    $company = cucCompany();
    app()->instance('current.company', $company);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    $this->mock(AsaasServiceInterface::class, function ($mock) {
        $mock->shouldReceive('getSubscriptionPayments')->andReturn([]);
        cucSubscription($mock);
        $mock->shouldReceive('getCustomerOverduePayments')->andReturn([cucOverdueInvoice()], [cucOverdueInvoice()], []);
        $mock->shouldReceive('payWithCreditCard')->once()->andReturn(['id' => 'pay_nov', 'status' => 'CONFIRMED']);
        $mock->shouldReceive('updateSubscriptionCreditCard')->once()->andReturn(['id' => 'sub_cartao']);
    });

    Livewire::actingAs($admin)
        ->test(BillingSettings::class)
        ->assertSee('Em atraso')
        ->assertSee('Atualizar cartão e pagar')
        ->call('openCardUpdate')
        ->assertDispatched('open-card-update-modal')
        ->assertSet('cardUpdateOverdueTotal', 406.0)
        ->assertSet('cardUpdateOverdueCount', 1)
        ->assertSee('Pagar R$ 406,00 e salvar cartão')
        ->set('cardNumber', '4111 1111 1111 1111')
        ->set('cardExpiry', '12/30')
        ->set('cardCvv', '123')
        ->set('cardHolderName', 'FULANO DA SILVA')
        ->set('cardCpfCnpj', '123.456.789-09')
        ->set('cardPostalCode', '01310-000')
        ->set('cardAddressNumber', '100')
        ->call('updateSubscriptionCard')
        ->assertSet('cardUpdateSuccess', true)
        ->assertSet('cardUpdateError', null)
        ->assertSet('status', 'ACTIVE')
        ->assertSet('cardNumber', '')
        ->assertSet('cardCvv', '')
        ->assertSee('Pagamento de R$ 406,00 confirmado e cartão salvo');

    expect($company->fresh()->status)->toBe('ACTIVE');
});

test('tela: cartão recusado mostra o motivo e limpa os dados do cartão', function () {
    $company = cucCompany();
    app()->instance('current.company', $company);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    $this->mock(AsaasServiceInterface::class, function ($mock) {
        $mock->shouldReceive('getSubscriptionPayments')->andReturn([]);
        cucSubscription($mock);
        $mock->shouldReceive('getCustomerOverduePayments')->andReturn([cucOverdueInvoice()]);
        $mock->shouldReceive('payWithCreditCard')->once()->andThrow(new CardPaymentDeclinedException('Transação não autorizada'));
    });

    Livewire::actingAs($admin)
        ->test(BillingSettings::class)
        ->call('openCardUpdate')
        ->set('cardNumber', '4111 1111 1111 1111')
        ->set('cardExpiry', '12/30')
        ->set('cardCvv', '123')
        ->set('cardHolderName', 'FULANO DA SILVA')
        ->set('cardCpfCnpj', '123.456.789-09')
        ->set('cardPostalCode', '01310-000')
        ->set('cardAddressNumber', '100')
        ->call('updateSubscriptionCard')
        ->assertSet('cardUpdateSuccess', false)
        ->assertSet('cardUpdateError', 'Cartão recusado: Transação não autorizada. Nada foi cobrado e o cartão da assinatura continua o mesmo.')
        ->assertSet('cardNumber', '')
        ->assertSet('cardCvv', '');

    expect($company->fresh()->status)->toBe('OVERDUE');
});

test('tela: valor vencido exibido não pode ser alterado pelo navegador', function () {
    $company = cucCompany();
    app()->instance('current.company', $company);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    $this->mock(AsaasServiceInterface::class, fn ($mock) => $mock->shouldReceive('getSubscriptionPayments')->andReturn([]));

    expect(fn () => Livewire::actingAs($admin)->test(BillingSettings::class)->set('cardUpdateOverdueTotal', 1.0))
        ->toThrow(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
});
