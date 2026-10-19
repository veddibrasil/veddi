<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductOption;
use App\Models\ProductOptionGroup;
use App\Services\Html\DescriptionSanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Auditoria 15/10 (superfície) — regressão do achado "XSS armazenado na descrição de produto"
 * (auditoria 17/09). O DescriptionSanitizer novo (DOMDocument) desembrulha a tag proibida movendo
 * os filhos para o pai, mas o laço itera um snapshot de childNodes tirado ANTES da mudança: os
 * filhos promovidos nunca são sanitizados. `<font><img src=x onerror=...></font>` sai intacto.
 *
 * Sinks: {!! $product->description_html !!} no chat público (mesma origem do /admin e /superadmin)
 * e x-html="option.description" no chat e no PDV. A CSP permite 'unsafe-inline', então o handler
 * onerror executa.
 */
test('sanitizador não deixa passar handler de evento dentro de tag proibida', function (string $payload) {
    $clean = DescriptionSanitizer::sanitize($payload);

    expect(strtolower($clean))->not->toContain('onerror')
        ->not->toContain('onload')
        ->not->toContain('javascript:');
})->with([
    'img dentro de font' => '<font><img src=x onerror=alert(document.domain)></font>',
    'svg dentro de tag desconhecida' => '<x><svg onload=alert(1)></svg></x>',
    'link javascript dentro de div' => '<div><a href="javascript:alert(1)">promo</a></div>',
]);

test('chat público renderiza a descrição maliciosa do produto como HTML executável', function () {
    $company = Company::create([
        'name' => 'Loja XSS',
        'slug' => 'loja-xss-'.uniqid(),
        'order_prefix' => 'XSS',
        'active' => true,
        'status' => 'ACTIVE',
        'email' => 'contato@lojaxss.com',
    ]);

    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    $category = ProductCategory::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Lanches',
        'active' => true,
        'sort_order' => 1,
    ]);

    $product = Product::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'product_category_id' => $category->id,
        'name' => 'Coxinha',
        'description' => 'Deliciosa <font><img src=x onerror=alert(document.domain)></font>',
        'price' => 10,
        'active' => true,
        'available_in_delivery' => true,
        'sort_order' => 1,
    ]);

    DB::table('branch_product')->insert([
        'branch_id' => $branch->id,
        'product_id' => $product->id,
        'available' => 1,
    ]);

    $group = ProductOptionGroup::create([
        'company_id' => $company->id,
        'name' => 'Molho',
        'total_qty' => 1,
        'min_qty' => 0,
        'fixed' => false,
    ]);
    $product->optionGroups()->attach($group->id, ['sort_order' => 0]);
    ProductOption::create([
        'product_option_group_id' => $group->id,
        'name' => 'Barbecue',
        'description' => '<i><x><svg onload=alert(2)></svg></x></i>',
        'additional_price' => 0,
    ]);

    $html = $this->get('/'.$company->slug)->assertOk()->getContent();

    expect($html)->toContain('Coxinha')
        ->not->toContain('onerror="alert(document.domain)"');
});
