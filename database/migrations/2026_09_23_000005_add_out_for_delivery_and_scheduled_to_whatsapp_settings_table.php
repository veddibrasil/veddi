<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_settings', function (Blueprint $table) {
            $table->boolean('notify_on_scheduled')->default(true)->after('notify_on_paid');
            $table->boolean('notify_on_out_for_delivery')->default(true)->after('notify_on_ready');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_settings', function (Blueprint $table) {
            $table->dropColumn(['notify_on_scheduled', 'notify_on_out_for_delivery']);
        });
    }
};
