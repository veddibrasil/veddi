<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return; // SQLite não suporta MODIFY COLUMN; ENUM é irrelevante em SQLite
        }

        DB::statement("ALTER TABLE payments MODIFY COLUMN status ENUM('pending','paid','expired','failed','refunded') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement("ALTER TABLE payments MODIFY COLUMN status ENUM('pending','paid','expired','failed') NOT NULL DEFAULT 'pending'");
    }
};
