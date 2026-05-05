<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_option_groups', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        Schema::create('option_group_product', function (Blueprint $table) {
            $table->foreignId('product_option_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->primary(['product_option_group_id', 'product_id']);
        });

        // Migrate existing data: fill company_id from product, create pivot rows
        DB::table('product_option_groups as pog')
            ->join('products as p', 'p.id', '=', 'pog.product_id')
            ->select('pog.id as group_id', 'p.company_id', 'pog.product_id', 'pog.sort_order')
            ->orderBy('pog.id')
            ->get()
            ->each(function ($row) {
                DB::table('product_option_groups')
                    ->where('id', $row->group_id)
                    ->update(['company_id' => $row->company_id]);

                DB::table('option_group_product')->insert([
                    'product_option_group_id' => $row->group_id,
                    'product_id' => $row->product_id,
                    'sort_order' => $row->sort_order,
                ]);
            });

        Schema::disableForeignKeyConstraints();

        Schema::table('product_option_groups', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
            $table->dropColumn('product_id');
        });

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::table('product_option_groups', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->constrained()->cascadeOnDelete();
        });

        Schema::enableForeignKeyConstraints();

        // Restore product_id from pivot
        DB::table('option_group_product')
            ->get()
            ->each(function ($row) {
                DB::table('product_option_groups')
                    ->where('id', $row->product_option_group_id)
                    ->update(['product_id' => $row->product_id]);
            });

        Schema::dropIfExists('option_group_product');

        Schema::disableForeignKeyConstraints();

        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('product_option_groups', function (Blueprint $table) {
                $table->dropForeign(['company_id']);
            });
        }

        Schema::table('product_option_groups', function (Blueprint $table) {
            $table->dropColumn('company_id');
        });

        Schema::enableForeignKeyConstraints();
    }
};
