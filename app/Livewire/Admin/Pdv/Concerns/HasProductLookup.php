<?php

namespace App\Livewire\Admin\Pdv\Concerns;

use App\Models\Product;

/**
 * Compartilhado entre Terminal (venda direta) e TabTerminal (mesa/comanda): busca de produto
 * por código de barras, checagem de estoque antes de adicionar e montagem dos dados de opções
 * pro seletor client-side (Alpine). Cada componente implementa seu próprio addProduct().
 */
trait HasProductLookup
{
    public function lookupByBarcode(): void
    {
        $barcode = trim($this->barcodeInput);
        $this->barcodeInput = '';
        $this->dispatch('pdv-barcode-processed');

        if (blank($barcode) || ! $this->selectedBranchId) {
            return;
        }

        $product = Product::withoutGlobalScopes()
            ->whereHas('branches', fn ($q) => $q
                ->where('branches.id', $this->selectedBranchId)
                ->where('branch_product.available', true)
            )
            ->where('active', true)
            ->where('available_in_pdv', true)
            ->where('barcode', $barcode)
            ->first();

        if (! $product) {
            $this->addError('barcode', "Código '{$barcode}' não encontrado.");

            return;
        }

        $this->resetValidation('barcode');
        $this->addProduct($product->id);
    }

    public function buildProductDataForSidebar(Product $product): ?array
    {
        if ($product->optionGroups->isEmpty()) {
            return null;
        }

        $existingSels = [];
        foreach ($this->cart[(string) $product->id]['options'] ?? [] as $gId => $gData) {
            foreach ($gData['selections'] ?? [] as $oId => $sel) {
                $existingSels[(int) $gId][(int) $oId] = (int) ($sel['qty'] ?? 0);
            }
        }

        $allFixed = $product->optionGroups->every(fn ($g) => $g->fixed);

        return [
            'id' => $product->id,
            'name' => $product->name,
            'price' => (float) $product->effective_price,
            'allFixed' => $allFixed,
            'groups' => $product->optionGroups->values()->map(function ($g, $gi) use ($product, $existingSels) {
                $isVariantPriceGroup = $product->is_variant && $gi === 0;
                $resolvePrice = fn ($o) => ($isVariantPriceGroup || ! $g->fixed) ? (float) $o->additional_price : 0.0;

                return [
                    'id' => $g->id,
                    'name' => $g->name,
                    'image_url' => $g->image_url,
                    'total_qty' => $g->total_qty,
                    'min_qty' => $g->min_qty,
                    'fixed' => (bool) $g->fixed,
                    'options' => [
                        ...$g->options->map(fn ($o) => [
                            'id' => $o->id,
                            'name' => $o->name,
                            'image_url' => $o->image_url,
                            'description' => $o->description_html,
                            'additional_price' => $resolvePrice($o),
                            'max_qty' => $o->max_qty,
                            'paused' => false,
                            'prefilledQty' => $g->fixed
                                ? (int) $o->default_qty
                                : ($existingSels[$g->id][$o->id] ?? 0),
                        ])->toArray(),
                        ...$g->inactiveOptions->map(fn ($o) => [
                            'id' => $o->id,
                            'name' => $o->name,
                            'image_url' => $o->image_url,
                            'description' => $o->description_html,
                            'additional_price' => $resolvePrice($o),
                            'paused' => true,
                            'prefilledQty' => 0,
                        ])->toArray(),
                    ],
                ];
            })->toArray(),
        ];
    }

    private function checkStockBeforeAdd(int $productId, string $productName): bool
    {
        $stocks = $this->productStocks;

        if (! array_key_exists($productId, $stocks)) {
            return true;
        }

        $available = (int) $stocks[$productId];
        $inCart = 0;

        foreach ($this->cart as $key => $item) {
            $pid = (int) ($item['product_id'] ?? explode('_', (string) $key)[0]);
            if ($pid === $productId) {
                $inCart += (int) ($item['qty'] ?? 0);
            }
        }

        if ($inCart >= $available) {
            $this->addError('stock', "Estoque insuficiente para \"{$productName}\": disponível {$available}.");

            return false;
        }

        return true;
    }
}
