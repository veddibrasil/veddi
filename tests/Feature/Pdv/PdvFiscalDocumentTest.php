<?php

use App\Jobs\IssueFiscalNote;
use App\Livewire\Admin\Pdv\TabTerminal;
use App\Livewire\Admin\Pdv\Terminal;
use App\Models\CompanyFiscalConfig;
use App\Models\Customer;
use App\Models\Order;
use App\Services\Fiscal\BrasilApiCnpjLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function fiscalPdvContext(): array
{
    $context = pdvContext();
    $context['company']->update(['fiscal_notes_enabled' => true]);
    CompanyFiscalConfig::create(['company_id' => $context['company']->id, 'branch_id' => $context['branch']->id, 'is_default' => true, 'enabled' => true]);

    return $context;
}

function brasilApiPadaria(): array
{
    return [
        'cnpj' => '11444777000161', 'razao_social' => 'PADARIA CLIENTE LTDA', 'descricao_situacao_cadastral' => 'ATIVA',
        'descricao_tipo_de_logradouro' => 'AVENIDA', 'logradouro' => 'PAULISTA', 'numero' => '1000', 'complemento' => 'ANDAR 2',
        'bairro' => 'BELA VISTA', 'municipio' => 'SAO PAULO', 'uf' => 'SP', 'cep' => '01310100', 'email' => 'Fiscal@Padaria.test',
    ];
}

function pdvCheckout($product)
{
    return Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('paymentMethod', 'cash')
        ->set('cashReceivedInput', '10.00');
}

test('venda de balcão sem cliente cadastrado leva o CPF digitado para a nota', function () {
    ['admin' => $admin, 'product' => $product, 'company' => $company] = fiscalPdvContext();
    Bus::fake();
    $this->actingAs($admin);

    pdvCheckout($product)
        ->set('issueFiscalNote', true)
        ->set('fiscalDocument', '529.982.247-25')
        ->assertSet('fiscalDocumentState', 'cpf')
        ->call('processOrder')
        ->assertHasNoErrors();

    $order = Order::withoutGlobalScopes()->sole();
    expect($order->fiscal_recipient['document'])->toBe('52998224725');
    Bus::assertDispatched(IssueFiscalNote::class, fn ($job) => $job->orderId === $order->id);
});

test('CPF inválido trava a venda com erro no campo da nota', function () {
    ['admin' => $admin, 'product' => $product, 'company' => $company] = fiscalPdvContext();
    Bus::fake();
    $this->actingAs($admin);

    pdvCheckout($product)
        ->set('issueFiscalNote', true)
        ->set('fiscalDocument', '123.456.789-00')
        ->assertSet('fiscalDocumentState', 'invalid')
        ->call('processOrder')
        ->assertHasErrors('fiscalDocument');

    expect(Order::withoutGlobalScopes()->count())->toBe(0);
    Bus::assertNotDispatched(IssueFiscalNote::class);
});

test('cliente com CNPJ incompleto no cadastro não trava venda sem emissão de nota', function () {
    ['admin' => $admin, 'product' => $product, 'company' => $company] = fiscalPdvContext();
    $customer = Customer::withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => 'Mercadinho', 'phone' => '11966665555', 'fiscal_recipient' => ['document' => '11444777000242']]);
    Bus::fake();
    $this->actingAs($admin);

    pdvCheckout($product)
        ->call('selectCustomer', $customer->id)
        ->call('processOrder')
        ->assertHasNoErrors()
        ->assertSet('step', 'catalog');

    expect(Order::withoutGlobalScopes()->sole()->fiscal_recipient['document'])->toBe('11444777000242');
    Bus::assertNotDispatched(IssueFiscalNote::class);
});

test('CNPJ digitado busca os dados da empresa e emite NF-e com eles', function () {
    ['admin' => $admin, 'product' => $product, 'company' => $company] = fiscalPdvContext();
    Http::fake(['brasilapi.com.br/*' => Http::response(brasilApiPadaria())]);
    Bus::fake();
    $this->actingAs($admin);

    pdvCheckout($product)
        ->set('issueFiscalNote', true)
        ->set('fiscalDocument', '11.444.777/0001-61')
        ->assertSet('fiscalCompany.name', 'PADARIA CLIENTE LTDA')
        ->assertSet('fiscalCompany.street', 'AVENIDA PAULISTA')
        ->assertSet('fiscalCompany.ie_indicator', '9')
        ->assertSet('fiscalDocumentState', 'cnpj')
        ->assertSet('showFiscalCompanyForm', false)
        ->call('processOrder')
        ->assertHasNoErrors();

    $recipient = Order::withoutGlobalScopes()->sole()->fiscal_recipient;
    expect($recipient)->toMatchArray([
        'document' => '11444777000161', 'name' => 'PADARIA CLIENTE LTDA', 'city' => 'SAO PAULO', 'state' => 'SP',
        'zip' => '01310100', 'email' => 'fiscal@padaria.test', 'ie_indicator' => '9',
    ]);
    Bus::assertDispatched(IssueFiscalNote::class);
});

test('consulta do CNPJ falhando abre o formulário da empresa e a venda não sai incompleta', function () {
    ['admin' => $admin, 'product' => $product, 'company' => $company] = fiscalPdvContext();
    Http::fake(['brasilapi.com.br/*' => Http::response(['message' => 'CNPJ não encontrado'], 404)]);
    Bus::fake();
    $this->actingAs($admin);

    pdvCheckout($product)
        ->set('issueFiscalNote', true)
        ->set('fiscalDocument', '11.444.777/0001-61')
        ->assertSet('showFiscalCompanyForm', true)
        ->assertSet('fiscalDocumentState', 'cnpj_incomplete')
        ->set('showFiscalCompanyForm', false)
        ->call('processOrder')
        ->assertHasErrors(['fiscalCompany.name', 'fiscalCompany.street'])
        ->assertSet('showFiscalCompanyForm', true);

    expect(Order::withoutGlobalScopes()->count())->toBe(0);
});

test('marcar a emissão traz o CPF do cliente para conferência e o caixa pode remover', function () {
    ['admin' => $admin, 'product' => $product, 'company' => $company] = fiscalPdvContext();
    $customer = Customer::withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => 'Maria', 'phone' => '11988887777', 'tax_id' => '52998224725']);
    Bus::fake();
    $this->actingAs($admin);

    pdvCheckout($product)
        ->call('selectCustomer', $customer->id)
        ->assertSet('fiscalDocument', '')
        ->set('issueFiscalNote', true)
        ->assertSet('fiscalDocument', '529.982.247-25')
        ->call('clearFiscalDocument')
        ->call('processOrder')
        ->assertHasNoErrors();

    expect(Order::withoutGlobalScopes()->sole()->fiscal_recipient['document'])->toBe('');
});

test('salvar no cadastro grava os dados fiscais no cliente para as próximas compras', function () {
    ['admin' => $admin, 'product' => $product, 'company' => $company] = fiscalPdvContext();
    $customer = Customer::withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => 'Maria', 'phone' => '11988887777']);
    Bus::fake();
    $this->actingAs($admin);

    pdvCheckout($product)
        ->call('selectCustomer', $customer->id)
        ->set('issueFiscalNote', true)
        ->set('fiscalDocument', '529.982.247-25')
        ->set('rememberFiscalData', true)
        ->call('processOrder')
        ->assertHasNoErrors();

    expect($customer->fresh()->fiscal_recipient['document'])->toBe('52998224725');
});

test('fechar comanda sem cliente com CPF na nota grava o CPF no pedido', function () {
    ['admin' => $admin, 'product' => $product, 'company' => $company, 'branch' => $branch] = fiscalPdvContext();
    $table = openTable($company, $branch);
    $this->actingAs($admin);

    $component = Livewire::test(TabTerminal::class)
        ->set('selectedTableId', $table->id)
        ->call('addProduct', $product->id);

    $orderId = Order::withoutGlobalScopes()->sole()->id;
    Bus::fake();

    $component->call('proceedToCloseTab', $orderId)
        ->set('paymentMethod', 'cash')
        ->set('cashReceivedInput', '10.00')
        ->set('issueFiscalNote', true)
        ->set('fiscalDocument', '529.982.247-25')
        ->call('confirmCloseTab')
        ->assertHasNoErrors();

    expect(Order::withoutGlobalScopes()->find($orderId)->fiscal_recipient['document'])->toBe('52998224725');
    Bus::assertDispatched(IssueFiscalNote::class, fn ($job) => $job->orderId === $orderId);
});

test('consulta pública do CNPJ normaliza os campos e usa cache', function () {
    Http::fake(['brasilapi.com.br/*' => Http::response(brasilApiPadaria())]);

    $lookup = new BrasilApiCnpjLookup;
    $first = $lookup->find('11.444.777/0001-61');
    $lookup->find('11444777000161');

    expect($first)->toBe([
        'name' => 'PADARIA CLIENTE LTDA', 'email' => 'fiscal@padaria.test', 'street' => 'AVENIDA PAULISTA',
        'number' => '1000', 'complement' => 'ANDAR 2', 'neighborhood' => 'BELA VISTA', 'city' => 'SAO PAULO',
        'state' => 'SP', 'zip' => '01310100', 'status' => 'ATIVA',
    ]);
    Http::assertSentCount(1);
});

test('consulta pública do CNPJ devolve null quando a API falha', function () {
    Http::fake(['brasilapi.com.br/*' => Http::response([], 500)]);

    expect((new BrasilApiCnpjLookup)->find('11444777000161'))->toBeNull();
});
