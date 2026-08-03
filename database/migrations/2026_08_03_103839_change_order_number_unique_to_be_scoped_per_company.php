<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // order_number é gerado a partir do order_prefix da empresa (3 letras do
            // slug), que não é garantido único entre empresas — duas empresas com
            // nomes parecidos (ex.: "Mister Coxinha" e "Mister Coxinha2") podem gerar
            // o mesmo prefixo e colidir na Nª order de cada uma. O número só precisa
            // ser único dentro da própria empresa (é o que aparece pro cliente/caixa).
            $table->dropUnique(['order_number']);
            $table->unique(['company_id', 'order_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'order_number']);
            $table->unique('order_number');
        });
    }
};
