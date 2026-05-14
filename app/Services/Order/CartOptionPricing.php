<?php

namespace App\Services\Order;

use App\Models\Product;
use App\Models\ProductOption;
use Illuminate\Support\Collection;
use RuntimeException;

class CartOptionPricing
{
    /**
     * Resolve option extra price using DB values only.
     * Also returns a normalized options payload with server-side additional_price.
     *
     * Expected $item['options'] shape (tolerant):
     * - array<groupId => ['selections' => array<optionId => ['qty'=>int, 'additional_price'=>mixed]]]>
     * - or array<array{id:int, selections:...}> (some UIs)
     *
     * @param  array<string, mixed>  $item
     * @return array{extra: float, options: array<int, array<string, mixed>>}
     */
    public function resolve(Product $product, array $item): array
    {
        $rawGroups = $item['options'] ?? [];
        if (! is_array($rawGroups) || $rawGroups === []) {
            return ['extra' => 0.0, 'options' => []];
        }

        // Ensure we have the option groups for product (ordering matters for variant pricing).
        if (! $product->relationLoaded('optionGroups')) {
            $product->load('optionGroups');
        }

        /** @var Collection<int, ProductOptionGroup> $productGroups */
        $productGroups = $product->optionGroups->values();
        $productGroupById = $productGroups->keyBy('id');
        $groupIndexById = $productGroups->mapWithKeys(fn ($g, $i) => [$g->id => $i]);

        [$groupIds, $optionIds] = $this->extractIds($rawGroups);

        // Options scoped to valid group IDs prevents cross-company option injection.
        $options = ProductOption::query()
            ->whereIn('id', $optionIds)
            ->whereIn('product_option_group_id', $groupIds)
            ->get()
            ->keyBy('id');

        $extra = 0.0;
        $normalized = [];

        foreach ($this->iterateGroups($rawGroups) as $groupId => $groupData) {
            $groupId = (int) $groupId;
            if (! $productGroupById->has($groupId)) {
                throw new RuntimeException("Grupo de opção inválido para o produto #{$product->id}.");
            }

            $group = $productGroupById[$groupId];
            $groupIndex = (int) ($groupIndexById[$groupId] ?? 0);
            $isVariantPriceGroup = (bool) $product->is_variant && $groupIndex === 0;

            $groupNormalized = $groupData;
            $groupNormalized['id'] = $groupId;

            $selections = $groupData['selections'] ?? [];
            if (! is_array($selections)) {
                $selections = [];
            }

            $normSelections = [];

            foreach ($selections as $optionId => $sel) {
                $optionId = (int) (is_int($optionId) || ctype_digit((string) $optionId) ? $optionId : ($sel['id'] ?? 0));
                if ($optionId <= 0 || ! $options->has($optionId)) {
                    throw new RuntimeException("Opção inválida no carrinho para o produto #{$product->id}.");
                }

                $opt = $options[$optionId];
                if ((int) $opt->product_option_group_id !== $groupId) {
                    throw new RuntimeException("Opção #{$optionId} não pertence ao grupo #{$groupId}.");
                }

                $qty = (int) ($sel['qty'] ?? 0);
                if ($qty <= 0) {
                    continue;
                }

                $additional = ($isVariantPriceGroup || ! $group->fixed)
                    ? (float) $opt->additional_price
                    : 0.0;

                $extra += $qty * $additional;

                $selNorm = $sel;
                $selNorm['id'] = $optionId;
                $selNorm['qty'] = $qty;
                $selNorm['additional_price'] = $additional;
                $normSelections[$optionId] = $selNorm;
            }

            $groupNormalized['selections'] = $normSelections;
            $normalized[$groupId] = $groupNormalized;
        }

        return ['extra' => round($extra, 2), 'options' => $normalized];
    }

    /**
     * @param  array<mixed>  $rawGroups
     * @return array{0: array<int>, 1: array<int>}
     */
    private function extractIds(array $rawGroups): array
    {
        $groupIds = [];
        $optionIds = [];

        foreach ($this->iterateGroups($rawGroups) as $groupId => $groupData) {
            $gid = (int) $groupId;
            if ($gid > 0) {
                $groupIds[] = $gid;
            } elseif (isset($groupData['id'])) {
                $groupIds[] = (int) $groupData['id'];
            }

            $selections = $groupData['selections'] ?? [];
            if (! is_array($selections)) {
                continue;
            }

            foreach ($selections as $optionId => $sel) {
                $oid = (int) (is_int($optionId) || ctype_digit((string) $optionId) ? $optionId : ($sel['id'] ?? 0));
                if ($oid > 0) {
                    $optionIds[] = $oid;
                }
            }
        }

        return [array_values(array_unique($groupIds)), array_values(array_unique($optionIds))];
    }

    /**
     * Normalizes group iteration for both keyed and list-based payloads.
     *
     * @param  array<mixed>  $rawGroups
     * @return iterable<int, array<string, mixed>>
     */
    private function iterateGroups(array $rawGroups): iterable
    {
        foreach ($rawGroups as $key => $groupData) {
            if (is_array($groupData) && isset($groupData['id']) && ! is_int($key) && ! ctype_digit((string) $key)) {
                // Uncommon, but keep it.
                yield (int) $groupData['id'] => $groupData;

                continue;
            }

            if (is_array($groupData) && isset($groupData['id']) && is_int($key)) {
                // List-based: [{id, selections}]
                yield (int) $groupData['id'] => $groupData;

                continue;
            }

            if (is_array($groupData)) {
                // Keyed: [groupId => {selections}]
                yield (int) $key => $groupData;
            }
        }
    }
}
