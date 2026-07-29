<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('delivery_settings', function (Blueprint $table) {
            $table->enum('fee_type', ['flat', 'neighborhood', 'distance', 'zone'])->default('flat')->change();
            $table->json('zones')->nullable()->after('service_radius_km');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('delivery_settings', function (Blueprint $table) {
            $table->dropColumn('zones');
            $table->enum('fee_type', ['flat', 'neighborhood', 'distance'])->default('flat')->change();
        });
    }
};
