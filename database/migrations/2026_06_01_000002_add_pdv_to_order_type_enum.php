<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite (test env) doesn't support ALTER COLUMN on enums — skip.
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement("ALTER TABLE orders MODIFY COLUMN order_type ENUM('delivery', 'pickup', 'pdv') NULL");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement("ALTER TABLE orders MODIFY COLUMN order_type ENUM('delivery', 'pickup') NULL");
    }
};
