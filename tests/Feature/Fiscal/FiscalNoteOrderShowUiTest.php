<?php

use App\Livewire\Admin\Orders\Show;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\FiscalNote;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function fiscalOrderShowContext(): array
{
    $company = Company::create([
        'name' => 'Empresa NFC-e',
        'slug' => 'empresa-nfce-'.uniqid(),
        'order_prefix' => 'NFC',
        'active' => true,
        'status' => 'ACTIVE',
        'fiscal_notes_enabled' => true,
    ]);

    app()->instance('current.company', $company);

    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial Central',
        'address' => 'Rua B, 10',
        'city' => 'SP',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Maria',
        'phone' => '11999990002',
        'email' => 'maria@teste.com',
        'address' => 'Rua das Flores',
        'number' => '123',
        'neighborhood' => 'Centro',
        'city' => 'São Paulo',
        'cep' => '01310-100',
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
        'name' => 'X-Burguer',
        'price' => 25.00,
        'active' => true,
        'sort_order' => 1,
    ]);

    $order = Order::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'customer_id' => $customer->id,
        'branch_id' => $branch->id,
        'subtotal' => 25.00,
        'delivery_fee' => 0,
        'discount' => 0,
        'total' => 25.00,
        'fee' => 0,
        'net_value' => 25.00,
        'status' => 'paid',
        'notes' => '',
        'payment_method' => 'pix',
        'order_type' => 'pickup',
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'product_name' => 'X-Burguer',
        'unit_price' => 25.00,
        'quantity' => 1,
        'subtotal' => 25.00,
    ]);

    return compact('company', 'branch', 'customer', 'order');
}

function grantFiscalIssuePermission(User $user, Company $company): void
{
    $permission = Permission::firstOrCreate(
        ['name' => 'fiscal.issue'],
        ['group' => 'fiscal', 'label' => 'Emitir notas fiscais'],
    );

    UserPermission::create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'permission_id' => $permission->id,
        'granted' => true,
    ]);
}

test('usuário com permissão fiscal.issue vê botão de emitir NFC-e quando o pedido não tem nota', function () {
    ['company' => $company, 'order' => $order] = fiscalOrderShowContext();

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);
    grantFiscalIssuePermission($admin, $company);

    Livewire::actingAs($admin)
        ->test(Show::class, ['order' => $order])
        ->assertSee('Nota Fiscal (NFC-e)')
        ->assertSee('Emitir NFC-e');
});

test('nota fiscal autorizada aparece na tela do pedido com link pro DANFE', function () {
    ['company' => $company, 'order' => $order] = fiscalOrderShowContext();

    FiscalNote::create([
        'company_id' => $company->id,
        'order_id' => $order->id,
        'status' => 'authorized',
        'access_key' => str_repeat('1', 44),
        'data' => ['danfe_url' => 'https://focusnfe.example/danfe/123'],
    ]);

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);
    grantFiscalIssuePermission($admin, $company);

    Livewire::actingAs($admin)
        ->test(Show::class, ['order' => $order])
        ->assertSee('Autorizada')
        ->assertSee('Baixar DANFE');
});

test('empresa sem módulo fiscal não mostra seção de nota fiscal', function () {
    ['company' => $company, 'order' => $order] = fiscalOrderShowContext();
    $company->update(['fiscal_notes_enabled' => false]);
    app()->instance('current.company', $company->fresh());

    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);
    grantFiscalIssuePermission($admin, $company);

    Livewire::actingAs($admin)
        ->test(Show::class, ['order' => $order])
        ->assertDontSee('Nota Fiscal (NFC-e)');
});
