<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Módulo Garçom é cobrado na mesma assinatura/fatura do plano (asaas_subscription_id),
            // igual o módulo PDV — por isso não há uma coluna *_asaas_id dedicada aqui.
            $table->boolean('waiter_module_enabled')->default(false)->after('pdv_module_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('waiter_module_enabled');
        });
    }
};
