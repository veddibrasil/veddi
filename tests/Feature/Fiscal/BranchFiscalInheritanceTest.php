<?php

use App\Livewire\Admin\Fiscal\IssueControl;
use App\Livewire\Admin\Fiscal\Templates;
use App\Livewire\Admin\Orders\Show;
use App\Livewire\Admin\Products\Form;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyFiscalConfig;
use App\Models\Customer;
use App\Models\FiscalTemplate;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Models\UserPermission;
use App\Services\Fiscal\FiscalNoteService;
use App\Services\Fiscal\FiscalTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Cenário do pedido TRA-2026-42814: empresa do Simples com duas filiais, cadastro geral do
 * produto completo e uma exceção antiga em Arapongas (cópia do geral, sem PIS/COFINS, CSOSN 500).
 */
function inheritanceContext(): array
{
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(['status' => 'autorizado', 'numero' => '1'], 201)]);

    $company = Company::create(['name' => 'Coxinhas', 'slug' => 'heranca-'.uniqid(), 'order_prefix' => 'TRA', 'active' => true, 'status' => 'ACTIVE', 'plan' => 'pro', 'fiscal_notes_enabled' => true, 'owner_cpf_cnpj' => '11222333000181']);
    app()->instance('current.company', $company);
    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);
    foreach (['fiscal.issue', 'fiscal.view', 'products.update', 'products.view', 'orders.view'] as $name) {
        $permission = Permission::firstOrCreate(['name' => $name], ['group' => explode('.', $name)[0], 'label' => $name]);
        UserPermission::create(['user_id' => $admin->id, 'company_id' => $company->id, 'permission_id' => $permission->id, 'granted' => true]);
    }

    $general = ['ncm' => '19059090', 'cfop' => '5102', 'icms_situation' => '400', 'origin' => '0', 'unit' => 'UN', 'pis_situation' => '07', 'cofins_situation' => '07', 'taxes' => []];
    $legacyCopy = ['ncm' => '19059090', 'cfop' => '5102', 'unit' => 'un', 'origin' => '0', 'icms_situation' => '500', 'interstate_cfop' => '6404', 'taxes' => []];
    $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'Salgados', 'active' => true]);
    $product = Product::create(['company_id' => $company->id, 'product_category_id' => $category->id, 'name' => 'Cento de salgados', 'price' => 30, 'active' => true, 'fiscal_data' => $general]);
    $customer = Customer::create(['company_id' => $company->id, 'name' => 'Cliente', 'phone' => '43999990001']);
    $recipient = ['document' => '11222333000181', 'name' => 'Alimentos SA', 'street' => 'Rua Castro Alves', 'number' => '106', 'neighborhood' => 'Zona 06', 'city' => 'Maringá', 'state' => 'PR', 'zip' => '87015000', 'ie_indicator' => '9'];

    $branches = [];
    foreach (['Maringá' => '11222333000181', 'Arapongas' => '11444777000161'] as $name => $cnpj) {
        $branch = Branch::create(['company_id' => $company->id, 'name' => $name, 'cnpj' => $cnpj, 'address' => 'Avenida', 'number' => '1', 'neighborhood' => 'Centro', 'city' => $name, 'state' => 'PR', 'cep' => '86700000', 'active' => true, 'opens_at' => '00:00:00', 'closes_at' => '23:59:59']);
        CompanyFiscalConfig::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'issuer_cnpj' => $cnpj, 'legal_name' => $name.' Ltda', 'enabled' => true, 'is_default' => $name === 'Maringá', 'crt' => 1, 'nfce_serie' => 1, 'nfe_serie' => 3, 'environment' => 'homologacao', 'focus_nfe_company_id' => 'focus-'.$name, 'token_homologacao' => 'hom-'.$name, 'webhook_token' => 'hook-'.$name, 'inscricao_estadual' => '123456789']);
        $product->branches()->attach($branch->id, ['available' => true, 'fiscal_data' => $name === 'Arapongas' ? json_encode($legacyCopy) : null]);
        $branches[$name] = $branch;
    }
    $order = Order::create(['company_id' => $company->id, 'branch_id' => $branches['Arapongas']->id, 'customer_id' => $customer->id, 'subtotal' => 30, 'total' => 30, 'delivery_fee' => 0, 'fee' => 0, 'net_value' => 30, 'status' => 'paid', 'payment_method' => 'pix', 'order_type' => 'pickup', 'fiscal_recipient' => $recipient]);
    OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'product_name' => $product->name, 'quantity' => 1, 'unit_price' => 30, 'subtotal' => 30]);

    return compact('company', 'admin', 'product', 'branches', 'order', 'general', 'legacyCopy');
}

function inheritanceBranchProfile(Product $product, Branch $branch): ?array
{
    $raw = DB::table('branch_product')->where('product_id', $product->id)->where('branch_id', $branch->id)->value('fiscal_data');

    return $raw === null ? null : json_decode($raw, true);
}

test('exceção da filial herda do cadastro geral o que ela não troca', function () {
    ['order' => $order] = inheritanceContext();

    expect(app(FiscalNoteService::class)->preflight($order))->toBeNull();
    $note = app(FiscalNoteService::class)->issue($order);

    expect($note->model)->toBe('55');
    Http::assertSent(fn ($request) => str_contains($request->url(), '/v2/nfe?')
        && $request['items'][0]['icms_situacao_tributaria'] === '500'
        && $request['items'][0]['pis_situacao_tributaria'] === '07'
        && $request['items'][0]['cofins_situacao_tributaria'] === '07'
        && $request['items'][0]['codigo_ncm'] === '19059090');
});

test('campo que falta em todo lugar aponta para o cadastro geral, mesmo com exceção na filial', function () {
    ['order' => $order, 'product' => $product, 'admin' => $admin, 'general' => $general, 'branches' => $branches] = inheritanceContext();
    $product->update(['fiscal_data' => array_diff_key($general, ['pis_situation' => true, 'cofins_situation' => true])]);

    $issue = app(FiscalNoteService::class)->check($order->fresh())['issues'][0];
    expect($issue['products'][0])->toMatchArray(['source' => 'general', 'fields' => ['CST PIS', 'CST COFINS'], 'has_branch_profile' => true, 'branch_id' => null])
        ->and($issue['message'])->toContain('Cento de salgados: faltam CST PIS, CST COFINS (cadastro geral do produto #'.$product->id.', vale para todas as filiais)');

    // Corrigir no cadastro geral resolve a filial com exceção, e a exceção fica só com o que muda nela.
    Livewire::actingAs($admin)->test(Form::class, ['product' => $product->fresh()])
        ->assertSet('branchFiscalProfiles.'.$branches['Arapongas']->id.'.data', ['interstate_cfop' => '6404', 'icms_situation' => '500'])
        ->set('fiscalData.pis_situation', '07')
        ->set('fiscalData.cofins_situation', '07')
        ->call('save')->assertHasNoErrors();

    expect(app(FiscalNoteService::class)->preflight($order->fresh()))->toBeNull()
        ->and(inheritanceBranchProfile($product, $branches['Arapongas']))->toBe(['interstate_cfop' => '6404', 'icms_situation' => '500', 'taxes' => []]);
});

test('migração enxuga exceções antigas e mudança no cadastro geral passa a chegar na filial', function () {
    ['order' => $order, 'product' => $product, 'general' => $general, 'branches' => $branches] = inheritanceContext();

    (require database_path('migrations/2026_11_17_000001_compact_branch_fiscal_profiles.php'))->up();
    expect(inheritanceBranchProfile($product, $branches['Arapongas']))->toBe(['interstate_cfop' => '6404', 'icms_situation' => '500', 'taxes' => []]);

    $product->update(['fiscal_data' => [...$general, 'ncm' => '21069090']]);
    app(FiscalNoteService::class)->issue($order->fresh());

    Http::assertSent(fn ($request) => $request['items'][0]['codigo_ncm'] === '21069090'
        && $request['items'][0]['icms_situacao_tributaria'] === '500');
});

test('ligar exceção começa vazia e exceção igual ao cadastro geral é desfeita ao salvar', function () {
    ['product' => $product, 'admin' => $admin, 'branches' => $branches] = inheritanceContext();
    $maringa = $branches['Maringá']->id;

    Livewire::actingAs($admin)->test(Form::class, ['product' => $product->fresh()])
        ->set("branchFiscalProfiles.{$maringa}.enabled", true)
        ->assertSet("branchFiscalProfiles.{$maringa}.data", [])
        ->assertSee('Geral: 19059090')
        ->set("branchFiscalProfiles.{$maringa}.data.ncm", '19059090')
        ->call('save')->assertHasNoErrors();

    expect(inheritanceBranchProfile($product, $branches['Maringá']))->toBeNull()
        ->and(session('status'))->toContain('Maringá ficou sem perfil fiscal próprio e usa o cadastro geral');
});

test('lote no cadastro geral avisa a exceção da filial que continua valendo', function () {
    ['company' => $company, 'product' => $product] = inheritanceContext();
    $template = FiscalTemplate::create(['company_id' => $company->id, 'name' => 'Salgados', 'crt' => 1, 'state' => 'PR', 'data' => ['icms_situation' => '102', 'pis_situation' => '49']]);

    $row = app(FiscalTemplateService::class)->preview($template, [$product->id], null, 'replace')['rows'][0];

    expect($row['changed'])->toBe(['icms_situation', 'pis_situation'])
        ->and($row['warnings'])->toContain('Na filial Arapongas continua valendo a exceção própria: CST / CSOSN ICMS 500. Para usar o cadastro geral lá também, abra o produto e apague a exceção.');
});

test('lote na filial olha o que a nota usa e não transforma valor do geral em exceção', function () {
    ['company' => $company, 'product' => $product, 'branches' => $branches, 'admin' => $admin] = inheritanceContext();
    $arapongas = $branches['Arapongas']->id;
    $service = app(FiscalTemplateService::class);

    // PIS/COFINS já vêm do cadastro geral: "preencher vazios" não cria exceção.
    $pis = FiscalTemplate::create(['company_id' => $company->id, 'name' => 'PIS', 'data' => ['pis_situation' => '49', 'cofins_situation' => '49']]);
    $row = $service->preview($pis, [$product->id], $arapongas, 'fill')['rows'][0];
    expect($row['changed'])->toBe([])->and($row['missing_before'])->toBe([]);

    // Substituir pelo mesmo CSOSN do geral desfaz a exceção daquele campo.
    $simples = FiscalTemplate::create(['company_id' => $company->id, 'name' => 'Simples', 'crt' => 1, 'data' => ['icms_situation' => '400']]);
    $row = $service->preview($simples, [$product->id], $arapongas, 'replace')['rows'][0];
    expect($row['changed'])->toBe(['icms_situation'])->and($row['after'])->not->toHaveKey('icms_situation');

    $service->apply($simples, [$product->id], $arapongas, 'replace', [$product->id => $row['fingerprint']], $admin);
    expect(inheritanceBranchProfile($product, $branches['Arapongas']))->not->toHaveKey('icms_situation');

    Livewire::actingAs($admin)->test(Templates::class)
        ->set('target', (string) $branches['Maringá']->id)
        ->assertSee('sem exceção nesta filial (usa o cadastro geral)');
});

test('cartão do pedido mostra a pendência de agora no lugar da mensagem antiga', function () {
    ['order' => $order, 'product' => $product, 'admin' => $admin, 'general' => $general] = inheritanceContext();
    $order->update(['fiscal_error' => 'Dados fiscais pendentes no produto Cento de salgados : pis_situation.']);
    $product->update(['fiscal_data' => array_diff_key($general, ['pis_situation' => true])]);

    Livewire::actingAs($admin)->test(Show::class, ['order' => $order->fresh()])
        ->assertSee('Antes de emitir, resolva:')
        ->assertSee('Dados fiscais incompletos em 1 produto(s): Cento de salgados.')
        ->assertDontSee(': pis_situation')
        ->assertSee('Emitir NF-e');

    $product->update(['fiscal_data' => $general]);
    Livewire::actingAs($admin)->test(Show::class, ['order' => $order->fresh()])
        ->assertDontSee('Antes de emitir, resolva:')
        ->assertSee('Última tentativa de emissão falhou: Dados fiscais pendentes no produto Cento de salgados : pis_situation.');
});

test('Controle de notas mostra tentativa parada antes do envio como não enviada', function () {
    ['order' => $order, 'admin' => $admin] = inheritanceContext();
    $order->update(['fiscal_error' => 'Dados fiscais pendentes no produto Cento de salgados : pis_situation.']);

    Livewire::actingAs($admin)->test(IssueControl::class)
        ->set('situationFilter', 'failed')
        ->assertSee('Não enviada')
        ->assertSee('Na última tentativa: Dados fiscais pendentes')
        ->call('openIssueOne', $order->id)
        ->assertSee('Emitir nota fiscal')
        ->assertDontSee('Reemitir nota fiscal')
        ->assertDontSee('Motivo da última falha');
});
