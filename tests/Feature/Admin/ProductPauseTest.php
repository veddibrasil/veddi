<?php

use App\Jobs\SyncIfoodCatalogJob;
use App\Livewire\Admin\Products\Pauses;
use App\Livewire\Admin\Roles\Index as RolesIndex;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\User;
use App\Services\Company\BranchMenuCopier;
use App\Services\Company\UserPermissionService;
use App\Services\Order\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function pauseContext(): array
{
    $company = Company::create([
        'name' => 'Empresa '.uniqid(),
        'slug' => 'empresa-'.uniqid(),
        'order_prefix' => 'PS',
        'active' => true,
        'pdv_module_enabled' => true,
    ]);
    app()->instance('current.company', $company);

    $branch = Branch::create(['company_id' => $company->id, 'name' => 'Centro', 'active' => true]);
    $other = Branch::create(['company_id' => $company->id, 'name' => 'Bairro', 'active' => true]);
    $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'Salgados', 'active' => true]);

    $coxinha = Product::create(['company_id' => $company->id, 'product_category_id' => $category->id, 'name' => 'Coxinha', 'price' => 8, 'active' => true]);
    $kibe = Product::create(['company_id' => $company->id, 'product_category_id' => $category->id, 'name' => 'Kibe', 'price' => 7, 'active' => true]);

    foreach ([$branch, $other] as $b) {
        DB::table('branch_product')->insert([
            ['branch_id' => $b->id, 'product_id' => $coxinha->id, 'available' => true, 'track_stock' => false, 'quantity' => 0],
            ['branch_id' => $b->id, 'product_id' => $kibe->id, 'available' => true, 'track_stock' => true, 'quantity' => 5],
        ]);
    }

    return compact('company', 'branch', 'other', 'coxinha', 'kibe');
}

function staffOf(Company $company, string $role, ?Branch $branch = null): User
{
    $user = User::factory()->create();
    $user->companies()->attach($company->id, ['role' => $role, 'branch_id' => $branch?->id]);
    UserPermissionService::assignRolePermissions($user, $company, $role);

    return $user;
}

function pivotOf(Branch $branch, Product $product): object
{
    return DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $product->id)->first();
}

test('migration dá products.pause a admin, gerente, caixa, cozinha e bar, e só a eles', function () {
    $withPause = Role::whereNull('company_id')
        ->whereHas('permissions', fn ($q) => $q->where('name', 'products.pause'))
        ->pluck('slug')->sort()->values()->all();

    expect($withPause)->toBe(['bar', 'branch_manager', 'caixa', 'company_admin', 'cozinha']);
});

test('caixa, cozinha e bar veem "Pausar produtos" no menu e abrem a tela; entrega não', function (string $role, bool $allowed) {
    ['company' => $company, 'branch' => $branch] = pauseContext();
    $user = staffOf($company, $role, $branch);

    $home = route(User::homeRouteForRole($role));
    $response = $this->actingAs($user)->get($home)->assertOk();

    if ($allowed) {
        $response->assertSee('href="'.route('admin.products.pauses').'"', false);
        $this->actingAs($user)->get(route('admin.products.pauses'))->assertOk()->assertSee('Coxinha');
    } else {
        $response->assertDontSee('href="'.route('admin.products.pauses').'"', false);
        $this->actingAs($user)->get(route('admin.products.pauses'))->assertForbidden();
    }
})->with([
    'caixa' => ['caixa', true],
    'cozinha' => ['cozinha', true],
    'bar' => ['bar', true],
    'entrega' => ['entrega', false],
]);

test('cozinha pausa e o item sai de venda na filial (chat, PDV e marketplaces leem available)', function () {
    ['company' => $company, 'branch' => $branch, 'other' => $other, 'coxinha' => $coxinha] = pauseContext();
    $cook = staffOf($company, 'cozinha', $branch);

    Livewire::actingAs($cook)->test(Pauses::class)
        ->assertSet('branchId', $branch->id)
        ->call('pause', $coxinha->id)
        ->assertSee('Coxinha pausado em Centro');

    $pivot = pivotOf($branch, $coxinha);
    expect((bool) $pivot->available)->toBeFalse()
        ->and($pivot->paused_at)->not->toBeNull()
        ->and($pivot->paused_by)->toBe($cook->id)
        ->and((bool) pivotOf($other, $coxinha)->available)->toBeTrue();

    // Mesma consulta do catálogo do PDV/chat: o item some.
    $sellable = Product::whereHas('branches', fn ($q) => $q->where('branches.id', $branch->id)->where('branch_product.available', true))->pluck('name');
    expect($sellable->all())->toBe(['Kibe']);
});

test('caixa e cozinha usam ao mesmo tempo: pausa repetida não troca quem pausou e o caixa retoma', function () {
    ['company' => $company, 'branch' => $branch, 'coxinha' => $coxinha] = pauseContext();
    $cook = staffOf($company, 'cozinha', $branch);
    $cashier = staffOf($company, 'caixa', $branch);

    $kitchenScreen = Livewire::actingAs($cook)->test(Pauses::class);
    $cashierScreen = Livewire::actingAs($cashier)->test(Pauses::class);

    // Cada chamada roda como o dono da tela (o login é global no teste).
    $this->actingAs($cook);
    $kitchenScreen->call('pause', $coxinha->id);
    $this->actingAs($cashier);
    $cashierScreen->call('pause', $coxinha->id); // tela do caixa ainda mostrava "à venda"

    expect(pivotOf($branch, $coxinha)->paused_by)->toBe($cook->id);

    // A tela do caixa, ao atualizar, mostra quem pausou.
    $cashierScreen->call('$refresh')->assertSee('Pausado por '.$cook->name);

    $cashierScreen->call('resume', $coxinha->id)->assertSee('Coxinha voltou à venda em Centro');

    $pivot = pivotOf($branch, $coxinha);
    expect((bool) $pivot->available)->toBeTrue()
        ->and($pivot->paused_at)->toBeNull()
        ->and($pivot->paused_by)->toBeNull();
});

test('reposição de estoque não despausa; retomar respeita estoque zerado', function () {
    ['company' => $company, 'branch' => $branch, 'kibe' => $kibe] = pauseContext();
    $cashier = staffOf($company, 'caixa', $branch);
    $stock = app(StockService::class);

    $stock->pause($branch, $kibe, $cashier);
    $stock->adjust($branch, $kibe, 10, 'chegou mercadoria');

    expect((bool) pivotOf($branch, $kibe)->available)->toBeFalse()
        ->and((int) pivotOf($branch, $kibe)->quantity)->toBe(15);

    $stock->setQuantity($branch, $kibe, 0, 'acabou');
    $stock->resume($branch, $kibe, $cashier);

    // Retomado, mas sem estoque controlado: segue fora de venda até repor.
    expect((bool) pivotOf($branch, $kibe)->available)->toBeFalse()
        ->and(pivotOf($branch, $kibe)->paused_at)->toBeNull();

    $stock->adjust($branch, $kibe, 3);
    expect((bool) pivotOf($branch, $kibe)->available)->toBeTrue();
});

test('papel preso à filial não pausa produto de outra filial nem de outra empresa', function () {
    ['company' => $company, 'branch' => $branch, 'other' => $other, 'coxinha' => $coxinha] = pauseContext();
    $cook = staffOf($company, 'cozinha', $branch);

    Livewire::actingAs($cook)->test(Pauses::class)
        ->set('branchId', $other->id)
        ->call('pause', $coxinha->id)
        ->assertForbidden();

    expect(pivotOf($other, $coxinha)->paused_at)->toBeNull();

    $foreign = pauseContext(); // troca current.company para a outra empresa
    app()->instance('current.company', $company);

    expect(fn () => Livewire::actingAs($cook)->test(Pauses::class)->call('pause', $foreign['coxinha']->id))
        ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

    expect(pivotOf($foreign['branch'], $foreign['coxinha'])->paused_at)->toBeNull();
});

test('admin escolhe a filial e pausa só nela', function () {
    ['company' => $company, 'branch' => $branch, 'other' => $other, 'coxinha' => $coxinha] = pauseContext();
    $admin = staffOf($company, 'company_admin');

    Livewire::actingAs($admin)->test(Pauses::class)
        ->set('branchId', $other->id)
        ->call('pause', $coxinha->id)
        ->set('filter', 'paused')
        ->assertSee('Coxinha')
        ->assertDontSee('Kibe');

    expect(pivotOf($other, $coxinha)->paused_at)->not->toBeNull()
        ->and(pivotOf($branch, $coxinha)->paused_at)->toBeNull();
});

test('pausar avisa o iFood da filial', function () {
    Bus::fake([SyncIfoodCatalogJob::class]);
    $ctx = ifoodContext('PS');
    app()->instance('current.company', $ctx['company']);
    $cook = staffOf($ctx['company'], 'cozinha', $ctx['branch']);

    Livewire::actingAs($cook)->test(Pauses::class)->call('pause', $ctx['product']->id);

    Bus::assertDispatched(SyncIfoodCatalogJob::class);
});

test('tipo customizado com a permissão pausa; sem ela não vê nem abre', function () {
    ['company' => $company, 'branch' => $branch, 'coxinha' => $coxinha] = pauseContext();
    $admin = staffOf($company, 'company_admin');

    Livewire::actingAs($admin)->test(RolesIndex::class)
        ->set('name', 'Balcão')
        ->set('selectedPermissions', ['products.pause'])
        ->call('save')
        ->assertHasNoErrors();

    $counter = staffOf($company, 'balcao');

    $this->actingAs($counter)->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('href="'.route('admin.products.pauses').'"', false);

    // Tipo da empresa não é preso a filial: escolhe na tela.
    Livewire::actingAs($counter)->test(Pauses::class)->set('branchId', $branch->id)->call('pause', $coxinha->id);
    expect(pivotOf($branch, $coxinha)->paused_at)->not->toBeNull();

    // Super admin tirou a permissão do Bar: quem entra no papel depois não pausa.
    DB::table('role_permissions')
        ->where('role_id', Role::whereNull('company_id')->where('slug', 'bar')->value('id'))
        ->where('permission_id', DB::table('permissions')->where('name', 'products.pause')->value('id'))
        ->delete();
    $plain = staffOf($company, 'bar');

    $this->actingAs($plain)->get(route('admin.products.pauses'))->assertForbidden();
});

test('cópia de cardápio não leva a pausa para a filial nova', function () {
    ['company' => $company, 'branch' => $branch, 'coxinha' => $coxinha] = pauseContext();
    app(StockService::class)->pause($branch, $coxinha, staffOf($company, 'caixa', $branch));

    $target = Branch::create(['company_id' => $company->id, 'name' => 'Nova', 'active' => true]);
    app(BranchMenuCopier::class)->copy($branch, $target);

    $copied = pivotOf($target, $coxinha);
    expect((bool) $copied->available)->toBeTrue()
        ->and($copied->paused_at)->toBeNull();
});
