<?php

/*
 * Helpers compartilhados pelos testes da auditoria de fluxos (tests/Feature/Audit/Flows).
 * Arquivo sem sufixo Test.php: o Pest não o executa como teste, cada arquivo faz require_once.
 * Nomes com prefixo flowAudit* para não colidir com helpers globais de outros testes.
 */

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\Order\OrderService;
use Illuminate\Support\Facades\DB;

if (! function_exists('flowAuditContext')) {
    /**
     * Empresa + filial + produto com controle de estoque (10 un.) + cliente.
     *
     * @return array{company: Company, branch: Branch, category: ProductCategory, product: Product, customer: Customer}
     */
    function flowAuditContext(array $companyOverrides = [], ?int $stock = 10, float $price = 20.0): array
    {
        $company = Company::create(array_merge([
            'name' => 'Empresa Fluxo',
            'slug' => 'empresa-fluxo-'.uniqid(),
            'order_prefix' => 'FLX',
            'active' => true,
            'plan' => 'pro',
            'email' => 'contato@fluxo.test',
        ], $companyOverrides));

        app()->instance('current.company', $company);

        $branch = Branch::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'name' => 'Filial Fluxo',
            'address' => 'Rua A, 1',
            'city' => 'SP',
            'active' => true,
            'opens_at' => '00:00:00',
            'closes_at' => '23:59:59',
        ]);

        $category = ProductCategory::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'name' => 'Salgados',
            'active' => true,
            'sort_order' => 1,
        ]);

        $product = flowAuditProduct($company, $branch, $category, 'Coxinha', $price, $stock);

        $customer = Customer::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'name' => 'Cliente Fluxo',
            'phone' => '11977770001',
            'email' => 'cliente@fluxo.test',
            'tax_id' => '52998224725',
        ]);

        return compact('company', 'branch', 'category', 'product', 'customer');
    }

    function flowAuditProduct(Company $company, Branch $branch, ProductCategory $category, string $name, float $price, ?int $stock = null): Product
    {
        $product = Product::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'product_category_id' => $category->id,
            'name' => $name,
            'price' => $price,
            'active' => true,
            'sort_order' => 1,
        ]);

        DB::table('branch_product')->insert([
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'available' => 1,
            'track_stock' => $stock !== null ? 1 : 0,
            'quantity' => $stock ?? 0,
        ]);

        return $product;
    }

    function flowAuditStock(Branch $branch, Product $product): int
    {
        return (int) DB::table('branch_product')
            ->where('branch_id', $branch->id)
            ->where('product_id', $product->id)
            ->value('quantity');
    }

    /** Pedido do chat criado pelo caminho real (OrderService), com baixa de estoque. */
    function flowAuditChatOrder(array $ctx, int $qty = 3, string $status = 'pending', string $paymentMethod = 'PIX', ?\App\Models\Coupon $coupon = null): Order
    {
        $order = app(OrderService::class)->createOrder(
            customerId: $ctx['customer']->id,
            branchId: $ctx['branch']->id,
            cart: [(string) $ctx['product']->id => ['product_id' => $ctx['product']->id, 'qty' => $qty]],
            notes: '',
            paymentMethod: $paymentMethod,
            orderType: 'pickup',
            status: 'pending',
            coupon: $coupon,
        );

        if ($status !== 'pending') {
            $order->update(['status' => $status]);
        }

        return $order->fresh();
    }
}
