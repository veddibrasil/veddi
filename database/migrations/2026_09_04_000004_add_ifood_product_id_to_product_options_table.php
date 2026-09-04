<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Confirmado contra sandbox real (2026-09-04): cada opção de complemento
 * (COMBO_V2 + OFFER_UNIT) também precisa de um "product" próprio referenciado
 * via options[].productId, separado do ifood_option_id (que é o id da opção
 * em si). Ver IfoodCatalogSyncService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_options', function (Blueprint $table) {
            $table->string('ifood_product_id')->nullable()->after('ifood_option_id');
        });
    }

    public function down(): void
    {
        Schema::table('product_options', function (Blueprint $table) {
            $table->dropColumn('ifood_product_id');
        });
    }
};
