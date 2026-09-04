<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Confirmado contra sandbox real (2026-09-04): a Catalog API exige um
 * catalogId no path de /categories (GET /catalog/v2.0/merchants/{merchantId}/
 * catalogs retorna o catalogId único que toda loja já tem por padrão).
 * Persistido aqui pra não buscar de novo em toda chamada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ifood_integrations', function (Blueprint $table) {
            $table->string('catalog_id')->nullable()->after('merchant_id');
        });
    }

    public function down(): void
    {
        Schema::table('ifood_integrations', function (Blueprint $table) {
            $table->dropColumn('catalog_id');
        });
    }
};
