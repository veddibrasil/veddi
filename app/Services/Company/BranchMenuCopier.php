<?php

namespace App\Services\Company;

use App\Models\Branch;
use App\Services\Order\MenuCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Copia o cardápio de uma filial para outra da mesma empresa.
 *
 * Produto, categoria e grupos de opções são da empresa; a filial só escolhe quais produtos vende
 * (pivot `branch_product`). Copiar é vincular a filial de destino aos mesmos produtos da origem,
 * e as categorias aparecem junto. Editar o produto depois vale para as duas filiais.
 *
 * Estoque é físico e não é copiado: produto com controle de estoque começa zerado e indisponível
 * até alguém lançar a quantidade da nova filial. Vínculo com iFood e 99Food (ids/erros de sync) também
 * fica de fora — é por loja e só existe depois de conectar a integração da filial.
 */
class BranchMenuCopier
{
    public function __construct(private MenuCache $menuCache) {}

    /**
     * @return int Quantidade de produtos vinculados à filial de destino.
     */
    public function copy(Branch $source, Branch $target): int
    {
        if ((int) $source->company_id !== (int) $target->company_id) {
            throw new InvalidArgumentException('Só é possível copiar o cardápio entre filiais da mesma empresa.');
        }

        if ((int) $source->id === (int) $target->id) {
            return 0;
        }

        // Query direta no pivot (fora do CompanyScope): o company_id do produto é conferido
        // explicitamente para nunca vincular produto de outra empresa.
        $rows = DB::table('branch_product')
            ->join('products', 'products.id', '=', 'branch_product.product_id')
            ->where('branch_product.branch_id', $source->id)
            ->where('products.company_id', $target->company_id)
            ->whereNull('products.deleted_at')
            ->whereNotExists(fn ($q) => $q->from('branch_product as existing')
                ->whereColumn('existing.product_id', 'branch_product.product_id')
                ->where('existing.branch_id', $target->id))
            ->orderBy('branch_product.product_id')
            ->get([
                'branch_product.product_id',
                'branch_product.available',
                'branch_product.track_stock',
                'branch_product.min_quantity',
            ])
            ->map(fn ($row) => [
                'branch_id' => $target->id,
                'product_id' => $row->product_id,
                'available' => $row->track_stock ? false : (bool) $row->available,
                'track_stock' => (bool) $row->track_stock,
                'min_quantity' => (int) $row->min_quantity,
                'quantity' => 0,
            ]);

        foreach ($rows->chunk(500) as $chunk) {
            DB::table('branch_product')->insert($chunk->values()->all());
        }

        Cache::forget("stock:low:branch:{$target->id}");
        $this->menuCache->forgetBranch($target->id, $target->company_id);

        return $rows->count();
    }
}
