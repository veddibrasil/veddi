<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Caso o ambiente já tenha a versão antiga (promo_price_from/to), migra para (type/value)
        $hasFrom = Schema::hasColumn('products', 'promo_price_from');
        $hasTo = Schema::hasColumn('products', 'promo_price_to');

        if ($hasFrom || $hasTo) {
            Schema::table('products', function (Blueprint $table) {
                if (! Schema::hasColumn('products', 'promo_price_type')) {
                    $table->enum('promo_price_type', ['fixed', 'percentage'])->default('fixed')->after('promo_price_enabled');
                }
                if (! Schema::hasColumn('products', 'promo_price_value')) {
                    $table->decimal('promo_price_value', 8, 2)->nullable()->after('promo_price_type');
                }
            });

            // Converte: assume promo antiga sempre foi "valor fixo" e usa o "por" como valor.
            DB::table('products')
                ->whereNotNull('promo_price_to')
                ->update([
                    'promo_price_type' => 'fixed',
                    'promo_price_value' => DB::raw('promo_price_to'),
                    'promo_price_enabled' => DB::raw('CASE WHEN promo_price_enabled = 1 THEN 1 ELSE 1 END'),
                ]);

            Schema::table('products', function (Blueprint $table) {
                $drops = [];
                if (Schema::hasColumn('products', 'promo_price_from')) {
                    $drops[] = 'promo_price_from';
                }
                if (Schema::hasColumn('products', 'promo_price_to')) {
                    $drops[] = 'promo_price_to';
                }
                if (! empty($drops)) {
                    $table->dropColumn($drops);
                }
            });

            return;
        }

        // Caso não exista nenhuma das colunas (ex.: base antiga sem promo), cria as novas colunas.
        if (! Schema::hasColumn('products', 'promo_price_type') || ! Schema::hasColumn('products', 'promo_price_value')) {
            Schema::table('products', function (Blueprint $table) {
                if (! Schema::hasColumn('products', 'promo_price_enabled')) {
                    $table->boolean('promo_price_enabled')->default(false)->after('price');
                }
                if (! Schema::hasColumn('products', 'promo_price_type')) {
                    $table->enum('promo_price_type', ['fixed', 'percentage'])->default('fixed')->after('promo_price_enabled');
                }
                if (! Schema::hasColumn('products', 'promo_price_value')) {
                    $table->decimal('promo_price_value', 8, 2)->nullable()->after('promo_price_type');
                }
            });
        }
    }

    public function down(): void
    {
        // Rollback "best effort": recria promo_price_from/to e tenta popular com base no preço atual.
        if (Schema::hasColumn('products', 'promo_price_type') || Schema::hasColumn('products', 'promo_price_value')) {
            Schema::table('products', function (Blueprint $table) {
                if (! Schema::hasColumn('products', 'promo_price_from')) {
                    $table->decimal('promo_price_from', 8, 2)->nullable()->after('promo_price_enabled');
                }
                if (! Schema::hasColumn('products', 'promo_price_to')) {
                    $table->decimal('promo_price_to', 8, 2)->nullable()->after('promo_price_from');
                }
            });

            // promo_price_from = price (preço normal)
            DB::table('products')->update([
                'promo_price_from' => DB::raw('price'),
            ]);

            // promo_price_to: se fixed => value; se percentage => price * (1 - value/100)
            DB::table('products')->update([
                'promo_price_to' => DB::raw("CASE
                    WHEN promo_price_enabled = 0 OR promo_price_value IS NULL THEN NULL
                    WHEN promo_price_type = 'percentage' THEN ROUND(price * (1 - (promo_price_value / 100)), 2)
                    ELSE promo_price_value
                END"),
            ]);

            Schema::table('products', function (Blueprint $table) {
                $drops = [];
                if (Schema::hasColumn('products', 'promo_price_type')) {
                    $drops[] = 'promo_price_type';
                }
                if (Schema::hasColumn('products', 'promo_price_value')) {
                    $drops[] = 'promo_price_value';
                }
                if (! empty($drops)) {
                    $table->dropColumn($drops);
                }
            });
        }
    }
};
