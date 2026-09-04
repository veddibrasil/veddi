<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_product', function (Blueprint $table) {
            $table->string('ifood_item_id')->nullable()->after('product_id');
            $table->index('ifood_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('branch_product', function (Blueprint $table) {
            $table->dropIndex(['ifood_item_id']);
            $table->dropColumn('ifood_item_id');
        });
    }
};
