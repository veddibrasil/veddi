<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->timestamp('terms_accepted_at')->nullable()->after('setup_fee_paid_at');
            $table->foreignId('terms_accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete()->after('terms_accepted_at');
            $table->string('terms_version', 20)->nullable()->after('terms_accepted_by_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropForeign(['terms_accepted_by_user_id']);
            $table->dropColumn(['terms_accepted_at', 'terms_accepted_by_user_id', 'terms_version']);
        });
    }
};
