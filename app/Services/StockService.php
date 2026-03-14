<?php

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class StockService
{
    /**
     * Deduz estoque ao criar pedido.
     * Deve ser chamado dentro de um DB::transaction existente.
     * Lança InsufficientStockException se não houver estoque.
     */
    public function deductForOrder(Order $order): void
    {
        $order->loadMissing('items.product');

        foreach ($order->items as $item) {
            $pivot = DB::table('branch_product')
                ->where('branch_id', $order->branch_id)
                ->where('product_id', $item->product_id)
                ->lockForUpdate()
                ->first();

            if (! $pivot || ! $pivot->track_stock) {
                continue;
            }

            if ($pivot->quantity < $item->quantity) {
                throw new InsufficientStockException(
                    $item->product,
                    $pivot->quantity,
                    $item->quantity
                );
            }

            $newQty = $pivot->quantity - $item->quantity;

            DB::table('branch_product')
                ->where('branch_id', $order->branch_id)
                ->where('product_id', $item->product_id)
                ->update([
                    'quantity'  => $newQty,
                    'available' => $newQty > 0,
                ]);

            StockMovement::withoutGlobalScopes()->create([
                'company_id'      => $order->company_id,
                'branch_id'       => $order->branch_id,
                'product_id'      => $item->product_id,
                'order_id'        => $order->id,
                'user_id'         => null,
                'quantity'        => -$item->quantity,
                'quantity_before' => $pivot->quantity,
                'quantity_after'  => $newQty,
                'type'            => StockMovement::TYPE_ORDER_DEDUCTION,
                'notes'           => "Pedido {$order->order_number}",
            ]);
        }
    }

    /**
     * Restaura estoque ao cancelar pedido.
     */
    public function restoreForOrder(Order $order): void
    {
        $movements = StockMovement::withoutGlobalScopes()
            ->where('order_id', $order->id)
            ->where('type', StockMovement::TYPE_ORDER_DEDUCTION)
            ->get();

        if ($movements->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($movements, $order) {
            foreach ($movements as $movement) {
                $pivot = DB::table('branch_product')
                    ->where('branch_id', $movement->branch_id)
                    ->where('product_id', $movement->product_id)
                    ->lockForUpdate()
                    ->first();

                if (! $pivot) {
                    continue;
                }

                $deducted = abs($movement->quantity);
                $newQty   = $pivot->quantity + $deducted;

                DB::table('branch_product')
                    ->where('branch_id', $movement->branch_id)
                    ->where('product_id', $movement->product_id)
                    ->update([
                        'quantity'  => $newQty,
                        'available' => true,
                    ]);

                StockMovement::withoutGlobalScopes()->create([
                    'company_id'      => $order->company_id,
                    'branch_id'       => $movement->branch_id,
                    'product_id'      => $movement->product_id,
                    'order_id'        => $order->id,
                    'user_id'         => null,
                    'quantity'        => $deducted,
                    'quantity_before' => $pivot->quantity,
                    'quantity_after'  => $newQty,
                    'type'            => StockMovement::TYPE_ORDER_RESTORE,
                    'notes'           => "Cancelamento do pedido {$order->order_number}",
                ]);
            }
        });
    }

    /**
     * Ajuste manual: delta positivo (entrada) ou negativo (saída).
     */
    public function adjust(
        Branch $branch,
        Product $product,
        int $quantity,
        string $notes = '',
        ?User $user = null
    ): StockMovement {
        return DB::transaction(function () use ($branch, $product, $quantity, $notes, $user) {
            $pivot = DB::table('branch_product')
                ->where('branch_id', $branch->id)
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->first();

            $currentQty = $pivot ? $pivot->quantity : 0;
            $newQty     = max(0, $currentQty + $quantity);

            DB::table('branch_product')
                ->where('branch_id', $branch->id)
                ->where('product_id', $product->id)
                ->update([
                    'quantity'  => $newQty,
                    'available' => $newQty > 0,
                ]);

            $type = $quantity >= 0 ? StockMovement::TYPE_MANUAL_ADD : StockMovement::TYPE_MANUAL_REMOVE;

            $movement = StockMovement::withoutGlobalScopes()->create([
                'company_id'      => $branch->company_id,
                'branch_id'       => $branch->id,
                'product_id'      => $product->id,
                'order_id'        => null,
                'user_id'         => $user?->id,
                'quantity'        => $quantity,
                'quantity_before' => $currentQty,
                'quantity_after'  => $newQty,
                'type'            => $type,
                'notes'           => $notes,
            ]);

            Cache::forget("stock:low:branch:{$branch->id}");

            return $movement;
        });
    }

    /**
     * Define quantidade absoluta.
     */
    public function setQuantity(
        Branch $branch,
        Product $product,
        int $newQuantity,
        string $notes = '',
        ?User $user = null
    ): StockMovement {
        return DB::transaction(function () use ($branch, $product, $newQuantity, $notes, $user) {
            $pivot = DB::table('branch_product')
                ->where('branch_id', $branch->id)
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->first();

            $currentQty = $pivot ? $pivot->quantity : 0;
            $safeQty    = max(0, $newQuantity);

            DB::table('branch_product')
                ->where('branch_id', $branch->id)
                ->where('product_id', $product->id)
                ->update([
                    'quantity'  => $safeQty,
                    'available' => $safeQty > 0,
                ]);

            $movement = StockMovement::withoutGlobalScopes()->create([
                'company_id'      => $branch->company_id,
                'branch_id'       => $branch->id,
                'product_id'      => $product->id,
                'order_id'        => null,
                'user_id'         => $user?->id,
                'quantity'        => $safeQty - $currentQty,
                'quantity_before' => $currentQty,
                'quantity_after'  => $safeQty,
                'type'            => StockMovement::TYPE_MANUAL_SET,
                'notes'           => $notes,
            ]);

            Cache::forget("stock:low:branch:{$branch->id}");

            return $movement;
        });
    }

    /**
     * Habilita ou desabilita rastreamento de estoque para um produto em uma filial.
     */
    public function toggleTracking(Branch $branch, Product $product, bool $track): void
    {
        DB::table('branch_product')
            ->where('branch_id', $branch->id)
            ->where('product_id', $product->id)
            ->update(['track_stock' => $track]);

        Cache::forget("stock:low:branch:{$branch->id}");
    }

    /**
     * Retorna produtos com estoque abaixo do min_quantity para uma filial.
     */
    public function getLowStockProducts(Branch $branch): Collection
    {
        return Cache::remember("stock:low:branch:{$branch->id}", now()->addMinutes(15), function () use ($branch) {
            return DB::table('branch_product')
                ->join('products', 'products.id', '=', 'branch_product.product_id')
                ->where('branch_product.branch_id', $branch->id)
                ->where('branch_product.track_stock', true)
                ->whereColumn('branch_product.quantity', '<=', 'branch_product.min_quantity')
                ->select('products.id', 'products.name', 'branch_product.quantity', 'branch_product.min_quantity')
                ->get();
        });
    }
}
