<?php

use App\Contracts\FiscalNoteProviderInterface;
use App\DTOs\FiscalAutoIssueVerdict;
use App\DTOs\FiscalNoteDTO;
use App\DTOs\FiscalNoteResult;
use App\Jobs\AutoIssueFiscalNote;
use App\Jobs\CancelFiscalNotesOfCancelledOrder;
use App\Jobs\IssueFiscalNote;
use App\Livewire\Admin\Fiscal\Config;
use App\Livewire\Admin\Fiscal\IssueControl;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyFiscalConfig;
use App\Models\CompanyNotification;
use App\Models\Customer;
use App\Models\FiscalNote;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\Fiscal\FiscalAutoIssueMonitor;
use App\Services\Fiscal\FiscalAutoIssuePolicy;
use App\Services\Fiscal\FocusNfeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/** Provider falso: guarda o que foi enviado e devolve o status pedido (emissão e cancelamento). */
function autoIssueFakeProvider(string $issueStatus = 'authorized', string $cancelStatus = 'cancelled', ?string $error = null): object
{
    $fake = new class($issueStatus, $cancelStatus, $error) implements FiscalNoteProviderInterface
    {
        /** @var array<int, FiscalNoteDTO> */
        public array $issued = [];

        public int $cancelled = 0;

        public function __construct(public string $issueStatus, public string $cancelStatus, public ?string $error) {}

        public function issue(FiscalNoteDTO $dto): FiscalNoteResult
        {
            $this->issued[] = $dto;

            return new FiscalNoteResult(status: $this->issueStatus, providerReference: $dto->reference, accessKey: str_repeat('4', 44), errorMessage: $this->error);
        }

        public function cancel(string $providerReference, string $justification): FiscalNoteResult
        {
            $this->cancelled++;

            return new FiscalNoteResult(status: $this->cancelStatus, providerReference: $providerReference, errorMessage: $this->cancelStatus === 'cancelled' ? null : 'Prazo de cancelamento superior ao previsto na legislação');
        }

        public function query(string $providerReference): FiscalNoteResult
        {
            return new FiscalNoteResult(status: 'authorized', providerReference: $providerReference);
        }

        public function createCompany(array $payload): array
        {
            return [];
        }

        public function updateCompany(string $focusCompanyId, array $payload): array
        {
            return [];
        }

        public function listWebhooks(): array
        {
            return [];
        }

        public function createWebhook(array $payload): array
        {
            return [];
        }

        public function deleteWebhook(string $webhookId): void {}
    };

    app()->instance(FiscalNoteProviderInterface::class, $fake);

    return $fake;
}

/**
 * Empresa com módulo fiscal, filial com NFC-e automática ligada há 1 hora e um pedido do chat
 * aceito (status 'preparing') com um item. $order sobrescreve os campos do pedido.
 */
function autoIssueContext(array $order = [], bool $autoIssue = true, array $config = [], array $item = []): array
{
    $company = Company::create([
        'name' => 'Empresa Auto NFC-e',
        'slug' => 'empresa-auto-nfce-'.uniqid(),
        'order_prefix' => 'ANF',
        'active' => true,
        'status' => 'ACTIVE',
        'owner_cpf_cnpj' => '12345678000100',
        'fiscal_notes_enabled' => true,
    ]);
    config(['fiscal.focus_nfe.token' => 'token_test']);
    app()->instance('current.company', $company);

    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial Auto',
        'address' => 'Rua A',
        'number' => '100',
        'neighborhood' => 'Centro',
        'city' => 'São Paulo',
        'state' => 'SP',
        'cep' => '01310000',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    $fiscalConfig = CompanyFiscalConfig::create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'is_default' => true,
        'enabled' => true,
        'auto_issue_enabled' => $autoIssue,
        'auto_issue_enabled_at' => $autoIssue ? now()->subHour() : null,
        ...$config,
    ]);

    $customer = Customer::withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => 'Cliente', 'phone' => '11999990001']);
    $category = ProductCategory::withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => 'Salgados']);
    $product = Product::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'product_category_id' => $category->id,
        'name' => 'Coxinha',
        'price' => 10.00,
        'active' => true,
    ]);

    $orderModel = DB::transaction(function () use ($company, $branch, $customer, $product, $order, $item) {
        $created = Order::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'subtotal' => 30.00,
            'delivery_fee' => 0,
            'total' => 30.00,
            'fee' => 0,
            'net_value' => 30.00,
            'status' => 'preparing',
            'payment_method' => 'PIX',
            'order_type' => 'delivery',
            'channel' => 'chat',
            ...$order,
        ]);

        OrderItem::create([
            'order_id' => $created->id,
            'product_id' => $product->id,
            'product_name' => 'Coxinha',
            'unit_price' => 10.00,
            'quantity' => 3,
            'subtotal' => 30.00,
            ...$item,
        ]);

        return $created;
    });

    return ['company' => $company, 'branch' => $branch, 'config' => $fiscalConfig, 'order' => $orderModel, 'customer' => $customer, 'product' => $product];
}

function runAutoIssue(int $orderId): void
{
    app()->call([new AutoIssueFiscalNote($orderId), 'handle']);
}

test('pedido do chat aceito e pago recebe NFC-e automática uma vez só', function () {
    Queue::fake();
    $provider = autoIssueFakeProvider();
    ['order' => $order] = autoIssueContext();

    Queue::assertPushed(AutoIssueFiscalNote::class, fn ($job) => $job->orderId === $order->id && $job->queue === 'low');

    runAutoIssue($order->id);
    runAutoIssue($order->id);

    $note = FiscalNote::withoutGlobalScopes()->where('order_id', $order->id)->sole();
    expect($note->status)->toBe('authorized')
        ->and($note->model)->toBe('65')
        ->and($note->data['automatic'])->toBeTrue()
        ->and($provider->issued)->toHaveCount(1)
        ->and($order->fresh()->fiscal_auto_attempted_at)->not->toBeNull();
});

test('chat pago esperando o aceite não emite; o aceite dispara a NFC-e', function () {
    Queue::fake();
    autoIssueFakeProvider();
    ['order' => $order] = autoIssueContext(['status' => 'paid']);

    Queue::assertNotPushed(AutoIssueFiscalNote::class);
    expect(app(FiscalAutoIssuePolicy::class)->evaluate($order->fresh())->action)->toBe(FiscalAutoIssueVerdict::WAIT);

    // "Preparando" no painel é o aceite do chat.
    $order->update(['status' => 'preparing']);

    Queue::assertPushed(AutoIssueFiscalNote::class, fn ($job) => $job->orderId === $order->id);
    runAutoIssue($order->id);
    expect(FiscalNote::withoutGlobalScopes()->where('order_id', $order->id)->where('status', 'authorized')->exists())->toBeTrue();
});

test('filial sem a chave, pedido anterior à ativação e marketplace nunca emitem sozinhos', function (array $orderAttributes, bool $autoIssue, array $config) {
    Queue::fake();
    $provider = autoIssueFakeProvider();
    ['order' => $order] = autoIssueContext($orderAttributes, $autoIssue, $config);

    Queue::assertNotPushed(AutoIssueFiscalNote::class);
    runAutoIssue($order->id);

    expect(FiscalNote::withoutGlobalScopes()->where('order_id', $order->id)->exists())->toBeFalse()
        ->and($provider->issued)->toBe([])
        ->and($order->fresh()->fiscal_auto_attempted_at)->toBeNull();
})->with([
    'chave desligada' => [[], false, []],
    'pedido anterior à ativação' => [[], true, ['auto_issue_enabled_at' => now()->addMinute()]],
    'iFood' => [['channel' => 'ifood', 'payment_method' => 'ifood'], true, []],
    'disjuntor aberto' => [[], true, ['auto_issue_paused_at' => now()]],
]);

test('NF-e, brinde sem valor e caixa que desmarcou ficam no Controle sem chamar a Focus', function (array $orderAttributes, array $item) {
    Queue::fake();
    $provider = autoIssueFakeProvider();
    ['order' => $order] = autoIssueContext($orderAttributes, item: $item);

    runAutoIssue($order->id);

    $fresh = $order->fresh();
    expect(FiscalNote::withoutGlobalScopes()->where('order_id', $order->id)->exists())->toBeFalse()
        ->and($provider->issued)->toBe([])
        ->and($fresh->fiscal_auto_attempted_at)->not->toBeNull()
        ->and($fresh->fiscal_error)->toBeNull();
})->with([
    'CNPJ vira NF-e' => [['fiscal_recipient' => ['document' => '11444777000161', 'name' => 'Padaria LTDA']], []],
    'CPF com NF-e solicitada' => [['fiscal_recipient' => ['document' => '52998224725'], 'fiscal_document_preference' => 'nfe'], []],
    'CPF em operação interestadual' => [['fiscal_recipient' => ['document' => '52998224725', 'state' => 'PR']], []],
    'brinde com valor zero' => [[], ['product_name' => 'Coxinha (Brinde)', 'unit_price' => 0, 'subtotal' => 0]],
    'caixa desmarcou' => [['order_type' => 'pdv', 'fiscal_auto_skip' => true], []],
]);

test('venda do PDV a receber só emite depois do pagamento, mesmo já entregue', function () {
    Queue::fake();
    autoIssueFakeProvider();
    ['order' => $order] = autoIssueContext(['order_type' => 'pdv', 'status' => 'awaiting_payment', 'payment_method' => 'cash']);

    $order->update(['status' => 'delivered']);
    Queue::assertNotPushed(AutoIssueFiscalNote::class);
    expect(app(FiscalAutoIssuePolicy::class)->evaluate($order->fresh())->action)->toBe(FiscalAutoIssueVerdict::WAIT);

    Payment::create(['order_id' => $order->id, 'payment_gateway' => 'cash', 'amount' => 30.00, 'status' => 'paid', 'paid_at' => now()]);

    Queue::assertPushed(AutoIssueFiscalNote::class, fn ($job) => $job->orderId === $order->id);
    runAutoIssue($order->id);
    expect(FiscalNote::withoutGlobalScopes()->where('order_id', $order->id)->sole()->status)->toBe('authorized');
});

test('pedido agendado espera a hora: emite quando entra em preparo', function () {
    Queue::fake();
    autoIssueFakeProvider();
    ['order' => $order] = autoIssueContext(['status' => 'scheduled', 'order_type' => 'pdv', 'scheduled_at' => now()->addHours(2)]);
    Payment::create(['order_id' => $order->id, 'payment_gateway' => 'cash', 'amount' => 30.00, 'status' => 'paid', 'paid_at' => now()]);

    Queue::assertNotPushed(AutoIssueFiscalNote::class);

    $order->update(['status' => 'preparing']);
    Queue::assertPushed(AutoIssueFiscalNote::class);
    runAutoIssue($order->id);
    expect(FiscalNote::withoutGlobalScopes()->where('order_id', $order->id)->exists())->toBeTrue();
});

test('pendência antes do envio grava o motivo e gera um resumo só no sino', function () {
    Queue::fake();
    $provider = autoIssueFakeProvider();
    ['order' => $first, 'company' => $company, 'branch' => $branch, 'customer' => $customer, 'product' => $product] = autoIssueContext();
    // Emitente sem CNPJ: pendência de emitente em todo pedido.
    $company->update(['owner_cpf_cnpj' => null]);

    runAutoIssue($first->id);

    $second = Order::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id,
        'subtotal' => 30, 'delivery_fee' => 0, 'total' => 30, 'fee' => 0, 'net_value' => 30,
        'status' => 'preparing', 'payment_method' => 'PIX', 'order_type' => 'delivery', 'channel' => 'chat',
    ]);
    OrderItem::create(['order_id' => $second->id, 'product_id' => $product->id, 'product_name' => 'Coxinha', 'unit_price' => 10, 'quantity' => 3, 'subtotal' => 30]);
    runAutoIssue($second->id);

    expect($provider->issued)->toBe([])
        ->and($first->fresh()->fiscal_error)->toContain('CNPJ')
        ->and($second->fresh()->fiscal_error)->toContain('CNPJ');

    $notification = CompanyNotification::where('company_id', $company->id)->where('type', FiscalAutoIssueMonitor::FAILED_TYPE)->sole();
    expect($notification->subtitle)->toStartWith('2 pedido(s)')
        ->and(CompanyNotification::where('company_id', $company->id)->where('type', 'fiscal_note_failed')->exists())->toBeFalse();
});

test('rejeições seguidas na mesma filial pausam a emissão automática e avisam', function () {
    Queue::fake();
    config(['fiscal.auto_issue.breaker_threshold' => 2]);
    autoIssueFakeProvider('rejected', error: 'Rejeição 781: Emissor não habilitado para emissão da NFC-e');
    ['order' => $first, 'company' => $company, 'branch' => $branch, 'config' => $config, 'customer' => $customer, 'product' => $product] = autoIssueContext();

    $makeOrder = function () use ($company, $branch, $customer, $product) {
        $order = Order::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'subtotal' => 30, 'delivery_fee' => 0, 'total' => 30, 'fee' => 0, 'net_value' => 30,
            'status' => 'preparing', 'payment_method' => 'PIX', 'order_type' => 'delivery', 'channel' => 'chat',
        ]);
        OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'product_name' => 'Coxinha', 'unit_price' => 10, 'quantity' => 3, 'subtotal' => 30]);

        return $order;
    };

    runAutoIssue($first->id);
    expect($config->fresh()->auto_issue_paused_at)->toBeNull();

    $second = $makeOrder();
    runAutoIssue($second->id);

    $config->refresh();
    expect($config->auto_issue_paused_at)->not->toBeNull()
        ->and($config->auto_issue_pause_reason)->toContain('781')
        ->and(CompanyNotification::where('company_id', $company->id)->where('type', FiscalAutoIssueMonitor::PAUSED_TYPE)->count())->toBe(1);

    // Pausada: o próximo pedido não vai para a Focus.
    $third = $makeOrder();
    runAutoIssue($third->id);
    expect(FiscalNote::withoutGlobalScopes()->where('order_id', $third->id)->exists())->toBeFalse()
        ->and($third->fresh()->fiscal_auto_attempted_at)->toBeNull();
});

test('depois de retomar, só rejeições novas contam para o disjuntor', function () {
    Queue::fake();
    config(['fiscal.auto_issue.breaker_threshold' => 2]);
    autoIssueFakeProvider('rejected', error: 'Rejeição 462: CSC não cadastrado');
    ['order' => $first, 'company' => $company, 'branch' => $branch, 'config' => $config, 'customer' => $customer, 'product' => $product] = autoIssueContext();
    $makeOrder = function () use ($company, $branch, $customer, $product) {
        $order = Order::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'subtotal' => 30, 'delivery_fee' => 0, 'total' => 30, 'fee' => 0, 'net_value' => 30,
            'status' => 'preparing', 'payment_method' => 'PIX', 'order_type' => 'delivery', 'channel' => 'chat',
        ]);
        OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'product_name' => 'Coxinha', 'unit_price' => 10, 'quantity' => 3, 'subtotal' => 30]);

        return $order;
    };

    runAutoIssue($first->id);
    runAutoIssue($makeOrder()->id);
    expect($config->fresh()->auto_issue_paused_at)->not->toBeNull();

    $this->travel(1)->minutes();
    $admin = User::factory()->create(['is_super_admin' => true]);
    Livewire::actingAs($admin)->test(Config::class)->set('branchId', $branch->id)->call('resumeAutoIssue');
    $this->travel(1)->minutes();

    // Uma rejeição depois da retomada não basta para pausar de novo.
    runAutoIssue($makeOrder()->id);
    expect($config->fresh()->auto_issue_paused_at)->toBeNull();

    runAutoIssue($makeOrder()->id);
    expect($config->fresh()->auto_issue_paused_at)->not->toBeNull();
});

test('pedido cancelado no meio da emissão não recebe nota', function () {
    Queue::fake();
    $provider = autoIssueFakeProvider();
    ['order' => $order] = autoIssueContext();
    $stale = Order::withoutGlobalScopes()->with(['company', 'items.product', 'branch'])->find($order->id);

    // Cancelado no banco depois da avaliação (UPDATE de outro processo).
    Order::withoutGlobalScopes()->whereKey($order->id)->update(['status' => 'cancelled']);

    expect(fn () => app(\App\Services\Fiscal\FiscalNoteService::class)->issue($stale, null, null, '65', automatic: true))
        ->toThrow(\RuntimeException::class, 'Pedido cancelado');
    expect(FiscalNote::withoutGlobalScopes()->where('order_id', $order->id)->exists())->toBeFalse()
        ->and($provider->issued)->toBe([]);
});

test('resumo de falhas não conta nota rejeitada emitida pelo caixa', function () {
    Queue::fake();
    autoIssueFakeProvider('rejected', error: 'Rejeição 539');
    ['order' => $pdvOrder, 'company' => $company, 'branch' => $branch, 'customer' => $customer, 'product' => $product] = autoIssueContext(['order_type' => 'pdv', 'fiscal_auto_skip' => true]);
    app()->call([new IssueFiscalNote($pdvOrder->id), 'handle']);

    $auto = Order::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id,
        'subtotal' => 30, 'delivery_fee' => 0, 'total' => 30, 'fee' => 0, 'net_value' => 30,
        'status' => 'preparing', 'payment_method' => 'PIX', 'order_type' => 'delivery', 'channel' => 'chat',
    ]);
    OrderItem::create(['order_id' => $auto->id, 'product_id' => $product->id, 'product_name' => 'Coxinha', 'unit_price' => 10, 'quantity' => 3, 'subtotal' => 30]);
    runAutoIssue($auto->id);

    $notification = CompanyNotification::where('company_id', $company->id)->where('type', FiscalAutoIssueMonitor::FAILED_TYPE)->sole();
    expect($notification->subtitle)->toStartWith('1 pedido(s)')
        ->and($notification->link)->toContain('dateFrom=');
});

test('notas manuais rejeitadas não contam para o disjuntor', function () {
    Queue::fake();
    config(['fiscal.auto_issue.breaker_threshold' => 1]);
    autoIssueFakeProvider('rejected', error: 'Rejeição 539');
    ['order' => $order, 'config' => $config] = autoIssueContext(['fiscal_auto_skip' => true]);

    app()->call([new IssueFiscalNote($order->id), 'handle']);

    expect(FiscalNote::withoutGlobalScopes()->where('order_id', $order->id)->sole()->status)->toBe('rejected')
        ->and($config->fresh()->auto_issue_paused_at)->toBeNull();
});

test('pedido cancelado tem a NFC-e autorizada cancelada junto', function () {
    Queue::fake();
    $provider = autoIssueFakeProvider();
    ['order' => $order] = autoIssueContext();
    runAutoIssue($order->id);

    $order->refresh()->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancellation_reason' => 'Cliente desistiu']);

    Queue::assertPushed(CancelFiscalNotesOfCancelledOrder::class, fn ($job) => $job->orderId === $order->id);
    app()->call([new CancelFiscalNotesOfCancelledOrder($order->id), 'handle']);

    $note = FiscalNote::withoutGlobalScopes()->where('order_id', $order->id)->sole();
    expect($note->status)->toBe('cancelled')
        ->and($provider->cancelled)->toBe(1)
        ->and($note->events()->where('type', 'cancellation')->sole()->data['justification'])->toBe(CancelFiscalNotesOfCancelledOrder::JUSTIFICATION);
});

test('cancelamento fora do prazo avisa e aparece no Controle como pedido cancelado com nota', function () {
    Queue::fake();
    autoIssueFakeProvider(cancelStatus: 'error');
    ['order' => $order, 'company' => $company] = autoIssueContext();
    runAutoIssue($order->id);

    $order->refresh()->update(['status' => 'cancelled', 'cancelled_at' => now()]);
    app()->call([new CancelFiscalNotesOfCancelledOrder($order->id), 'handle']);

    expect(FiscalNote::withoutGlobalScopes()->where('order_id', $order->id)->sole()->status)->toBe('authorized');
    $notification = CompanyNotification::where('company_id', $company->id)->where('type', 'fiscal_note_failed')->sole();
    expect($notification->title)->toContain('cancelado com nota fiscal ativa')
        ->and($notification->subtitle)->toContain('Prazo de cancelamento')
        ->and($notification->link)->toContain('search='.$order->order_number)
        ->and($notification->link)->toContain('dateFrom='.$order->created_at->toDateString());

    $admin = User::factory()->create(['is_super_admin' => true]);
    Livewire::actingAs($admin)->test(IssueControl::class)
        ->set('situationFilter', 'cancelled_order')
        ->assertSee($order->order_number)
        ->assertSee('Pedido cancelado')
        ->assertSee('Automática');
});

test('cancelar pedido sem nota não enfileira cancelamento fiscal', function () {
    Queue::fake();
    ['order' => $order] = autoIssueContext(['status' => 'paid', 'order_type' => 'pdv'], autoIssue: false);

    $order->update(['status' => 'cancelled']);

    Queue::assertNotPushed(CancelFiscalNotesOfCancelledOrder::class);
});

test('varredura pega pedido pronto que escapou do observer e respeita o isolamento por empresa', function () {
    Queue::fake();
    ['order' => $order] = autoIssueContext(['status' => 'awaiting_payment']);
    ['order' => $other] = autoIssueContext(['status' => 'awaiting_payment'], autoIssue: false);
    app()->forgetInstance('current.company');

    // Pedido do chat pago esperando aceite: a varredura nem carrega.
    Order::withoutGlobalScopes()->create([
        'company_id' => $order->company_id, 'branch_id' => $order->branch_id, 'customer_id' => $order->customer_id,
        'subtotal' => 30, 'delivery_fee' => 0, 'total' => 30, 'fee' => 0, 'net_value' => 30,
        'status' => 'paid', 'payment_method' => 'PIX', 'order_type' => 'delivery', 'channel' => 'chat',
    ]);

    // UPDATE direto no banco: nenhum observer roda.
    Order::withoutGlobalScopes()->whereKey([$order->id, $other->id])->update(['status' => 'preparing']);
    Queue::assertNotPushed(AutoIssueFiscalNote::class);

    $this->artisan('fiscal:auto-issue --dry-run')->expectsOutputToContain('Seriam enfileirados: 1')->assertSuccessful();
    Queue::assertNotPushed(AutoIssueFiscalNote::class);

    $this->artisan('fiscal:auto-issue')->assertSuccessful();
    Queue::assertPushed(AutoIssueFiscalNote::class, 1);
    Queue::assertPushed(AutoIssueFiscalNote::class, fn ($job) => $job->orderId === $order->id);
});

test('PDV com a automática ligada já marca a nota; desmarcar grava que o caixa não quer nota', function () {
    ['admin' => $admin, 'product' => $product, 'company' => $company, 'branch' => $branch] = pdvContext();
    $company->update(['fiscal_notes_enabled' => true]);
    CompanyFiscalConfig::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'is_default' => true, 'enabled' => true, 'auto_issue_enabled' => true, 'auto_issue_enabled_at' => now()->subHour()]);
    Bus::fake();
    $this->actingAs($admin);

    Livewire::test(\App\Livewire\Admin\Pdv\Terminal::class)
        ->assertSet('fiscalAutoIssueOn', true)
        ->assertSet('issueFiscalNote', true)
        ->call('addProduct', $product->id)->call('proceedToPayment')
        ->set('paymentMethod', 'cash')->set('cashReceivedInput', '10.00')
        ->set('issueFiscalNote', false)
        ->assertSee('desmarcado, esta venda fica sem nota')
        ->call('processOrder')
        ->assertHasNoErrors()
        // Próxima venda volta marcada.
        ->assertSet('issueFiscalNote', true);

    $order = Order::withoutGlobalScopes()->sole();
    expect($order->fiscal_auto_skip)->toBeTrue()->and($order->fiscal_auto_attempted_at)->toBeNull();
    Bus::assertNotDispatched(IssueFiscalNote::class);
    Bus::assertNotDispatched(AutoIssueFiscalNote::class);
});

test('PDV marcado emite pela tela e a automática não repete', function () {
    ['admin' => $admin, 'product' => $product, 'company' => $company, 'branch' => $branch] = pdvContext();
    $company->update(['fiscal_notes_enabled' => true]);
    CompanyFiscalConfig::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'is_default' => true, 'enabled' => true, 'auto_issue_enabled' => true, 'auto_issue_enabled_at' => now()->subHour()]);
    Bus::fake();
    $this->actingAs($admin);

    Livewire::test(\App\Livewire\Admin\Pdv\Terminal::class)
        ->call('addProduct', $product->id)->call('proceedToPayment')
        ->set('paymentMethod', 'cash')->set('cashReceivedInput', '10.00')
        ->call('processOrder')
        ->assertHasNoErrors();

    $order = Order::withoutGlobalScopes()->sole();
    expect($order->fiscal_auto_skip)->toBeTrue()->and($order->fiscal_auto_attempted_at)->toBeNull();
    Bus::assertDispatched(IssueFiscalNote::class, fn ($job) => $job->orderId === $order->id);
    Bus::assertNotDispatched(AutoIssueFiscalNote::class);
    expect(app(FiscalAutoIssuePolicy::class)->evaluate($order)->action)->toBe(FiscalAutoIssueVerdict::SKIP);
});

test('PDV com a automática desligada continua desmarcado e sem marcar o pedido', function () {
    ['admin' => $admin, 'product' => $product, 'company' => $company, 'branch' => $branch] = pdvContext();
    $company->update(['fiscal_notes_enabled' => true]);
    CompanyFiscalConfig::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'is_default' => true, 'enabled' => true, 'auto_issue_enabled' => false]);
    Bus::fake();
    $this->actingAs($admin);

    Livewire::test(\App\Livewire\Admin\Pdv\Terminal::class)
        ->assertSet('issueFiscalNote', false)
        ->call('addProduct', $product->id)->call('proceedToPayment')
        ->set('paymentMethod', 'cash')->set('cashReceivedInput', '10.00')
        ->call('processOrder')
        ->assertHasNoErrors();

    $order = Order::withoutGlobalScopes()->sole();
    expect($order->fiscal_auto_skip)->toBeFalse()->and($order->fiscal_auto_attempted_at)->toBeNull();
    Bus::assertNotDispatched(IssueFiscalNote::class);
    Bus::assertNotDispatched(AutoIssueFiscalNote::class);
});

test('comanda aberta não emite; fechar desmarcado grava a escolha e fechar marcado emite pela tela', function (bool $issueNow) {
    ['admin' => $admin, 'product' => $product, 'company' => $company, 'branch' => $branch] = pdvContext();
    $table = openTable($company, $branch);
    $company->update(['fiscal_notes_enabled' => true]);
    CompanyFiscalConfig::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'is_default' => true, 'enabled' => true, 'auto_issue_enabled' => true, 'auto_issue_enabled_at' => now()->subHour()]);
    $this->actingAs($admin);

    Bus::fake();
    $component = Livewire::test(\App\Livewire\Admin\Pdv\TabTerminal::class)
        ->assertSet('issueFiscalNote', true)
        ->set('selectedTableId', $table->id)
        ->call('addProduct', $product->id)
        ->assertHasNoErrors();
    $orderId = Order::withoutGlobalScopes()->sole()->id;
    Bus::assertNotDispatched(AutoIssueFiscalNote::class);

    $component->call('proceedToCloseTab', $orderId)
        ->set('paymentMethod', 'cash')->set('cashReceivedInput', '10.00')
        ->set('issueFiscalNote', $issueNow)
        ->call('confirmCloseTab')
        ->assertSet('step', 'catalog');

    $order = Order::withoutGlobalScopes()->find($orderId);
    expect($order->fiscal_auto_skip)->toBeTrue()->and($order->fiscal_auto_attempted_at)->toBeNull();
    $issueNow
        ? Bus::assertDispatched(IssueFiscalNote::class, fn ($job) => $job->orderId === $orderId)
        : Bus::assertNotDispatched(IssueFiscalNote::class);
    Bus::assertNotDispatched(AutoIssueFiscalNote::class);
})->with(['desmarcado' => false, 'marcado' => true]);

test('configuração: ligar marca o início, retomar fecha o disjuntor', function () {
    ['branch' => $branch, 'config' => $config] = autoIssueContext(autoIssue: false);
    $admin = User::factory()->create(['is_super_admin' => true]);

    $config->update(['enabled' => false]);
    Livewire::actingAs($admin)->test(Config::class)
        ->set('branchId', $branch->id)
        ->set('autoIssueEnabled', true)
        ->call('save')
        ->assertHasNoErrors();

    $config->refresh();
    expect($config->auto_issue_enabled)->toBeTrue()->and($config->auto_issue_enabled_at)->not->toBeNull();

    $config->update(['auto_issue_paused_at' => now(), 'auto_issue_pause_reason' => 'Rejeição 781']);
    Livewire::actingAs($admin)->test(Config::class)
        ->set('branchId', $branch->id)
        ->assertSee('Rejeição 781')
        ->call('resumeAutoIssue')
        ->assertSet('autoIssuePausedAt', null);

    expect($config->fresh()->auto_issue_paused_at)->toBeNull()->and($config->fresh()->autoIssueActive())->toBeFalse();
});

test('NFC-e manda uma forma por pagamento e descreve a forma 99', function () {
    Http::fake(['*/v2/nfce*' => Http::response(['status' => 'autorizado', 'chave_nfe' => str_repeat('1', 44)], 201)]);
    $dto = fn (array $overrides) => new FiscalNoteDTO(...[
        'emitenteCnpj' => '12345678000100', 'emitenteNome' => 'Empresa', 'emitenteLogradouro' => 'Rua A', 'emitenteNumero' => '1',
        'emitenteBairro' => 'Centro', 'emitenteMunicipio' => 'São Paulo', 'emitenteUf' => 'SP', 'emitenteCep' => '01310000',
        'emitenteCrt' => 1, 'emitenteInscricaoEstadual' => 'ISENTO', 'nfceSerie' => '1', 'orderId' => 1, 'total' => 30.00,
        'paymentMethod' => 'split', 'items' => [['name' => 'Coxinha', 'quantity' => 3, 'unit_price' => 10, 'subtotal' => 30]],
        ...$overrides,
    ]);

    (new FocusNfeService('tok', 'https://homologacao.focusnfe.com.br'))->issue($dto(['payments' => [['method' => 'cash', 'amount' => 10.0], ['method' => 'pix', 'amount' => 20.0]]]));
    Http::assertSent(fn ($request) => count($request['formas_pagamento']) === 2
        && $request['formas_pagamento'][0] === ['forma_pagamento' => '01', 'valor_pagamento' => 10.0]
        && $request['formas_pagamento'][1] === ['forma_pagamento' => '17', 'valor_pagamento' => 20.0]);

    (new FocusNfeService('tok', 'https://homologacao.focusnfe.com.br'))->issue($dto(['paymentMethod' => 'ifood', 'orderId' => 2]));
    Http::assertSent(fn ($request) => ($request['formas_pagamento'][0]['forma_pagamento'] ?? null) === '99'
        && ($request['formas_pagamento'][0]['descricao_pagamento'] ?? null) === 'Pago pelo iFood');

    // Soma diferente do total: fica a forma do pedido.
    (new FocusNfeService('tok', 'https://homologacao.focusnfe.com.br'))->issue($dto(['paymentMethod' => 'PIX', 'orderId' => 3, 'payments' => [['method' => 'cash', 'amount' => 50.0]]]));
    Http::assertSent(fn ($request) => $request['formas_pagamento'] === [['forma_pagamento' => '17', 'valor_pagamento' => 30.0]]);
});
