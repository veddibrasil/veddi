<?php

use App\Events\OrderStatusUpdated;
use App\Mail\OrderDelivered;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

function setupDeliveredEmailContext(bool $withEmail = true): array
{
    $company = Company::create([
        'name' => 'Empresa Teste',
        'slug' => 'empresa-teste',
        'order_prefix' => 'TST',
        'active' => true,
    ]);

    app()->instance('current.company', $company);

    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial Central',
        'address' => 'Rua A, 1',
        'city' => 'SP',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'João',
        'phone' => '11999990001',
        'email' => $withEmail ? 'joao@teste.com' : null,
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
        'price' => 10.00,
        'active' => true,
        'sort_order' => 1,
    ]);

    $order = Order::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'customer_id' => $customer->id,
        'branch_id' => $branch->id,
        'subtotal' => 50.00,
        'total' => 50.00,
        'status' => 'ready',
        'payment_method' => 'pix',
        'order_type' => 'delivery',
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'product_name' => 'Coxinha',
        'unit_price' => 10.00,
        'quantity' => 2,
        'subtotal' => 20.00,
        'options' => [],
    ]);

    return compact('company', 'branch', 'customer', 'order', 'category', 'product');
}

test('envia email ao finalizar (delivered) quando cliente tem email', function () {
    Mail::fake();

    ['customer' => $customer, 'order' => $order] = setupDeliveredEmailContext(withEmail: true);

    $order->update(['status' => 'delivered']);
    OrderStatusUpdated::dispatch($order->fresh());

    Mail::assertQueued(OrderDelivered::class, function (OrderDelivered $mail) use ($customer, $order) {
        return $mail->hasTo($customer->email)
            && $mail->order->is($order);
    });

    expect($order->fresh()->delivered_email_sent_at)->not->toBeNull();
});

test('não envia email ao finalizar se cliente não tem email', function () {
    Mail::fake();

    ['order' => $order] = setupDeliveredEmailContext(withEmail: false);

    $order->update(['status' => 'delivered']);
    OrderStatusUpdated::dispatch($order->fresh());

    Mail::assertNotQueued(OrderDelivered::class);
    expect($order->fresh()->delivered_email_sent_at)->toBeNull();
});

test('não reenfila email se já foi enviado', function () {
    Mail::fake();

    ['order' => $order] = setupDeliveredEmailContext(withEmail: true);

    $order->forceFill(['status' => 'delivered', 'delivered_email_sent_at' => now()])->save();
    OrderStatusUpdated::dispatch($order->fresh());

    Mail::assertNotQueued(OrderDelivered::class);
});
