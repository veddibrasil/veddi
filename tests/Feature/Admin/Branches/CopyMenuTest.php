<?php

use App\Livewire\Admin\Branches\Form;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\Company\BranchMenuCopier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function cmCompany(string $name = 'Cópia Teste'): Company
{
    $company = Company::create([
        'name' => $name.' '.uniqid(),
        'slug' => 'copia-teste-'.uniqid(),
        'order_prefix' => 'CPM',
        'active' => true,
        'plan' => 'pro',
    ]);

    app()->instance('current.company', $company);

    return $company;
}

function cmBranch(Company $company, string $name = 'Filial Centro', array $overrides = []): Branch
{
    return Branch::withoutGlobalScopes()->create(array_merge([
        'company_id' => $company->id,
        'name' => $name,
        'address' => 'Rua A, 1',
        'city' => 'Maringá',
        'state' => 'PR',
        'cep' => '87013-000',
        'active' => true,
    ], $overrides));
}

function cmProduct(Company $company, string $name, ?Branch $branch = null, array $pivot = []): Product
{
    $category = ProductCategory::withoutGlobalScopes()->firstOrCreate(
        ['company_id' => $company->id, 'name' => 'Salgados'],
        ['active' => true, 'sort_order' => 1],
    );

    $product = Product::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'product_category_id' => $category->id,
        'name' => $name,
        'price' => 8.00,
        'active' => true,
        'sort_order' => 1,
    ]);

    if ($branch) {
        DB::table('branch_product')->insert(array_merge([
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'available' => true,
        ], $pivot));
    }

    return $product;
}

function cmAdmin(Company $company): User
{
    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    return $admin;
}

function cmPivot(Branch $branch, Product $product): ?object
{
    return DB::table('branch_product')
        ->where('branch_id', $branch->id)
        ->where('product_id', $product->id)
        ->first();
}

function cmFillNewBranch(\Livewire\Features\SupportTesting\Testable $component, string $name = 'Filial Nova'): \Livewire\Features\SupportTesting\Testable
{
    return $component
        ->set('name', $name)
        ->set('address', 'Av. Brasil')
        ->set('city', 'Maringá')
        ->set('state', 'PR')
        ->set('cep', '87013-000');
}

// ─── Criação copiando o cardápio ────────────────────────────────────────────

test('criar filial copiando o cardápio vincula os mesmos produtos sem copiar estoque nem iFood', function () {
    $company = cmCompany();
    $source = cmBranch($company);

    $simple = cmProduct($company, 'Coxinha', $source, ['ifood_item_id' => 'ifood-item-coxinha', 'ifood_sync_error' => 'erro antigo']);
    $tracked = cmProduct($company, 'Kibe', $source, ['track_stock' => true, 'quantity' => 10, 'min_quantity' => 2]);
    $paused = cmProduct($company, 'Esfiha', $source, ['available' => false]);
    $removed = cmProduct($company, 'Pastel removido', $source);
    $removed->delete();
    $otherBranchOnly = cmProduct($company, 'Só de outra filial', cmBranch($company, 'Filial Bairro'));

    Livewire::actingAs(cmAdmin($company))
        ->test(Form::class)
        ->tap(fn ($c) => cmFillNewBranch($c))
        ->set('copyMenuFromBranchId', (string) $source->id)
        ->call('save')
        ->assertHasNoErrors();

    $new = Branch::withoutGlobalScopes()->where('name', 'Filial Nova')->firstOrFail();

    $simplePivot = cmPivot($new, $simple);
    expect((bool) $simplePivot->available)->toBeTrue()
        ->and((int) $simplePivot->quantity)->toBe(0)
        ->and($simplePivot->ifood_item_id)->toBeNull()
        ->and($simplePivot->ifood_sync_error)->toBeNull();

    // Estoque é físico: controle e mínimo vêm junto, quantidade não; sem estoque, fora de venda.
    $trackedPivot = cmPivot($new, $tracked);
    expect((bool) $trackedPivot->track_stock)->toBeTrue()
        ->and((int) $trackedPivot->min_quantity)->toBe(2)
        ->and((int) $trackedPivot->quantity)->toBe(0)
        ->and((bool) $trackedPivot->available)->toBeFalse();

    expect((bool) cmPivot($new, $paused)->available)->toBeFalse();
    expect(cmPivot($new, $removed))->toBeNull();
    expect(cmPivot($new, $otherBranchOnly))->toBeNull();

    // A filial de origem fica como estava.
    expect((int) cmPivot($source, $tracked)->quantity)->toBe(10)
        ->and(cmPivot($source, $simple)->ifood_item_id)->toBe('ifood-item-coxinha');

    expect(session('status'))
        ->toContain('3 produto(s) do cardápio de Filial Centro')
        ->toContain('Próximo passo');
});

test('cardápio copiado aparece no chat da nova filial com a categoria', function () {
    $company = cmCompany();
    $source = cmBranch($company);
    cmProduct($company, 'Coxinha', $source);

    Livewire::actingAs(cmAdmin($company))
        ->test(Form::class)
        ->tap(fn ($c) => cmFillNewBranch($c))
        ->set('copyMenuFromBranchId', (string) $source->id)
        ->call('save')
        ->assertHasNoErrors();

    $new = Branch::withoutGlobalScopes()->where('name', 'Filial Nova')->firstOrFail();

    $menu = ProductCategory::withoutGlobalScopes()
        ->where('company_id', $company->id)
        ->with(['products' => fn ($q) => $q->join('branch_product as bp', fn ($join) => $join
            ->on('bp.product_id', '=', 'products.id')
            ->where('bp.branch_id', $new->id))])
        ->get();

    expect($menu->firstWhere('name', 'Salgados')->products->pluck('name')->all())->toBe(['Coxinha']);
});

test('sem escolher a origem a filial nasce sem produtos', function () {
    $company = cmCompany();
    $source = cmBranch($company);
    cmProduct($company, 'Coxinha', $source);

    Livewire::actingAs(cmAdmin($company))
        ->test(Form::class)
        ->tap(fn ($c) => cmFillNewBranch($c))
        ->call('save')
        ->assertHasNoErrors();

    $new = Branch::withoutGlobalScopes()->where('name', 'Filial Nova')->firstOrFail();

    expect(DB::table('branch_product')->where('branch_id', $new->id)->count())->toBe(0);
    expect(session('status'))->toStartWith('Filial criada!');
});

// ─── Tela ───────────────────────────────────────────────────────────────────

test('form de criação oferece as filiais da empresa com a contagem de produtos', function () {
    $company = cmCompany();
    $source = cmBranch($company);
    cmProduct($company, 'Coxinha', $source);
    cmProduct($company, 'Kibe', $source);
    cmBranch($company, 'Filial Fechada', ['active' => false]);

    Livewire::actingAs(cmAdmin($company))
        ->test(Form::class)
        ->assertSee('Copiar produtos e categorias de')
        ->assertSee('Não copiar — começar sem produtos')
        ->assertSee('Filial Centro — 2 produto(s)')
        ->assertSee('Filial Fechada (inativa) — 0 produto(s)')
        ->assertSee('O estoque não é copiado');
});

test('primeira filial e edição não mostram a cópia de cardápio', function () {
    $company = cmCompany();
    $admin = cmAdmin($company);

    Livewire::actingAs($admin)->test(Form::class)
        ->assertDontSee('Copiar produtos e categorias de');

    $branch = cmBranch($company);

    Livewire::actingAs($admin)->test(Form::class, ['branch' => $branch])
        ->assertDontSee('Copiar produtos e categorias de');
});

// ─── Isolamento entre empresas ──────────────────────────────────────────────

test('não copia cardápio de filial de outra empresa', function () {
    $other = cmCompany('Outra');
    $foreign = cmBranch($other, 'Filial Secreta');
    cmProduct($other, 'Produto Secreto', $foreign);

    $company = cmCompany();
    cmBranch($company);

    Livewire::actingAs(cmAdmin($company))
        ->test(Form::class)
        ->assertDontSee('Filial Secreta')
        ->tap(fn ($c) => cmFillNewBranch($c))
        ->set('copyMenuFromBranchId', (string) $foreign->id)
        ->call('save')
        ->assertHasErrors(['copyMenuFromBranchId']);

    expect(Branch::withoutGlobalScopes()->where('name', 'Filial Nova')->exists())->toBeFalse();
});

test('company_id adulterado por quem não é super admin não lista nem copia filial de outra empresa', function () {
    $other = cmCompany('Outra');
    $foreign = cmBranch($other, 'Filial Secreta');
    cmProduct($other, 'Produto Secreto', $foreign);

    $company = cmCompany();
    cmBranch($company);

    Livewire::actingAs(cmAdmin($company))
        ->test(Form::class)
        ->set('company_id', $other->id)
        ->assertDontSee('Filial Secreta')
        ->tap(fn ($c) => cmFillNewBranch($c))
        ->set('copyMenuFromBranchId', (string) $foreign->id)
        ->call('save')
        ->assertHasErrors(['copyMenuFromBranchId']);

    expect(DB::table('branch_product')->where('branch_id', '!=', $foreign->id)->count())->toBe(0);
});

test('super admin copia dentro da empresa escolhida e trocar a empresa desfaz a escolha', function () {
    $companyA = cmCompany('Empresa A');
    $branchA = cmBranch($companyA, 'Filial A');
    cmProduct($companyA, 'Coxinha A', $branchA);

    $companyB = cmCompany('Empresa B');
    $branchB = cmBranch($companyB, 'Filial B');
    $productB = cmProduct($companyB, 'Coxinha B', $branchB);

    $superAdmin = User::factory()->create(['is_super_admin' => true]);

    Livewire::actingAs($superAdmin)
        ->test(Form::class)
        ->set('company_id', $companyA->id)
        ->set('copyMenuFromBranchId', (string) $branchA->id)
        ->set('company_id', $companyB->id)
        ->assertSet('copyMenuFromBranchId', '')
        ->assertSee('Filial B — 1 produto(s)')
        ->assertDontSee('Filial A —')
        ->tap(fn ($c) => cmFillNewBranch($c, 'Filial B2'))
        ->set('copyMenuFromBranchId', (string) $branchB->id)
        ->call('save')
        ->assertHasNoErrors();

    $new = Branch::withoutGlobalScopes()->where('name', 'Filial B2')->firstOrFail();

    expect((int) $new->company_id)->toBe($companyB->id)
        ->and(cmPivot($new, $productB))->not->toBeNull()
        ->and(DB::table('branch_product')->where('branch_id', $new->id)->count())->toBe(1);
});

test('serviço recusa copiar entre filiais de empresas diferentes', function () {
    $companyA = cmCompany('Empresa A');
    $branchA = cmBranch($companyA);
    cmProduct($companyA, 'Coxinha', $branchA);

    $companyB = cmCompany('Empresa B');
    $branchB = cmBranch($companyB);

    expect(fn () => app(BranchMenuCopier::class)->copy($branchA, $branchB))
        ->toThrow(InvalidArgumentException::class);

    expect(DB::table('branch_product')->where('branch_id', $branchB->id)->count())->toBe(0);
});

test('serviço não duplica produto que a filial de destino já vende', function () {
    $company = cmCompany();
    $source = cmBranch($company);
    $target = cmBranch($company, 'Filial Bairro');
    $shared = cmProduct($company, 'Coxinha', $source);
    cmProduct($company, 'Kibe', $source);

    DB::table('branch_product')->insert(['branch_id' => $target->id, 'product_id' => $shared->id, 'available' => false]);

    expect(app(BranchMenuCopier::class)->copy($source, $target))->toBe(1);
    expect(DB::table('branch_product')->where('branch_id', $target->id)->count())->toBe(2)
        ->and((bool) cmPivot($target, $shared)->available)->toBeFalse();
});
