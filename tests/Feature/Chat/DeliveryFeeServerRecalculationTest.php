<?php

use App\Livewire\Chat\OrderChat;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DeliverySetting;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * `deliveryFee` é propriedade pública Livewire recalculada durante o fluxo
 * (resolveDeliveryFee), mas nada impede o client de sobrescrevê-la depois —
 * por isso o teste chama confirmOrder() diretamente no componente (mesma
 * razão de ResumePendingOrderCrossTenantTest: mount() do OrderChat não aceita
 * parâmetro de rota tipado, então o harness de teste do Livewire não serve
 * aqui) simulando exatamente esse ataque: o client zera deliveryFee antes de
 * confirmar.
 */
test('total do pedido usa a taxa de entrega recalculada no servidor, mesmo se o client zerar deliveryFee antes de confirmar', function () {
    $company = Company::create([
        'name' => 'Empresa Frete',
        'slug' => 'empresa-frete-'.uniqid(),
        'order_prefix' => 'FRT',
        'active' => true,
        'plan' => 'pro',
        'email' => 'contato@empresa.com',
    ]);
    app()->instance('current.company', $company);

    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    DeliverySetting::create([
        'branch_id' => $branch->id,
        'company_id' => $company->id,
        'fee_type' => 'flat',
        'flat_fee' => 15.00,
        'minimum_order_value' => 0,
        'active' => true,
    ]);

    $category = ProductCategory::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Salgados',
        'active' => true,
        'sort_order' => 1,
    ]);

    $product = Product::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'product_category_id' => $category->id,
        'name' => 'Coxinha',
        'price' => 20.00,
        'active' => true,
        'available_in_delivery' => true,
        'sort_order' => 1,
    ]);

    DB::table('branch_product')->insert([
        'branch_id' => $branch->id,
        'product_id' => $product->id,
        'available' => 1,
    ]);

    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Cliente Frete',
        'phone' => '11999999999',
    ]);

    $component = new OrderChat;
    $component->customerId = $customer->id;
    $component->companyId = $company->id;
    $component->selectedBranchId = $branch->id;
    $component->orderType = 'delivery';
    $component->paymentMethod = 'CASH';
    $component->neighborhood = 'Centro';
    $component->cart = [
        $product->id => [
            'product_id' => $product->id,
            'qty' => 1,
            'name' => $product->name,
            'price' => (float) $product->price,
            'options' => [],
        ],
    ];

    // Ataque: client já calculou o frete real (15) em algum momento anterior do
    // fluxo, mas adultera a propriedade pra zero antes de confirmar o pedido.
    $component->deliveryFee = 0.0;

    $component->confirmOrder();

    $order = Order::withoutGlobalScopes()->where('company_id', $company->id)->latest()->firstOrFail();

    expect((float) $order->delivery_fee)->toBe(15.0);
    expect((float) $order->total)->toBe(35.0); // 20 (produto) + 15 (frete real, não o 0 forjado)
});
