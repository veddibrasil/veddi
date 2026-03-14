<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['company_id', 'created_at'], 'orders_company_created_at_idx');
            $table->index(['branch_id', 'status'], 'orders_branch_status_idx');
        });

        Schema::table('coupon_usages', function (Blueprint $table) {
            $table->index(['coupon_id', 'customer_id'], 'coupon_usages_coupon_customer_idx');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->index(['branch_id', 'product_id', 'created_at'], 'stock_movements_branch_product_created_idx');
            $table->index(['company_id', 'type'], 'stock_movements_company_type_idx');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_company_created_at_idx');
            $table->dropIndex('orders_branch_status_idx');
        });

        Schema::table('coupon_usages', function (Blueprint $table) {
            $table->dropIndex('coupon_usages_coupon_customer_idx');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex('stock_movements_branch_product_created_idx');
            $table->dropIndex('stock_movements_company_type_idx');
        });
    }
};
