<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Item de pedido iFood sem produto ligado no VEDDI (cardápio montado direto no iFood) entra
     * como item avulso: nome, preço e complementos do próprio pedido, sem product_id. A FK
     * continua valendo pros itens com produto.
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->change();
        });
    }

    /** Só reverte se não houver item avulso gravado (coluna volta a ser obrigatória). */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable(false)->change();
        });
    }
};
