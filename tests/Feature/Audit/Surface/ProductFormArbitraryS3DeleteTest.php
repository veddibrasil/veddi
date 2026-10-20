<?php

use App\Livewire\Admin\Products\Form;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductOption;
use App\Models\ProductOptionGroup;
use App\Models\User;
use App\Services\Company\UserPermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Auditoria 15/10 (superfície/uploads) — Products/Form confia no `image_path` que vem do
 * navegador dentro de `optionGroups` (prop pública array). Ao enviar uma imagem nova para o
 * grupo/opção, o save() apaga do S3 o caminho que o client mandou:
 *
 *   $groupImagePath = $groupData['image_path'];            // valor do client
 *   Storage::disk('s3')->delete($groupImagePath);          // qualquer chave do bucket
 *
 * Qualquer company_admin/branch_manager (inclusive quem se cadastrou no plano free, ativo na
 * hora) apaga logo/fotos de qualquer outra empresa — os caminhos aparecem nas URLs públicas do
 * cardápio (image_url = AWS_URL + image_path).
 */
function s3DeleteContext(): array
{
    Storage::fake('s3');

    $victim = Company::create([
        'name' => 'Empresa Vitima S3',
        'slug' => 'vitima-s3-'.uniqid(),
        'order_prefix' => 'VS3',
        'active' => true,
        'plan' => 'pro',
        'logo_path' => 'logos/company_vitima.png',
    ]);
    Storage::disk('s3')->put('logos/company_vitima.png', 'logo-da-vitima');
    Storage::disk('s3')->put('products/foto-da-vitima.jpg', 'foto-da-vitima');

    $attacker = Company::create([
        'name' => 'Empresa Atacante S3',
        'slug' => 'atacante-s3-'.uniqid(),
        'order_prefix' => 'AS3',
        'active' => true,
        'plan' => 'pro',
    ]);
    app()->instance('current.company', $attacker);

    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $attacker->id,
        'name' => 'Filial A',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    $category = ProductCategory::withoutGlobalScopes()->create([
        'company_id' => $attacker->id,
        'name' => 'Lanches',
        'active' => true,
        'sort_order' => 1,
    ]);

    $product = Product::withoutGlobalScopes()->create([
        'company_id' => $attacker->id,
        'product_category_id' => $category->id,
        'name' => 'Lanche do atacante',
        'price' => 10,
        'active' => true,
        'available_in_delivery' => true,
        'available_in_pdv' => true,
        'available_in_ifood' => false,
        'sort_order' => 1,
    ]);
    DB::table('branch_product')->insert(['branch_id' => $branch->id, 'product_id' => $product->id, 'available' => 1]);

    $group = ProductOptionGroup::create([
        'company_id' => $attacker->id,
        'name' => 'Adicionais',
        'total_qty' => 1,
        'min_qty' => 0,
        'fixed' => false,
    ]);
    $product->optionGroups()->attach($group->id, ['sort_order' => 0]);

    $option = ProductOption::create([
        'product_option_group_id' => $group->id,
        'name' => 'Bacon',
        'additional_price' => 2,
    ]);

    $admin = User::factory()->create();
    $admin->companies()->attach($attacker->id, ['role' => 'company_admin']);
    UserPermissionService::assignRolePermissions($admin, $attacker, 'company_admin');

    return compact('victim', 'attacker', 'product', 'group', 'option', 'admin');
}

test('upload de imagem de grupo não apaga do S3 o caminho arbitrário mandado pelo navegador', function () {
    ['product' => $product, 'group' => $group, 'admin' => $admin] = s3DeleteContext();

    $this->actingAs($admin);

    Livewire::test(Form::class, ['product' => $product])
        ->assertSet('canSave', true)
        ->set('optionGroups.0.image_path', 'logos/company_vitima.png')
        ->set('groupImages.gid-'.$group->id, UploadedFile::fake()->image('novo.png'))
        ->call('save');

    expect(Storage::disk('s3')->exists('logos/company_vitima.png'))->toBeTrue();
});

test('upload de imagem de opção não apaga do S3 o caminho arbitrário mandado pelo navegador', function () {
    ['product' => $product, 'group' => $group, 'option' => $option, 'admin' => $admin] = s3DeleteContext();

    $this->actingAs($admin);

    Livewire::test(Form::class, ['product' => $product])
        ->set('optionGroups.0.options.0.image_path', 'products/foto-da-vitima.jpg')
        ->set('optionImages.gid-'.$group->id.'_oid-'.$option->id, UploadedFile::fake()->image('novo.png'))
        ->call('save');

    expect(Storage::disk('s3')->exists('products/foto-da-vitima.jpg'))->toBeTrue();
});

test('controle: o mesmo upload sem adulterar image_path mantém o arquivo da outra empresa', function () {
    ['product' => $product, 'group' => $group, 'admin' => $admin] = s3DeleteContext();

    $this->actingAs($admin);

    Livewire::test(Form::class, ['product' => $product])
        ->set('groupImages.gid-'.$group->id, UploadedFile::fake()->image('novo.png'))
        ->call('save')
        ->assertHasNoErrors();

    expect(Storage::disk('s3')->exists('logos/company_vitima.png'))->toBeTrue();
});
