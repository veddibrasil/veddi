<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('available_in_pdv')->default(true)->after('active');
            $table->boolean('available_in_delivery')->default(true)->after('available_in_pdv');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['available_in_pdv', 'available_in_delivery']);
        });
    }
};
