<?php

use App\Exceptions\FiscalPreflightException;
use App\Models\Address;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyFiscalConfig;
use App\Models\Customer;
use App\Models\FiscalNote;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\Fiscal\FiscalDeliveryAddress;
use App\Services\Fiscal\FiscalEmissionPolicy;
use App\Services\Fiscal\FiscalNoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * NFC-e de entrega a domicílio (presença 4). A SEFAZ rejeita sem o endereço do destinatário
 * (788): caso real do pedido iFood com CPF cujo endereço (sem UF) não ia para a nota.
 */
function homeDeliveryContext(array $address = ['line1' => 'Avenida Brasil', 'number' => '1200', 'neighborhood' => 'Novo Centro', 'city' => 'Maringá', 'cep' => '87013000'], array $order = []): array
{
    $company = Company::create([
        'name' => 'Coxinharia', 'slug' => 'coxinharia-'.uniqid(), 'order_prefix' => 'CXA',
        'active' => true, 'status' => 'ACTIVE', 'owner_cpf_cnpj' => '12345678000100', 'fiscal_notes_enabled' => true,
    ]);
    config(['fiscal.focus_nfe.token' => 'token_test']);
    app()->instance('current.company', $company);

    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'name' => 'Maringá', 'address' => 'Rua A', 'number' => '100',
        'neighborhood' => 'Centro', 'city' => 'Maringa', 'state' => 'PR', 'cep' => '87010000',
        'active' => true, 'opens_at' => '00:00:00', 'closes_at' => '23:59:59',
    ]);
    CompanyFiscalConfig::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'is_default' => true, 'enabled' => true]);

    $customer = Customer::withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => 'Cliente iFood', 'phone' => '44999990001']);
    $record = $address === [] ? null : Address::create($address);

    $orderModel = Order::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id,
        'subtotal' => 30.00, 'delivery_fee' => 0, 'total' => 30.00, 'fee' => 0, 'net_value' => 30.00,
        'status' => 'paid', 'payment_method' => 'ifood', 'channel' => 'ifood', 'order_type' => 'delivery',
        'delivery_address_id' => $record?->id,
        'fiscal_recipient' => ['document' => '52998224725'],
        ...$order,
    ]);

    $category = ProductCategory::withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => 'Salgados']);
    $product = Product::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'product_category_id' => $category->id, 'name' => 'Coxinha', 'price' => 10.00,
        'active' => true, 'fiscal_data' => ['ncm' => '21069090', 'cfop' => '5102', 'icms_situation' => '400'],
    ]);
    OrderItem::create(['order_id' => $orderModel->id, 'product_id' => $product->id, 'product_name' => 'Coxinha', 'unit_price' => 10.00, 'quantity' => 3, 'subtotal' => 30.00]);

    return ['company' => $company, 'branch' => $branch, 'order' => $orderModel];
}

test('NFC-e de entrega leva o endereço da entrega com a UF da filial quando o pedido não guarda a UF', function () {
    ['order' => $order] = homeDeliveryContext();
    Http::preventStrayRequests();
    Http::fake(['*focusnfe*' => Http::response(['status' => 'autorizado', 'chave_nfe' => str_repeat('1', 44)], 200)]);

    $note = app(FiscalNoteService::class)->issue($order);

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        $data = $request->data();

        return str_contains($request->url(), '/v2/nfce?')
            && $data['presenca_comprador'] === '4'
            && $data['cpf_destinatario'] === '52998224725'
            && $data['logradouro_destinatario'] === 'Avenida Brasil'
            && $data['numero_destinatario'] === '1200'
            && $data['bairro_destinatario'] === 'Novo Centro'
            && $data['municipio_destinatario'] === 'Maringá'
            && $data['uf_destinatario'] === 'PR'
            && $data['cep_destinatario'] === '87013000'
            && $data['indicador_inscricao_estadual_destinatario'] === 9;
    });
    expect($note->model)->toBe('65')
        ->and($note->status)->toBe('authorized')
        ->and($note->data['delivery_address']['state'])->toBe('PR');
});

test('UF e bairro que faltam vêm da consulta do CEP quando a cidade não é a da filial', function () {
    ['order' => $order] = homeDeliveryContext(['line1' => 'Rua Pioneiro', 'number' => '', 'neighborhood' => '', 'city' => 'Sarandi', 'cep' => '87111-000']);
    Http::fake([
        'viacep.com.br/*' => Http::response(['cep' => '87111-000', 'logradouro' => 'Rua do CEP', 'bairro' => 'Jardim Sarandi', 'localidade' => 'Sarandi', 'uf' => 'PR']),
        '*focusnfe*' => Http::response(['status' => 'autorizado', 'chave_nfe' => str_repeat('1', 44)], 200),
    ]);

    app(FiscalNoteService::class)->issue($order);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v2/nfce?')
        && $request['logradouro_destinatario'] === 'Rua Pioneiro' // o do pedido não é trocado pelo do CEP
        && $request['numero_destinatario'] === 'S/N'
        && $request['bairro_destinatario'] === 'Jardim Sarandi'
        && $request['uf_destinatario'] === 'PR'
        && $request['cep_destinatario'] === '87111000');
});

test('entrega sem endereço utilizável barra a NFC-e antes de criar nota ou chamar a SEFAZ', function () {
    ['order' => $order] = homeDeliveryContext(['line1' => 'Rua Sem Bairro', 'number' => '10', 'city' => 'Sarandi']);
    Http::fake();

    $check = app(FiscalNoteService::class)->check($order);

    expect($check['decision']->model)->toBe('65')
        ->and($check['issues'])->toHaveCount(1)
        ->and($check['issues'][0]['group'])->toBe('order')
        ->and($check['issues'][0]['message'])->toContain('bairro', 'UF');
    expect(fn () => app(FiscalNoteService::class)->issue($order))->toThrow(FiscalPreflightException::class);
    expect(FiscalNote::count())->toBe(0);
    Http::assertNothingSent();
});

test('endereço do destinatário nos dados fiscais vale quando o da entrega está incompleto', function () {
    ['order' => $order] = homeDeliveryContext(['line1' => 'Rua Sem Bairro', 'city' => 'Sarandi']);
    $order->update(['fiscal_recipient' => [
        'document' => '52998224725', 'name' => 'Maria da Silva', 'street' => 'Rua Fiscal', 'number' => '9',
        'neighborhood' => 'Zona 7', 'city' => 'Maringá', 'state' => 'PR', 'zip' => '87020000',
    ]]);
    Http::preventStrayRequests();
    Http::fake(['*focusnfe*' => Http::response(['status' => 'autorizado', 'chave_nfe' => str_repeat('1', 44)], 200)]);

    app(FiscalNoteService::class)->issue($order);

    Http::assertSent(fn ($request) => $request['logradouro_destinatario'] === 'Rua Fiscal'
        && $request['bairro_destinatario'] === 'Zona 7'
        && $request['uf_destinatario'] === 'PR');
});

test('entrega em outra UF não sai em NFC-e', function () {
    ['order' => $order] = homeDeliveryContext(['line1' => 'Rua X', 'number' => '1', 'neighborhood' => 'Centro', 'city' => 'Ourinhos', 'cep' => '19900000']);
    Http::fake(['viacep.com.br/*' => Http::response(['cep' => '19900-000', 'localidade' => 'Ourinhos', 'uf' => 'SP'])]);

    $issues = app(FiscalNoteService::class)->check($order)['issues'];

    expect($issues[0]['group'])->toBe('order')->and($issues[0]['message'])->toContain('outra UF (SP)');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'focusnfe'));
});

test('NFC-e de entrega sem CPF ou CNPJ pede o documento', function () {
    ['order' => $order] = homeDeliveryContext(order: ['fiscal_recipient' => ['document' => '']]);
    Http::fake();

    $check = app(FiscalNoteService::class)->check($order);

    expect($check['decision']->model)->toBeNull()
        ->and($check['decision']->needs)->toBe(['document'])
        ->and($check['issues'][0]['message'])->toContain('entrega a domicílio exige o CPF ou CNPJ');
    Http::assertNothingSent();
});

test('retirada e balcão seguem sem endereço do destinatário na NFC-e', function (string $orderType) {
    ['order' => $order] = homeDeliveryContext([], ['order_type' => $orderType, 'channel' => 'chat', 'payment_method' => 'PIX', 'fiscal_recipient' => ['document' => '']]);
    Http::preventStrayRequests();
    Http::fake(['*focusnfe*' => Http::response(['status' => 'autorizado', 'chave_nfe' => str_repeat('1', 44)], 200)]);

    $note = app(FiscalNoteService::class)->issue($order);

    Http::assertSent(fn ($request) => $request['presenca_comprador'] === '1'
        && ! array_key_exists('logradouro_destinatario', $request->data())
        && ! array_key_exists('cpf_destinatario', $request->data()));
    expect($note->data['delivery_address'])->toBeNull();
})->with(['pickup', 'pdv']);

test('política: sem documento, só a entrega a domicílio exige CPF ou CNPJ na NFC-e', function () {
    $policy = app(FiscalEmissionPolicy::class);
    $decide = fn (bool $homeDelivery) => $policy->decide(null, 'PR', ['document' => ''], 30.0, false, null, null, homeDelivery: $homeDelivery);

    expect($decide(true)->model)->toBeNull()
        ->and($decide(true)->needs)->toBe(['document'])
        ->and($decide(false)->model)->toBe('65');
});

test('UF deduzida da filial ignora acento e caixa no nome da cidade', function () {
    ['order' => $order, 'branch' => $branch] = homeDeliveryContext(['line1' => 'Rua Y', 'number' => '2', 'neighborhood' => 'Zona 1', 'city' => 'MARINGÁ']);
    Http::preventStrayRequests();

    $resolved = app(FiscalDeliveryAddress::class)->resolve($order, [], [$branch]);

    expect($resolved['missing'])->toBe([])->and($resolved['address']['state'])->toBe('PR');
});

test('UF gravada por extenso não vai para a nota: vale a sigla da filial', function () {
    ['order' => $order, 'branch' => $branch] = homeDeliveryContext(['line1' => 'Rua Y', 'number' => '2', 'neighborhood' => 'Zona 1', 'city' => 'Maringá', 'state' => 'Paraná']);
    Http::preventStrayRequests();

    expect(app(FiscalDeliveryAddress::class)->resolve($order, [], [$branch])['address']['state'])->toBe('PR');
});
