<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Preenche branch_id das notas já emitidas a partir do pedido associado.
     * Notas sem order_id (pedido já deletado) ficam com branch_id null —
     * histórico morto, o resolver nunca precisa delas.
     */
    public function up(): void
    {
        DB::table('fiscal_notes')
            ->whereNull('fiscal_notes.branch_id')
            ->whereNotNull('fiscal_notes.order_id')
            ->orderBy('fiscal_notes.id')
            ->chunkById(500, function ($notes) {
                $orderIds = $notes->pluck('order_id')->unique();

                $branchByOrder = DB::table('orders')
                    ->whereIn('id', $orderIds)
                    ->pluck('branch_id', 'id');

                foreach ($notes as $note) {
                    $branchId = $branchByOrder[$note->order_id] ?? null;

                    if ($branchId === null) {
                        continue;
                    }

                    DB::table('fiscal_notes')
                        ->where('id', $note->id)
                        ->update(['branch_id' => $branchId]);
                }
            }, 'fiscal_notes.id', 'id');
    }

    /**
     * Data migration — não reversível com segurança.
     */
    public function down(): void {}
};
