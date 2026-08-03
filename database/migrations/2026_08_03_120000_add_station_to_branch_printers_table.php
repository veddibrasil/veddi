<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_printers', function (Blueprint $table) {
            // MySQL não deixa dropar o unique(branch_id) direto: ele sustenta a FK de
            // branch_id. Precisa soltar a FK antes, trocar os índices, e recriar a FK
            // por cima do novo unique composto (branch_id continua sendo a coluna mais
            // à esquerda, então ele também serve de suporte pra FK).
            $table->dropForeign(['branch_id']);
            $table->dropUnique(['branch_id']);

            $table->string('station', 20)->default('geral')->after('branch_id');
            $table->boolean('print_fiscal_note')->default(false)->after('auto_print');

            $table->unique(['branch_id', 'station']);
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('branch_printers', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
            $table->dropUnique(['branch_id', 'station']);
            $table->dropColumn(['station', 'print_fiscal_note']);

            $table->unique('branch_id');
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
        });
    }
};
