<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_notifications', function (Blueprint $table) {
            $table->boolean('is_kitchen')->default(false)->after('is_delivery');
            $table->boolean('is_bar')->default(false)->after('is_kitchen');
        });
    }

    public function down(): void
    {
        Schema::table('company_notifications', function (Blueprint $table) {
            $table->dropColumn(['is_kitchen', 'is_bar']);
        });
    }
};
