<?php

namespace App\Services\Ifood;

use App\DTOs\IfoodOrderDTO;
use App\Models\Product;
use App\Models\ProductOption;
use App\Services\Order\CartOptionPricing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class IfoodOrderMapper
{
    /**
     * Resolve os itens do pedido iFood para o formato de $cart esperado por
     * OrderService::createOrder().
     *
     * Item ligado a um produto da filial: OrderService reprecifica pelo cadastro do VEDDI
     * (o preço do payload iFood fica só como referência).
     *
     * Item sem vínculo — cardápio montado direto no iFood, complemento sem opção equivalente,
     * complemento aninhado ou escolha fora das regras do grupo do produto — entra como item
     * avulso, com nome, preço e complementos do próprio pedido iFood. O pedido já foi feito e
     * pago lá: recusar aqui só perderia o pedido.
     *
     * @return array<string, array<string, mixed>>
     */
    public function mapToCart(IfoodOrderDTO $dto, int $branchId): array
    {
        $cart = [];

        foreach ($dto->items as $index => $item) {
            $productId = $this->resolveProductId($item, $branchId);
            $options = $productId ? $this->mapOptions($item, $productId) : null;

            if ($options === null) {
                Log::channel('ifood')->info('iFood: item sem vínculo no VEDDI entrou como item avulso', [
                    'ifood_order_id' => $dto->ifoodOrderId,
                    'branch_id' => $branchId,
                    'ifood_item_id' => $item['ifoodItemId'],
                    'product_id' => $productId,
                    'reason' => $productId ? 'complemento' : 'item',
                ]);

                $cart["ifood_{$index}"] = $this->unlinkedItem($item);

                continue;
            }

            $cart["ifood_{$index}"] = [
                'product_id' => $productId,
                'qty' => $item['quantity'],
                'name' => $item['name'],
                'price' => $item['unitPrice'],
                'options' => $options,
                'notes' => $item['observations'] ?? null,
            ];
        }

        return $cart;
    }

    private function resolveProductId(array $item, int $branchId): ?int
    {
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

        return $productId ? (int) $productId : null;
    }

    /**
     * Item avulso: preço da linha é o totalPrice do iFood (item + complementos, já vezes a
     * quantidade). Complementos viram o mesmo snapshot que as telas e comandas leem
     * (group_name + selections com name/qty/additional_price), agrupados pelo groupName do iFood.
     */
    private function unlinkedItem(array $item): array
    {
        $qty = max(1, (int) $item['quantity']);
        $lineTotal = $item['totalPrice'] > 0
            ? $item['totalPrice']
            : $item['unitPrice'] * $qty + $item['optionsPrice'];

        $groups = [];
        foreach ($item['options'] as $option) {
            $groupName = $option['groupName'] ?? 'Complementos';
            $groups[$groupName] ??= ['group_name' => $groupName, 'selections' => []];
            $groups[$groupName]['selections'][] = $this->unlinkedSelection($option);

            foreach ([...$option['customizations'], ...$option['nestedOptions']] as $child) {
                $groups[$groupName]['selections'][] = $this->unlinkedSelection($child, $option['name']);
            }
        }

        return [
            'product_id' => null,
            'unlinked' => true,
            'qty' => $qty,
            'name' => $item['name'],
            'unit_price' => round($lineTotal / $qty, 2),
            'subtotal' => round($lineTotal, 2),
            'options' => array_values($groups),
            'notes' => $item['observations'] ?? null,
        ];
    }

    private function unlinkedSelection(array $option, ?string $parentName = null): array
    {
        return [
            'name' => $parentName ? "{$parentName} / {$option['name']}" : $option['name'],
            'qty' => $option['quantity'],
            'additional_price' => $option['unitPrice'],
        ];
    }

    /** Project third-level customizations into mapped selections, preserving their parent IDs. */
    private function flattenOptions(array $options, ?string $parentId = null): iterable
    {
        foreach ($options as $option) {
            if ($parentId !== null) {
                $option['parentOptionId'] = $parentId;
            }
            yield $option;
            yield from $this->flattenOptions($option['customizations'] ?? [], $option['ifoodOptionId']);
        }
    }

    /**
     * Complementos do item ligados às opções do produto. Null quando algum não tem equivalente
     * no VEDDI ou a escolha não passa nas regras dos grupos do produto: aí o item vai como avulso.
     */
    private function mapOptions(array $item, int $productId): ?array
    {
        $options = [];

        foreach ($this->flattenOptions($item['options']) as $option) {
            // Complemento-de-complemento: o schema de produtos só tem 1 nível
            // (product_option_groups → product_options).
            if ($option['hasNestedOptions']) {
                return null;
            }

            $productOption = ProductOption::where('ifood_option_id', $option['ifoodOptionId'])
                ->whereHas('group.products', fn ($query) => $query->where('products.id', $productId))
                ->with('group')
                ->first();

            // Mesmo caso do item principal (ver resolveProductId): pedido de teste troca o
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
                return null;
            }

            $groupId = $productOption->product_option_group_id;
            $options[$groupId]['id'] = $groupId;
            $options[$groupId]['group_name'] = $productOption->group->name;
            $selection = $options[$groupId]['selections'][$productOption->id] ?? ['name' => $productOption->name, 'qty' => 0];
            $selection['qty'] += $option['quantity'];
            // Keep each parent association when the same customization occurs twice.
            if (isset($option['parentOptionId'])) {
                $selection['ifood_parents'][] = $option['parentOptionId'];
            }
            $options[$groupId]['selections'][$productOption->id] = $selection;
        }

        // Mesma validação que OrderService faz ao criar o pedido (máximo por opção e por grupo,
        // mínimo do grupo): sem ela, uma escolha fora da regra falhava o pedido inteiro.
        $product = Product::withoutGlobalScopes()->with('optionGroups')->find($productId);
        try {
            app(CartOptionPricing::class)->resolve($product, ['options' => $options]);
        } catch (RuntimeException) {
            return null;
        }

        return $options;
    }
}
