<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_notes', function (Blueprint $table) {
            // Focus NFe às vezes retorna chave_nfe com prefixo "NFe" (47 chars) além dos
            // 44 dígitos padrão da chave de acesso — char(44) truncava e quebrava o update
            // (SQLSTATE[22001] em produção, ver fiscal-2026-07-15.log). string(50) sobra
            // margem sem impor tamanho fixo (CHAR paddava/rejeitava valores diferentes de 44).
            $table->string('access_key', 50)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_notes', function (Blueprint $table) {
            $table->char('access_key', 44)->nullable()->change();
        });
    }
};
