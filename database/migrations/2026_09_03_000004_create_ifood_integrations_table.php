<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ifood_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('merchant_id')->nullable()->index();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->string('user_code')->nullable()->unique();
            $table->text('authorization_code_verifier')->nullable();
            $table->string('verification_url')->nullable();
            $table->timestamp('user_code_expires_at')->nullable();
            $table->string('status')->default('disconnected');
            $table->string('webhook_status')->default('unknown');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_webhook_received_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'merchant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ifood_integrations');
    }
};
