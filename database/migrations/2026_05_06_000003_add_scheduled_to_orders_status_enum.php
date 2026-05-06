<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        // Em testes (SQLite) não há suporte a ALTER TABLE ... MODIFY COLUMN ... ENUM.
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement("ALTER TABLE orders MODIFY COLUMN status ENUM('pending','awaiting_payment','paid','preparing','ready','delivered','cancelled','refunded','scheduled') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement("ALTER TABLE orders MODIFY COLUMN status ENUM('pending','awaiting_payment','paid','preparing','ready','delivered','cancelled','refunded') NOT NULL DEFAULT 'pending'");
    }
};
