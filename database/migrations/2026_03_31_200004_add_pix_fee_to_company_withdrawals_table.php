<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_withdrawals', function (Blueprint $table) {
            $table->decimal('pix_fee', 10, 2)->default(0)->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('company_withdrawals', function (Blueprint $table) {
            $table->dropColumn('pix_fee');
        });
    }
};
