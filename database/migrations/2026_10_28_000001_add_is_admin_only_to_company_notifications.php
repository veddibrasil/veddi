<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Notificação só para company_admin (ex.: alertas e resumo de vendas): o sino é por empresa,
     * e sem a marca gerente e papéis customizados veriam faturamento e poderiam apagar o aviso.
     */
    public function up(): void
    {
        Schema::table('company_notifications', function (Blueprint $table) {
            $table->boolean('is_admin_only')->default(false)->after('is_bar');
        });
    }

    public function down(): void
    {
        Schema::table('company_notifications', function (Blueprint $table) {
            $table->dropColumn('is_admin_only');
        });
    }
};
