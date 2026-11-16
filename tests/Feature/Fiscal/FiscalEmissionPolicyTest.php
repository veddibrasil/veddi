<?php

use App\Exceptions\FiscalNoteAlreadyIssuedException;
use App\Jobs\IssueFiscalNote;
use App\Livewire\Admin\Fiscal\IssueControl;
use App\Livewire\Admin\Orders\Show;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyFiscalConfig;
use App\Models\FiscalNote;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\Fiscal\FiscalEmissionPolicy;
use App\Services\Fiscal\FiscalNoteService;
use App\Services\Fiscal\FiscalRecipient;
use App\Support\Fiscal\AccessKey;
use App\Support\Fiscal\FiscalCorrectionLinks;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

const POLICY_ALNUM_CNPJ = '12ABC34501DE35';

function policyRecipient(array $overrides = []): array
{
    return [
        'document' => '11222333000181', 'name' => 'Comprador Ltda', 'email' => '', 'street' => 'Rua B', 'number' => '2',
        'complement' => '', 'neighborhood' => 'Centro', 'city' => 'Curitiba', 'state' => 'PR', 'zip' => '80010000',
        'ie_indicator' => '9', 'ie' => '', 'ie_confirmed' => true, ...$overrides,
    ];
}

/** Filial no PR, venda de balcão (presencial) de R$ 30 com produto de cadastro fiscal completo. */
function policyContext(bool $nfceCnpj = true, array $order = [], array $recipient = []): array
{
    $company = Company::create(['name' => 'Emitente PR', 'slug' => 'policy-'.uniqid(), 'order_prefix' => 'POL', 'active' => true, 'status' => 'ACTIVE', 'fiscal_notes_enabled' => true, 'owner_cpf_cnpj' => '11444777000161']);
    app()->instance('current.company', $company);
    config(['fiscal.focus_nfe.token' => 'policy-token']);
    $branch = Branch::create(['company_id' => $company->id, 'name' => 'Arapongas', 'cnpj' => '11444777000161', 'address' => 'Rua A', 'number' => '1', 'neighborhood' => 'Centro', 'city' => 'Arapongas', 'state' => 'PR', 'cep' => '86700000', 'active' => true, 'opens_at' => '00:00:00', 'closes_at' => '23:59:59']);
    $config = CompanyFiscalConfig::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'enabled' => true, 'is_default' => true, 'nfe_serie' => 3, 'nfce_serie' => 1, 'crt' => 1, 'nfce_cnpj_enabled' => $nfceCnpj]);
    $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'Salgados']);
    $product = Product::create(['company_id' => $company->id, 'product_category_id' => $category->id, 'name' => 'Coxinha', 'price' => 10, 'active' => true,
        'fiscal_data' => ['ncm' => '19059090', 'cfop' => '5102', 'interstate_cfop' => '6102', 'icms_situation' => '102', 'origin' => '0', 'pis_situation' => '49', 'cofins_situation' => '49']]);
    $customer = \App\Models\Customer::create(['company_id' => $company->id, 'name' => 'Contato', 'phone' => '43999990000']);
    $orderModel = Order::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'branch_id' => $branch->id, 'subtotal' => 30, 'total' => 30, 'delivery_fee' => 0, 'fee' => 0, 'net_value' => 30,
        'status' => 'paid', 'payment_method' => 'cash', 'order_type' => 'pdv', 'delivery_type' => 'balcao', 'fiscal_recipient' => policyRecipient($recipient), ...$order]);
    OrderItem::create(['order_id' => $orderModel->id, 'product_id' => $product->id, 'product_name' => 'Coxinha', 'unit_price' => 10, 'quantity' => 3, 'subtotal' => 30]);
    Http::preventStrayRequests();

    return ['company' => $company, 'branch' => $branch, 'config' => $config, 'order' => $orderModel, 'product' => $product];
}

function policyIssuer(Company $company): User
{
    $user = User::factory()->create();
    $user->companies()->attach($company->id, ['role' => 'company_admin']);
    $permission = \App\Models\Permission::firstOrCreate(['name' => 'fiscal.issue'], ['group' => 'fiscal', 'label' => 'Emitir notas']);
    \App\Models\UserPermission::create(['user_id' => $user->id, 'company_id' => $company->id, 'permission_id' => $permission->id, 'granted' => true]);

    return $user;
}

function policyDecide(array $recipient, array $context = []): \App\DTOs\FiscalEmissionDecision
{
    $config = new CompanyFiscalConfig(['nfce_cnpj_enabled' => $context['enabled'] ?? true]);

    return app(FiscalEmissionPolicy::class)->decide(
        $config, 'PR', $recipient, $context['total'] ?? 30.0, $context['presential'] ?? true, $context['operationState'] ?? null, $context['preference'] ?? null,
    );
}

// ── Documento ─────────────────────────────────────────────────────────────

test('documento preserva letras e zeros iniciais e reconhece o tipo pelo formato', function (string $input, ?string $type, bool $valid, string $normalized) {
    expect(FiscalRecipient::normalizeDocument($input))->toBe($normalized)
        ->and(FiscalRecipient::documentType($input))->toBe($type)
        ->and(FiscalRecipient::isValidDocument($input))->toBe($valid);
})->with([
    'CPF com máscara' => ['529.982.247-25', 'cpf', true, '52998224725'],
    'CPF com dígito errado' => ['529.982.247-26', 'cpf', false, '52998224726'],
    'CNPJ numérico' => ['11.222.333/0001-81', 'cnpj', true, '11222333000181'],
    'CNPJ com zeros iniciais' => ['00.000.000/0001-91', 'cnpj', true, '00000000000191'],
    'CNPJ alfanumérico (exemplo da Receita)' => ['12.ABC.345/01DE-35', 'cnpj', true, '12ABC34501DE35'],
    'CNPJ alfanumérico em minúsculas' => ['12.abc.345/01de-35', 'cnpj', true, '12ABC34501DE35'],
    'CNPJ alfanumérico com DV errado' => ['12ABC34501DE36', 'cnpj', false, '12ABC34501DE36'],
    'letra no dígito verificador' => ['12ABC34501DE3A', null, false, '12ABC34501DE3A'],
    'CPF com letra não vira CNPJ' => ['5299822472A', null, false, '5299822472A'],
    '13 posições' => ['1222333000181', null, false, '1222333000181'],
    'todos iguais' => ['11.111.111/1111-11', 'cnpj', false, '11111111111111'],
]);

test('formatação e validação do destinatário mantêm o CNPJ alfanumérico', function () {
    expect(FiscalRecipient::formatDocument('12abc34501de35'))->toBe('12.ABC.345/01DE-35');
    $validated = FiscalRecipient::validate(policyRecipient(['document' => '12.abc.345/01de-35']));
    expect($validated['document'])->toBe(POLICY_ALNUM_CNPJ)
        ->and(FiscalRecipient::model($validated))->toBe('55')
        ->and($validated['ie_confirmed'])->toBeTrue();
    expect(FiscalRecipient::validate(['document' => '529.982.247-25'])['ie_confirmed'])->toBeFalse();
});

test('chave de acesso com CNPJ alfanumérico do emitente não perde as letras', function () {
    $key = '41261112ABC34501DE35650010000000011000000019';
    expect(strlen($key))->toBe(44)
        ->and(AccessKey::normalize('NFe'.$key))->toBe($key)
        ->and(AccessKey::isValid($key))->toBeTrue()
        ->and(AccessKey::issuerCnpj($key))->toBe(POLICY_ALNUM_CNPJ)
        ->and(AccessKey::isValid(str_repeat('1', 44)))->toBeTrue()
        ->and(AccessKey::isValid('4126111ABC34501DE35650010000000011000000019'))->toBeFalse();
});

// ── Política ──────────────────────────────────────────────────────────────

test('matriz da política de emissão', function (array $recipient, array $context, ?string $model, array $allowed, array $needs) {
    $decision = policyDecide($recipient, $context);
    expect($decision->model)->toBe($model)
        ->and($decision->allowedModels)->toBe($allowed)
        ->and($decision->needs)->toBe($needs)
        ->and($decision->version)->toBe(FiscalEmissionPolicy::VERSION);
})->with([
    'filial sem liberação: CNPJ continua NF-e' => [policyRecipient(), ['enabled' => false], '55', ['55'], []],
    'CNPJ elegível: NFC-e com opção de NF-e' => [policyRecipient(), [], '65', ['65', '55'], []],
    'CNPJ alfanumérico elegível' => [policyRecipient(['document' => POLICY_ALNUM_CNPJ]), [], '65', ['65', '55'], []],
    'comprador pede NF-e' => [policyRecipient(), ['preference' => 'nfe'], '55', ['65', '55'], []],
    'entrega ou retirada (não presencial)' => [policyRecipient(), ['presential' => false, 'operationState' => 'PR'], '55', ['55'], []],
    'operação interestadual' => [policyRecipient(), ['presential' => false, 'operationState' => 'SC'], '55', ['55'], []],
    'contribuinte de ICMS' => [policyRecipient(['ie_indicator' => '1', 'ie' => '123']), [], '55', ['55'], []],
    'isento de IE' => [policyRecipient(['ie_indicator' => '2']), [], '55', ['55'], []],
    'IE sugerida pela consulta, sem conferir: NF-e como antes' => [policyRecipient(['ie_confirmed' => false]), [], '55', ['55'], ['ie_confirmation']],
    'IE não informada' => [policyRecipient(['ie_indicator' => '', 'ie_confirmed' => false]), [], '55', ['55'], ['ie_confirmation']],
    'NFC-e pedida com IE a conferir' => [policyRecipient(['ie_confirmed' => false]), ['preference' => 'nfce'], null, ['55'], ['ie_confirmation']],
    'recipient salvo antes do campo ie_confirmed' => [array_diff_key(policyRecipient(), ['ie_confirmed' => true]), [], '55', ['55'], ['ie_confirmation']],
    'NFC-e pedida sem ser permitida' => [policyRecipient(), ['presential' => false, 'preference' => 'nfce'], null, ['55'], []],
    'CNPJ logo abaixo de R$ 200 mil' => [policyRecipient(), ['total' => 199999.99], '65', ['65', '55'], []],
    'CNPJ no limite de R$ 200 mil' => [policyRecipient(), ['total' => 200000.00], '55', ['55'], []],
    'CPF' => [['document' => '52998224725'], [], '65', ['65'], []],
    'CPF pedindo NF-e' => [['document' => '52998224725'], ['preference' => 'nfe'], null, ['65'], []],
    'CPF a partir de R$ 200 mil' => [['document' => '52998224725'], ['total' => 200000.00], null, [], ['document']],
    'sem documento logo abaixo de R$ 10 mil' => [['document' => ''], ['total' => 9999.99], '65', ['65'], []],
    'sem documento no limite de R$ 10 mil' => [['document' => ''], ['total' => 10000.00], null, [], ['document']],
    'sem documento pedindo NF-e' => [['document' => ''], ['preference' => 'nfe'], null, ['65'], ['document']],
    'documento inválido' => [['document' => '11222333000182'], [], null, [], ['document']],
]);

test('pedido decide pela venda: balcão e comanda são presenciais, entrega e retirada não', function () {
    expect(FiscalEmissionPolicy::isPresential('pdv', 'balcao'))->toBeTrue()
        ->and(FiscalEmissionPolicy::isPresential('pdv', null))->toBeTrue()
        ->and(FiscalEmissionPolicy::isPresential('pdv', 'entrega'))->toBeFalse()
        ->and(FiscalEmissionPolicy::isPresential('pdv', 'retirar'))->toBeFalse()
        ->and(FiscalEmissionPolicy::isPresential('delivery', null))->toBeFalse()
        ->and(FiscalEmissionPolicy::isPresential('pickup', null))->toBeFalse();

    ['order' => $order] = policyContext(order: ['order_type' => 'delivery', 'delivery_type' => null]);
    expect(app(FiscalEmissionPolicy::class)->forOrder($order)->model)->toBe('55');
});

// ── Payload / XML ─────────────────────────────────────────────────────────

test('NFC-e com CNPJ alfanumérico envia só os campos documentados do destinatário', function () {
    ['order' => $order] = policyContext(recipient: ['document' => POLICY_ALNUM_CNPJ]);
    Http::fake(function ($request) {
        expect($request->url())->toContain('/v2/nfce?ref=')
            ->and($request['cnpj_destinatario'])->toBe(POLICY_ALNUM_CNPJ)
            ->and($request['indicador_inscricao_estadual_destinatario'])->toBe(9)
            ->and($request['nome_destinatario'])->toContain('SEM VALOR FISCAL')
            ->and($request['presenca_comprador'])->toBe('1')
            ->and($request->data())->not->toHaveKeys(['CPF', 'CNPJ', 'cpf_destinatario'])
            ->and($request['items'][0]['codigo_ncm'])->toBe('19059090');

        return Http::response(['status' => 'autorizado', 'chave_nfe' => 'NFe41261111444777000161650010000000011000000019'], 201);
    });

    $note = app(FiscalNoteService::class)->issue($order, null, null, '65');

    expect($note->model)->toBe('65')
        ->and($note->data['policy']['version'])->toBe(FiscalEmissionPolicy::VERSION)
        ->and($note->data['policy']['model'])->toBe('65')
        ->and($note->data['recipient']['document'])->toBe(POLICY_ALNUM_CNPJ);
});

test('NFC-e com CPF usa cpf_destinatario', function () {
    ['order' => $order] = policyContext(recipient: ['document' => '52998224725', 'name' => '', 'street' => '', 'number' => '', 'neighborhood' => '', 'city' => '', 'state' => '', 'zip' => '', 'ie_indicator' => '']);
    Http::fake(function ($request) {
        expect($request['cpf_destinatario'])->toBe('52998224725')
            ->and($request->data())->not->toHaveKeys(['CPF', 'CNPJ', 'cnpj_destinatario', 'nome_destinatario']);

        return Http::response(['status' => 'processando_autorizacao'], 202);
    });

    expect(app(FiscalNoteService::class)->issue($order)->model)->toBe('65');
});

test('NF-e de venda presencial é operação interna mesmo com a sede do comprador em outra UF', function () {
    ['order' => $order] = policyContext(nfceCnpj: false, recipient: ['state' => 'SP', 'city' => 'São Paulo', 'zip' => '01001000']);
    Http::fake(function ($request) {
        expect($request->url())->toContain('/v2/nfe?ref=')
            ->and($request['local_destino'])->toBe('1')
            ->and($request['presenca_comprador'])->toBe('1')
            ->and($request['items'][0]['cfop'])->toBe('5102');

        return Http::response(['status' => 'processando_autorizacao'], 202);
    });

    expect(app(FiscalNoteService::class)->issue($order)->model)->toBe('55');
});

// ── Pré-checagem e job ────────────────────────────────────────────────────

test('NFC-e com CNPJ exige o cadastro fiscal do produto, sem códigos genéricos', function () {
    ['order' => $order, 'product' => $product] = policyContext();
    $product->update(['fiscal_data' => ['icms_situation' => '102']]);

    $check = app(FiscalNoteService::class)->check($order);

    expect($check['decision']->model)->toBe('65')
        ->and($check['issues'][0]['group'])->toBe('products')
        ->and($check['issues'][0]['products'][0])->toMatchArray(['product_id' => $product->id, 'source' => 'general'])
        ->and($check['issues'][0]['message'])->toContain('Dados fiscais pendentes para NFC-e com CNPJ');

    // Consumidor sem documento mantém a NFC-e histórica.
    $order->update(['fiscal_recipient' => ['document' => '']]);
    expect(app(FiscalNoteService::class)->check($order->fresh())['issues'])->toBe([]);
});

test('pré-checagem junta documento, destinatário e produtos numa só lista', function () {
    ['order' => $order, 'product' => $product] = policyContext(recipient: ['ie_confirmed' => false, 'street' => '']);
    $product->update(['fiscal_data' => []]);

    $issues = collect(app(FiscalNoteService::class)->check($order, null, null, 'nfe')['issues'])->pluck('group')->all();

    expect($issues)->toBe(['recipient', 'products']);
});

test('modelo conferido diferente do que a política decide na execução não emite em silêncio', function () {
    ['order' => $order, 'config' => $config] = policyContext();
    $config->update(['nfce_cnpj_enabled' => false]);
    Http::fake();

    $job = new IssueFiscalNote($order->id, null, null, '65');
    $job->withFakeQueueInteractions()->handle(app(FiscalNoteService::class));

    $job->assertFailed();
    expect(FiscalNote::count())->toBe(0);
    Http::assertNothingSent();
});

test('uma venda nunca tem NFC-e e NF-e ativas ao mesmo tempo', function () {
    ['order' => $order, 'company' => $company] = policyContext();
    FiscalNote::create(['company_id' => $company->id, 'order_id' => $order->id, 'branch_id' => $order->branch_id, 'model' => '65', 'status' => 'pending', 'provider_reference' => 'order_'.$order->id.'_x']);
    $order->update(['fiscal_document_preference' => 'nfe']);
    Http::fake();

    expect(fn () => app(FiscalNoteService::class)->issue($order->fresh(), null, null, '55'))->toThrow(FiscalNoteAlreadyIssuedException::class);
    expect(FiscalNote::count())->toBe(1);
    Http::assertNothingSent();
});

// ── Tela do pedido ────────────────────────────────────────────────────────

test('modal do pedido mostra emitente, escolha de modelo e grava a preferência confirmada', function () {
    ['order' => $order, 'company' => $company] = policyContext();
    Queue::fake();

    Livewire::actingAs(policyIssuer($company))->test(Show::class, ['order' => $order])
        ->call('openFiscalModal')
        ->assertSee('Emitente: Arapongas')
        ->assertSee('NFC-e com CNPJ')
        ->assertSee('Pronto para emitir NFC-e')
        ->call('$set', 'fiscalDocumentChoice', 'nfe')
        ->assertSee('Pronto para emitir NF-e')
        ->call('issueFiscalNote')
        ->assertHasNoErrors();

    expect($order->fresh()->fiscal_document_preference)->toBe('nfe');
    Queue::assertPushed(IssueFiscalNote::class, fn ($job) => $job->orderId === $order->id && $job->confirmedModel === '55');
});

test('IE a conferir mantém NF-e e conferir no modal libera a NFC-e com CNPJ', function () {
    ['order' => $order, 'company' => $company] = policyContext(recipient: ['ie_confirmed' => false]);
    Queue::fake();

    $component = Livewire::actingAs(policyIssuer($company))->test(Show::class, ['order' => $order])
        ->call('openFiscalModal')
        ->assertSee('Pronto para emitir NF-e')
        ->assertSee('Conferir IE para emitir NFC-e com CNPJ')
        ->call('$set', 'fiscalDocumentChoice', 'nfce')
        ->assertSee('Confirme a situação da inscrição estadual')
        ->call('issueFiscalNote')
        ->assertHasErrors('fiscalEmission');
    Queue::assertNothingPushed();

    $component->call('openFiscalRecipient')
        ->set('fiscalRecipientDraft.ie_indicator', '9')
        ->call('saveFiscalRecipientAndContinue')
        ->assertHasNoErrors()
        ->assertSee('Pronto para emitir NFC-e')
        ->call('issueFiscalNote')
        ->assertHasNoErrors();

    expect($order->fresh()->fiscal_recipient['ie_confirmed'])->toBeTrue()
        ->and($order->fresh()->fiscal_document_preference)->toBe('nfce');
    Queue::assertPushed(IssueFiscalNote::class, fn ($job) => $job->confirmedModel === '65');
});

test('trocar o comprador do pedido descarta a escolha de modelo feita para o anterior', function () {
    ['order' => $order, 'company' => $company] = policyContext();
    $order->update(['fiscal_document_preference' => 'nfe']);

    Livewire::actingAs(policyIssuer($company))->test(Show::class, ['order' => $order])
        ->call('openFiscalRecipient')
        ->set('fiscalRecipientDraft.name', 'Comprador Atualizado')
        ->call('saveFiscalRecipient')->assertHasNoErrors();
    expect($order->fresh()->fiscal_document_preference)->toBe('nfe');

    Livewire::actingAs(policyIssuer($company))->test(Show::class, ['order' => $order->fresh()])
        ->call('openFiscalRecipient')
        ->set('fiscalRecipientDraft', ['document' => '529.982.247-25'])
        ->call('saveFiscalRecipient')->assertHasNoErrors();
    expect($order->fresh()->fiscal_document_preference)->toBeNull()
        ->and(app(FiscalEmissionPolicy::class)->forOrder($order->fresh())->model)->toBe('65');
});

test('configuração recusa emitente com CNPJ alfanumérico em vez de gravá-lo truncado', function () {
    ['company' => $company, 'branch' => $branch] = policyContext();
    $branch->update(['cnpj' => null]);
    CompanyFiscalConfig::where('branch_id', $branch->id)->delete();
    $admin = User::factory()->create(['is_super_admin' => true]);

    Livewire::actingAs($admin)->test(\App\Livewire\Admin\Fiscal\Config::class)
        ->set('branchId', $branch->id)
        ->set('branchCnpj', '12.ABC.345/01DE-35')
        ->call('save')
        ->assertHasErrors('branchCnpj');
    expect($branch->fresh()->cnpj)->toBeNull();
});

test('CNPJ novo digitado no modal consulta a empresa e só emite depois de salvar', function () {
    ['order' => $order, 'company' => $company] = policyContext(nfceCnpj: false, recipient: ['document' => '']);
    app()->instance(\App\Contracts\CnpjLookupInterface::class, new class implements \App\Contracts\CnpjLookupInterface
    {
        public function find(string $cnpj): ?array
        {
            return ['name' => 'Alfa Numérica Ltda', 'email' => null, 'street' => 'Rua Nova', 'number' => '10', 'complement' => null, 'neighborhood' => 'Centro', 'city' => 'Londrina', 'state' => 'PR', 'zip' => '86010000', 'status' => 'ATIVA'];
        }
    });
    Queue::fake();

    Livewire::actingAs(policyIssuer($company))->test(Show::class, ['order' => $order])
        ->call('openFiscalModal')
        ->set('fiscalCustomerDocument', '12.abc.345/01de-35')
        ->assertSet('showFiscalRecipient', true)
        ->assertSet('fiscalRecipientDraft.name', 'Alfa Numérica Ltda')
        ->call('issueFiscalNote')
        ->assertHasErrors('fiscalCustomerDocument')
        ->call('saveFiscalRecipientAndContinue')
        ->assertHasNoErrors()
        ->assertSet('fiscalCustomerDocument', '12.ABC.345/01DE-35')
        ->call('issueFiscalNote')
        ->assertHasNoErrors();

    expect($order->fresh()->fiscal_recipient['document'])->toBe(POLICY_ALNUM_CNPJ);
    Queue::assertPushed(IssueFiscalNote::class, fn ($job) => $job->confirmedModel === '55');
});

test('pendência de produto tem link de correção só para quem edita produtos', function () {
    ['order' => $order, 'company' => $company, 'product' => $product, 'branch' => $branch] = policyContext();
    $product->branches()->attach($branch->id, ['available' => true, 'fiscal_data' => json_encode(['icms_situation' => '102'])]);
    $editor = policyIssuer($company);
    $editorRole = \App\Models\Permission::firstOrCreate(['name' => 'products.update'], ['group' => 'products', 'label' => 'Editar produto']);
    \App\Models\UserPermission::create(['user_id' => $editor->id, 'company_id' => $company->id, 'permission_id' => $editorRole->id, 'granted' => true]);

    $expected = '/admin/products/'.$product->id.'/edit?voltar=%2Fadmin%2Forders%2F'.$order->id.'#fiscal-filial-'.$branch->id;
    Livewire::actingAs($editor)->test(Show::class, ['order' => $order])
        ->call('openFiscalModal')
        ->assertSee('perfil fiscal da filial Arapongas')
        ->assertSeeHtml(e($expected));

    $cashier = User::factory()->create();
    $cashier->companies()->attach($company->id, ['role' => 'caixa', 'branch_id' => $branch->id]);
    $permission = \App\Models\Permission::firstOrCreate(['name' => 'fiscal.issue'], ['group' => 'fiscal', 'label' => 'Emitir notas']);
    \App\Models\UserPermission::create(['user_id' => $cashier->id, 'company_id' => $company->id, 'permission_id' => $permission->id, 'granted' => true]);
    expect(FiscalCorrectionLinks::canEditProducts($cashier, $company))->toBeFalse();
});

test('vários produtos pendentes ganham atalho para preencher todos na Tributação em lote', function () {
    ['order' => $order, 'company' => $company, 'product' => $product, 'branch' => $branch] = policyContext(nfceCnpj: false);
    $product->update(['fiscal_data' => null]);
    $second = Product::create(['company_id' => $company->id, 'product_category_id' => $product->product_category_id, 'name' => 'Kibe', 'price' => 10, 'active' => true]);
    OrderItem::create(['order_id' => $order->id, 'product_id' => $second->id, 'product_name' => 'Kibe', 'unit_price' => 10, 'quantity' => 1, 'subtotal' => 10]);
    $order->update(['subtotal' => 40, 'total' => 40, 'net_value' => 40]);
    $editor = policyIssuer($company);
    $permission = \App\Models\Permission::firstOrCreate(['name' => 'products.update'], ['group' => 'products', 'label' => 'Editar produto']);
    \App\Models\UserPermission::create(['user_id' => $editor->id, 'company_id' => $company->id, 'permission_id' => $permission->id, 'granted' => true]);

    $ids = $product->id.'%2C'.$second->id;
    Livewire::actingAs($editor)->test(Show::class, ['order' => $order])
        ->call('openFiscalModal')
        ->assertSee('Preencher 2 produto(s) de uma vez')
        ->assertSeeHtml('/admin/fiscal/tributacao?')
        ->assertSeeHtml('produtos='.$ids.'&amp;destino=general')
        ->assertSeeHtml('voltar=%2Fadmin%2Forders%2F'.$order->id);

    // Quem só emite não recebe o atalho (a tela de lote é de quem edita produtos).
    $cashier = User::factory()->create();
    $cashier->companies()->attach($company->id, ['role' => 'caixa', 'branch_id' => $branch->id]);
    $issue = \App\Models\Permission::firstOrCreate(['name' => 'fiscal.issue'], ['group' => 'fiscal', 'label' => 'Emitir notas']);
    \App\Models\UserPermission::create(['user_id' => $cashier->id, 'company_id' => $company->id, 'permission_id' => $issue->id, 'granted' => true]);
    Livewire::actingAs($cashier)->test(Show::class, ['order' => $order])
        ->call('openFiscalModal')
        ->assertSee('Kibe')
        ->assertDontSee('de uma vez');
});

test('retorno da correção do produto só aceita caminho interno do painel', function (mixed $path, ?string $expected) {
    expect(FiscalCorrectionLinks::safeReturnPath($path))->toBe($expected);
})->with([
    ['/admin/orders/10', '/admin/orders/10'],
    ['//evil.test/admin/', null],
    ['https://evil.test/admin/orders/1', null],
    ['/admin\\..\\x', null],
    ['/login', null],
    [null, null],
]);

// ── Controle de notas e PDV ───────────────────────────────────────────────

test('lote do Controle de notas confirma no job o modelo da pré-checagem', function () {
    ['order' => $order, 'company' => $company] = policyContext();
    $user = policyIssuer($company);
    $view = \App\Models\Permission::firstOrCreate(['name' => 'fiscal.view'], ['group' => 'fiscal', 'label' => 'Ver notas']);
    \App\Models\UserPermission::create(['user_id' => $user->id, 'company_id' => $company->id, 'permission_id' => $view->id, 'granted' => true]);
    Bus::fake();

    Livewire::actingAs($user)->test(IssueControl::class)
        ->set('dateFrom', '')->set('dateTo', '')
        ->set('selected', [$order->id])
        ->call('prepareIssue')
        ->assertSet('confirmSummary.nfce', 1)
        ->call('issueSelected');

    Bus::assertBatched(fn (PendingBatch $batch) => $batch->jobs->first()->confirmedModel === '65');
});

test('PDV com filial liberada emite NFC-e com CNPJ conferido e grava a escolha do caixa', function () {
    ['admin' => $admin, 'product' => $product, 'company' => $company, 'branch' => $branch] = pdvContext();
    $company->update(['fiscal_notes_enabled' => true]);
    $branch->update(['state' => 'PR']);
    CompanyFiscalConfig::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'is_default' => true, 'enabled' => true, 'nfce_cnpj_enabled' => true]);
    Http::fake(['brasilapi.com.br/*' => Http::response(['razao_social' => 'PADARIA CLIENTE LTDA', 'descricao_situacao_cadastral' => 'ATIVA', 'logradouro' => 'PAULISTA', 'numero' => '1000', 'bairro' => 'BELA VISTA', 'municipio' => 'CURITIBA', 'uf' => 'PR', 'cep' => '80010000'])]);
    Bus::fake();
    $this->actingAs($admin);

    $terminal = Livewire::test(\App\Livewire\Admin\Pdv\Terminal::class)
        ->call('addProduct', $product->id)->call('proceedToPayment')
        ->set('paymentMethod', 'cash')->set('cashReceivedInput', '10.00')
        ->set('issueFiscalNote', true)
        ->set('fiscalDocument', '11.444.777/0001-61')
        ->assertSee('Conferir IE para NFC-e')
        ->assertSee('NF-e · PADARIA CLIENTE LTDA');

    // Pedir NFC-e com a IE só sugerida pela consulta: a venda não sai sem conferir.
    $terminal->call('$set', 'fiscalDocumentChoice', 'nfce')
        ->call('processOrder')->assertHasErrors('fiscalDocument')->assertSet('showFiscalCompanyForm', true);
    expect(Order::withoutGlobalScopes()->count())->toBe(0);

    $terminal->call('confirmFiscalCompany')
        ->assertSet('fiscalCompany.ie_confirmed', true)
        ->assertSee('NFC-e com CNPJ')
        ->call('processOrder')
        ->assertHasNoErrors();

    $order = Order::withoutGlobalScopes()->sole();
    expect($order->fiscal_recipient['ie_confirmed'])->toBeTrue()->and($order->fiscal_document_preference)->toBe('nfce');
    Bus::assertDispatched(IssueFiscalNote::class, fn ($job) => $job->orderId === $order->id && $job->confirmedModel === '65');
});

test('PDV respeita a escolha de NF-e do caixa', function () {
    ['admin' => $admin, 'product' => $product, 'company' => $company, 'branch' => $branch] = pdvContext();
    $company->update(['fiscal_notes_enabled' => true]);
    CompanyFiscalConfig::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'is_default' => true, 'enabled' => true, 'nfce_cnpj_enabled' => true]);
    $customer = \App\Models\Customer::withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => 'Padaria', 'phone' => '11966665555', 'fiscal_recipient' => policyRecipient(['document' => '11444777000161'])]);
    Bus::fake();
    $this->actingAs($admin);

    Livewire::test(\App\Livewire\Admin\Pdv\Terminal::class)
        ->call('addProduct', $product->id)->call('proceedToPayment')
        ->set('paymentMethod', 'cash')->set('cashReceivedInput', '10.00')
        ->call('selectCustomer', $customer->id)
        ->set('issueFiscalNote', true)
        ->assertSet('fiscalDocument', '11.444.777/0001-61')
        ->call('$set', 'fiscalDocumentChoice', 'nfe')
        ->call('processOrder')
        ->assertHasNoErrors();

    $order = Order::withoutGlobalScopes()->sole();
    expect($order->fiscal_document_preference)->toBe('nfe');
    Bus::assertDispatched(IssueFiscalNote::class, fn ($job) => $job->confirmedModel === '55');
});

test('salvar o produto aberto pela pendência volta para o pedido sem emitir', function () {
    ['order' => $order, 'product' => $product, 'company' => $company] = policyContext();
    $admin = User::factory()->create(['is_super_admin' => true]);
    Queue::fake();

    Livewire::withQueryParams(['voltar' => '/admin/orders/'.$order->id])
        ->actingAs($admin)->test(\App\Livewire\Admin\Products\Form::class, ['product' => $product->fresh()])
        ->assertSet('returnTo', '/admin/orders/'.$order->id)
        ->assertSee('Corrigindo pendência de nota fiscal')
        ->set('fiscalData.cest', '1700100')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect('/admin/orders/'.$order->id);

    Livewire::withQueryParams(['voltar' => 'https://evil.test/admin/x'])
        ->actingAs($admin)->test(\App\Livewire\Admin\Products\Form::class, ['product' => $product->fresh()])
        ->assertSet('returnTo', null);
    Queue::assertNothingPushed();
});
