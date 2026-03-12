<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_user', function (Blueprint $table) {
            $table->string('role', 100)->default('company_admin')->change();
        });
    }

    public function down(): void
    {
        Schema::table('company_user', function (Blueprint $table) {
            $table->enum('role', ['company_admin', 'branch_manager'])->default('company_admin')->change();
        });
    }
};
