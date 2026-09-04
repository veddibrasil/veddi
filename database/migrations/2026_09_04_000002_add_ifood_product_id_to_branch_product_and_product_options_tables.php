<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Catalog API do iFood distingue "item" (entrada de cardápio, referenciada
 * pelos pedidos) de "product" (registro interno que o item aponta dentro do
 * payload de PUT /items, via item.productId). São ids diferentes; ifood_item_id
 * já existia, faltava persistir o segundo pra manter o mesmo id entre
 * sincronizações (PUT é idempotente por id). Só existe pra produtos simples —
 * complemento (COMBO_V2) ainda não tem push implementado, ver
 * IfoodCatalogSyncService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_product', function (Blueprint $table) {
            $table->string('ifood_product_id')->nullable()->after('ifood_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('branch_product', function (Blueprint $table) {
            $table->dropColumn('ifood_product_id');
        });
    }
};
