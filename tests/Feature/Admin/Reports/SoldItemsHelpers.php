<?php

/*
 * Helpers compartilhados pelos testes do relatório de itens vendidos e das sugestões de venda
 * (tests/Feature/Admin/Reports). Prefixo "sir" para não colidir com helpers globais de outros
 * arquivos (o Pest carrega todos no mesmo namespace).
 *
 * Pedidos e itens são gravados direto com DB::table() para montar centenas de pedidos rápido e
 * controlar created_at (que não é fillable em Order).
 */

use App\DTOs\SalesInsight;
use App\DTOs\SoldItemsFilters;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\Sales\SalesInsightsService;
use App\Services\Sales\SoldItemsReportService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

if (! function_exists('sirCompany')) {
    function sirCompany(string $suffix = 'A', array $extra = []): Company
    {
        $company = Company::create(array_merge([
            'name' => 'Empresa Itens '.$suffix,
            'slug' => 'sir-'.strtolower($suffix).'-'.uniqid(),
            'order_prefix' => 'S'.strtoupper(substr($suffix, 0, 2)),
            'active' => true,
            'plan' => 'pro',
            'status' => 'ACTIVE',
        ], $extra));

        app()->instance('current.company', $company);

        return $company;
    }

    function sirBranch(Company $company, string $name = 'Filial Centro', bool $active = true): Branch
    {
        return Branch::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'name' => $name,
            'address' => 'Rua A, 1',
            'city' => 'Maringá',
            'state' => 'PR',
            'active' => $active,
            'opens_at' => '00:00:00',
            'closes_at' => '23:59:59',
        ]);
    }

    function sirCategory(Company $company, string $name = 'Salgados'): ProductCategory
    {
        return ProductCategory::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'name' => $name,
            'active' => true,
            'sort_order' => 1,
        ]);
    }

    /** Produto de catálogo antigo por padrão (created_at no início do ano): não conta como lançamento. */
    function sirProduct(Company $company, ?ProductCategory $category, string $name, float $price = 10.0, array $attributes = [], string $createdAt = '2026-01-01 00:00:00'): Product
    {
        $category ??= ProductCategory::withoutGlobalScopes()->where('company_id', $company->id)->first() ?? sirCategory($company);

        $product = Product::withoutGlobalScopes()->create(array_merge([
            'company_id' => $company->id,
            'product_category_id' => $category->id,
            'name' => $name,
            'price' => $price,
            'active' => true,
            'sort_order' => 1,
        ], $attributes));

        $product->forceFill(['created_at' => $createdAt])->saveQuietly();

        return $product;
    }

    function sirStock(Branch $branch, Product $product, int $quantity = 10, bool $trackStock = true, bool $available = true): void
    {
        DB::table('branch_product')->insert([
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'available' => $available,
            'quantity' => $quantity,
            'min_quantity' => 0,
            'track_stock' => $trackStock,
        ]);
    }

    function sirUser(Company $company, string $role = 'company_admin', ?Branch $branch = null): User
    {
        $user = User::factory()->create();
        $user->companies()->attach($company->id, ['role' => $role, 'branch_id' => $branch?->id]);

        return $user;
    }

    /** Empresa + filial + categoria prontas, com current.company apontando para a empresa. */
    function sirContext(string $suffix = 'A'): array
    {
        $company = sirCompany($suffix);
        $branch = sirBranch($company);
        $category = sirCategory($company);

        return compact('company', 'branch', 'category');
    }

    /**
     * Cria um pedido com itens. Cada item: [Product|string $produto, int $qtd, ?float $precoUnitario].
     * String vira item avulso (product_id null, como os itens sem vínculo do iFood). Preço 0 = brinde.
     *
     * @param  array  $overrides  colunas de orders (status, channel, order_type, is_open_tab, branch_id...)
     */
    function sirOrder(array $ctx, string $createdAt, array $items, array $overrides = []): int
    {
        static $sequence = 0;
        $sequence++;

        $lines = [];
        $subtotal = 0.0;
        foreach ($items as $item) {
            [$product, $quantity] = $item;
            $isModel = $product instanceof Product;
            $unitPrice = $item[2] ?? ($isModel ? (float) $product->price : 10.0);
            $lineSubtotal = round($quantity * $unitPrice, 2);
            $subtotal += $lineSubtotal;

            $lines[] = [
                'product_id' => $isModel ? $product->id : null,
                'product_name' => $isModel ? $product->name : (string) $product,
                'unit_price' => $unitPrice,
                'quantity' => $quantity,
                'subtotal' => $lineSubtotal,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];
        }

        $orderId = DB::table('orders')->insertGetId(array_merge([
            'company_id' => $ctx['company']->id,
            'branch_id' => $ctx['branch']->id,
            'order_number' => 'SIR-'.$sequence,
            'subtotal' => $subtotal,
            'total' => $subtotal,
            'status' => 'paid',
            'payment_method' => 'pix',
            'order_type' => 'delivery',
            'channel' => 'chat',
            'is_open_tab' => false,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ], $overrides));

        if ($lines !== []) {
            DB::table('order_items')->insert(array_map(fn ($line) => $line + ['order_id' => $orderId], $lines));
        }

        return $orderId;
    }

    /**
     * Cria $count pedidos; $at recebe o índice (0..count-1) e devolve o created_at.
     */
    function sirOrders(array $ctx, int $count, callable $at, array $items, array $overrides = []): void
    {
        for ($i = 0; $i < $count; $i++) {
            sirOrder($ctx, $at($i), $items, $overrides);
        }
    }

    /**
     * Data "espalhada": um pedido por dia a partir de $start (ciclo de $days dias) e hora variando
     * entre 8h e 19h, para não criar pico de dia da semana nem de horário.
     */
    function sirSpreadAt(int $i, string $start = '2026-10-01', int $days = 28): string
    {
        return CarbonImmutable::parse($start)
            ->addDays($i % $days)
            ->setTime(8 + ($i * 5) % 12, 15)
            ->format('Y-m-d H:i:s');
    }

    /**
     * Pedidos de base (chat) com 3 produtos "neutros" (7 un. cada no máximo), espalhados em
     * outubro/2026: dá volume para passar de sales_insights.min_orders sem disparar regra nenhuma.
     *
     * @return list<Product>
     */
    function sirBase(array $ctx, int $orders = 21): array
    {
        $fillers = [
            sirProduct($ctx['company'], $ctx['category'], 'Base Pão de Queijo', 10.0),
            sirProduct($ctx['company'], $ctx['category'], 'Base Esfiha', 10.0),
            sirProduct($ctx['company'], $ctx['category'], 'Base Quibe', 10.0),
        ];

        for ($i = 0; $i < $orders; $i++) {
            sirOrder($ctx, sirSpreadAt($i), [[$fillers[$i % 3], 1]]);
        }

        return $fillers;
    }

    function sirFilters(Company $company, string $start = '2026-10-01', string $end = '2026-10-28', mixed $branchId = null, ?string $channel = null, mixed $categoryId = null): SoldItemsFilters
    {
        return SoldItemsFilters::fromInput($company->id, $start, $end, $branchId, $channel, $categoryId);
    }

    function sirReport(Company $company, string $start = '2026-10-01', string $end = '2026-10-28', mixed $branchId = null, ?string $channel = null, mixed $categoryId = null): array
    {
        return app(SoldItemsReportService::class)->build($company->id, sirFilters($company, $start, $end, $branchId, $channel, $categoryId));
    }

    function sirGenerate(Company $company, string $start = '2026-10-01', string $end = '2026-10-28', mixed $branchId = null, ?string $channel = null, mixed $categoryId = null): array
    {
        return app(SalesInsightsService::class)->generate($company, sirFilters($company, $start, $end, $branchId, $channel, $categoryId));
    }

    function sirKey(Product $product): string
    {
        return SoldItemsReportService::productKey($product->id, null);
    }

    /** @return list<SalesInsight> */
    function sirFind(array $result, string $type, ?Product $product = null, ?string $scope = null): array
    {
        return array_values(array_filter(
            $result['insights'],
            fn (SalesInsight $insight) => $insight->type === $type
                && ($product === null || $insight->productKey === sirKey($product))
                && ($scope === null || $insight->scope === $scope),
        ));
    }

    function sirFirst(array $result, string $type, ?Product $product = null, ?string $scope = null): ?SalesInsight
    {
        return sirFind($result, $type, $product, $scope)[0] ?? null;
    }

    /** @return list<string> */
    function sirTypes(array $result): array
    {
        return array_map(fn (SalesInsight $insight) => $insight->type, $result['insights']);
    }
}
