<?php

namespace App\Services\Keeta;

use App\Models\Branch;
use App\Models\KeetaIntegration;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Monta o Merchant do Open Delivery que a Keeta busca no nosso GET merchant (um por filial).
 *
 * Ids determinísticos, sem tabela de mapeamento (o mesmo molde da 99Food):
 *   produto  → Item "veddi-p{id}" (externalCode igual, é o que volta no pedido) + ItemOffer "veddi-po{id}"
 *   opção    → Item "veddi-o{id}" + Option "veddi-oo{id}"
 *   grupo    → OptionGroup "veddi-g{id}"; categoria → "veddi-c{id}"; menu/serviço → "veddi-m{filial}"
 *
 * Só entra produto ativo, marcado "Disponível na Keeta" e ligado à filial; sem categoria fica
 * de fora com pendência. Indisponível na filial (pausa/estoque) vai como UNAVAILABLE.
 */
class KeetaCatalogBuilder
{
    private const MAX_NAME = 120;

    public function __construct(private readonly KeetaStoreHours $hours) {}

    /**
     * @return array{merchant: array, issues: array<int, string>, products: array<int, int>}
     */
    public function build(KeetaIntegration $integration): array
    {
        $branch = Branch::withoutGlobalScopes()->with('company')->findOrFail($integration->branch_id);
        $products = $this->sellableProducts($integration);
        $availability = $this->availability($integration->branch_id, $products->modelKeys());
        $issues = [];

        $byCategory = $products->filter(function (Product $product) use (&$issues) {
            if (! $product->category) {
                $issues[$product->id] = 'Produto sem categoria. Escolha uma categoria no cadastro.';

                return false;
            }
            if ((float) $product->effective_price <= 0) {
                $issues[$product->id] = 'Produto sem preço: a Keeta exige preço de entrega.';

                return false;
            }

            return true;
        })->groupBy('product_category_id')->sortBy(fn (Collection $items) => [$items->first()->category->sort_order, $items->first()->category->id]);

        $categories = $itemOffers = $items = $groups = $included = [];

        foreach ($byCategory as $categoryProducts) {
            $category = $categoryProducts->first()->category;
            $offerIds = [];

            foreach ($categoryProducts->sortBy(['sort_order', 'id'])->values() as $index => $product) {
                $groupIds = [];
                foreach ($product->optionGroups as $group) {
                    // Grupo fixo já está no preço do produto: não vira escolha na Keeta.
                    if ($group->fixed || $group->options->isEmpty()) {
                        continue;
                    }
                    $groupIds[] = 'veddi-g'.$group->id;
                    $groups[$group->id] ??= $this->group($group, $items, count($groups));
                }

                $available = $availability[$product->id] ?? false;
                $items['veddi-p'.$product->id] = $this->item(
                    'veddi-p'.$product->id,
                    $product->name,
                    $this->description($product->description),
                    $available,
                    $product->image_path ? $product->image_url : null,
                );

                $itemOffers[] = array_filter([
                    'id' => 'veddi-po'.$product->id,
                    'itemId' => 'veddi-p'.$product->id,
                    'index' => $index,
                    'status' => $available ? 'AVAILABLE' : 'UNAVAILABLE',
                    'price' => $this->price((float) $product->effective_price, (float) $product->price),
                    'optionGroupsId' => $groupIds === [] ? null : $groupIds,
                ], fn ($value) => $value !== null);
                $offerIds[] = 'veddi-po'.$product->id;
                $included[] = $product->id;
            }

            if ($offerIds !== []) {
                $categories[] = [
                    'id' => 'veddi-c'.$category->id,
                    'index' => count($categories),
                    'name' => Str::limit($category->name, self::MAX_NAME, ''),
                    'externalCode' => 'veddi-c'.$category->id,
                    'status' => 'AVAILABLE',
                    'itemOfferId' => $offerIds,
                ];
            }
        }

        $menuId = 'veddi-m'.$integration->branch_id;
        $open = $integration->status === KeetaIntegration::STATUS_ACTIVE && $integration->isStoreOpen();

        return [
            'merchant' => [
                'id' => $integration->merchant_ref,
                'status' => $open ? 'AVAILABLE' : 'UNAVAILABLE',
                'lastUpdate' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
                'basicInfo' => $this->basicInfo($branch),
                'services' => [[
                    'id' => 'veddi-s'.$integration->branch_id,
                    'status' => $open ? 'AVAILABLE' : 'UNAVAILABLE',
                    'serviceType' => 'DELIVERY',
                    'menuId' => $menuId,
                    'serviceHours' => [
                        'id' => 'veddi-h'.$integration->branch_id,
                        'weekHours' => $this->hours->forBranch($branch),
                    ],
                ]],
                'menus' => [[
                    'id' => $menuId,
                    'name' => 'Cardápio',
                    'externalCode' => $menuId,
                    'categoryId' => array_column($categories, 'id'),
                ]],
                'categories' => $categories,
                'itemOffers' => $itemOffers,
                'items' => array_values($items),
                'optionGroups' => array_values($groups),
            ],
            'issues' => $issues,
            'products' => $included,
        ];
    }

    /**
     * Grupo de complemento (compartilhável entre produtos, como no VEDDI). As opções viram Items
     * próprios ("veddi-o{id}"), adicionados em $items.
     */
    private function group($group, array &$items, int $index): array
    {
        $options = [];

        foreach ($group->options->sortBy(['sort_order', 'id'])->values() as $position => $option) {
            $itemId = 'veddi-o'.$option->id;
            $items[$itemId] ??= $this->item($itemId, $option->name, null, (bool) $option->active, $option->image_path ? $option->image_url : null);

            $options[] = [
                'id' => 'veddi-oo'.$option->id,
                'itemId' => $itemId,
                'index' => $position,
                'status' => $option->active ? 'AVAILABLE' : 'UNAVAILABLE',
                'price' => $this->price((float) $option->additional_price, (float) $option->additional_price),
            ];
        }

        $min = $group->allow_skip ? 0 : (int) $group->min_qty;

        return [
            'id' => 'veddi-g'.$group->id,
            'index' => $index,
            'name' => Str::limit($group->name, self::MAX_NAME, ''),
            'externalCode' => 'veddi-g'.$group->id,
            'status' => 'AVAILABLE',
            'minPermitted' => $min,
            'maxPermitted' => max(1, $min, (int) $group->total_qty),
            'options' => $options,
        ];
    }

    private function item(string $id, string $name, ?string $description, bool $available, ?string $imageUrl): array
    {
        return array_filter([
            'id' => $id,
            'name' => Str::limit($name, self::MAX_NAME, ''),
            'description' => $description,
            'externalCode' => $id,
            'status' => $available ? 'AVAILABLE' : 'UNAVAILABLE',
            // A Keeta baixa a imagem dos servidores dela: só URL pública HTTPS.
            'image' => $imageUrl && str_starts_with($imageUrl, 'https://') ? ['URL' => $imageUrl] : null,
            'unit' => 'UN',
        ], fn ($value) => $value !== null && $value !== '');
    }

    /** value = preço atual (promoção); originalValue = preço cheio. */
    private function price(float $value, float $original): array
    {
        return ['value' => round($value, 2), 'originalValue' => round(max($value, $original), 2)];
    }

    private function basicInfo(Branch $branch): array
    {
        $phone = preg_replace('/\D/', '', (string) $branch->phone);
        $email = (string) ($branch->company?->email ?? '');

        return [
            'name' => $branch->company?->name ?? $branch->name,
            'contactEmails' => $email !== '' ? [$email] : [],
            'contactPhones' => ['commercialNumber' => $phone !== '' ? $phone : '0'],
        ];
    }

    private function description(?string $html): ?string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags((string) $html)));

        return $text === '' ? null : Str::limit($text, 500, '');
    }

    private function sellableProducts(KeetaIntegration $integration): Collection
    {
        return Product::withoutGlobalScopes()
            ->where('company_id', $integration->company_id)
            ->where('active', true)
            ->where('available_in_keeta', true)
            ->whereHas('branches', fn ($q) => $q->where('branches.id', $integration->branch_id))
            ->with(['category', 'optionGroups.options'])
            ->get();
    }

    /** @return array<int, bool> disponível na filial (pivot + estoque) */
    private function availability(int $branchId, array $productIds): array
    {
        return DB::table('branch_product')->where('branch_id', $branchId)->whereIn('product_id', $productIds)
            ->get(['product_id', 'available', 'track_stock', 'quantity'])
            ->mapWithKeys(fn ($row) => [$row->product_id => (bool) $row->available && (! $row->track_stock || (int) $row->quantity > 0)])
            ->all();
    }
}
