<?php

namespace App\Services\Ifood;

use App\Contracts\IfoodGatewayContract;
use App\Models\Branch;
use App\Models\IfoodCategory;
use App\Models\IfoodIntegration;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductOption;
use App\Models\ProductOptionGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/**
 * Catalog API v2.0 do iFood: cada item de cardápio precisa de um categoryId
 * (criado antes, via POST /categories) e é enviado individualmente via
 * PUT /items — não existe endpoint de lote. Os ids de item/produto/opção são
 * gerados por nós (UUID v4, exigido pelo iFood) e persistidos localmente
 * (branch_product.ifood_item_id/ifood_product_id, product_options.
 * ifood_option_id/ifood_product_id) pra manter o mesmo id entre sincronizações
 * — PUT é idempotente por id, reenviar o mesmo id sobrescreve em vez de duplicar.
 *
 * Produto com grupo de complemento vira item COMBO_V2 com grupo(s) do tipo
 * OFFER_UNIT — exatamente um grupo precisa ser o "MAIN" (vínculo declarado em
 * products[].optionGroups, com associationType: MAIN só no primeiro; os demais
 * omitem o campo). Formato confirmado contra sandbox real em 2026-09-04
 * (categoria, item simples e item com complemento retornaram 200/201).
 */
class IfoodCatalogSyncService
{
    /** Namespace fixo pra gerar UUID v5 determinístico do grupo de opção (ver ensureOptionGroupId). */
    private const OPTION_GROUP_UUID_NAMESPACE = '6f2a9c1e-6b5f-4c1a-9b3d-2f8e7a1d4c60';

    public function __construct(private readonly IfoodGatewayContract $gateway) {}

    /**
     * Sincronização completa (produtos, categorias, preço, disponibilidade) —
     * batch periódico de segurança. Garante que todo produto/opção elegível
     * tenha um ifood_item_id/ifood_option_id atribuído e envie um PUT /items
     * por produto.
     */
    public function syncFullCatalog(IfoodIntegration $integration): void
    {
        $branch = $integration->branch;

        $products = Product::where('company_id', $integration->company_id)
            ->where('active', true)
            ->where('available_in_ifood', true)
            ->whereHas('branches', fn ($q) => $q
                ->where('branches.id', $branch->id)
                ->where('branch_product.available', true)
            )
            ->with(['category', 'optionGroups.options'])
            ->get();

        $synced = 0;

        foreach ($products as $product) {
            if (! $product->category) {
                Log::channel('ifood')->warning('iFood: produto sem categoria, pulando sync', [
                    'product_id' => $product->id,
                    'branch_id' => $branch->id,
                ]);

                continue;
            }

            $this->gateway->syncCatalog($integration, $this->buildItemPayload($integration, $branch, $product));
            $synced++;
        }

        $integration->update(['last_synced_at' => now()]);

        Log::channel('ifood')->info('iFood: catálogo sincronizado (completo)', [
            'ifood_integration_id' => $integration->id,
            'items_count' => $synced,
        ]);
    }

    /**
     * Sincronização em tempo real de disponibilidade (pausar/despausar 1 item).
     * Exige que o item já tenha passado por syncFullCatalog ao menos uma vez
     * (senão não existe ifood_item_id ainda pra referenciar) — se não tiver,
     * loga e não faz nada (não é um erro fatal, só significa "ainda não sincronizado").
     */
    public function syncAvailability(Branch $branch, Product $product): void
    {
        $integration = IfoodIntegration::where('branch_id', $branch->id)
            ->where('status', 'active')
            ->first();

        if (! $integration) {
            return;
        }

        $ifoodItemId = DB::table('branch_product')
            ->where('branch_id', $branch->id)
            ->where('product_id', $product->id)
            ->value('ifood_item_id');

        if (! $ifoodItemId) {
            Log::channel('ifood')->info('iFood: produto ainda sem ifood_item_id, pulando sync de disponibilidade (precisa de syncFullCatalog primeiro)', [
                'product_id' => $product->id,
                'branch_id' => $branch->id,
            ]);

            return;
        }

        if (! $product->category) {
            return;
        }

        // PUT /items não expõe um endpoint dedicado só de status — reenvia o item
        // inteiro com o status atualizado (idempotente, mesmo custo de payload).
        $this->gateway->syncCatalog($integration, $this->buildItemPayload($integration, $branch, $product->fresh(['category', 'optionGroups.options'])));

        Log::channel('ifood')->info('iFood: disponibilidade de item sincronizada', [
            'product_id' => $product->id,
            'branch_id' => $branch->id,
        ]);
    }

    /** Monta o payload completo (item + products + optionGroups + options) de UM produto. */
    private function buildItemPayload(IfoodIntegration $integration, Branch $branch, Product $product): array
    {
        $categoryId = $this->ensureCategoryId($integration, $branch, $product->category);
        $itemType = $product->optionGroups->isEmpty() ? 'DEFAULT' : 'COMBO_V2';
        $itemId = $this->ensureItemId($branch->id, $product, $itemType);
        $itemProductId = $this->ensureItemProductId($branch->id, $product);

        $available = DB::table('branch_product')
            ->where('branch_id', $branch->id)
            ->where('product_id', $product->id)
            ->value('available');

        $status = ((bool) $available) && $product->active && $product->available_in_ifood ? 'AVAILABLE' : 'UNAVAILABLE';

        $products = [];
        $optionGroups = [];
        $options = [];
        $mainProductOptionGroups = [];

        foreach ($product->optionGroups as $index => $group) {
            $groupId = $this->ensureOptionGroupId($group);
            $optionIds = [];

            foreach ($group->options as $option) {
                $optionId = $this->ensureOptionId($option);
                $optionProductId = $this->ensureOptionProductId($option);

                $products[] = [
                    'id' => $optionProductId,
                    'name' => $option->name,
                    'externalCode' => "veddi-option-{$option->id}",
                ];

                $options[] = [
                    'id' => $optionId,
                    'productId' => $optionProductId,
                    'status' => $option->active ? 'AVAILABLE' : 'UNAVAILABLE',
                    'price' => ['value' => (float) $option->additional_price],
                ];

                $optionIds[] = $optionId;
            }

            $optionGroups[] = [
                'id' => $groupId,
                'name' => $group->name,
                'status' => 'AVAILABLE',
                'optionGroupType' => 'OFFER_UNIT',
                'optionIds' => $optionIds,
            ];

            // Exatamente um grupo precisa ser o "MAIN" do combo — o primeiro.
            // Os demais entram no vínculo sem associationType (ver doc de exemplo
            // real: og-soda-choice não leva o campo).
            $relation = [
                'id' => $groupId,
                'min' => $group->min_qty,
                'max' => $group->total_qty,
                'index' => $index,
            ];

            if ($index === 0) {
                $relation['associationType'] = 'MAIN';
            }

            $mainProductOptionGroups[] = $relation;
        }

        $itemProduct = [
            'id' => $itemProductId,
            'name' => $product->name,
            'externalCode' => "veddi-product-{$product->id}",
        ];

        if ($mainProductOptionGroups !== []) {
            $itemProduct['optionGroups'] = $mainProductOptionGroups;
        }

        array_unshift($products, $itemProduct);

        return [
            'item' => [
                'id' => $itemId,
                'type' => $itemType,
                'productId' => $itemProductId,
                'categoryId' => $categoryId,
                'status' => $status,
                'price' => ['value' => (float) $product->effective_price],
                'externalCode' => "veddi-p{$product->id}",
            ],
            'products' => $products,
            'optionGroups' => $optionGroups,
            'options' => $options,
        ];
    }

    private function ensureCategoryId(IfoodIntegration $integration, Branch $branch, ProductCategory $category): string
    {
        $existing = IfoodCategory::withoutGlobalScopes()
            ->where('branch_id', $branch->id)
            ->where('product_category_id', $category->id)
            ->value('ifood_category_id');

        if ($existing) {
            return $existing;
        }

        $ifoodCategoryId = $this->gateway->createCategory($integration, $category->name);

        IfoodCategory::create([
            'company_id' => $integration->company_id,
            'branch_id' => $branch->id,
            'product_category_id' => $category->id,
            'ifood_category_id' => $ifoodCategoryId,
        ]);

        return $ifoodCategoryId;
    }

    /**
     * iFood rejeita PUT /items reaproveitando o mesmo id se o tipo mudar
     * (DEFAULT -> COMBO_V2 ou vice-versa: "Item type cannot be changed") — se o
     * produto tinha um item sincronizado com outro tipo, gera um id novo em vez
     * de reenviar o antigo. O item antigo fica órfão do lado do iFood (nunca
     * mais atualizado, mas também não removido automaticamente de lá).
     */
    private function ensureItemId(int $branchId, Product $product, string $itemType): string
    {
        $row = DB::table('branch_product')
            ->where('branch_id', $branchId)
            ->where('product_id', $product->id)
            ->first(['ifood_item_id', 'ifood_item_type']);

        if ($row?->ifood_item_id && $row->ifood_item_type === null) {
            // Item sincronizado antes deste rastreamento de tipo existir — reaproveita
            // o id (tipo real não mudou, só nunca foi registrado) e faz o backfill.
            DB::table('branch_product')
                ->where('branch_id', $branchId)
                ->where('product_id', $product->id)
                ->update(['ifood_item_type' => $itemType]);

            return $row->ifood_item_id;
        }

        if ($row?->ifood_item_id && $row->ifood_item_type === $itemType) {
            return $row->ifood_item_id;
        }

        if ($row?->ifood_item_id) {
            Log::channel('ifood')->warning('iFood: tipo de item mudou, gerando novo item id', [
                'product_id' => $product->id,
                'branch_id' => $branchId,
                'tipo_anterior' => $row->ifood_item_type,
                'tipo_novo' => $itemType,
                'item_id_orfao' => $row->ifood_item_id,
            ]);
        }

        $uuid = (string) Str::uuid();

        DB::table('branch_product')
            ->where('branch_id', $branchId)
            ->where('product_id', $product->id)
            ->update(['ifood_item_id' => $uuid, 'ifood_item_type' => $itemType]);

        return $uuid;
    }

    private function ensureItemProductId(int $branchId, Product $product): string
    {
        return $this->ensureBranchProductUuid($branchId, $product->id, 'ifood_product_id');
    }

    private function ensureBranchProductUuid(int $branchId, int $productId, string $column): string
    {
        $existing = DB::table('branch_product')
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->value($column);

        if ($existing) {
            return $existing;
        }

        $uuid = (string) Str::uuid();

        DB::table('branch_product')
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->update([$column => $uuid]);

        return $uuid;
    }

    private function ensureOptionId(ProductOption $option): string
    {
        if ($option->ifood_option_id) {
            return $option->ifood_option_id;
        }

        $uuid = (string) Str::uuid();
        $option->update(['ifood_option_id' => $uuid]);

        return $uuid;
    }

    private function ensureOptionProductId(ProductOption $option): string
    {
        if ($option->ifood_product_id) {
            return $option->ifood_product_id;
        }

        $uuid = (string) Str::uuid();
        $option->update(['ifood_product_id' => $uuid]);

        return $uuid;
    }

    /**
     * iFood não expõe id próprio pro grupo de complemento — gera um UUID v5
     * determinístico a partir do id do nosso ProductOptionGroup (mesmo
     * group->id sempre produz o mesmo UUID), estável entre sincronizações sem
     * precisar de coluna nova.
     */
    private function ensureOptionGroupId(ProductOptionGroup $group): string
    {
        return (string) Uuid::uuid5(self::OPTION_GROUP_UUID_NAMESPACE, "option-group-{$group->id}");
    }
}
