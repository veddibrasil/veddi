<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Nulo = a filial usa o order_prefix da empresa (caso de todas as filiais existentes).
        Schema::table('branches', fn (Blueprint $table) => $table->string('order_prefix', 10)->nullable()->after('name'));
    }

    public function down(): void
    {
        Schema::table('branches', fn (Blueprint $table) => $table->dropColumn('order_prefix'));
    }
};
