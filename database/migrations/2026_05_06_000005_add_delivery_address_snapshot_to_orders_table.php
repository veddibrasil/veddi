<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('delivery_address')->nullable()->after('order_type');
            $table->string('delivery_number', 20)->nullable()->after('delivery_address');
            $table->string('delivery_neighborhood')->nullable()->after('delivery_number');
            $table->string('delivery_city')->nullable()->after('delivery_neighborhood');
            $table->string('delivery_cep', 9)->nullable()->after('delivery_city');
            $table->string('delivery_complement', 100)->nullable()->after('delivery_cep');
        });

        // Backfill: copy customer address to existing delivery orders (subquery syntax works on both MySQL and SQLite)
        DB::statement("UPDATE orders SET
            delivery_address      = (SELECT address      FROM customers WHERE customers.id = orders.customer_id),
            delivery_number       = (SELECT number       FROM customers WHERE customers.id = orders.customer_id),
            delivery_neighborhood = (SELECT neighborhood FROM customers WHERE customers.id = orders.customer_id),
            delivery_city         = (SELECT city         FROM customers WHERE customers.id = orders.customer_id),
            delivery_cep          = (SELECT cep          FROM customers WHERE customers.id = orders.customer_id),
            delivery_complement   = (SELECT complement   FROM customers WHERE customers.id = orders.customer_id)
            WHERE order_type = 'delivery'");
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'delivery_address',
                'delivery_number',
                'delivery_neighborhood',
                'delivery_city',
                'delivery_cep',
                'delivery_complement',
            ]);
        });
    }
};
