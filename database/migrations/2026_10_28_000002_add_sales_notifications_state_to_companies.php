<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Controle dos avisos de vendas no sino (SalesNotifier): quando cada alerta urgente foi enviado
     * (sai daqui quando o problema se resolve) e a última semana resumida. Fica no banco, e não no
     * cache, para sobreviver a flush/eviction sem reenviar tudo.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->json('sales_notifications_state')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('sales_notifications_state');
        });
    }
};
