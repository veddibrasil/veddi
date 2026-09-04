<?php

namespace App\Services\Ifood;

use App\DTOs\IfoodOrderDTO;
use App\Exceptions\IfoodMappingException;
use App\Models\ProductOption;
use Illuminate\Support\Facades\DB;

class IfoodOrderMapper
{
    /**
     * Resolve os itens do pedido iFood para o formato de $cart esperado por
     * OrderService::createOrder(). Preço do payload iFood é usado só como
     * referência — nunca é confiado como unit_price (OrderService reprecifica
     * tudo via resolveProducts()).
     *
     * @return array<string, array{product_id: int, qty: int, name: string, price: float, options: array}>
     *
     * @throws IfoodMappingException se algum item/opção não estiver mapeado, ou se
     *                               houver complemento aninhado (2+ níveis, não suportado).
     */
    public function mapToCart(IfoodOrderDTO $dto, int $branchId): array
    {
        $cart = [];

        foreach ($dto->items as $index => $item) {
            $productId = DB::table('branch_product')
                ->where('branch_id', $branchId)
                ->where('ifood_item_id', $item['ifoodItemId'])
                ->value('product_id');

            // Pedido de teste do iFood substitui o item.id do catálogo por um id
            // efêmero próprio do pedido de teste (name também vira "PEDIDO DE
            // TESTE - NÃO ENTREGAR") — o externalCode que geramos no sync
            // (IfoodCatalogSyncService::buildItemPayload, formato "veddi-p{id}")
            // continua batendo e é o fallback confiável nesse caso.
            if (! $productId && preg_match('/^veddi-p(\d+)$/', (string) $item['externalCode'], $m)) {
                $productId = DB::table('branch_product')
                    ->where('branch_id', $branchId)
                    ->where('product_id', (int) $m[1])
                    ->value('product_id');
            }

            if (! $productId) {
                throw new IfoodMappingException(
                    "Item iFood '{$item['name']}' (ifoodItemId={$item['ifoodItemId']}) não está mapeado para nenhum produto na filial #{$branchId}."
                );
            }

            $options = $this->mapOptions($item, (int) $productId);

            $cart["ifood_{$index}"] = [
                'product_id' => (int) $productId,
                'qty' => $item['quantity'],
                'name' => $item['name'],
                'price' => $item['unitPrice'],
                'options' => $options,
            ];
        }

        return $cart;
    }

    private function mapOptions(array $item, int $productId): array
    {
        $options = [];

        foreach ($item['options'] as $option) {
            if ($option['hasNestedOptions']) {
                throw new IfoodMappingException(
                    "Item '{$item['name']}' tem complemento aninhado (complemento-de-complemento) — não suportado pelo schema atual de produtos (product_option_groups → product_options, 1 nível só)."
                );
            }

            $productOption = ProductOption::where('ifood_option_id', $option['ifoodOptionId'])
                ->with('group')
                ->first();

            // Mesmo caso do item principal (ver mapToCart): pedido de teste troca o
            // id do complemento também, externalCode ("veddi-option-{id}") é o fallback.
            if (! $productOption && preg_match('/^veddi-option-(\d+)$/', (string) $option['externalCode'], $m)) {
                $productOption = ProductOption::find((int) $m[1])?->load('group');
            }

            // Grupos de opção são compartilháveis entre produtos (option_group_product é
            // many-to-many, ver migration 2026_05_05_000001_make_option_groups_shared) —
            // não dá pra checar um product_id direto na opção, tem que confirmar pelo pivot.
            $belongsToProduct = $productOption?->group
                ?->products()
                ->where('products.id', $productId)
                ->exists() ?? false;

            if (! $productOption || ! $belongsToProduct) {
                throw new IfoodMappingException(
                    "Complemento iFood '{$option['name']}' (ifoodOptionId={$option['ifoodOptionId']}) não está mapeado para uma opção do produto #{$productId}."
                );
            }

            $groupId = $productOption->product_option_group_id;
            $options[$groupId]['id'] = $groupId;
            $options[$groupId]['selections'][$productOption->id] = ['qty' => $option['quantity']];
        }

        return $options;
    }
}
