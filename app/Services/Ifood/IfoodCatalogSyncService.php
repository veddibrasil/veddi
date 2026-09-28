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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/** Publica a estrutura por PUT e altera preços/disponibilidade por PATCH dedicado. */
class IfoodCatalogSyncService
{
    /** Namespace fixo pra gerar UUID v5 determinístico do grupo de opção (ver ensureOptionGroupId). */
    private const OPTION_GROUP_UUID_NAMESPACE = '6f2a9c1e-6b5f-4c1a-9b3d-2f8e7a1d4c60';

    public function __construct(private readonly IfoodGatewayContract $gateway) {}

    /** Inclui itens já publicados mesmo quando foram pausados ou removidos do canal. */
    public function syncFullCatalog(IfoodIntegration $integration): void
    {
        Cache::lock("ifood:catalog:{$integration->id}", 300)->block(5, function () use ($integration) {
            $products = Product::where('company_id', $integration->company_id)
                ->whereHas('branches', fn ($q) => $q->where('branches.id', $integration->branch_id)
                    ->where(fn ($q) => $q->whereNotNull('branch_product.ifood_item_id')
                        ->orWhere(fn ($q) => $q->where('branch_product.available', true)->where('products.active', true)->where('products.available_in_ifood', true))))
                ->with(['category', 'optionGroups.options'])->get();
            $this->syncProducts($integration, $products);
            $integration->update(['last_synced_at' => now()]);
        });
    }

    public function syncProduct(IfoodIntegration $integration, Product $product): void
    {
        if ($product->company_id !== $integration->company_id) {
            return;
        }
        $pivot = DB::table('branch_product')->where('branch_id', $integration->branch_id)->where('product_id', $product->id)->first();
        if (! $pivot || (! $pivot->ifood_item_id && (! $product->available_in_ifood || ! $product->active || ! $pivot->available))) {
            return;
        }
        Cache::lock("ifood:catalog:{$integration->id}", 300)->block(5, function () use ($integration, $product) {
            $this->syncProducts($integration, [$product->load(['category', 'optionGroups.options'])]);
        });
    }

    public function syncAvailability(Branch $branch, Product $product): void
    {
        if ($branch->company_id !== $product->company_id) {
            return;
        }
        $integration = IfoodIntegration::where('company_id', $branch->company_id)->where('branch_id', $branch->id)->where('status', 'active')->first();
        $id = DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $product->id)->value('ifood_item_id');
        if (! $integration || ! $id) {
            return;
        }
        $available = DB::table('branch_product')->where('branch_id', $branch->id)->where('product_id', $product->id)->value('available');
        Cache::lock("ifood:catalog:{$integration->id}", 300)->block(5, function () use ($integration, $id, $available, $product) {
            $this->gateway->updateItemStatuses($integration, [[
                'id' => $id, 'status' => $available && $product->active && $product->available_in_ifood ? 'AVAILABLE' : 'UNAVAILABLE',
            ]]);
        });
    }

    private function syncProducts(IfoodIntegration $integration, $products): void
    {
        $prices = $statuses = $prepared = $optionPrices = $optionStatuses = [];
        foreach ($products as $product) {
            if (! $product->category) {
                throw new \RuntimeException("Produto {$product->id} sem categoria; sincronização interrompida.");
            }
            $oldId = DB::table('branch_product')->where('branch_id', $integration->branch_id)->where('product_id', $product->id)->value('ifood_item_id');
            $old = $oldId ? $this->gateway->getCatalogItem($integration, $oldId) : null;
            $payload = $this->buildItemPayload($integration, $integration->branch, $product);
            if ($old && $old['item']['id'] !== $payload['item']['id']) {
                // Troca de tipo exige um novo ID; o item anterior precisa sair de venda.
                $statuses[$oldId] = ['id' => $oldId, 'status' => 'UNAVAILABLE'];
                $old = null;
            }
            if ($old) {
                $item = $payload['item'];
                if ($old['item']['price'] != $item['price']) {
                    $prices[$item['id']] = ['itemId' => $item['id'], 'price' => $item['price']['value']];
                }
                if ($old['item']['status'] !== $item['status']) {
                    $statuses[$item['id']] = ['id' => $item['id'], 'status' => $item['status']];
                }
                $oldOptions = collect($old['options'] ?? [])->keyBy('id');
                foreach ($payload['options'] as $option) {
                    $previous = $oldOptions->get($option['id']);
                    if ($previous && $previous['price'] != $option['price']) {
                        $optionPrices[$option['id']] = $option['price'];
                    }
                    if ($previous && $previous['status'] !== $option['status']) {
                        $optionStatuses[$option['id']] = $option['status'];
                    }
                }
            }
            $prepared[] = [$payload, $old];
        }
        // Todos os preços/status existentes são alterados exclusivamente por PATCH.
        if ($prices) {
            $this->gateway->updateItemPrices($integration, array_values($prices));
        }
        if ($statuses) {
            $this->gateway->updateItemStatuses($integration, array_values($statuses));
        }
        foreach ($optionPrices as $id => $price) {
            $this->gateway->updateOptionPrice($integration, $id, $price);
        }
        foreach ($optionStatuses as $id => $status) {
            $this->gateway->updateOptionStatus($integration, $id, $status);
        }
        foreach ($prepared as [$payload, $old]) {
            if (! $old || $this->structure($payload) != $this->structure($old)) {
                $this->gateway->syncCatalog($integration, $payload);
                DB::table('branch_product')->where('branch_id', $integration->branch_id)->where('ifood_product_id', $payload['item']['productId'])
                    ->update(['ifood_item_id' => $payload['item']['id'], 'ifood_item_type' => $payload['item']['type']]);
            }
        }
    }

    private function structure(array $payload): array
    {
        unset($payload['item']['price'], $payload['item']['status']);
        $payload['options'] ??= [];
        foreach ($payload['options'] as &$option) {
            unset($option['price'], $option['status']);
        }

        return $payload;
    }

    private function imagePath(IfoodIntegration $integration, ?string $path): ?string
    {
        if (! $path) {
            return null;
        }
        $contents = Storage::disk('s3')->get($path);
        if (! is_string($contents) || $contents === '' || strlen($contents) > 5 * 1024 * 1024) {
            throw new \RuntimeException('A foto do catálogo deve ter até 5 MB e estar disponível no armazenamento.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);
        if (! in_array($mime, ['image/jpeg', 'image/png'], true)) {
            throw new \RuntimeException('O iFood aceita fotos JPG ou PNG.');
        }

        return Cache::remember('ifood:image:'.$integration->merchant_id.':'.hash('sha256', $contents), now()->addDays(30),
            fn () => $this->gateway->uploadCatalogImage($integration, 'data:'.$mime.';base64,'.base64_encode($contents)));
    }

    /** Monta o payload completo (item + products + optionGroups + options) de UM produto. */
    private function buildItemPayload(IfoodIntegration $integration, Branch $branch, Product $product): array
    {
        $categoryId = $this->ensureCategoryId($integration, $branch, $product->category);
        // Complementos ficam no próprio item DEFAULT. COMBO_V2 exige "produtos do combo";
        // com o grupo como MAIN o Portal mostrava "Combo vazio" e o item sumia do app.
        $itemType = 'DEFAULT';
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

                $optionProduct = [
                    'id' => $optionProductId,
                    'name' => $option->name,
                    'externalCode' => "veddi-option-{$option->id}",
                ];
                if ($option->image_path) {
                    $optionProduct['imagePath'] = $this->imagePath($integration, $option->image_path);
                }
                $products[] = $optionProduct;

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

            // associationType MAIN só existe em COMBO_V2; item DEFAULT vincula o grupo sem ele.
            $mainProductOptionGroups[] = [
                'id' => $groupId,
                'min' => $group->min_qty,
                'max' => $group->total_qty,
                'index' => $index,
            ];
        }

        $itemProduct = [
            'id' => $itemProductId,
            'name' => $product->name,
            'externalCode' => "veddi-product-{$product->id}",
        ];

        if ($product->image_path) {
            $itemProduct['imagePath'] = $this->imagePath($integration, $product->image_path);
        }

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
     * ("Item type cannot be changed") — itens publicados antes como COMBO_V2
     * ganham um id novo DEFAULT em vez de reenviar o antigo. syncProducts pausa
     * o ID anterior antes de publicar o novo.
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
                'item_id_anterior' => $row->ifood_item_id,
            ]);
        }

        if ($row?->ifood_item_id) {
            // Preserva o UUID v4 nas retentativas sem perder a referência do item anterior.
            return Cache::rememberForever('ifood:item-type:'.$branchId.':'.$row->ifood_item_id.':'.$itemType, fn () => (string) Str::uuid());
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
