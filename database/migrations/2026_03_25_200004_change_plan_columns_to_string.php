<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Change plan columns from ENUM to VARCHAR so new plans can be added
 * without requiring a schema migration each time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('plan', 30)->default('free')->change();
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('plan', 30)->change();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->enum('plan', ['free', 'pro'])->default('free')->change();
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->enum('plan', ['free', 'pro'])->change();
        });
    }
};
