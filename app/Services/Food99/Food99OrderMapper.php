<?php

namespace App\Services\Food99;

use App\DTOs\Food99OrderDTO;
use App\Models\Product;
use App\Models\ProductOption;
use App\Services\Order\CartOptionPricing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Itens do pedido 99Food → $cart do OrderService::createOrder().
 *
 * Item ligado (app_item_id "veddi-p{product_id}", gerado pelo Food99CatalogSyncService) com
 * complementos ligados ("veddi-o{option_id}") que passam nas regras do produto: OrderService
 * reprecifica pelo cadastro do VEDDI. O resto — cardápio montado direto na 99, complemento
 * sem equivalente, complemento aninhado, venda por peso — entra como item avulso com nome e
 * preço do próprio pedido: ele já foi feito e pago lá, recusar aqui só perderia o pedido.
 */
class Food99OrderMapper
{
    /** @return array<string, array<string, mixed>> */
    public function mapToCart(Food99OrderDTO $dto, int $branchId): array
    {
        $cart = [];

        foreach ($dto->items as $index => $item) {
            $productId = $item['isWeight'] ? null : $this->resolveProductId($item['appItemId'], $branchId);
            $options = $productId ? $this->mapOptions($item, $productId) : null;

            if ($options === null) {
                Log::channel('food99')->info('99Food: item sem vínculo no VEDDI entrou como item avulso', [
                    'food99_order_id' => $dto->orderId,
                    'branch_id' => $branchId,
                    'product_id' => $productId,
                    'reason' => $item['isWeight'] ? 'peso' : ($productId ? 'complemento' : 'item'),
                ]);

                $cart["99food_{$index}"] = $this->unlinkedItem($item);

                continue;
            }

            $cart["99food_{$index}"] = [
                'product_id' => $productId,
                'qty' => $item['quantity'],
                'name' => $item['name'],
                'price' => $item['unitPrice'],
                'options' => $options,
                'notes' => $item['remark'],
            ];
        }

        return $cart;
    }

    private function resolveProductId(string $appItemId, int $branchId): ?int
    {
        if (preg_match('/^veddi-p(\d+)$/', $appItemId, $m) !== 1) {
            return null;
        }

        $productId = DB::table('branch_product')
            ->where('branch_id', $branchId)
            ->where('product_id', (int) $m[1])
            ->value('product_id');

        return $productId ? (int) $productId : null;
    }

    /**
     * Item avulso: preço da linha é o total_price da 99 ((sku + subitens) × amount). Subitens
     * viram o snapshot que telas e comandas leem (group_name + selections); aninhados entram
     * achatados com o nome do pai. [VALIDAR 99Food] venda por peso: o peso vai no nome.
     */
    private function unlinkedItem(array $item): array
    {
        $qty = max(1, (int) $item['quantity']);
        $subTotalPerUnit = array_sum(array_map(fn (array $sub) => $sub['unitPrice'] * $sub['quantity'], $item['subItems']));
        $lineTotal = $item['totalPrice'] > 0 ? $item['totalPrice'] : ($item['unitPrice'] + $subTotalPerUnit) * $qty;
        $name = $item['name'];

        if ($item['isWeight'] && $item['weight'] !== null) {
            $name .= ' ('.$item['weight'].($item['weightUnit'] ? ' '.$item['weightUnit'] : '').')';
        }

        $selections = [];
        foreach ($this->flatten($item['subItems']) as $sub) {
            $selections[] = ['name' => $sub['name'], 'qty' => $sub['quantity'], 'additional_price' => $sub['unitPrice']];
        }

        return [
            'product_id' => null,
            'unlinked' => true,
            'qty' => $qty,
            'name' => $name,
            'unit_price' => round($lineTotal / $qty, 2),
            'subtotal' => round($lineTotal, 2),
            'options' => $selections === [] ? [] : [['group_name' => 'Complementos', 'selections' => $selections]],
            'notes' => $item['remark'],
        ];
    }

    private function flatten(array $subItems, ?string $parentName = null): iterable
    {
        foreach ($subItems as $sub) {
            yield [...$sub, 'name' => $parentName ? "{$parentName} / {$sub['name']}" : $sub['name']];
            yield from $this->flatten($sub['subItems'], $sub['name']);
        }
    }

    /**
     * Complementos ligados às opções do produto. Null quando algum não tem equivalente no
     * VEDDI, tem filhos (o cadastro só tem 1 nível) ou a escolha não passa nas regras do grupo.
     */
    private function mapOptions(array $item, int $productId): ?array
    {
        $options = [];

        foreach ($item['subItems'] as $sub) {
            if ($sub['subItems'] !== [] || preg_match('/^veddi-o(\d+)$/', $sub['appItemId'], $m) !== 1) {
                return null;
            }

            $productOption = ProductOption::with('group')->find((int) $m[1]);

            // Grupo de opção é compartilhável (option_group_product many-to-many): confirma pelo pivot.
            $belongsToProduct = $productOption?->group
                ?->products()
                ->where('products.id', $productId)
                ->exists() ?? false;

            if (! $belongsToProduct) {
                return null;
            }

            $groupId = $productOption->product_option_group_id;
            $options[$groupId]['id'] = $groupId;
            $options[$groupId]['group_name'] = $productOption->group->name;
            $selection = $options[$groupId]['selections'][$productOption->id] ?? ['name' => $productOption->name, 'qty' => 0];
            // amount do subitem é por unidade do item pai; o OrderService multiplica pela qty.
            $selection['qty'] += $sub['quantity'];
            $options[$groupId]['selections'][$productOption->id] = $selection;
        }

        $product = Product::withoutGlobalScopes()->with('optionGroups')->find($productId);
        try {
            app(CartOptionPricing::class)->resolve($product, ['options' => $options]);
        } catch (RuntimeException) {
            return null;
        }

        return $options;
    }
}
