<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ifood_integrations', function (Blueprint $table) {
            $table->json('available_merchants')->nullable()->after('merchant_id');
        });
    }

    public function down(): void
    {
        Schema::table('ifood_integrations', function (Blueprint $table) {
            $table->dropColumn('available_merchants');
        });
    }
};
