<?php

/*
 * Helpers compartilhados pelos testes de auditoria de isolamento multi-tenant
 * (tests/Feature/Audit/Tenant). Prefixo "atn" para não colidir com helpers
 * globais de outros arquivos de teste.
 */

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Symfony\Component\HttpKernel\Exception\HttpException;

if (! function_exists('atnCompany')) {
    function atnCompany(string $suffix, array $extra = []): Company
    {
        return Company::create(array_merge([
            'name' => 'Empresa '.$suffix,
            'slug' => 'atn-'.strtolower($suffix).'-'.uniqid(),
            'order_prefix' => 'T'.strtoupper(substr($suffix, 0, 2)),
            'active' => true,
            'plan' => 'pro',
        ], $extra));
    }

    function atnBranch(Company $company, string $name = 'Filial'): Branch
    {
        return Branch::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'name' => $name,
            'address' => 'Rua A, 1',
            'city' => 'SP',
            'state' => 'SP',
            'cep' => '01310-100',
            'active' => true,
            'opens_at' => '00:00:00',
            'closes_at' => '23:59:59',
        ]);
    }

    function atnCategory(Company $company, string $name = 'Categoria'): ProductCategory
    {
        return ProductCategory::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'name' => $name,
            'active' => true,
            'sort_order' => 1,
        ]);
    }

    function atnProduct(Company $company, ProductCategory $category, string $name = 'Produto', ?Branch $branch = null, int $qty = 5): Product
    {
        $product = Product::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'product_category_id' => $category->id,
            'name' => $name,
            'price' => 10.0,
            'active' => true,
            'sort_order' => 1,
        ]);

        if ($branch) {
            \Illuminate\Support\Facades\DB::table('branch_product')->insert([
                'branch_id' => $branch->id,
                'product_id' => $product->id,
                'available' => 1,
                'track_stock' => 1,
                'quantity' => $qty,
            ]);
        }

        return $product;
    }

    function atnUser(Company $company, string $role, ?Branch $branch = null): User
    {
        $user = User::factory()->create();
        $user->companies()->attach($company->id, ['role' => $role, 'branch_id' => $branch?->id]);

        return $user;
    }

    function atnCustomer(Company $company, string $name = 'Cliente'): Customer
    {
        return Customer::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'name' => $name,
            'phone' => '1199'.random_int(1000000, 9999999),
            'email' => strtolower(str_replace(' ', '', $name)).uniqid().'@teste.com',
        ]);
    }

    function atnOrder(Company $company, Branch $branch, Customer $customer, array $extra = []): Order
    {
        $previous = app()->bound('current.company') ? app('current.company') : null;
        app()->instance('current.company', $company);

        $order = Order::withoutGlobalScopes()->create(array_merge([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'subtotal' => 50.0,
            'total' => 50.0,
            'status' => 'paid',
            'payment_method' => 'pix',
            'order_type' => 'delivery',
            'channel' => 'chat',
        ], $extra));

        if ($previous) {
            app()->instance('current.company', $previous);
        }

        return $order;
    }

    /**
     * Executa a tentativa de ataque engolindo SÓ as exceções que significam
     * "bloqueado" (prop #[Locked], AuthorizationException, abort 403/404). Qualquer
     * outro erro sobe e derruba o teste — evita falso positivo por erro de setup.
     */
    function atnAttempt(callable $attack): mixed
    {
        try {
            return $attack();
        } catch (CannotUpdateLockedPropertyException|AuthorizationException $e) {
            return null;
        } catch (HttpException $e) {
            if (in_array($e->getStatusCode(), [403, 404], true)) {
                return null;
            }

            throw $e;
        } catch (\InvalidArgumentException $e) {
            // Chamada encadeada depois de uma ação barrada (403): o harness fica sem snapshot.
            if (str_contains($e->getMessage(), 'Invalid Livewire snapshot structure')) {
                return null;
            }

            throw $e;
        }
    }
}
