<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_product', function (Blueprint $table) {
            // Resultado do último envio do item ao iFood nesta filial: o sync segue item a item,
            // então um produto com problema não derruba os outros e o erro fica visível no cadastro.
            $table->string('ifood_sync_error')->nullable()->after('ifood_item_type');
            $table->timestamp('ifood_synced_at')->nullable()->after('ifood_sync_error');
        });

        Schema::table('branch_pauses', function (Blueprint $table) {
            // Pausa da filial replicada como interrupção da loja no iFood (opcional).
            $table->string('ifood_interruption_id')->nullable()->after('recurring_annual');
        });

        Schema::table('ifood_order_events', function (Blueprint $table) {
            // Motivo, em texto pro restaurante, de um pedido do iFood que não entrou no painel.
            $table->string('error')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('branch_product', function (Blueprint $table) {
            $table->dropColumn(['ifood_sync_error', 'ifood_synced_at']);
        });

        Schema::table('branch_pauses', function (Blueprint $table) {
            $table->dropColumn('ifood_interruption_id');
        });

        Schema::table('ifood_order_events', function (Blueprint $table) {
            $table->dropColumn('error');
        });
    }
};
