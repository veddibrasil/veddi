<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', fn (Blueprint $table) => $table->unsignedSmallInteger('chat_acceptance_timeout_minutes')->default(0));
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('chat_acceptance_expires_at')->nullable()->index();
            $table->timestamp('chat_accepted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['chat_acceptance_expires_at']);
            $table->dropColumn(['chat_acceptance_expires_at', 'chat_accepted_at']);
        });
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('chat_acceptance_timeout_minutes'));
    }
};
