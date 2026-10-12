<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Consentimento explícito (Meta + LGPD), por cliente de cada empresa.
        Schema::table('customers', function (Blueprint $table) {
            $table->timestamp('whatsapp_opt_in_at')->nullable();
            $table->timestamp('whatsapp_opt_out_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['whatsapp_opt_in_at', 'whatsapp_opt_out_at']);
        });
    }
};
