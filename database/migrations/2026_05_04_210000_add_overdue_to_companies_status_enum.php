<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->enum('status', ['PENDING_PAYMENT', 'ACTIVE', 'OVERDUE', 'BLOCKED'])->default('ACTIVE')->change();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->enum('status', ['PENDING_PAYMENT', 'ACTIVE', 'BLOCKED'])->default('ACTIVE')->change();
        });
    }
};
