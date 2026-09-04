<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_options', function (Blueprint $table) {
            $table->string('ifood_option_id')->nullable()->after('product_option_group_id');
            $table->index('ifood_option_id');
        });
    }

    public function down(): void
    {
        Schema::table('product_options', function (Blueprint $table) {
            $table->dropIndex(['ifood_option_id']);
            $table->dropColumn('ifood_option_id');
        });
    }
};
