<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Módulo PDV é cobrado na mesma assinatura/fatura do plano (asaas_subscription_id),
            // não numa assinatura própria — por isso não há uma coluna *_asaas_id dedicada aqui.
            $table->boolean('pdv_module_enabled')->default(false)->after('fiscal_addon_asaas_id');
        });

        // PDV deixou de ser um plano e passou a ser um módulo pago à parte.
        // Qualquer empresa que ainda esteja com plan='pdv' migra para 'pro' com o módulo já habilitado,
        // evitando ValueError ao castear a coluna para o enum Plan (que não tem mais o case Pdv).
        DB::table('companies')
            ->where('plan', 'pdv')
            ->update(['plan' => 'pro', 'pdv_module_enabled' => true]);
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('pdv_module_enabled');
        });
    }
};
