<?php

use App\Events\OrderStatusUpdated;
use App\Jobs\IssueFiscalNote;
use App\Livewire\Admin\Orders\Show;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyFiscalConfig;
use App\Models\Customer;
use App\Models\FiscalNote;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\Fiscal\FiscalEventService;
use App\Services\Fiscal\FiscalNoteService;
use App\Services\Fiscal\FiscalRecipient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function nfeContext(): array
{
    $company = Company::create(['name' => 'Emitente', 'slug' => 'nfe-'.uniqid(), 'order_prefix' => 'NFE', 'active' => true, 'status' => 'ACTIVE', 'fiscal_notes_enabled' => true, 'owner_cpf_cnpj' => '11222333000181']);
    app()->instance('current.company', $company);
    config(['fiscal.focus_nfe.token' => 'nfe-token', 'fiscal.focus_nfe.webhook_token' => 'nfe-secret']);
    $branch = Branch::create(['company_id' => $company->id, 'name' => 'Matriz', 'cnpj' => '11222333000181', 'address' => 'Rua A', 'number' => '1', 'neighborhood' => 'Centro', 'city' => 'São Paulo', 'state' => 'SP', 'cep' => '01001000', 'active' => true, 'opens_at' => '00:00:00', 'closes_at' => '23:59:59']);
    $config = CompanyFiscalConfig::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'enabled' => true, 'is_default' => true, 'nfe_enabled' => true, 'nfe_serie' => 3, 'environment' => 'homologacao', 'crt' => 1]);
    $recipient = ['document' => '11222333000181', 'name' => 'Destinatário Ltda', 'email' => 'fiscal@example.com', 'street' => 'Rua B', 'number' => '2', 'neighborhood' => 'Centro', 'city' => 'São Paulo', 'state' => 'SP', 'zip' => '01001000', 'ie_indicator' => '9', 'ie' => ''];
    $customer = Customer::create(['company_id' => $company->id, 'name' => 'Contato', 'phone' => '11999990000', 'fiscal_recipient' => $recipient]);
    $order = Order::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'branch_id' => $branch->id, 'subtotal' => 30, 'total' => 30, 'delivery_fee' => 0, 'fee' => 0, 'net_value' => 30, 'status' => 'paid', 'payment_method' => 'pix', 'order_type' => 'delivery']);
    $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'Alimentos']);
    $product = Product::create(['company_id' => $company->id, 'product_category_id' => $category->id, 'name' => 'Produto', 'price' => 10, 'active' => true, 'fiscal_data' => ['ncm' => '21069090', 'cfop' => '5102', 'interstate_cfop' => '6102', 'icms_situation' => '400', 'origin' => 0, 'pis_situation' => '07', 'cofins_situation' => '07']]);
    OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'product_name' => 'Produto', 'unit_price' => 10, 'quantity' => 3, 'subtotal' => 30]);
    Http::preventStrayRequests();

    return compact('company', 'branch', 'config', 'recipient', 'customer', 'order', 'product');
}

test('CNPJ seleciona NF-e com série própria e referência persistida antes do envio', function () {
    ['order' => $order] = nfeContext();
    Http::fake(function ($request) use ($order) {
        $note = FiscalNote::where('order_id', $order->id)->firstOrFail();
        expect($request->url())->toContain('/v2/nfe?ref='.$note->provider_reference);
        expect($request['cnpj_destinatario'])->toBe('11222333000181');
        expect($request['serie'])->toBe(3);
        expect($request['tipo_documento'])->toBe(1);
        expect($request['presenca_comprador'])->toBe('2');
        expect($request['nome_destinatario'])->toContain('SEM VALOR FISCAL');
        expect($request['items'][0]['icms_situacao_tributaria'])->toBe('400');

        return Http::response(['status' => 'autorizado', 'chave_nfe' => str_repeat('1', 44), 'numero' => '12', 'protocolo' => '123'], 201);
    });
    $note = app(FiscalNoteService::class)->issue($order);
    expect($note->model)->toBe('55')->and($note->status)->toBe('authorized')->and($note->number)->toBe('12')->and($note->protocol)->toBe('123');
});

test('CPF e documento ausente selecionam NFC-e', function (?string $document) {
    ['order' => $order] = nfeContext();
    $order->update(['fiscal_recipient' => ['document' => $document ?? '']]);
    Http::fake(['*' => Http::response(['status' => 'autorizado'], 200)]);
    $note = app(FiscalNoteService::class)->issue($order);
    expect($note->model)->toBe('65');
    Http::assertSent(fn ($request) => str_contains($request->url(), '/v2/nfce?'));
})->with(['52998224725', null]);

test('CNPJ incompleto nunca faz fallback para NFC-e', function () {
    ['order' => $order] = nfeContext();
    $order->update(['fiscal_recipient' => ['document' => '11222333000181']]);
    Http::fake();
    expect(fn () => app(FiscalNoteService::class)->issue($order))->toThrow(RuntimeException::class);
    Http::assertNothingSent();
    expect(FiscalNote::count())->toBe(0);
});

test('CNPJ sempre emite NF-e, mesmo em configuração antiga gravada com NF-e desligada', function () {
    ['order' => $order, 'config' => $config] = nfeContext();
    $config->forceFill(['nfe_enabled' => false])->saveQuietly();
    Http::fake(['*' => Http::response(['status' => 'autorizado', 'chave_nfe' => str_repeat('1', 44)], 201)]);
    $note = app(FiscalNoteService::class)->issue($order);
    expect($note->model)->toBe('55');
    Http::assertSent(fn ($request) => str_contains($request->url(), '/v2/nfe?'));
});

test('configuração fiscal nova nasce com NF-e habilitada', function () {
    ['config' => $config] = nfeContext();
    $config->delete();
    $fresh = CompanyFiscalConfig::create(['company_id' => $config->company_id, 'branch_id' => $config->branch_id, 'enabled' => true]);
    expect($fresh->fresh()->nfe_enabled)->toBeTrue();
});

test('dados fiscais do pedido não mudam quando cadastro do cliente muda', function () {
    ['order' => $order, 'customer' => $customer] = nfeContext();
    $customer->update(['fiscal_recipient' => ['document' => '52998224725']]);
    expect($order->fresh()->fiscal_recipient['document'])->toBe('11222333000181');
});

test('nota rejeitada por produto sem classificação bloqueia envio antes da API', function () {
    ['order' => $order, 'product' => $product] = nfeContext();
    $product->update(['fiscal_data' => null]);
    Http::fake();
    expect(fn () => app(FiscalNoteService::class)->issue($order))->toThrow(RuntimeException::class, 'produto');
    Http::assertNothingSent();
});

test('operação interestadual envia CFOP específico e destino interestadual', function () {
    ['order' => $order, 'recipient' => $recipient] = nfeContext();
    $recipient['state'] = 'RJ';
    $recipient['city'] = 'Rio de Janeiro';
    $order->update(['fiscal_recipient' => $recipient]);
    Http::fake(['*' => Http::response(['status' => 'processando_autorizacao'], 202)]);
    app(FiscalNoteService::class)->issue($order);
    Http::assertSent(fn ($request) => $request['local_destino'] === '2' && $request['items'][0]['cfop'] === '6102');
});

test('rateio fiscal mantém soma exata de frete descontos despesas e total', function () {
    ['order' => $order] = nfeContext();
    $order->update(['delivery_fee' => 2.01, 'discount' => 1, 'manual_discount' => 0.01, 'service_fee' => 0.5, 'total' => 31.5]);
    Http::fake(['*' => Http::response(['status' => 'autorizado'], 201)]);
    app(FiscalNoteService::class)->issue($order);
    Http::assertSent(fn ($r) => $r['valor_total'] === 31.5 && $r['items'][0]['valor_frete'] === 2.01 && $r['items'][0]['valor_desconto'] === 1.01 && $r['items'][0]['valor_outras_despesas'] === 0.5);
});

test('timeout de emissão mantém nota pendente e impede duplicidade entre modelos', function () {
    ['order' => $order] = nfeContext();
    Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));
    $note = app(FiscalNoteService::class)->issue($order);
    expect($note->status)->toBe('pending')->and($note->provider_reference)->not->toBeNull();
    expect(fn () => app(FiscalNoteService::class)->issue($order, '52998224725'))->toThrow(\App\Exceptions\FiscalNoteAlreadyIssuedException::class);
    expect(FiscalNote::count())->toBe(1);
});

test('consulta usa modelo e ambiente originais após alteração da configuração', function () {
    ['order' => $order, 'config' => $config] = nfeContext();
    Http::fake(['*' => Http::sequence()->push(['status' => 'processando_autorizacao'], 202)->push(['status' => 'autorizado'], 200)]);
    $note = app(FiscalNoteService::class)->issue($order);
    $config->update(['environment' => 'producao']);
    expect(app(FiscalNoteService::class)->reconcile($note)->status)->toBe('authorized');
    Http::assertSent(fn ($r) => str_contains($r->url(), 'homologacao.focusnfe.com.br/v2/nfe/'.$note->provider_reference));
});

test('erro de cancelamento preserva nota autorizada e registra evento', function () {
    ['order' => $order] = nfeContext();
    Http::fake(['*' => Http::response(['status' => 'autorizado'], 201)]);
    $note = app(FiscalNoteService::class)->issue($order);
    Http::fake(['*' => Http::response(['mensagem' => 'Fora do prazo'], 422)]);
    app(FiscalNoteService::class)->cancel($note, 'Cancelamento solicitado pelo cliente');
    expect($note->fresh()->status)->toBe('authorized')->and($note->events()->count())->toBe(1);
    Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), '/v2/nfe/'));
});

test('webhook antecipado não é sobrescrito pelo POST ainda em processamento', function () {
    ['order' => $order] = nfeContext();
    Http::fake(function ($request) {
        $note = FiscalNote::firstOrFail();
        $this->postJson(route('webhook.fiscal'), ['ref' => $note->provider_reference, 'status' => 'autorizado', 'chave_nfe' => str_repeat('2', 44)], ['x-focus-nfe-token' => 'nfe-secret'])->assertOk();

        return Http::response(['status' => 'processando_autorizacao'], 202);
    });
    $note = app(FiscalNoteService::class)->issue($order);
    expect($note->status)->toBe('authorized')->and($note->access_key)->toBe(str_repeat('2', 44));
});

test('webhook atrasado não reautoriza nota cancelada', function () {
    ['order' => $order] = nfeContext();
    $note = FiscalNote::create(['company_id' => $order->company_id, 'order_id' => $order->id, 'model' => '55', 'status' => 'cancelled', 'provider_reference' => 'cancelled-ref']);
    $this->postJson(route('webhook.fiscal'), ['ref' => 'cancelled-ref', 'status' => 'autorizado'], ['x-focus-nfe-token' => 'nfe-secret'])->assertOk();
    expect($note->fresh()->status)->toBe('cancelled');
});

test('pagamento não emite NF-e sozinho e a pré-checagem aceita o destinatário empresarial preservado', function () {
    ['order' => $order] = nfeContext();
    Queue::fake();
    event(new OrderStatusUpdated($order));
    Queue::assertNotPushed(IssueFiscalNote::class);
    expect(app(FiscalNoteService::class)->preflight($order->fresh()))->toBeNull()
        ->and($order->fresh()->fiscal_recipient['document'])->toBe('11222333000181');
});

test('falha de dados fiscais fica pendente no pedido sem alterar nota em processamento', function () {
    ['order' => $order] = nfeContext();
    $note = FiscalNote::create(['company_id' => $order->company_id, 'order_id' => $order->id, 'status' => 'pending', 'model' => '55']);
    (new IssueFiscalNote($order->id))->failed(new RuntimeException('Dados fiscais pendentes'));
    expect($order->fresh()->fiscal_error)->toBe('Dados fiscais pendentes')->and($note->fresh()->status)->toBe('pending');
});

test('carta de correção e inutilização usam endpoints de NF-e e mantêm histórico', function () {
    ['order' => $order, 'config' => $config] = nfeContext();
    Http::fake(['*' => Http::response(['status' => 'autorizado'], 200)]);
    $note = app(FiscalNoteService::class)->issue($order);
    $service = app(FiscalEventService::class);
    $correction = $service->correction($note, 'Correção de informação adicional permitida');
    $invalidation = $service->invalidate($config, ['serie' => 3, 'numero_inicial' => 50, 'numero_final' => 51, 'justificativa' => 'Faixa não utilizada pelo estabelecimento']);
    expect($correction->status)->toBe('authorized')->and($invalidation->status)->toBe('authorized');
    Http::assertSent(fn ($r) => str_contains($r->url(), '/carta_correcao'));
    Http::assertSent(fn ($r) => str_contains($r->url(), '/nfe/inutilizacao') && $r['cnpj'] === '11222333000181');
    expect(fn () => $service->invalidate($config, ['serie' => 3, 'numero_inicial' => 51, 'numero_final' => 52, 'justificativa' => 'Faixa não utilizada pelo estabelecimento']))->toThrow(RuntimeException::class, 'faixa');
});

test('pedido exibe NF-e e permite salvar destinatário para próximas compras', function () {
    ['company' => $company, 'order' => $order, 'customer' => $customer, 'recipient' => $recipient] = nfeContext();
    $user = User::factory()->create();
    $user->companies()->attach($company->id, ['role' => 'company_admin']);
    $permission = \App\Models\Permission::firstOrCreate(['name' => 'fiscal.issue'], ['group' => 'fiscal', 'label' => 'Emitir notas']);
    \App\Models\UserPermission::create(['user_id' => $user->id, 'company_id' => $company->id, 'permission_id' => $permission->id, 'granted' => true]);
    Livewire::actingAs($user)->test(Show::class, ['order' => $order])
        ->assertSee('Nota Fiscal (NF-e)')->assertSee('Emitir NF-e')
        ->call('openFiscalRecipient')->set('fiscalRecipientDraft.name', 'Empresa Atualizada')
        ->set('rememberFiscalRecipient', true)->call('saveFiscalRecipient')->assertHasNoErrors();
    expect($customer->fresh()->fiscal_recipient['name'])->toBe('Empresa Atualizada');
    expect($order->fresh()->fiscal_recipient['name'])->toBe('Empresa Atualizada');
});

test('destinatário não pode ser alterado após emissão ativa', function () {
    ['company' => $company, 'order' => $order] = nfeContext();
    $user = User::factory()->create();
    $user->companies()->attach($company->id, ['role' => 'company_admin']);
    $permission = \App\Models\Permission::firstOrCreate(['name' => 'fiscal.issue'], ['group' => 'fiscal', 'label' => 'Emitir notas']);
    \App\Models\UserPermission::create(['user_id' => $user->id, 'company_id' => $company->id, 'permission_id' => $permission->id, 'granted' => true]);
    FiscalNote::create(['company_id' => $company->id, 'order_id' => $order->id, 'status' => 'authorized']);
    Livewire::actingAs($user)->test(Show::class, ['order' => $order])->call('openFiscalRecipient')->call('saveFiscalRecipient')->assertHasErrors('fiscalRecipientDraft.document');
});

test('validação rejeita CPF e CNPJ com dígitos verificadores incorretos', function (string $document) {
    nfeContext();
    expect(fn () => FiscalRecipient::validate(['document' => $document]))->toThrow(\Illuminate\Validation\ValidationException::class);
})->with(['11111111111', '52998224724', '11222333000182']);

test('documentos fiscais são armazenados em disco privado e reutilizados', function () {
    ['order' => $order] = nfeContext();
    \Illuminate\Support\Facades\Storage::fake('local');
    $note = FiscalNote::create(['company_id' => $order->company_id, 'order_id' => $order->id, 'status' => 'authorized', 'model' => '55', 'environment' => 'homologacao', 'data' => ['xml_url' => 'https://homologacao.focusnfe.com.br/xml/nfe.xml']]);
    Http::fake(['*' => Http::response('<?xml version="1.0"?><nfeProc/>', 200)]);
    $service = app(\App\Services\Fiscal\FiscalDocumentService::class);
    $path = $service->store($note, 'xml');
    \Illuminate\Support\Facades\Storage::disk('local')->assertExists($path);
    expect($service->store($note, 'xml'))->toBe($path);
    Http::assertSentCount(1);
});

test('documentos fiscais não enviam credenciais para hosts externos', function () {
    ['order' => $order] = nfeContext();
    \Illuminate\Support\Facades\Storage::fake('local');
    $note = FiscalNote::create(['company_id' => $order->company_id, 'order_id' => $order->id, 'status' => 'authorized', 'model' => '55', 'data' => ['xml_url' => 'https://external.example/steal']]);
    Http::fake();
    expect(fn () => app(\App\Services\Fiscal\FiscalDocumentService::class)->store($note, 'xml'))->toThrow(RuntimeException::class, 'indisponível');
    Http::assertNothingSent();
});

test('consulta pode confirmar cancelamento de NF-e anteriormente autorizada', function () {
    ['order' => $order] = nfeContext();
    $note = FiscalNote::create(['company_id' => $order->company_id, 'order_id' => $order->id, 'status' => 'authorized', 'model' => '55', 'provider_reference' => 'cancel-ref']);
    Http::fake(['*' => Http::response(['status' => 'cancelado'], 200)]);
    expect(app(FiscalNoteService::class)->reconcile($note)->status)->toBe('cancelled');
});

test('consulta indisponível bloqueia nova emissão de tentativa legada ambígua', function () {
    ['order' => $order] = nfeContext();
    $note = FiscalNote::create(['company_id' => $order->company_id, 'order_id' => $order->id, 'status' => 'error', 'model' => '55', 'provider_reference' => 'timeout-ref']);
    Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));
    expect(app(FiscalNoteService::class)->reconcile($note)->status)->toBe('pending');
    expect(fn () => app(FiscalNoteService::class)->issue($order))->toThrow(\App\Exceptions\FiscalNoteAlreadyIssuedException::class);
});

test('dados fiscais de destinatário exigem IE quando contribuinte', function () {
    ['recipient' => $recipient] = nfeContext();
    $recipient['ie_indicator'] = '1';
    expect(fn () => FiscalRecipient::validate($recipient))->toThrow(\Illuminate\Validation\ValidationException::class);
    $recipient['ie'] = '123456789';
    expect(FiscalRecipient::validate($recipient)['ie'])->toBe('123456789');
});

test('perfil fiscal de CRT incompatível impede NF-e antes do envio', function () {
    ['order' => $order, 'config' => $config] = nfeContext();
    $config->update(['crt' => 3]);
    Http::fake();
    expect(fn () => app(FiscalNoteService::class)->issue($order))->toThrow(RuntimeException::class, 'incompatível');
    Http::assertNothingSent();
});

test('formulário fiscal inválido mantém dados anteriores e apresenta campos pendentes', function () {
    ['company' => $company, 'order' => $order] = nfeContext();
    $user = User::factory()->create();
    $user->companies()->attach($company->id, ['role' => 'company_admin']);
    $permission = \App\Models\Permission::firstOrCreate(['name' => 'fiscal.issue'], ['group' => 'fiscal', 'label' => 'Emitir notas']);
    \App\Models\UserPermission::create(['user_id' => $user->id, 'company_id' => $company->id, 'permission_id' => $permission->id, 'granted' => true]);
    Livewire::actingAs($user)->test(Show::class, ['order' => $order])->call('openFiscalRecipient')->set('fiscalRecipientDraft.street', '')->call('saveFiscalRecipient')->assertHasErrors('fiscalRecipientDraft.street');
    expect($order->fresh()->fiscal_recipient['street'])->toBe('Rua B');
});

test('mensagem de autorização da SEFAZ não cria pendência fiscal no pedido', function () {
    ['order' => $order] = nfeContext();
    Http::fake(['*' => Http::response(['status' => 'autorizado', 'mensagem_sefaz' => 'Autorizado o uso da NF-e'], 201)]);
    app(FiscalNoteService::class)->issue($order, requestedBy: null);
    expect($order->fresh()->fiscal_error)->toBeNull();
});

test('reconciliação recupera inutilização pendente somente para emitente série e faixa exatos', function () {
    ['company' => $company, 'config' => $config] = nfeContext();
    $event = \App\Models\FiscalNoteEvent::create(['company_id' => $company->id, 'fiscal_config_id' => $config->id, 'type' => 'invalidation', 'status' => 'pending', 'data' => ['environment' => 'homologacao', 'request' => ['cnpj' => '11222333000181', 'serie' => 3, 'numero_inicial' => 50, 'numero_final' => 51]]]);
    Http::fake(['*' => Http::response([['cnpj' => '11222333000181', 'modelo' => '55', 'serie' => '3', 'numero_inicial' => '50', 'numero_final' => '51', 'status' => 'autorizado', 'protocolo_sefaz' => '123']], 200)]);
    expect(app(FiscalEventService::class)->reconcile($event)->status)->toBe('authorized');
});

test('reconciliação de carta pendente exige correspondência do texto e XML da correção', function () {
    ['company' => $company, 'config' => $config, 'order' => $order] = nfeContext();
    $note = FiscalNote::create(['company_id' => $company->id, 'order_id' => $order->id, 'status' => 'authorized', 'model' => '55', 'provider_reference' => 'correction-ref']);
    $text = 'Correção de informação adicional permitida';
    $event = \App\Models\FiscalNoteEvent::create(['company_id' => $company->id, 'fiscal_config_id' => $config->id, 'fiscal_note_id' => $note->id, 'type' => 'correction', 'status' => 'pending', 'data' => ['environment' => 'homologacao', 'request' => ['correcao' => $text]]]);
    Http::fake(['*' => Http::response(['status' => 'autorizado', 'requisicao_carta_correcao' => ['correcao' => $text], 'caminho_xml_carta_correcao' => '/correction.xml'], 200)]);
    expect(app(FiscalEventService::class)->reconcile($event)->status)->toBe('authorized');
});

test('NF-e representa pagamentos divididos pelos valores efetivamente registrados', function () {
    ['order' => $order] = nfeContext();
    $order->update(['payment_method' => 'split']);
    $order->payments()->create(['payment_gateway' => 'cash', 'amount' => 10, 'status' => 'paid']);
    $order->payments()->create(['payment_gateway' => 'pix_manual', 'amount' => 20, 'status' => 'paid']);
    Http::fake(['*' => Http::response(['status' => 'autorizado'], 201)]);
    app(FiscalNoteService::class)->issue($order);
    Http::assertSent(fn ($r) => count($r['formas_pagamento']) === 2 && $r['formas_pagamento'][0]['forma_pagamento'] === '01' && $r['formas_pagamento'][1]['forma_pagamento'] === '17' && (float) $r['formas_pagamento'][1]['valor_pagamento'] === 20.0);
});
