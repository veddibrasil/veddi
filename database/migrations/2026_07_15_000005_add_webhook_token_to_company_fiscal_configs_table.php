<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_fiscal_configs', function (Blueprint $table) {
            // Segredo do webhook por empresa — antes era um único token global
            // (config/fiscal.php) compartilhado por todas as empresas; vazar esse
            // token comprometia o canal de status assíncrono de todo mundo.
            $table->text('webhook_token')->nullable()->after('focus_nfe_webhook_id');
        });
    }

    public function down(): void
    {
        Schema::table('company_fiscal_configs', function (Blueprint $table) {
            $table->dropColumn('webhook_token');
        });
    }
};
