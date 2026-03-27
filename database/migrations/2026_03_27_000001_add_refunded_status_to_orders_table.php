<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE orders MODIFY COLUMN status ENUM(
            'pending',
            'awaiting_payment',
            'paid',
            'preparing',
            'ready',
            'delivered',
            'cancelled',
            'refunded'
        ) DEFAULT 'pending'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE orders MODIFY COLUMN status ENUM(
            'pending',
            'awaiting_payment',
            'paid',
            'preparing',
            'ready',
            'delivered',
            'cancelled'
        ) DEFAULT 'pending'");
    }
};
