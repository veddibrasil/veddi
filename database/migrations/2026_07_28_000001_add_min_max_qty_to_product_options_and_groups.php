<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_option_groups', function (Blueprint $table) {
            $table->unsignedInteger('min_qty')->default(0)->after('total_qty');
        });

        Schema::table('product_options', function (Blueprint $table) {
            $table->unsignedInteger('max_qty')->nullable()->after('default_qty');
        });
    }

    public function down(): void
    {
        Schema::table('product_option_groups', function (Blueprint $table) {
            $table->dropColumn('min_qty');
        });

        Schema::table('product_options', function (Blueprint $table) {
            $table->dropColumn('max_qty');
        });
    }
};
