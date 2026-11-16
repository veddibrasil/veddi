<?php

use App\Livewire\Admin\Fiscal\Templates;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyFiscalConfig;
use App\Models\FiscalTemplate;
use App\Models\FiscalTemplateApplication;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\Fiscal\FiscalTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function templateContext(): array
{
    $company = Company::create(['name' => 'Lote', 'slug' => 'lote-'.uniqid(), 'order_prefix' => 'LOT', 'active' => true, 'status' => 'ACTIVE', 'fiscal_notes_enabled' => true]);
    app()->instance('current.company', $company);
    $branches = collect(['Maringá', 'Arapongas'])->map(fn ($name) => Branch::create(['company_id' => $company->id, 'name' => $name, 'state' => 'PR', 'active' => true, 'opens_at' => '00:00:00', 'closes_at' => '23:59:59']));
    foreach ($branches as $branch) {
        CompanyFiscalConfig::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'enabled' => true, 'crt' => 1]);
    }
    $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'Salgados']);
    $empty = Product::create(['company_id' => $company->id, 'product_category_id' => $category->id, 'name' => 'Coxinha', 'price' => 8, 'active' => true]);
    $partial = Product::create(['company_id' => $company->id, 'product_category_id' => $category->id, 'name' => 'Kibe', 'price' => 8, 'active' => true, 'fiscal_data' => ['ncm' => '16025000', 'cfop' => '5405']]);
    $template = FiscalTemplate::create(['company_id' => $company->id, 'name' => 'Salgados Simples PR', 'crt' => 1, 'state' => 'PR',
        'data' => ['ncm' => '19059090', 'cfop' => '5102', 'icms_situation' => '102', 'origin' => '0', 'pis_situation' => '49', 'cofins_situation' => '49']]);
    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);
    $permission = \App\Models\Permission::firstOrCreate(['name' => 'products.update'], ['group' => 'products', 'label' => 'Editar produto']);
    \App\Models\UserPermission::create(['user_id' => $admin->id, 'company_id' => $company->id, 'permission_id' => $permission->id, 'granted' => true]);

    return compact('company', 'branches', 'empty', 'partial', 'template', 'admin');
}

test('prévia preenche só campos vazios e mostra pendências resolvidas e restantes', function () {
    ['empty' => $empty, 'partial' => $partial, 'template' => $template] = templateContext();

    $preview = app(FiscalTemplateService::class)->preview($template, [$empty->id, $partial->id], null, 'fill');
    $rows = collect($preview['rows'])->keyBy('product_id');

    expect($preview['blocked'])->toBeNull()
        ->and($rows[$empty->id]['after'])->toMatchArray(['ncm' => '19059090', 'cfop' => '5102'])
        ->and($rows[$partial->id]['after'])->toMatchArray(['ncm' => '16025000', 'cfop' => '5405', 'icms_situation' => '102'])
        ->and($rows[$partial->id]['changed'])->not->toContain('ncm', 'cfop')
        ->and($rows[$empty->id]['missing_before'])->toHaveCount(6)
        ->and($rows[$empty->id]['missing_after'])->toBe([])
        ->and($preview['summary'])->toMatchArray(['changed' => 2, 'resolved' => 10, 'remaining' => 0]);
    expect($empty->fresh()->fiscal_data)->toBeNull();
});

test('substituir exige escolha explícita e troca valores existentes', function () {
    ['partial' => $partial, 'template' => $template, 'admin' => $admin] = templateContext();

    Livewire::actingAs($admin)->test(Templates::class)
        ->set('applyTemplateId', (string) $template->id)
        ->set('mode', 'replace')
        ->set('selected', [(string) $partial->id])
        ->call('generatePreview')
        ->assertHasErrors('confirmReplace')
        ->set('confirmReplace', true)
        ->call('generatePreview')
        ->assertHasNoErrors()
        ->assertSee('5405')
        ->call('applyPreview')
        ->assertHasNoErrors();

    expect($partial->fresh()->fiscal_data)->toMatchArray(['ncm' => '19059090', 'cfop' => '5102']);
    $application = FiscalTemplateApplication::sole();
    expect($application->mode)->toBe('replace')
        ->and($application->user_id)->toBe($admin->id)
        ->and($application->changes[0]['before'])->toMatchArray(['cfop' => '5405']);
});

test('perfil da filial: só altera perfil existente e nunca completa com o cadastro geral', function () {
    ['empty' => $empty, 'partial' => $partial, 'branches' => $branches, 'company' => $company, 'admin' => $admin] = templateContext();
    $empty->branches()->attach($branches[1]->id, ['available' => true, 'fiscal_data' => json_encode(['icms_situation' => '500', 'cfop' => '5405'])]);
    $partial->branches()->attach($branches[1]->id, ['available' => true]);
    $pisOnly = FiscalTemplate::create(['company_id' => $company->id, 'name' => 'PIS/COFINS', 'data' => ['pis_situation' => '04', 'cofins_situation' => '04']]);

    $preview = app(FiscalTemplateService::class)->preview($pisOnly, [$empty->id, $partial->id], $branches[1]->id, 'fill');
    $rows = collect($preview['rows'])->keyBy('product_id');
    expect($rows[$partial->id]['skipped'])->toContain('Sem perfil fiscal próprio')
        ->and($rows[$empty->id]['after'])->toBe(['icms_situation' => '500', 'cfop' => '5405', 'pis_situation' => '04', 'cofins_situation' => '04'])
        ->and($rows[$empty->id]['missing_after'])->toBe(['NCM', 'Origem'])
        ->and($rows[$empty->id]['warnings'])->toContain('CST/CSOSN 500 indica substituição tributária: confira o CEST.');

    app(FiscalTemplateService::class)->apply($pisOnly, [$empty->id, $partial->id], $branches[1]->id, 'fill', [$empty->id => $rows[$empty->id]['fingerprint']], $admin);

    $pivot = fn (Product $product) => DB::table('branch_product')->where('branch_id', $branches[1]->id)->where('product_id', $product->id)->value('fiscal_data');
    expect(json_decode($pivot($empty), true))->toMatchArray(['pis_situation' => '04'])
        ->and($pivot($partial))->toBeNull()
        ->and($empty->fresh()->fiscal_data)->toBeNull();
});

test('edição concorrente depois da prévia impede a gravação inteira', function () {
    ['empty' => $empty, 'partial' => $partial, 'template' => $template, 'admin' => $admin] = templateContext();
    $service = app(FiscalTemplateService::class);
    $preview = $service->preview($template, [$empty->id, $partial->id], null, 'fill');
    $fingerprints = collect($preview['rows'])->mapWithKeys(fn ($row) => [$row['product_id'] => $row['fingerprint']])->all();

    $partial->update(['fiscal_data' => ['ncm' => '16025000', 'cfop' => '5405', 'origin' => '0']]);

    expect(fn () => $service->apply($template, [$empty->id, $partial->id], null, 'fill', $fingerprints, $admin))
        ->toThrow(RuntimeException::class, 'mudou depois da prévia');
    expect($empty->fresh()->fiscal_data)->toBeNull()
        ->and(FiscalTemplateApplication::count())->toBe(0);
});

test('modelo editado depois da prévia não grava valores que ninguém viu', function () {
    ['empty' => $empty, 'template' => $template, 'admin' => $admin] = templateContext();
    $service = app(FiscalTemplateService::class);
    $preview = $service->preview($template, [$empty->id], null, 'fill');

    $template->update(['data' => [...$template->data, 'ncm' => '21069090']]);

    expect(fn () => $service->apply($template, [$empty->id], null, 'fill', collect($preview['rows'])->pluck('fingerprint', 'product_id')->all(), $admin, $preview['template_fingerprint']))
        ->toThrow(RuntimeException::class, 'modelo foi alterado');
    expect($empty->fresh()->fiscal_data)->toBeNull();
});

test('desfazer devolve os valores anteriores e preserva o que foi editado depois', function () {
    ['empty' => $empty, 'partial' => $partial, 'template' => $template, 'admin' => $admin] = templateContext();
    $service = app(FiscalTemplateService::class);
    $preview = $service->preview($template, [$empty->id, $partial->id], null, 'fill');
    $application = $service->apply($template, [$empty->id, $partial->id], null, 'fill', collect($preview['rows'])->pluck('fingerprint', 'product_id')->all(), $admin);
    $partial->update(['fiscal_data' => [...$partial->fresh()->fiscal_data, 'cest' => '1700100']]);

    $result = $service->revert($application, $admin);

    expect($result)->toBe(['restored' => 1, 'skipped' => ['Kibe']])
        ->and($empty->fresh()->fiscal_data)->toBe([])
        ->and($partial->fresh()->fiscal_data['cest'])->toBe('1700100')
        ->and($application->fresh()->reverted_at)->not->toBeNull();
    expect(fn () => $service->revert($application->fresh(), $admin))->toThrow(RuntimeException::class, 'já foi desfeita');
});

test('vigência, regime e UF incompatíveis bloqueiam a aplicação', function () {
    ['empty' => $empty, 'template' => $template, 'branches' => $branches, 'company' => $company] = templateContext();
    $service = app(FiscalTemplateService::class);

    $template->update(['valid_until' => now()->subDay()->toDateString()]);
    expect($service->preview($template, [$empty->id], null, 'fill')['blocked'])->toContain('fora da vigência');

    $template->update(['valid_until' => null, 'crt' => 3]);
    expect($service->preview($template->fresh(), [$empty->id], $branches[0]->id, 'fill')['blocked'])->toContain('CRT 1')
        ->and($service->preview($template->fresh(), [$empty->id], null, 'fill')['warnings'][0])->toContain('regime diferente');

    $template->update(['crt' => 1, 'state' => 'SP']);
    expect($service->preview($template->fresh(), [$empty->id], $branches[0]->id, 'fill')['blocked'])->toContain('UF SP');
});

test('modelo valida códigos pelo regime e não aceita modelo vazio', function () {
    $service = app(FiscalTemplateService::class);

    expect(fn () => $service->validateTemplateData(['icms_situation' => '102'], null, 3))->toThrow(ValidationException::class);
    expect(fn () => $service->validateTemplateData([], null, null))->toThrow(ValidationException::class);
    expect(fn () => $service->validateTemplateData(['ncm' => '19059090'], '{"icms_situacao_tributaria": "00"}', 1))->toThrow(ValidationException::class);
    expect($service->validateTemplateData(['ncm' => '19059090', 'cest' => ''], '{"icms_aliquota": 18}', 3))->toBe(['ncm' => '19059090', 'taxes' => ['icms_aliquota' => 18]]);
});

test('produtos e modelos de outra empresa nunca entram na aplicação', function () {
    ['empty' => $empty, 'template' => $template, 'admin' => $admin] = templateContext();
    $other = templateContext();
    app()->instance('current.company', $template->company);

    $preview = app(FiscalTemplateService::class)->preview($template, [$empty->id, $other['empty']->id], null, 'fill');
    expect(collect($preview['rows'])->pluck('product_id')->all())->toBe([$empty->id]);

    $component = Livewire::actingAs($admin)->test(Templates::class)
        ->set('applyTemplateId', (string) $other['template']->id)
        ->set('selected', [(string) $empty->id]);
    expect(fn () => $component->call('generatePreview'))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
    expect($other['empty']->fresh()->fiscal_data)->toBeNull();
});

test('atalho das pendências chega com produtos e destino escolhidos e volta ao pedido', function () {
    ['empty' => $empty, 'template' => $template, 'admin' => $admin, 'branches' => $branches] = templateContext();
    $other = templateContext();
    app()->instance('current.company', $template->company);
    // Destino filial: só produtos vendidos nela (o perfil próprio é o que a emissão usa).
    $empty->branches()->attach($branches[1]->id, ['available' => true, 'fiscal_data' => json_encode(['icms_situation' => '102'])]);

    Livewire::withQueryParams(['produtos' => $empty->id.','.$other['empty']->id.',abc', 'destino' => (string) $branches[1]->id, 'voltar' => '/admin/orders/42'])
        ->actingAs($admin)->test(Templates::class)
        ->assertSet('selected', [(string) $empty->id])
        ->assertSet('target', (string) $branches[1]->id)
        ->assertSet('returnTo', '/admin/orders/42')
        ->assertSee('Voltar ao pedido');

    // Filial de outra empresa e retorno externo são ignorados.
    Livewire::withQueryParams(['destino' => (string) $other['branches'][0]->id, 'voltar' => 'https://evil.test/admin/x'])
        ->actingAs($admin)->test(Templates::class)
        ->assertSet('target', 'general')
        ->assertSet('returnTo', null)
        ->assertDontSee('Voltar ao pedido');
});

test('filtro só pendentes lista produtos com campo da NF-e vazio no destino', function () {
    ['empty' => $empty, 'partial' => $partial, 'admin' => $admin, 'company' => $company] = templateContext();
    $complete = Product::create(['company_id' => $company->id, 'product_category_id' => $empty->product_category_id, 'name' => 'Pastel', 'price' => 8, 'active' => true,
        'fiscal_data' => ['ncm' => '19059090', 'cfop' => '5102', 'icms_situation' => '102', 'origin' => '0', 'pis_situation' => '49', 'cofins_situation' => '49']]);

    expect(app(FiscalTemplateService::class)->incompleteProductIds($company->id, [$empty->id, $partial->id, $complete->id], null))
        ->toEqualCanonicalizing([$empty->id, $partial->id]);

    Livewire::actingAs($admin)->test(Templates::class)
        ->assertSee('Pastel')
        ->set('onlyPending', true)
        ->assertDontSee('Pastel')
        ->assertSee('Coxinha')
        ->call('selectFiltered')
        ->assertSet('selected', fn ($selected) => collect($selected)->map(fn ($id) => (int) $id)->sort()->values()->all() === collect([$empty->id, $partial->id])->sort()->values()->all());
});

test('gerente de filial aplica só no perfil da própria filial e não cria modelos', function () {
    ['empty' => $empty, 'template' => $template, 'branches' => $branches, 'company' => $company] = templateContext();
    $manager = User::factory()->create();
    $manager->companies()->attach($company->id, ['role' => 'branch_manager', 'branch_id' => $branches[0]->id]);
    $permission = \App\Models\Permission::firstOrCreate(['name' => 'products.update'], ['group' => 'products', 'label' => 'Editar produto']);
    \App\Models\UserPermission::create(['user_id' => $manager->id, 'company_id' => $company->id, 'permission_id' => $permission->id, 'granted' => true]);
    $empty->branches()->attach($branches[0]->id, ['available' => true, 'fiscal_data' => json_encode(['icms_situation' => '102'])]);

    $component = Livewire::actingAs($manager)->test(Templates::class)
        ->assertSet('target', (string) $branches[0]->id)
        ->set('target', 'general')
        ->set('applyTemplateId', (string) $template->id)
        ->set('selected', [(string) $empty->id])
        ->call('generatePreview')
        ->assertHasErrors('target');

    $component->call('saveTemplate')->assertForbidden();
});

test('tela cria modelo, gera prévia e aplica sem emitir nota', function () {
    ['empty' => $empty, 'admin' => $admin] = templateContext();
    \Illuminate\Support\Facades\Queue::fake();

    Livewire::actingAs($admin)->test(Templates::class)
        ->call('newTemplate')
        ->set('name', 'Bebidas Simples')
        ->set('crt', '1')
        ->set('fields.ncm', '22021000')
        ->set('fields.icms_situation', '500')
        ->set('fields.cfop', '5405')
        ->call('saveTemplate')
        ->assertHasNoErrors()
        ->set('applyTemplateId', (string) FiscalTemplate::where('name', 'Bebidas Simples')->value('id'))
        ->call('selectFiltered')
        ->call('generatePreview')
        ->assertSee('confira o CEST')
        ->call('applyPreview')
        ->assertHasNoErrors()
        ->assertSee('Bebidas Simples · 2 produto(s)');

    expect($empty->fresh()->fiscal_data)->toMatchArray(['ncm' => '22021000', 'icms_situation' => '500']);
    \Illuminate\Support\Facades\Queue::assertNothingPushed();
});

test('modelo vem escolhido para aplicar: único na empresa, recém-salvo ou pelo botão Aplicar', function () {
    ['template' => $template, 'admin' => $admin, 'company' => $company] = templateContext();

    $component = Livewire::actingAs($admin)->test(Templates::class)
        ->assertSet('applyTemplateId', (string) $template->id)
        ->assertSee('Valores do modelo Salgados Simples PR')
        ->assertSee('19059090');

    $component->call('newTemplate')
        ->set('name', 'Bebidas ST')
        ->set('fields.ncm', '22021000')
        ->set('fields.cest', '0300700')
        ->call('saveTemplate')
        ->assertHasNoErrors();
    $bebidas = FiscalTemplate::where('name', 'Bebidas ST')->sole();
    $component->assertSet('applyTemplateId', (string) $bebidas->id)
        ->assertSee('Valores do modelo Bebidas ST')
        ->assertSee('0300700');

    $component->call('editTemplate', $template->id)
        ->assertSet('name', 'Salgados Simples PR')
        ->assertSet('crt', '1')
        ->assertSet('fields.ncm', '19059090')
        ->assertSet('fields.pis_situation', '49')
        ->call('useTemplate', $bebidas->id)
        ->assertSet('applyTemplateId', (string) $bebidas->id);

    // Com mais de um modelo, a tela não escolhe sozinha ao abrir.
    Livewire::actingAs($admin)->test(Templates::class)->assertSet('applyTemplateId', '');
});

test('sugestões vêm dos produtos já preenchidos e do padrão da NFC-e, sem salvar nada', function () {
    ['company' => $company, 'empty' => $empty, 'partial' => $partial, 'template' => $template, 'admin' => $admin] = templateContext();
    $template->delete();
    $bebidas = ProductCategory::create(['company_id' => $company->id, 'name' => 'Bebidas']);
    $data = ['ncm' => '22021000', 'cfop' => '5405', 'icms_situation' => '500', 'origin' => '0', 'pis_situation' => '04', 'cofins_situation' => '04', 'cest' => ''];
    foreach (['Coca lata', 'Guaraná lata'] as $name) {
        Product::create(['company_id' => $company->id, 'product_category_id' => $bebidas->id, 'name' => $name, 'price' => 6, 'active' => true, 'fiscal_data' => $data]);
    }
    // Só unidade preenchida não é base para sugerir.
    $empty->update(['fiscal_data' => ['unit' => 'UN']]);

    $suggestions = collect(app(FiscalTemplateService::class)->suggestions($company->id))->keyBy('name');
    expect($suggestions->keys()->all())->toBe(['Bebidas – Simples PR', 'Salgados – Simples PR', 'Padrão da NFC-e ao consumidor – Simples PR'])
        ->and($suggestions['Bebidas – Simples PR'])->toMatchArray(['count' => 2, 'crt' => 1, 'state' => 'PR', 'generic' => false])
        ->and($suggestions['Bebidas – Simples PR']['data'])->not->toHaveKey('cest')
        ->and($suggestions['Salgados – Simples PR']['data'])->toBe(['ncm' => '16025000', 'cfop' => '5405'])
        ->and($suggestions['Padrão da NFC-e ao consumidor – Simples PR']['data'])->toMatchArray(['ncm' => '21069090', 'icms_situation' => '400']);
    expect(FiscalTemplate::count())->toBe(0);

    $key = $suggestions['Bebidas – Simples PR']['key'];
    Livewire::actingAs($admin)->test(Templates::class)
        ->assertSee('Sugestões para começar')
        ->call('useSuggestion', $key)
        ->assertSet('showForm', true)
        ->assertSet('name', 'Bebidas – Simples PR')
        ->assertSet('crt', '1')
        ->assertSet('state', 'PR')
        ->assertSet('fields.ncm', '22021000')
        ->assertSet('fields.icms_situation', '500')
        ->call('saveTemplate')
        ->assertHasNoErrors()
        ->assertDontSee('Igual a 2 produto(s)');

    // Sugestão salva some da lista; outras empresas não veem produtos desta.
    expect(collect(app(FiscalTemplateService::class)->suggestions($company->id))->pluck('key'))->not->toContain($key);
    $other = templateContext();
    expect(collect(app(FiscalTemplateService::class)->suggestions($other['company']->id))->pluck('name')->all())
        ->not->toContain('Bebidas – Simples PR');
});

test('modelo novo já vem com regime e UF das filiais; padrão CSOSN some no regime normal', function () {
    ['company' => $company, 'admin' => $admin, 'branches' => $branches] = templateContext();

    Livewire::actingAs($admin)->test(Templates::class)
        ->call('newTemplate')
        ->assertSet('crt', '1')
        ->assertSet('state', 'PR')
        ->assertSet('name', '');

    CompanyFiscalConfig::where('company_id', $company->id)->update(['crt' => 3]);
    expect(collect(app(FiscalTemplateService::class)->suggestions($company->id))->where('generic', true))->toBeEmpty();

    // Filiais com regimes diferentes: o formulário não escolhe sozinho.
    CompanyFiscalConfig::where('branch_id', $branches[1]->id)->update(['crt' => 1]);
    Livewire::actingAs($admin)->test(Templates::class)->call('newTemplate')->assertSet('crt', '')->assertSet('state', 'PR');

    // Chave inexistente ou forjada não abre nada.
    Livewire::actingAs($admin)->test(Templates::class)->call('useSuggestion', 'nao-existe')->assertNotFound();
});
