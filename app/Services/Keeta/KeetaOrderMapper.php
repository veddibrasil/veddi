<?php

namespace App\Services\Keeta;

use App\DTOs\KeetaOrderDTO;
use App\Models\Product;
use App\Models\ProductOption;
use App\Services\Order\CartOptionPricing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Itens do pedido Keeta → $cart do OrderService::createOrder().
 *
 * Item ligado (externalCode "veddi-p{product_id}", o id que o KeetaCatalogBuilder publica) com
 * complementos ligados ("veddi-o{option_id}") que passam nas regras do produto: o OrderService
 * reprecifica pelo cadastro do VEDDI. O resto — cardápio montado direto no portal da Keeta,
 * complemento sem equivalente, venda por peso — entra como item avulso com nome e preço do
 * próprio pedido: ele já foi feito e pago lá, recusar aqui só perderia o pedido.
 */
class KeetaOrderMapper
{
    /** @return array<string, array<string, mixed>> */
    public function mapToCart(KeetaOrderDTO $dto, int $branchId): array
    {
        $cart = [];

        foreach ($dto->items as $index => $item) {
            $byWeight = $item['unit'] !== 'UN' || floor($item['quantity']) !== $item['quantity'];
            $productId = $byWeight ? null : $this->resolveProductId($item['externalCode'], $branchId);
            $options = $productId ? $this->mapOptions($item, $productId) : null;

            if ($options === null) {
                Log::channel('keeta')->info('Keeta: item sem vínculo no VEDDI entrou como item avulso', [
                    'keeta_order_id' => $dto->orderId,
                    'branch_id' => $branchId,
                    'product_id' => $productId,
                    'reason' => $byWeight ? 'peso' : ($productId ? 'complemento' : 'item'),
                ]);

                $cart["keeta_{$index}"] = $this->unlinkedItem($item, $byWeight);

                continue;
            }

            $cart["keeta_{$index}"] = [
                'product_id' => $productId,
                'qty' => (int) $item['quantity'],
                'name' => $item['name'],
                'price' => $item['unitPrice'],
                'options' => $options,
                'notes' => $item['remark'],
            ];
        }

        return $cart;
    }

    private function resolveProductId(string $externalCode, int $branchId): ?int
    {
        if (preg_match('/^veddi-p(\d+)$/', $externalCode, $m) !== 1) {
            return null;
        }

        $productId = DB::table('branch_product')
            ->where('branch_id', $branchId)
            ->where('product_id', (int) $m[1])
            ->value('product_id');

        return $productId ? (int) $productId : null;
    }

    /**
     * Item avulso: preço da linha é o totalPrice da Keeta (quantidade × (unitário + opções)).
     * Opções viram o snapshot que telas e comandas leem (group_name + selections).
     */
    private function unlinkedItem(array $item, bool $byWeight): array
    {
        $qty = $byWeight ? 1 : max(1, (int) $item['quantity']);
        $lineTotal = $item['totalPrice'] > 0 ? $item['totalPrice'] : ($item['unitPrice'] + $item['optionsPrice']) * $item['quantity'];
        $name = $item['name'];

        if ($byWeight) {
            $name .= ' ('.rtrim(rtrim(number_format($item['quantity'], 3, ',', ''), '0'), ',').' '.$item['unit'].')';
        }

        $selections = array_map(fn (array $option) => [
            'name' => $option['name'],
            'qty' => $option['quantity'],
            'additional_price' => $option['unitPrice'],
        ], $item['options']);

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

    /**
     * Complementos ligados às opções do produto. Null quando algum não tem equivalente no
     * VEDDI, vem fracionado ou a escolha não passa nas regras do grupo.
     */
    private function mapOptions(array $item, int $productId): ?array
    {
        $options = [];

        foreach ($item['options'] as $option) {
            if (preg_match('/^veddi-o(\d+)$/', $option['externalCode'], $m) !== 1 || floor($option['quantity']) !== $option['quantity']) {
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
            // quantity da opção é por unidade do item; o OrderService multiplica pela qty.
            $selection['qty'] += (int) $option['quantity'];
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
