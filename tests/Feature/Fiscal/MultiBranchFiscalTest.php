<?php

use App\Livewire\Admin\Fiscal\Config;
use App\Livewire\Admin\Fiscal\Notes;
use App\Livewire\Admin\Products\Form;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyFiscalConfig;
use App\Models\Customer;
use App\Models\FiscalNote;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Models\UserPermission;
use App\Services\Fiscal\FiscalConfigResolver;
use App\Services\Fiscal\FiscalDocumentService;
use App\Services\Fiscal\FiscalEventService;
use App\Services\Fiscal\FiscalNoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function multiBranchFiscalFake(mixed $response = null): void
{
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::preventStrayRequests();
    Http::fake(['*' => $response ?? Http::response(['status' => 'autorizado', 'numero' => '1'], 201)]);
}

function multiBranchFiscalContext(): array
{
    multiBranchFiscalFake();
    $company = Company::create(['name' => 'Grupo', 'slug' => 'multi-fiscal-'.uniqid(), 'order_prefix' => 'MBF', 'active' => true, 'status' => 'ACTIVE', 'plan' => 'pro', 'fiscal_notes_enabled' => true, 'owner_cpf_cnpj' => '11222333000181']);
    app()->instance('current.company', $company);
    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);
    foreach (['fiscal.settings', 'fiscal.view', 'fiscal.issue', 'products.update', 'products.view', 'orders.view', 'pdv.operate'] as $name) {
        $permission = Permission::firstOrCreate(['name' => $name], ['group' => explode('.', $name)[0], 'label' => $name]);
        UserPermission::create(['user_id' => $admin->id, 'company_id' => $company->id, 'permission_id' => $permission->id, 'granted' => true]);
    }
    $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'Produtos', 'active' => true]);
    $base = ['ncm' => '21069090', 'cfop' => '5102', 'interstate_cfop' => '6102', 'icms_situation' => '400', 'origin' => 0, 'unit' => 'UN', 'pis_situation' => '07', 'cofins_situation' => '07', 'taxes' => []];
    $normal = array_replace($base, ['icms_situation' => '00', 'origin' => 2, 'pis_situation' => '01', 'cofins_situation' => '01', 'taxes' => ['icms_aliquota' => 18, 'pis_aliquota_porcentual' => 1.65]]);
    $product = Product::create(['company_id' => $company->id, 'product_category_id' => $category->id, 'name' => 'Produto compartilhado', 'price' => 30, 'active' => true, 'fiscal_data' => $base])->fresh();
    $recipient = ['document' => '11222333000181', 'name' => 'Cliente Ltda', 'street' => 'Rua Cliente', 'number' => '9', 'neighborhood' => 'Centro', 'city' => 'São Paulo', 'state' => 'SP', 'zip' => '01001000', 'ie_indicator' => '9'];
    $customer = Customer::create(['company_id' => $company->id, 'name' => 'Cliente', 'phone' => '11999990001']);
    $branches = $configs = $orders = [];
    foreach (['11222333000181', '11444777000161'] as $index => $cnpj) {
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Filial '.($index + 1), 'cnpj' => $cnpj, 'address' => 'Rua '.($index + 1), 'number' => '1', 'neighborhood' => 'Centro', 'city' => 'São Paulo', 'state' => 'SP', 'cep' => '01001000', 'active' => true, 'opens_at' => '00:00:00', 'closes_at' => '23:59:59']);
        $config = CompanyFiscalConfig::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'issuer_cnpj' => $cnpj, 'legal_name' => 'Emitente '.($index + 1).' Ltda', 'enabled' => true, 'is_default' => $index === 0, 'crt' => $index === 0 ? 1 : 3, 'nfce_serie' => $index + 1, 'nfe_serie' => $index + 3, 'environment' => 'homologacao', 'focus_nfe_company_id' => 'focus-'.$index, 'token_homologacao' => 'hom-'.$index, 'token_producao' => 'prod-'.$index, 'webhook_token' => 'hook-'.$index, 'inscricao_estadual' => '123456789', 'csc_nfce_producao' => 'csc-'.$index, 'id_token_nfce_producao' => '000001']);
        $order = Order::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'subtotal' => 30, 'total' => 30, 'delivery_fee' => 0, 'fee' => 0, 'net_value' => 30, 'status' => 'paid', 'payment_method' => 'pix', 'order_type' => 'pickup', 'fiscal_recipient' => $recipient]);
        OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'product_name' => $product->name, 'quantity' => 1, 'unit_price' => 30, 'subtotal' => 30]);
        $product->branches()->attach($branch->id, ['available' => true, 'fiscal_data' => $index === 1 ? json_encode($normal) : null]);
        $branches[] = $branch;
        $configs[] = $config;
        $orders[] = $order;
    }

    return compact('company', 'admin', 'product', 'branches', 'configs', 'orders', 'base', 'normal');
}

test('NF-e e NFC-e isolam emitente série token e tributação do mesmo produto entre filiais', function (string $model) {
    $ctx = multiBranchFiscalContext();
    foreach ($ctx['orders'] as $index => $order) {
        if ($model === '65') {
            $order->update(['fiscal_recipient' => ['document' => '']]);
        }
        $note = app(FiscalNoteService::class)->issue($order);
        expect($note->model)->toBe($model)->and($note->branch_id)->toBe($ctx['branches'][$index]->id)
            ->and($note->issuer_cnpj)->toBe($ctx['branches'][$index]->cnpj)
            ->and($note->fiscal_config_id)->toBe($ctx['configs'][$index]->id);
        Http::assertSent(fn ($request) => str_contains($request->url(), $model === '55' ? '/v2/nfe?' : '/v2/nfce?')
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('hom-'.$index.':'))
            && $request['cnpj_emitente'] === $ctx['branches'][$index]->cnpj
            && $request['serie'] === ($model === '55' ? $index + 3 : $index + 1)
            && $request['items'][0]['icms_situacao_tributaria'] === ($index === 0 ? '400' : '00')
            && $request['items'][0]['icms_origem'] === ($index === 0 ? 0 : 2)
            && ($index === 0 || $request['items'][0]['icms_aliquota'] === 18));
    }
    expect($ctx['product']->fresh()->fiscal_data)->toBe($ctx['base']);
    Http::assertSentCount(2);
})->with(['55', '65']);

test('filial sem configuração não emite pelo CNPJ padrão', function () {
    ['configs' => $configs, 'orders' => $orders] = multiBranchFiscalContext();
    $configs[1]->delete();
    expect(app(FiscalNoteService::class)->preflight($orders[1]))->toContain('Configure o emitente desta filial');
    expect(fn () => app(FiscalNoteService::class)->issue($orders[1]))->toThrow(RuntimeException::class, 'configuração fiscal');
    Http::assertNothingSent();
    expect(FiscalNote::count())->toBe(0);
});

test('perfil incompatível com CRT bloqueia os dois modelos antes de qualquer envio', function (string $document) {
    ['product' => $product, 'branches' => $branches, 'orders' => $orders] = multiBranchFiscalContext();
    $product->branches()->updateExistingPivot($branches[1]->id, ['fiscal_data' => null]);
    if ($document === '') {
        $orders[1]->update(['fiscal_recipient' => ['document' => '']]);
    }
    expect(app(FiscalNoteService::class)->preflight($orders[1]))->toContain('incompatível');
    Http::assertNothingSent();
})->with(['11222333000181', '']);

test('produção permite homologar uma filial enquanto outra emite em produção', function () {
    ['configs' => $configs, 'orders' => $orders] = multiBranchFiscalContext();
    app()->instance('env', 'production');
    $configs[0]->update(['emission_environment' => 'producao']);
    $configs[1]->update(['emission_environment' => 'homologacao']);
    foreach ($orders as $index => $order) {
        $note = app(FiscalNoteService::class)->issue($order);
        expect($note->environment)->toBe($index === 0 ? 'producao' : 'homologacao');
        Http::assertSent(fn ($request) => str_starts_with($request->url(), $index === 0 ? 'https://api.focusnfe.com.br/' : 'https://homologacao.focusnfe.com.br/')
            && $request->hasHeader('Authorization', 'Basic '.base64_encode(($index === 0 ? 'prod-0' : 'hom-1').':')));
    }
});

test('ambiente de desenvolvimento impede produção mesmo com seleção explícita na filial', function () {
    ['configs' => $configs, 'orders' => $orders] = multiBranchFiscalContext();
    $configs[0]->update(['emission_environment' => 'producao']);
    $note = app(FiscalNoteService::class)->issue($orders[0]);
    expect($note->environment)->toBe('homologacao');
    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://homologacao.focusnfe.com.br/'));
});

test('consulta cancelamento e documentos preservam filial e ambiente originais após mudar o padrão', function () {
    ['configs' => $configs, 'orders' => $orders] = multiBranchFiscalContext();
    app()->instance('env', 'production');
    $configs[0]->update(['emission_environment' => 'homologacao']);
    $note = app(FiscalNoteService::class)->issue($orders[0]);
    $configs[0]->update(['is_default' => false, 'emission_environment' => 'producao', 'environment' => 'producao']);
    $configs[1]->update(['is_default' => true]);
    multiBranchFiscalFake(Http::response(['status' => 'autorizado'], 200));
    app(FiscalNoteService::class)->reconcile($note);
    Http::assertSent(fn ($request) => $request->method() === 'GET'
        && str_starts_with($request->url(), 'https://homologacao.focusnfe.com.br/v2/nfe/')
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('hom-0:')));
    Storage::fake('local');
    $note->update(['data' => ['xml_url' => 'https://homologacao.focusnfe.com.br/original.xml']]);
    multiBranchFiscalFake(Http::response('<xml>original</xml>'));
    app(FiscalDocumentService::class)->store($note, 'xml');
    Http::assertSent(fn ($request) => $request->url() === 'https://homologacao.focusnfe.com.br/original.xml'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('hom-0:')));
    multiBranchFiscalFake(Http::response(['status' => 'cancelado'], 200));
    app(FiscalNoteService::class)->cancel($note, 'Cancelamento solicitado em teste');
    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_starts_with($request->url(), 'https://homologacao.focusnfe.com.br/v2/nfe/')
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('hom-0:')));
    expect($note->fresh()->status)->toBe('cancelled');
});

test('nota legada com CNPJ preservado resolve o emitente original sem usar o padrão atual', function () {
    ['configs' => $configs, 'orders' => $orders] = multiBranchFiscalContext();
    $note = FiscalNote::create(['company_id' => $orders[1]->company_id, 'order_id' => $orders[1]->id, 'branch_id' => $orders[1]->branch_id, 'issuer_cnpj' => $configs[0]->issuer_cnpj, 'status' => 'authorized']);
    $configs[0]->update(['is_default' => false]);
    $configs[1]->update(['is_default' => true]);
    expect(app(FiscalConfigResolver::class)->forNote($note)->id)->toBe($configs[0]->id);
});

test('CNPJ divergente do emitente salvo impede emissão e operação sobre a nota', function () {
    ['configs' => $configs, 'orders' => $orders, 'branches' => $branches] = multiBranchFiscalContext();
    $branches[0]->update(['cnpj' => $branches[1]->cnpj]);
    expect(app(FiscalNoteService::class)->preflight($orders[0]))->toContain('diverge');
    $note = FiscalNote::create(['company_id' => $orders[0]->company_id, 'fiscal_config_id' => $configs[0]->id, 'issuer_cnpj' => $branches[1]->cnpj, 'provider_reference' => 'mismatch', 'status' => 'authorized']);
    expect(fn () => app(FiscalNoteService::class)->cancel($note, 'Cancelamento de teste divergente'))->toThrow(RuntimeException::class, 'emitente original');
    Http::assertNothingSent();
});

test('cadastro envia razão social e configurações independentes e limpa segredos ao trocar filial', function () {
    ['admin' => $admin, 'branches' => $branches, 'configs' => $configs] = multiBranchFiscalContext();
    multiBranchFiscalFake(Http::response(['id' => 'focus-1'], 200));
    $form = Livewire::actingAs($admin)->test(Config::class)
        ->assertSet('legalName', 'Emitente 1 Ltda')
        ->set('providerToken', 'rascunho-secreto')->set('certificatePassword', 'rascunho-senha')->set('cscNfceProducao', 'rascunho-csc')
        ->set('branchId', $branches[1]->id)
        ->assertSet('providerToken', '')->assertSet('certificatePassword', '')->assertSet('cscNfceProducao', '')
        ->assertSet('legalName', 'Emitente 2 Ltda')
        ->set('legalName', 'Filial Dois Ltda')->set('emissionEnvironment', 'homologacao')
        ->call('save')->assertHasNoErrors();
    Http::assertSent(fn ($request) => $request->method() === 'PUT' && $request['cnpj'] === $branches[1]->cnpj
        && $request['nome'] === 'Filial Dois Ltda' && $request['regime_tributario'] === 3);
    expect($configs[0]->fresh()->legal_name)->toBe('Emitente 1 Ltda')
        ->and($configs[1]->fresh()->legal_name)->toBe('Filial Dois Ltda');
});

test('adulterar indicador público não permite trocar CNPJ já registrado', function () {
    ['admin' => $admin, 'branches' => $branches, 'configs' => $configs] = multiBranchFiscalContext();
    Livewire::actingAs($admin)->test(Config::class)->set('hasBranchCnpj', false)
        ->set('branchCnpj', $branches[1]->cnpj)->call('save')->assertHasErrors('branchCnpj');
    expect($branches[0]->fresh()->cnpj)->toBe($configs[0]->issuer_cnpj);
    Http::assertNothingSent();
});

test('falha no cadastro remoto não altera o CNPJ local nem a configuração anterior', function () {
    ['admin' => $admin, 'branches' => $branches, 'configs' => $configs] = multiBranchFiscalContext();
    $configs[0]->update(['focus_nfe_company_id' => null]);
    multiBranchFiscalFake(Http::response(['mensagem' => 'Cadastro inválido'], 422));
    Livewire::actingAs($admin)->test(Config::class)->set('branchCnpj', '52997689000174')
        ->set('legalName', 'Não persistir')->call('save')->assertHasErrors('enabled');
    expect($branches[0]->fresh()->cnpj)->toBe('11222333000181')
        ->and($configs[0]->fresh()->legal_name)->toBe('Emitente 1 Ltda');
});

test('formulário de produto persiste perfil próprio e permite voltar ao cadastro geral', function () {
    ['admin' => $admin, 'product' => $product, 'branches' => $branches, 'normal' => $normal] = multiBranchFiscalContext();
    $form = Livewire::actingAs($admin)->test(Form::class, ['product' => $product])
        ->assertSet('branchFiscalProfiles.'.$branches[1]->id.'.data.icms_situation', '00')
        ->set('branchFiscalProfiles.'.$branches[1]->id.'.data.cfop', '5101')->call('save')->assertHasNoErrors();
    $profile = json_decode(DB::table('branch_product')->where('product_id', $product->id)->where('branch_id', $branches[1]->id)->value('fiscal_data'), true);
    expect($profile['cfop'])->toBe('5101')->and($profile['taxes'])->toBe($normal['taxes']);
    Livewire::actingAs($admin)->test(Form::class, ['product' => $product->fresh()])
        ->set('branchFiscalProfiles.'.$branches[1]->id.'.enabled', false)->call('save')->assertHasNoErrors();
    expect(DB::table('branch_product')->where('product_id', $product->id)->where('branch_id', $branches[1]->id)->value('fiscal_data'))->toBeNull();
});

test('perfil de produto recusa sobrescrever CST por JSON adicional e campos inválidos', function () {
    ['admin' => $admin, 'product' => $product, 'branches' => $branches] = multiBranchFiscalContext();
    Livewire::actingAs($admin)->test(Form::class, ['product' => $product])
        ->set('branchFiscalProfiles.'.$branches[1]->id.'.taxes', '{"icms_situacao_tributaria":"400"}')
        ->call('save')->assertHasErrors('branchFiscalProfiles.'.$branches[1]->id.'.taxes');
    Livewire::actingAs($admin)->test(Form::class, ['product' => $product])
        ->set('branchFiscalProfiles.'.$branches[1]->id.'.data.ncm', '123')
        ->call('save')->assertHasErrors('branchFiscalProfiles.'.$branches[1]->id.'.data.ncm');
});

test('gerente só acessa configuração documentos e eventos da própria filial', function () {
    ['admin' => $admin, 'company' => $company, 'branches' => $branches, 'configs' => $configs, 'orders' => $orders] = multiBranchFiscalContext();
    $admin->companies()->updateExistingPivot($company->id, ['role' => 'branch_manager', 'branch_id' => $branches[0]->id]);
    $admin = $admin->fresh();
    $form = Livewire::actingAs($admin)->test(Config::class);
    expect($form->get('branchOptions'))->toHaveCount(1);
    $form->set('branchId', $branches[1]->id)->assertForbidden();
    $note = app(FiscalNoteService::class)->issue($orders[1]);
    $this->actingAs($admin)->get(route('admin.fiscal.document', ['note' => $note, 'type' => 'xml']))->assertForbidden();
    $this->actingAs($admin)->get(route('admin.pdv.fiscal-note-status', ['order' => $orders[1]]))->assertForbidden();
    Livewire::actingAs($admin)->test(Notes::class)->set('invalidationConfigId', (string) $configs[1]->id)
        ->call('confirmInvalidation')->assertForbidden();
});

test('inutilização usa CNPJ e ambiente da filial selecionada', function () {
    ['configs' => $configs] = multiBranchFiscalContext();
    app()->instance('env', 'production');
    $configs[1]->update(['emission_environment' => 'homologacao']);
    app(FiscalEventService::class)->invalidate($configs[1], ['serie' => 4, 'numero_inicial' => 50, 'numero_final' => 51, 'justificativa' => 'Faixa de teste não utilizada']);
    Http::assertSent(fn ($request) => $request->url() === 'https://homologacao.focusnfe.com.br/v2/nfe/inutilizacao'
        && $request['cnpj'] === '11444777000161'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('hom-1:')));
});

test('reimpressão térmica e PDF mantêm o emitente original mesmo após editar a filial', function () {
    ['company' => $company, 'configs' => $configs, 'orders' => $orders, 'branches' => $branches] = multiBranchFiscalContext();
    $orders[1]->update(['fiscal_recipient' => ['document' => '']]);
    $note = app(FiscalNoteService::class)->issue($orders[1]);
    $configs[1]->update(['legal_name' => 'Nome alterado depois']);
    $branches[1]->update(['address' => 'Endereço alterado depois']);
    $bytes = app(\App\Contracts\PrinterServiceInterface::class)->buildFiscalNoteReceipt($note, $orders[1], $company);
    expect($bytes)->toContain('Emitente 2 Ltda', '11444777000161', 'Rua 2', 'SEM VALOR FISCAL')
        ->not->toContain('11222333000181', 'Nome alterado depois', 'Endereço alterado depois');
    $html = view('livewire.admin.orders.receipt-fiscal-note', ['company' => $company, 'order' => $orders[1], 'note' => $note, 'qrCode' => null])->render();
    expect($html)->toContain('Emitente 2 Ltda', '11.444.777/0001-61', 'Rua 2')->not->toContain('Nome alterado depois', 'Endereço alterado depois');
});

test('chave de acesso identifica emitente de nota antiga sem snapshot de CNPJ', function () {
    ['company' => $company, 'configs' => $configs, 'orders' => $orders] = multiBranchFiscalContext();
    $key = '352611'.$configs[0]->issuer_cnpj.'65'.'001'.'000000001'.'1'.'12345678'.'1';
    expect(strlen($key))->toBe(44);
    $note = FiscalNote::create(['company_id' => $company->id, 'branch_id' => $orders[1]->branch_id, 'order_id' => $orders[1]->id, 'access_key' => 'NFe'.$key, 'status' => 'authorized']);
    expect(app(FiscalConfigResolver::class)->forNote($note)->id)->toBe($configs[0]->id);
});

test('filial com nota emitida não permite trocar CNPJ mesmo sem id de cadastro remoto', function () {
    ['admin' => $admin, 'configs' => $configs, 'orders' => $orders, 'branches' => $branches] = multiBranchFiscalContext();
    app(FiscalNoteService::class)->issue($orders[0]);
    $configs[0]->update(['focus_nfe_company_id' => null]);
    Livewire::actingAs($admin)->test(Config::class)->set('branchCnpj', $branches[1]->cnpj)
        ->call('save')->assertHasErrors('branchCnpj');
    expect($branches[0]->fresh()->cnpj)->toBe('11222333000181');
});

test('certificados de duas filiais ficam em arquivos privados separados', function () {
    ['admin' => $admin, 'branches' => $branches, 'configs' => $configs] = multiBranchFiscalContext();
    Storage::fake('local');
    foreach ($branches as $index => $branch) {
        Livewire::actingAs($admin)->test(Config::class)->set('branchId', $branch->id)
            ->set('enabled', false)->set('certificateFile', \Illuminate\Http\UploadedFile::fake()->createWithContent('certificado.pfx', 'certificado-'.$index))
            ->call('save')->assertHasNoErrors();
        $path = $configs[$index]->fresh()->certificate_path;
        expect($path)->toContain('branch-'.$branch->id);
        Storage::disk('local')->assertExists($path);
        expect(Storage::disk('local')->get($path))->toBe('certificado-'.$index);
    }
    expect($configs[0]->fresh()->certificate_path)->not->toBe($configs[1]->fresh()->certificate_path);
});

test('webhook com segredo de outra filial ou emitente divergente não atualiza a nota', function () {
    ['orders' => $orders, 'configs' => $configs] = multiBranchFiscalContext();
    $note = app(FiscalNoteService::class)->issue($orders[1]);
    $note->update(['status' => 'pending']);
    $this->postJson(route('webhook.fiscal'), ['ref' => $note->provider_reference, 'status' => 'autorizado'], ['x-focus-nfe-token' => 'hook-0'])->assertUnauthorized();
    expect($note->fresh()->status)->toBe('pending');
    $this->postJson(route('webhook.fiscal'), ['ref' => $note->provider_reference, 'status' => 'autorizado'], ['x-focus-nfe-token' => 'hook-1'])->assertOk();
    expect($note->fresh()->status)->toBe('authorized');
    $configs[1]->update(['issuer_cnpj' => $configs[0]->issuer_cnpj]);
    $this->postJson(route('webhook.fiscal'), ['ref' => $note->provider_reference, 'status' => 'cancelado'], ['x-focus-nfe-token' => 'hook-1'])->assertUnauthorized();
    expect($note->fresh()->status)->toBe('authorized');
});

test('gerente não altera perfil tributário de outra filial e preserva seus dados ao salvar', function () {
    ['admin' => $admin, 'company' => $company, 'product' => $product, 'branches' => $branches, 'normal' => $normal] = multiBranchFiscalContext();
    $admin->companies()->updateExistingPivot($company->id, ['role' => 'branch_manager', 'branch_id' => $branches[0]->id]);
    $admin = $admin->fresh();
    Livewire::actingAs($admin)->test(Form::class, ['product' => $product])->call('save')->assertHasNoErrors();
    $saved = json_decode(DB::table('branch_product')->where('product_id', $product->id)->where('branch_id', $branches[1]->id)->value('fiscal_data'), true);
    expect($saved)->toBe($normal);
    Livewire::actingAs($admin)->test(Form::class, ['product' => $product->fresh()])
        ->set('branchFiscalProfiles.'.$branches[1]->id, ['enabled' => true, 'data' => ['icms_situation' => '40'], 'taxes' => ''])
        ->call('save')->assertHasErrors('branchFiscalProfiles');
    $saved = json_decode(DB::table('branch_product')->where('product_id', $product->id)->where('branch_id', $branches[1]->id)->value('fiscal_data'), true);
    expect($saved)->toBe($normal);
});

test('perfil tributário não impede desvincular produto de uma filial', function () {
    ['admin' => $admin, 'product' => $product, 'branches' => $branches] = multiBranchFiscalContext();
    Livewire::actingAs($admin)->test(Form::class, ['product' => $product])
        ->set('selectedBranches', [(string) $branches[0]->id])->call('save')->assertHasNoErrors();
    expect($product->branches()->pluck('branches.id')->all())->toBe([$branches[0]->id]);
});

test('migration preenche emitentes existentes sem alterar tokens séries ou ambiente automático', function () {
    ['configs' => $configs, 'company' => $company, 'branches' => $branches] = multiBranchFiscalContext();
    $migration = require database_path('migrations/2026_11_10_000001_isolate_branch_fiscal_settings.php');
    $migration->down();
    $migration->up();
    foreach ($configs as $index => $config) {
        $config->refresh();
        expect($config->issuer_cnpj)->toBe($branches[$index]->cnpj)
            ->and($config->legal_name)->toBe($company->name)
            ->and($config->emission_environment)->toBeNull()
            ->and($config->token_homologacao)->toBe('hom-'.$index)
            ->and($config->nfce_serie)->toBe($index + 1);
    }
});

test('produto recusa perfil tributário vinculado a filial de outra empresa', function () {
    ['admin' => $admin, 'product' => $product] = multiBranchFiscalContext();
    $other = Company::create(['name' => 'Outra empresa', 'slug' => 'outro-fiscal-'.uniqid(), 'order_prefix' => 'OUT', 'active' => true]);
    $branch = Branch::withoutGlobalScopes()->create(['company_id' => $other->id, 'name' => 'Filial externa', 'active' => true]);
    Livewire::actingAs($admin)->test(Form::class, ['product' => $product])
        ->set('branchFiscalProfiles.'.$branch->id, ['enabled' => true, 'data' => ['icms_situation' => '00'], 'taxes' => ''])
        ->call('save')->assertHasErrors('branchFiscalProfiles');
    expect(DB::table('branch_product')->where('product_id', $product->id)->where('branch_id', $branch->id)->exists())->toBeFalse();
});

test('nova configuração de filial não herda razão social token série ou ambiente da anterior', function () {
    ['admin' => $admin, 'company' => $company, 'branches' => $branches, 'configs' => $configs] = multiBranchFiscalContext();
    $configs[1]->delete();
    Livewire::actingAs($admin)->test(Config::class)->set('branchId', $branches[1]->id)
        ->assertSet('enabled', false)->assertSet('legalName', $company->name)->assertSet('emissionEnvironment', '')
        ->assertSet('providerToken', '')->assertSet('hasProviderToken', false)->assertSet('hasCertificate', false)
        ->assertSet('nfceSerie', '1')->assertSet('nfeSerie', '1');
});

test('filial explicitamente em homologação dispensa CSC mesmo no servidor de produção', function () {
    ['admin' => $admin, 'branches' => $branches, 'configs' => $configs] = multiBranchFiscalContext();
    app()->instance('env', 'production');
    $configs[1]->update(['csc_nfce_producao' => null, 'id_token_nfce_producao' => null]);
    multiBranchFiscalFake(Http::response(['id' => 'focus-1']));
    Livewire::actingAs($admin)->test(Config::class)->set('branchId', $branches[1]->id)
        ->set('emissionEnvironment', 'producao')->call('save')->assertHasErrors(['cscNfceProducao', 'idTokenNfceProducao'])
        ->set('emissionEnvironment', 'homologacao')->call('save')->assertHasNoErrors();
    expect($configs[1]->fresh()->environment)->toBe('homologacao')
        ->and($configs[1]->fresh()->emission_environment)->toBe('homologacao');
});

test('emissão bloqueia JSON tributário legado que tenta contornar o CST validado', function (string $document) {
    ['orders' => $orders, 'product' => $product, 'branches' => $branches, 'normal' => $normal] = multiBranchFiscalContext();
    $normal['taxes']['icms_situacao_tributaria'] = '400';
    $product->branches()->updateExistingPivot($branches[1]->id, ['fiscal_data' => json_encode($normal)]);
    if ($document === '') {
        $orders[1]->update(['fiscal_recipient' => ['document' => '']]);
    }
    expect(app(FiscalNoteService::class)->preflight($orders[1]))->toContain('Campos tributários adicionais inválidos');
    Http::assertNothingSent();
})->with(['11222333000181', '']);

test('perfil de filial nunca herda alíquotas adicionais do cadastro geral', function () {
    ['orders' => $orders, 'product' => $product, 'base' => $base] = multiBranchFiscalContext();
    $base['taxes'] = ['icms_percentual_reducao_da_base_de_calculo' => 10];
    $product->update(['fiscal_data' => $base]);
    app(FiscalNoteService::class)->issue($orders[1]);
    Http::assertSent(fn ($request) => $request['items'][0]['icms_aliquota'] === 18
        && ! array_key_exists('icms_percentual_reducao_da_base_de_calculo', $request['items'][0]));
});
