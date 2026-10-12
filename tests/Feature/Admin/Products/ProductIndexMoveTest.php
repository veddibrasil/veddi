<?php

use App\Livewire\Admin\Products\Form;
use App\Livewire\Admin\Products\Index;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function moveTestSetup(string $suffix): array
{
    $company = Company::create([
        'name' => 'Move '.$suffix,
        'slug' => 'move-'.$suffix.'-'.uniqid(),
        'order_prefix' => 'MV'.strtoupper($suffix),
        'active' => true,
        'plan' => 'pro',
    ]);
    app()->instance('current.company', $company);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    $category = ProductCategory::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'name' => 'Salgados', 'active' => true, 'sort_order' => 1,
    ]);

    $products = collect(['Coxinha', 'Risole', 'Bolinha'])->map(fn ($name, $i) => Product::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'product_category_id' => $category->id,
        'name' => $name, 'price' => 8.0, 'active' => true, 'sort_order' => $i + 1,
    ]));

    return compact('company', 'admin', 'category', 'products');
}

test('moveProduct desce e sobe um produto trocando de posição com o vizinho', function () {
    ['admin' => $admin, 'products' => [$a, $b, $c]] = moveTestSetup('a');

    $component = Livewire::actingAs($admin)->test(Index::class);

    $component->call('moveProduct', $a->id, 'down');
    expect(Product::withoutGlobalScopes()->where('product_category_id', $a->product_category_id)->orderBy('sort_order')->pluck('name')->all())
        ->toBe(['Risole', 'Coxinha', 'Bolinha']);

    $component->call('moveProduct', $c->id, 'up');
    expect(Product::withoutGlobalScopes()->where('product_category_id', $a->product_category_id)->orderBy('sort_order')->pluck('name')->all())
        ->toBe(['Risole', 'Bolinha', 'Coxinha']);
});

test('moveProduct no primeiro (subir) ou no último (descer) não muda nada', function () {
    ['admin' => $admin, 'products' => [$a, $b, $c]] = moveTestSetup('b');

    $component = Livewire::actingAs($admin)->test(Index::class);
    $component->call('moveProduct', $a->id, 'up')->call('moveProduct', $c->id, 'down')->call('moveProduct', $b->id, 'sideways');

    expect(Product::withoutGlobalScopes()->where('product_category_id', $a->product_category_id)->orderBy('sort_order')->pluck('name')->all())
        ->toBe(['Coxinha', 'Risole', 'Bolinha']);
});

test('moveProduct não mexe em produto de outra empresa', function () {
    ['admin' => $admin] = moveTestSetup('c');
    $other = moveTestSetup('d');
    app()->instance('current.company', Company::find($admin->companies()->first()->id));

    // CompanyScope esconde o produto alheio: findOrFail estoura antes de qualquer escrita.
    expect(fn () => Livewire::actingAs($admin)->test(Index::class)->call('moveProduct', $other['products'][0]->id, 'down'))
        ->toThrow(ModelNotFoundException::class);

    expect($other['products'][0]->refresh()->sort_order)->toBe(1);
});

test('moveCategory troca a ordem de duas categorias da empresa', function () {
    ['admin' => $admin, 'company' => $company, 'category' => $first] = moveTestSetup('e');
    $second = ProductCategory::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'name' => 'Bebidas', 'active' => true, 'sort_order' => 2,
    ]);

    Livewire::actingAs($admin)->test(Index::class)->call('moveCategory', $first->id, 'down');

    expect($first->refresh()->sort_order)->toBe(1);
    expect($second->refresh()->sort_order)->toBe(0);
});

test('modo reordenar exibe botões sobe/desce acessíveis, desabilitados nas pontas', function () {
    ['admin' => $admin] = moveTestSetup('f');

    Livewire::actingAs($admin)->test(Index::class)
        ->call('toggleReorderMode')
        ->assertSeeHtml('aria-label="Subir Coxinha"')
        ->assertSeeHtml('aria-label="Descer Bolinha"')
        ->assertSeeHtml('aria-label="Subir a categoria Salgados"');
});

test('excluir/cancelar zera deletingId para o modal poder reabrir para o mesmo produto', function () {
    ['admin' => $admin, 'products' => [$a]] = moveTestSetup('g');

    Livewire::actingAs($admin)->test(Index::class)
        ->call('confirmDelete', $a->id)->assertSet('deletingId', $a->id)
        ->call('cancelDelete')->assertSet('deletingId', null)
        ->call('confirmDelete', $a->id)->assertSet('deletingId', $a->id);
});

test('lista de produtos tem rótulos acessíveis nos botões só-ícone e busca com debounce', function () {
    ['admin' => $admin] = moveTestSetup('h');

    Livewire::actingAs($admin)->test(Index::class)
        ->assertSeeHtml('aria-label="Editar Coxinha"')
        ->assertSeeHtml('aria-label="Excluir Coxinha"')
        ->assertSeeHtml('aria-label="Estoque de Coxinha"')
        ->assertSeeHtml('wire:model.live.debounce.300ms="search"');
});

test('produto com variações não pode ter o 1º grupo pulável ou com mínimo 0 (item viraria R$ 0,00)', function () {
    ['admin' => $admin, 'company' => $company, 'category' => $category] = moveTestSetup('i');
    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'name' => 'Filial', 'address' => 'Rua A, 1', 'city' => 'SP',
        'active' => true, 'opens_at' => '00:00:00', 'closes_at' => '23:59:59',
    ]);

    $group = fn (int $min, bool $skip) => [[
        'group_id' => null, 'key' => 'g1', 'name' => 'Tamanho', 'total_qty' => 1, 'min_qty' => (string) $min,
        'fixed' => false, 'allow_skip' => $skip, 'image_path' => null,
        'options' => [['id' => null, 'key' => 'o1', 'name' => 'Grande', 'additional_price' => '15.00', 'default_qty' => 0, 'max_qty' => '', 'description' => '', 'active' => true, 'sort_order' => 0, 'image_path' => null]],
    ]];

    $this->actingAs($admin);

    Livewire::test(Form::class)
        ->set('product_category_id', $category->id)
        ->set('name', 'Pizza')
        ->set('isVariant', true)
        ->set('selectedBranches', [(string) $branch->id])
        ->set('optionGroups', $group(0, false))
        ->call('save')
        ->assertHasErrors(['optionGroups.0.min_qty']);

    Livewire::test(Form::class)
        ->set('product_category_id', $category->id)
        ->set('name', 'Pizza')
        ->set('isVariant', true)
        ->set('selectedBranches', [(string) $branch->id])
        ->set('optionGroups', $group(1, true))
        ->call('save')
        ->assertHasErrors(['optionGroups.0.min_qty']);

    expect(Product::withoutGlobalScopes()->where('name', 'Pizza')->exists())->toBeFalse();
});

test('moveProduct e moveCategory exigem permissão products.update', function () {
    ['category' => $category, 'products' => [$a]] = moveTestSetup('j');

    $viewer = User::factory()->create(); // sem vínculo com a empresa: hasPermission() nega

    // Uma resposta 403 encerra o componente de teste: cada ação ganha o seu.
    Livewire::actingAs($viewer)->test(Index::class)->call('moveProduct', $a->id, 'down')->assertForbidden();
    Livewire::actingAs($viewer)->test(Index::class)->call('moveCategory', $category->id, 'down')->assertForbidden();

    expect($a->refresh()->sort_order)->toBe(1);
});
