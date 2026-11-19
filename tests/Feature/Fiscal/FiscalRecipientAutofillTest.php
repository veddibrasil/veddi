<?php

use App\Jobs\IssueFiscalNote;
use App\Livewire\Admin\Orders\Show;
use App\Livewire\Admin\Pdv\Terminal;
use App\Models\Company;
use App\Models\CompanyFiscalConfig;
use App\Models\Customer;
use App\Models\FiscalNote;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Fiscal\FiscalPostalCodeLookup;
use App\Services\Fiscal\FiscalRecipient;
use App\Services\Fiscal\FiscalRecipientAutofill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function autofillBuyer(array $overrides = []): array
{
    return [...[
        'document' => '52998224725', 'name' => 'Maria da Silva', 'email' => 'maria@example.test',
        'street' => 'Rua do Cadastro', 'number' => '42', 'neighborhood' => 'Centro',
        'city' => 'Curitiba', 'state' => 'PR', 'zip' => '80010000', 'ie_indicator' => '9', 'ie_confirmed' => true,
    ], ...$overrides];
}

function autofillContext(): array
{
    $ctx = pdvContext();
    $ctx['company']->update(['fiscal_notes_enabled' => true, 'owner_cpf_cnpj' => '11444777000161']);
    $ctx['admin']->update(['is_super_admin' => false]);
    $ctx['admin']->companies()->attach($ctx['company']->id, ['role' => 'company_admin']);
    foreach (['fiscal.issue', 'fiscal.view', 'pdv.operate'] as $name) {
        $permission = \App\Models\Permission::firstOrCreate(['name' => $name], ['group' => 'fiscal', 'label' => $name]);
        \App\Models\UserPermission::create(['user_id' => $ctx['admin']->id, 'company_id' => $ctx['company']->id, 'permission_id' => $permission->id, 'granted' => true]);
    }
    $ctx['branch']->update(['cnpj' => '11444777000161', 'state' => 'PR']);
    $ctx['product']->update(['fiscal_data' => ['ncm' => '19059090', 'cfop' => '5102', 'interstate_cfop' => '6102', 'icms_situation' => '102', 'origin' => '0', 'pis_situation' => '49', 'cofins_situation' => '49']]);
    CompanyFiscalConfig::create(['company_id' => $ctx['company']->id, 'branch_id' => $ctx['branch']->id, 'enabled' => true, 'is_default' => true, 'nfce_cnpj_enabled' => true]);
    config(['fiscal.focus_nfe.token' => 'autofill-test']);
    Http::preventStrayRequests();

    return $ctx;
}

function autofillOrder(array $ctx, array $recipient): Order
{
    $order = Order::create(['company_id' => $ctx['company']->id, 'branch_id' => $ctx['branch']->id,
        'status' => 'paid', 'order_type' => 'pdv', 'delivery_type' => 'balcao', 'payment_method' => 'cash',
        'subtotal' => 10, 'total' => 10, 'delivery_fee' => 0, 'fee' => 0, 'net_value' => 10, 'fiscal_recipient' => $recipient]);
    OrderItem::create(['order_id' => $order->id, 'product_id' => $ctx['product']->id, 'product_name' => $ctx['product']->name, 'unit_price' => 10, 'quantity' => 1, 'subtotal' => 10]);

    return $order;
}

test('CPF com cadastro fiscal parcial usa nome e endereço do mesmo cliente sem substituir dados fiscais existentes', function () {
    $ctx = autofillContext();
    $customer = Customer::create(['company_id' => $ctx['company']->id, 'name' => 'Maria da Silva', 'phone' => '41999990000',
        'tax_id' => '52998224725', 'address' => 'Rua do Cliente', 'number' => '42', 'city' => 'Curitiba', 'state' => 'PR', 'cep' => '80010000',
        'fiscal_recipient' => ['document' => '52998224725', 'street' => 'Endereço fiscal próprio']]);
    $recipient = FiscalRecipient::fromCustomer($customer);
    expect($recipient['name'])->toBe('Maria da Silva')->and($recipient['street'])->toBe('Endereço fiscal próprio')
        ->and($recipient)->not->toHaveKeys(['number', 'state']);
    Http::assertNothingSent();
});

test('CPF sem endereço fiscal reaproveita o endereço completo do próprio cadastro', function () {
    $ctx = autofillContext();
    $customer = Customer::create(['company_id' => $ctx['company']->id, 'name' => 'Maria', 'phone' => '41999990000', 'tax_id' => '52998224725',
        'address' => 'Rua do Cadastro', 'number' => '42', 'neighborhood' => 'Centro', 'city' => 'Curitiba', 'state' => 'PR', 'cep' => '80010000',
        'fiscal_recipient' => ['document' => '52998224725']]);
    expect(FiscalRecipient::fromCustomer($customer))->toMatchArray(['name' => 'Maria', 'street' => 'Rua do Cadastro', 'number' => '42', 'city' => 'Curitiba', 'state' => 'PR']);
});

test('preenchimento do CPF procura cadastro do mesmo documento apenas na empresa atual', function () {
    $ctx = autofillContext();
    Customer::create(['company_id' => $ctx['company']->id, 'name' => 'Maria', 'phone' => '41999990000', 'fiscal_recipient' => autofillBuyer()]);
    $result = app(FiscalRecipientAutofill::class)->forDocument($ctx['company']->id, '529.982.247-25', preferred: ['document' => '52998224725', 'number' => '99']);
    expect($result['recipient']['name'])->toBe('Maria da Silva')->and($result['recipient']['number'])->toBe('99');

    $other = Company::create(['name' => 'Outra loja', 'slug' => 'autofill-other', 'active' => true, 'order_prefix' => 'OTH']);
    $foreign = app(FiscalRecipientAutofill::class)->forDocument($other->id, '52998224725');
    expect($foreign['recipient'])->not->toHaveKeys(['name', 'street']);
    Http::assertNothingSent();
});

test('chat público não consulta dados de outros clientes da loja', function () {
    $ctx = autofillContext();
    Customer::create(['company_id' => $ctx['company']->id, 'name' => 'Maria', 'phone' => '41999990000', 'fiscal_recipient' => autofillBuyer()]);
    $result = app(FiscalRecipientAutofill::class)->forDocument($ctx['company']->id, '52998224725', reuseStoreData: false);
    expect($result['recipient'])->not->toHaveKeys(['name', 'street', 'email']);
    Http::assertNothingSent();
});

test('dados de nota autorizada anterior completam o mesmo documento sem exigir novo cadastro', function () {
    $ctx = autofillContext();
    $order = autofillOrder($ctx, autofillBuyer());
    FiscalNote::create(['company_id' => $ctx['company']->id, 'branch_id' => $ctx['branch']->id, 'order_id' => $order->id,
        'model' => '55', 'status' => 'authorized', 'data' => ['recipient' => autofillBuyer()]]);
    $result = app(FiscalRecipientAutofill::class)->forDocument($ctx['company']->id, '52998224725');
    expect($result['recipient']['street'])->toBe('Rua do Cadastro')->and($result['recipient']['name'])->toBe('Maria da Silva');
    Http::assertNothingSent();
});

test('CNPJ completo no cadastro dispensa chamada externa e conserva a IE conferida', function () {
    $ctx = autofillContext();
    $buyer = autofillBuyer(['document' => '11444777000161', 'name' => 'Empresa do Cadastro']);
    Customer::create(['company_id' => $ctx['company']->id, 'name' => 'Contato', 'phone' => '41999990000', 'fiscal_recipient' => $buyer]);
    $result = app(FiscalRecipientAutofill::class)->forDocument($ctx['company']->id, '11444777000161');
    expect($result['recipient']['name'])->toBe('Empresa do Cadastro')->and($result['recipient']['ie_confirmed'])->toBeTrue();
    Http::assertNothingSent();
});

test('consulta pública do CNPJ preenche os campos disponíveis e exige conferência da IE', function () {
    $ctx = autofillContext();
    Http::fake(['brasilapi.com.br/*' => Http::response(['razao_social' => 'Empresa Consultada', 'logradouro' => 'Rua Consulta', 'numero' => '12',
        'bairro' => 'Centro', 'municipio' => 'Curitiba', 'uf' => 'PR', 'cep' => '80010000', 'email' => 'fiscal@empresa.test'])]);
    $result = app(FiscalRecipientAutofill::class)->forDocument($ctx['company']->id, '11444777000161');
    expect($result['recipient'])->toMatchArray(['name' => 'Empresa Consultada', 'number' => '12', 'city' => 'Curitiba', 'state' => 'PR', 'zip' => '80010000', 'ie_confirmed' => false]);
    Http::assertSentCount(1);
});

test('nome do contato com CNPJ não substitui a razão social da consulta pública', function () {
    $ctx = autofillContext();
    $customer = Customer::create(['company_id' => $ctx['company']->id, 'name' => 'Contato da empresa', 'phone' => '41999990000',
        'tax_id' => '11444777000161', 'address' => 'Rua do Contato', 'number' => '42', 'neighborhood' => 'Centro', 'city' => 'Curitiba', 'state' => 'PR', 'cep' => '80010000']);
    Http::fake(['brasilapi.com.br/*' => Http::response(['razao_social' => 'Razão Social Oficial', 'logradouro' => 'Rua da Empresa', 'numero' => '100',
        'bairro' => 'Centro', 'municipio' => 'Curitiba', 'uf' => 'PR', 'cep' => '80010000'])]);
    $result = app(FiscalRecipientAutofill::class)->forDocument($ctx['company']->id, '11444777000161', $customer);
    expect($result['recipient']['name'])->toBe('Razão Social Oficial')->and($result['recipient']['street'])->toBe('Rua da Empresa');
    Http::assertSentCount(1);
});

test('número ausente na consulta de CNPJ não é inventado como sem número', function () {
    $ctx = autofillContext();
    Http::fake(['brasilapi.com.br/*' => Http::response(['razao_social' => 'Empresa sem número informado', 'logradouro' => 'Rua da Empresa',
        'bairro' => 'Centro', 'municipio' => 'Curitiba', 'uf' => 'PR', 'cep' => '80010000'])]);
    $result = app(FiscalRecipientAutofill::class)->forDocument($ctx['company']->id, '11444777000161');
    expect($result['recipient']['number'] ?? null)->toBeNull();
});

test('consulta de CEP preenche quatro campos, reutiliza cache e não inventa número', function () {
    Http::fake(['viacep.com.br/*' => Http::response(['cep' => '80010-000', 'logradouro' => 'Rua do CEP', 'bairro' => 'Centro', 'localidade' => 'Curitiba', 'uf' => 'PR'])]);
    $lookup = app(FiscalPostalCodeLookup::class);
    expect($lookup->find('80010-000'))->toBe(['street' => 'Rua do CEP', 'neighborhood' => 'Centro', 'city' => 'Curitiba', 'state' => 'PR']);
    expect($lookup->find('80010000'))->not->toHaveKeys(['number', 'complement']);
    expect($lookup->find('123'))->toBeNull();
    Http::assertSentCount(1);
});

test('rascunho já contém todos os campos e CEP formatado para não disparar consulta ao aplicar a máscara', function () {
    $draft = FiscalRecipient::draft(['document' => '52998224725', 'zip' => '80010000']);
    expect($draft['zip'])->toBe('80010-000')->and($draft)->toHaveKeys(['name', 'number', 'street', 'neighborhood', 'city', 'state', 'complement', 'email']);
    expect(FiscalRecipient::validate($draft)['zip'])->toBe('80010000');
});

test('falha ou CEP inexistente permite preenchimento manual', function (string $case) {
    Http::fake(['viacep.com.br/*' => match ($case) {
        'timeout' => Http::failedConnection(),
        'not_found' => Http::response(['erro' => true]),
        default => Http::response('indisponível', 503),
    }]);
    expect(app(FiscalPostalCodeLookup::class)->find('80010000'))->toBeNull();
})->with(['timeout', 'not_found', 'server_error']);

test('pedido com CPF novo exige apenas nome, CEP e número e permite salvar e emitir numa ação', function () {
    $ctx = autofillContext();
    $order = autofillOrder($ctx, ['document' => '52998224725']);
    Http::fake(['viacep.com.br/*' => Http::response(['cep' => '80010-000', 'logradouro' => 'Rua do CEP', 'bairro' => 'Centro', 'localidade' => 'Curitiba', 'uf' => 'PR'])]);
    Queue::fake();
    Livewire::actingAs($ctx['admin'])->test(Show::class, ['order' => $order])
        ->call('openFiscalModal')->call('$set', 'fiscalDocumentChoice', 'nfe')
        ->assertSet('showFiscalRecipient', true)
        ->set('fiscalRecipientDraft.name', 'Maria da Silva')
        ->set('fiscalRecipientDraft.zip', '80010-000')
        ->assertSet('fiscalRecipientDraft.city', 'Curitiba')
        ->set('fiscalRecipientDraft.number', '42')
        ->assertSee('Salvar e emitir NF-e')
        ->call('saveFiscalRecipientAndIssue', '55')->assertHasNoErrors()
        ->assertSet('showFiscalModal', false);
    expect($order->fresh()->fiscal_recipient['street'])->toBe('Rua do CEP');
    Queue::assertPushed(IssueFiscalNote::class, fn ($job) => $job->confirmedModel === '55');
});

test('PDV busca o CPF já conhecido e conclui a venda com NF-e sem redigitar endereço', function () {
    $ctx = autofillContext();
    Customer::create(['company_id' => $ctx['company']->id, 'name' => 'Maria', 'phone' => '41999990000', 'fiscal_recipient' => autofillBuyer()]);
    Bus::fake();
    Livewire::actingAs($ctx['admin'])->test(Terminal::class)
        ->call('addProduct', $ctx['product']->id)->call('proceedToPayment')
        ->set('paymentMethod', 'cash')->set('cashReceivedInput', '10.00')
        ->set('issueFiscalNote', true)->set('fiscalDocument', '52998224725')
        ->assertSet('fiscalCompany.name', 'Maria da Silva')
        ->call('$set', 'fiscalDocumentChoice', 'nfe')
        ->assertSet('showFiscalCompanyForm', false)
        ->call('processOrder')->assertHasNoErrors();
    Bus::assertDispatched(IssueFiscalNote::class, fn ($job) => $job->confirmedModel === '55');
    Http::assertNothingSent();
});

test('pedido acompanha a fila até autorizar e libera impressão sem recarregar a página', function () {
    $ctx = autofillContext();
    $order = autofillOrder($ctx, autofillBuyer());
    Queue::fake();
    $component = Livewire::actingAs($ctx['admin'])->test(Show::class, ['order' => $order])
        ->call('openFiscalModal')->call('issueFiscalNote')->assertHasNoErrors()
        ->assertSet('fiscalPendingAfterNoteId', 0)
        ->assertSee('Emissão solicitada. Aguardando autorização da nota fiscal…');
    $note = FiscalNote::create(['company_id' => $ctx['company']->id, 'branch_id' => $ctx['branch']->id,
        'order_id' => $order->id, 'model' => '65', 'status' => 'pending', 'data' => ['recipient' => autofillBuyer()]]);
    $component->call('refreshFiscalStatus')->assertSee('Pendente');
    $note->update(['status' => 'authorized']);
    $component->call('refreshFiscalStatus')->assertSet('fiscalPendingAfterNoteId', null)
        ->assertSee('Autorizada')->assertSee('Imprimir nota');
});

test('falha de emissão antes de criar nota aparece no pedido e encerra a espera', function () {
    $ctx = autofillContext();
    $order = autofillOrder($ctx, autofillBuyer());
    Queue::fake();
    $component = Livewire::actingAs($ctx['admin'])->test(Show::class, ['order' => $order])
        ->call('openFiscalModal')->call('issueFiscalNote')->assertSet('fiscalPendingAfterNoteId', 0);
    $order->update(['fiscal_error' => 'Confira o certificado da filial.']);
    $component->call('refreshFiscalStatus')->assertSet('fiscalPendingAfterNoteId', null)
        ->assertSee('Confira o certificado da filial.');
});
