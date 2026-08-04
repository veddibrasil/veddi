<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_printers', function (Blueprint $table) {
            $table->string('connection_type', 10)->default('network')->after('station');
            $table->string('printer_name')->nullable()->after('name');
            $table->string('ip_address')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('branch_printers', function (Blueprint $table) {
            $table->dropColumn(['connection_type', 'printer_name']);
            $table->string('ip_address')->nullable(false)->change();
        });
    }
};
