<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class StockInitialize extends Command
{
    protected $signature = 'stock:initialize';

    protected $description = 'Inicializa os campos de estoque em branch_product para registros existentes (quantity=0, track_stock=false)';

    public function handle(): int
    {
        $count = DB::table('branch_product')
            ->whereNull('quantity')
            ->orWhere('quantity', '<', 0)
            ->count();

        if ($count === 0) {
            $this->info('Todos os registros branch_product já estão inicializados.');

            return self::SUCCESS;
        }

        DB::table('branch_product')->update([
            'quantity' => DB::raw('COALESCE(quantity, 0)'),
            'min_quantity' => DB::raw('COALESCE(min_quantity, 0)'),
            'track_stock' => DB::raw('COALESCE(track_stock, 0)'),
        ]);

        $this->info('Estoque inicializado. Habilite o rastreamento manualmente por produto em /admin/stock.');

        return self::SUCCESS;
    }
}
