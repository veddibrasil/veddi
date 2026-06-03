<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('fiscal_notes_enabled')->default(false)->after('card_fee_absorbed_by_company');
            $table->string('fiscal_addon_asaas_id')->nullable()->after('fiscal_notes_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['fiscal_notes_enabled', 'fiscal_addon_asaas_id']);
        });
    }
};
