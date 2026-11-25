<?php

use App\Contracts\AsaasServiceInterface;
use App\Jobs\CreateAsaasSubscription;
use App\Jobs\ProcessAsaasWebhook;
use App\Models\Company;
use App\Services\Company\CompanyService;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * Empresa 5 em 25/11/2026: em atraso pela fatura de 24/11, com cobranças antigas no cartão
 * (taxa de 24/10, proporcionais de módulo de 27 e 28/10) sendo liquidadas pelo Asaas.
 */
function staleCompany(array $attributes = []): Company
{
    return Company::create(array_merge([
        'name' => 'Empresa Atraso',
        'slug' => 'empresa-atraso-'.uniqid(),
        'order_prefix' => 'ATR',
        'active' => true,
        'status' => 'OVERDUE',
        'overdue_since' => '2026-11-24',
        'plan' => 'essencial',
        'asaas_customer_id' => 'cus_atraso',
        'asaas_subscription_id' => 'sub_atraso',
        'asaas_setup_charge_id' => 'pay_setup_2410',
        'setup_fee_paid_at' => '2026-10-24 12:23:38',
        'subscription_payment_method' => 'CREDIT_CARD',
    ], $attributes));
}

function staleWebhook(string $event, array $payment, ?Job $job = null): void
{
    $webhook = new ProcessAsaasWebhook($event, ['payment' => array_merge(['customer' => 'cus_atraso'], $payment)]);

    if ($job) {
        $webhook->setJob($job);
    }

    $webhook->handle(app(CompanyService::class));
}

function staleOverdueInvoice(): array
{
    return ['id' => 'pay_nov', 'status' => 'OVERDUE', 'value' => 406.0, 'dueDate' => '2026-11-24', 'subscription' => 'sub_atraso'];
}

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 11, 25)->setTime(1, 0));
});

test('liquidação da taxa de ativação (PAYMENT_RECEIVED um mês depois) não libera a empresa nem regrava a data da taxa', function () {
    $company = staleCompany();

    $this->mock(AsaasServiceInterface::class, fn ($mock) => $mock->shouldReceive('getCustomerOverduePayments')
        ->with('cus_atraso')->once()->andReturn([staleOverdueInvoice()]));

    staleWebhook('PAYMENT_RECEIVED', ['id' => 'pay_setup_2410', 'subscription' => null]);

    $company->refresh();
    expect($company->status)->toBe('OVERDUE')
        ->and($company->overdue_since->toDateString())->toBe('2026-11-24')
        ->and($company->setup_fee_paid_at->toDateTimeString())->toBe('2026-10-24 12:23:38');
});

test('liquidação de proporcional de módulo (cobrança avulsa) não libera empresa com fatura vencida', function () {
    $company = staleCompany();

    $this->mock(AsaasServiceInterface::class, fn ($mock) => $mock->shouldReceive('getCustomerOverduePayments')
        ->once()->andReturn([staleOverdueInvoice()]));

    staleWebhook('PAYMENT_RECEIVED', ['id' => 'pay_garcom_2710', 'subscription' => null, 'externalReference' => 'waiter_module_activation_5']);

    expect($company->fresh()->status)->toBe('OVERDUE');
});

test('liquidação de fatura antiga da assinatura não libera empresa bloqueada pela fatura seguinte', function () {
    $company = staleCompany(['status' => 'BLOCKED', 'active' => false]);

    $this->mock(AsaasServiceInterface::class, fn ($mock) => $mock->shouldReceive('getCustomerOverduePayments')
        ->once()->andReturn([staleOverdueInvoice()]));

    staleWebhook('PAYMENT_RECEIVED', ['id' => 'pay_out', 'subscription' => 'sub_atraso']);

    $company->refresh();
    expect($company->status)->toBe('BLOCKED')
        ->and($company->active)->toBeFalse();
});

test('pagamento da fatura vencida libera a empresa e limpa o atraso', function () {
    $company = staleCompany(['status' => 'BLOCKED', 'active' => false]);

    // O Asaas ainda pode listar como vencida a própria fatura que acabou de ser paga.
    $this->mock(AsaasServiceInterface::class, fn ($mock) => $mock->shouldReceive('getCustomerOverduePayments')
        ->once()->andReturn([staleOverdueInvoice()]));

    staleWebhook('PAYMENT_CONFIRMED', ['id' => 'pay_nov', 'subscription' => 'sub_atraso']);

    $company->refresh();
    expect($company->status)->toBe('ACTIVE')
        ->and($company->active)->toBeTrue()
        ->and($company->overdue_since)->toBeNull();
});

test('cobrança avulsa antiga esquecida no Asaas não prende quem pagou a mensalidade', function () {
    $company = staleCompany();

    $this->mock(AsaasServiceInterface::class, fn ($mock) => $mock->shouldReceive('getCustomerOverduePayments')->once()->andReturn([
        ['id' => 'pay_pix_cadastro', 'status' => 'OVERDUE', 'value' => 158.0, 'dueDate' => '2026-10-25', 'subscription' => null],
        ['id' => 'pay_sub_antiga', 'status' => 'OVERDUE', 'value' => 59.0, 'dueDate' => '2026-10-24', 'subscription' => 'sub_apagada'],
    ]));

    staleWebhook('PAYMENT_CONFIRMED', ['id' => 'pay_nov', 'subscription' => 'sub_atraso']);

    expect($company->fresh()->status)->toBe('ACTIVE');
});

test('Asaas fora do ar na conferência: tenta de novo antes da última tentativa', function () {
    $company = staleCompany();

    $this->mock(AsaasServiceInterface::class, fn ($mock) => $mock->shouldReceive('getCustomerOverduePayments')
        ->andThrow(new RuntimeException('Asaas connection error')));

    $job = Mockery::mock(Job::class);
    $job->shouldReceive('attempts')->andReturn(1);

    expect(fn () => staleWebhook('PAYMENT_CONFIRMED', ['id' => 'pay_nov', 'subscription' => 'sub_atraso'], $job))
        ->toThrow(RuntimeException::class);

    expect($company->fresh()->status)->toBe('OVERDUE');
});

test('Asaas fora do ar na última tentativa: libera como antes para não bloquear quem pagou', function () {
    $company = staleCompany();

    $this->mock(AsaasServiceInterface::class, fn ($mock) => $mock->shouldReceive('getCustomerOverduePayments')
        ->andThrow(new RuntimeException('Asaas connection error')));

    $job = Mockery::mock(Job::class);
    $job->shouldReceive('attempts')->andReturn(3);

    staleWebhook('PAYMENT_CONFIRMED', ['id' => 'pay_nov', 'subscription' => 'sub_atraso'], $job);

    expect($company->fresh()->status)->toBe('ACTIVE');
});

test('empresa ativa: pagamento confirmado segue ativando sem consultar o Asaas', function () {
    $company = staleCompany(['status' => 'PENDING_PAYMENT', 'active' => false, 'overdue_since' => null]);

    $this->mock(AsaasServiceInterface::class, fn ($mock) => $mock->shouldNotReceive('getCustomerOverduePayments'));

    staleWebhook('PAYMENT_CONFIRMED', ['id' => 'pay_dez', 'subscription' => 'sub_atraso']);

    expect($company->fresh()->status)->toBe('ACTIVE');
});

test('upgrade do gratuito por PIX: a taxa paga regrava a data da taxa zero do cadastro e aplica o plano', function () {
    Queue::fake();

    $company = staleCompany([
        'status' => 'ACTIVE',
        'overdue_since' => null,
        'plan' => 'free',
        'pending_plan' => 'essencial',
        'asaas_subscription_id' => null,
        'asaas_setup_charge_id' => 'pay_upgrade',
        'setup_fee_paid_at' => '2026-09-01 10:00:00',
        'subscription_payment_method' => 'PIX',
    ]);

    $this->mock(AsaasServiceInterface::class, fn ($mock) => $mock->shouldNotReceive('getCustomerOverduePayments'));

    staleWebhook('PAYMENT_RECEIVED', ['id' => 'pay_upgrade', 'subscription' => null]);

    $company->refresh();
    expect($company->plan->value)->toBe('essencial')
        ->and($company->pending_plan)->toBeNull()
        ->and($company->setup_fee_paid_at->toDateString())->toBe('2026-11-25');

    Queue::assertPushed(CreateAsaasSubscription::class);
});

// ─── PAYMENT_OVERDUE ────────────────────────────────────────────────────────

test('nova fatura vencida não tira empresa do bloqueio', function () {
    $company = staleCompany(['status' => 'BLOCKED', 'active' => false]);

    staleWebhook('PAYMENT_OVERDUE', ['id' => 'pay_dez', 'subscription' => 'sub_atraso', 'dueDate' => '2026-12-24']);

    $company->refresh();
    expect($company->status)->toBe('BLOCKED')
        ->and($company->overdue_since->toDateString())->toBe('2026-11-24');
});

test('segunda fatura vencida não reinicia a carência da primeira', function () {
    $company = staleCompany();

    staleWebhook('PAYMENT_OVERDUE', ['id' => 'pay_dez', 'subscription' => 'sub_atraso', 'dueDate' => '2026-12-24']);

    $company->refresh();
    expect($company->status)->toBe('OVERDUE')
        ->and($company->overdue_since->toDateString())->toBe('2026-11-24');
});
