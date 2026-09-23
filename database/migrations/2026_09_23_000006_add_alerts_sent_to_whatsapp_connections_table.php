<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_connections', function (Blueprint $table) {
            // Controle do monitoramento diário: {"app_inactive": "2026-09-23T09:00:00-03:00", ...} — quando
            // cada alerta ativo foi enviado ao restaurante pela última vez (evita repetir o aviso todo dia).
            $table->json('alerts_sent')->nullable()->after('last_app_activity_at');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_connections', function (Blueprint $table) {
            $table->dropColumn('alerts_sent');
        });
    }
};
