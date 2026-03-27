<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE company_wallet_entries MODIFY COLUMN type ENUM('credit', 'fee', 'withdrawal', 'refund')");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE company_wallet_entries MODIFY COLUMN type ENUM('credit', 'fee', 'withdrawal')");
    }
};
