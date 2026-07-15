<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_notes', function (Blueprint $table) {
            // Webhook e reconciliação buscam por provider_reference a cada callback/ciclo —
            // sem índice vira table scan conforme o volume de notas cresce.
            $table->index('provider_reference');
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_notes', function (Blueprint $table) {
            $table->dropIndex(['provider_reference']);
        });
    }
};
