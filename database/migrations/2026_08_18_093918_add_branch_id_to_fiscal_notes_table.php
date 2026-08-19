<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_notes', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('order_id')->nullOnDelete()->constrained();
        });

        Schema::table('fiscal_notes', function (Blueprint $table) {
            $table->index(['branch_id', 'status']);
        });
    }

    public function down(): void
    {
        // No MySQL, o índice composto (branch_id, status) acaba sendo o único
        // sustentando a FK (o índice auto-criado pra ela some quando um índice
        // redundante cobrindo a mesma coluna líder é adicionado depois) — dropar a
        // FK antes do índice evita "Cannot drop index needed in a foreign key
        // constraint".
        Schema::table('fiscal_notes', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
        });

        Schema::table('fiscal_notes', function (Blueprint $table) {
            $table->dropIndex(['branch_id', 'status']);
        });

        Schema::table('fiscal_notes', function (Blueprint $table) {
            $table->dropColumn('branch_id');
        });
    }
};
