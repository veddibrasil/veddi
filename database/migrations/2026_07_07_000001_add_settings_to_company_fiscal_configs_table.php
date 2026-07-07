<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_fiscal_configs', function (Blueprint $table) {
            $table->unsignedTinyInteger('crt')->default(1)->after('enabled');
            $table->string('provider')->default('focus_nfe')->after('inscricao_estadual');
            $table->text('provider_token')->nullable()->after('provider');
            $table->string('environment')->default('homologacao')->after('provider_token');
            $table->unsignedInteger('nfce_serie')->default(1)->after('environment');
            $table->string('certificate_path')->nullable()->after('nfce_serie');
            $table->text('certificate_password')->nullable()->after('certificate_path');
        });
    }

    public function down(): void
    {
        Schema::table('company_fiscal_configs', function (Blueprint $table) {
            $table->dropColumn([
                'crt',
                'provider',
                'provider_token',
                'environment',
                'nfce_serie',
                'certificate_path',
                'certificate_password',
            ]);
        });
    }
};
