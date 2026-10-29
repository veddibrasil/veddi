<?php

/*
 * Atalhos das sugestões de venda (Itens vendidos): cupom, produto e estoque abrem já
 * preenchidos pela query string, sem gravar nada, e ignoram ids de outra empresa.
 */

use App\Livewire\Admin\Coupons\Index as CouponsIndex;
use App\Livewire\Admin\Products\Form as ProductForm;
use App\Livewire\Admin\Stock\Index as StockIndex;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Coupon;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Models\UserPermission;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
});

function sprCompany(string $slug): array
{
    $company = Company::create(['name' => 'Empresa '.$slug, 'slug' => $slug, 'order_prefix' => strtoupper(substr($slug, 0, 3)), 'active' => true]);

    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'name' => 'Centro '.$slug, 'address' => 'Rua A, 1', 'city' => 'SP',
        'active' => true, 'opens_at' => '00:00:00', 'closes_at' => '23:59:59',
    ]);
    $category = ProductCategory::withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => 'Lanches', 'active' => true, 'sort_order' => 1]);
    $product = Product::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'product_category_id' => $category->id, 'name' => 'X-Burguer '.$slug,
        'price' => 30, 'active' => true, 'sort_order' => 1,
    ]);

    return compact('company', 'branch', 'category', 'product');
}

function sprAdmin(Company $company, string $role = 'company_admin'): User
{
    $user = User::factory()->create();
    $user->companies()->attach($company->id, ['role' => $role]);
    app()->instance('current.company', $company);

    return $user;
}

test('cupom abre pré-preenchido pela sugestão e não grava nada', function () {
    $own = sprCompany('loja-a');
    $foreign = sprCompany('loja-b');
    $admin = sprAdmin($own['company']);

    Livewire::withQueryParams([
        'sugestao' => '1',
        'escopo' => 'product',
        'produtos' => $own['product']->id.','.$foreign['product']->id,
        'valor' => '10',
        'inicio' => '2026-10-30T17:00',
        'fim' => '2026-10-30T21:00',
        'nome' => '<b>Pico</b> X-Burguer',
        'codigo' => 'pico-1 ção!',
        'max_usos' => '50',
    ])->actingAs($admin)->test(CouponsIndex::class)
        ->assertSet('showModal', true)
        ->assertSet('prefilledFromSuggestion', true)
        ->assertSet('type', 'percentage')
        ->assertSet('scope', 'product')
        ->assertSet('scope_ids', [(string) $own['product']->id])
        ->assertSet('discount_value', 10.0)
        ->assertSet('starts_at', '2026-10-30T17:00')
        ->assertSet('expires_at', '2026-10-30T21:00')
        ->assertSet('name', 'Pico X-Burguer')
        ->assertSet('code', 'PICO1CAO')
        ->assertSet('max_uses', 50)
        ->assertSet('max_uses_per_customer', 1)
        ->assertSee('Pré-preenchido por uma sugestão de vendas');

    expect(Coupon::withoutGlobalScopes()->count())->toBe(0);
});

test('código de cupom já usado ganha sufixo no pré-preenchimento', function () {
    $own = sprCompany('loja-a');
    $admin = sprAdmin($own['company']);
    foreach (['PEDEDIRETO', 'PEDEDIRETO2'] as $code) {
        Coupon::withoutGlobalScopes()->create(['company_id' => $own['company']->id, 'code' => $code, 'name' => $code, 'type' => 'percentage', 'discount_value' => 10, 'scope' => 'order', 'active' => true]);
    }

    Livewire::withQueryParams(['sugestao' => '1', 'escopo' => 'order', 'valor' => '10', 'codigo' => 'PEDEDIRETO'])
        ->actingAs($admin)->test(CouponsIndex::class)
        ->assertSet('code', 'PEDEDIRETO3');
});

test('cupom de produto sem produto válido do tenant não pré-preenche (não vira desconto no pedido todo)', function () {
    $own = sprCompany('loja-a');
    $foreign = sprCompany('loja-b');
    $admin = sprAdmin($own['company']);

    Livewire::withQueryParams(['sugestao' => '1', 'escopo' => 'product', 'produtos' => (string) $foreign['product']->id, 'valor' => '10'])
        ->actingAs($admin)->test(CouponsIndex::class)
        ->assertSet('showModal', false)
        ->assertSet('prefilledFromSuggestion', false)
        ->assertSet('scope', 'order');
});

test('cupom ignora desconto fora de 1-100, datas inválidas e fim antes do início', function () {
    $own = sprCompany('loja-a');
    $admin = sprAdmin($own['company']);

    Livewire::withQueryParams(['sugestao' => '1', 'escopo' => 'order', 'valor' => '500', 'inicio' => '2026-13-45T99:00', 'fim' => 'amanhã'])
        ->actingAs($admin)->test(CouponsIndex::class)
        ->assertSet('showModal', true)
        ->assertSet('scope', 'order')
        ->assertSet('discount_value', null)
        ->assertSet('starts_at', null)
        ->assertSet('expires_at', null);

    Livewire::withQueryParams(['sugestao' => '1', 'inicio' => '2026-10-30T17:00', 'fim' => '2026-10-29T17:00'])
        ->actingAs($admin)->test(CouponsIndex::class)
        ->assertSet('starts_at', '2026-10-30T17:00')
        ->assertSet('expires_at', null);
});

test('sem coupons.create o atalho não abre o formulário', function () {
    $own = sprCompany('loja-a');
    $admin = sprAdmin($own['company']);
    UserPermission::create([
        'user_id' => $admin->id,
        'company_id' => $own['company']->id,
        'permission_id' => Permission::where('name', 'coupons.create')->value('id'),
        'granted' => false,
    ]);

    Livewire::withQueryParams(['sugestao' => '1', 'escopo' => 'order', 'valor' => '10'])
        ->actingAs($admin)->test(CouponsIndex::class)
        ->assertSet('canCreate', false)
        ->assertSet('showModal', false);
});

test('produto: ?promo= liga promoção percentual no formulário sem salvar', function () {
    $own = sprCompany('loja-a');
    $admin = sprAdmin($own['company']);

    Livewire::withQueryParams(['promo' => '15'])
        ->actingAs($admin)->test(ProductForm::class, ['product' => $own['product']->fresh()])
        ->assertSet('promo_price_enabled', true)
        ->assertSet('promo_price_type', 'percentage')
        ->assertSet('promo_price_value', '15')
        ->assertSet('promoFromSuggestion', true)
        ->assertSee('pré-preenchido por uma sugestão de vendas');

    expect((bool) $own['product']->fresh()->promo_price_enabled)->toBeFalse();
});

test('produto: ?promo= é ignorado fora de 1-50, em variante e com promoção já configurada', function (array $attributes, string $promo) {
    $own = sprCompany('loja-a');
    $admin = sprAdmin($own['company']);
    $own['product']->forceFill($attributes)->saveQuietly();

    $component = Livewire::withQueryParams(['promo' => $promo])
        ->actingAs($admin)->test(ProductForm::class, ['product' => $own['product']->fresh()])
        ->assertSet('promoFromSuggestion', false);

    if (! ($attributes['promo_price_enabled'] ?? false)) {
        $component->assertSet('promo_price_enabled', false);
    } else {
        $component->assertSet('promo_price_type', 'fixed')->assertSet('promo_price_value', '25.00');
    }
})->with([
    'acima de 50' => [[], '80'],
    'zero' => [[], '0'],
    'texto' => [[], 'abc'],
    'variante' => [['is_variant' => true], '15'],
    'promo já ligada' => [['promo_price_enabled' => true, 'promo_price_type' => 'fixed', 'promo_price_value' => 25], '15'],
]);

test('estoque: ?filial= seleciona filial do tenant e ignora filial de outra empresa', function () {
    $own = sprCompany('loja-a');
    $foreign = sprCompany('loja-b');
    $second = Branch::withoutGlobalScopes()->create([
        'company_id' => $own['company']->id, 'name' => 'Bairro', 'address' => 'Rua B, 2', 'city' => 'SP',
        'active' => true, 'opens_at' => '00:00:00', 'closes_at' => '23:59:59',
    ]);
    $admin = sprAdmin($own['company']);

    Livewire::withQueryParams(['filial' => (string) $second->id, 'search' => 'X-Burguer'])
        ->actingAs($admin)->test(StockIndex::class)
        ->assertSet('branchFilter', $second->id)
        ->assertSet('search', 'X-Burguer');

    Livewire::withQueryParams(['filial' => (string) $foreign['branch']->id])
        ->actingAs($admin)->test(StockIndex::class)
        ->assertSet('branchFilter', null);
});
