<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('confirmation_email_sent_at')->nullable()->after('delivered_email_sent_at');
            $table->index('confirmation_email_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['confirmation_email_sent_at']);
            $table->dropColumn('confirmation_email_sent_at');
        });
    }
};
