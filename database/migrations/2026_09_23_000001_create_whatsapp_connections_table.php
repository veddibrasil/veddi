<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Uma conexão por empresa. O número da plataforma (homologação/fallback)
        // NÃO entra aqui — vem de config('services.whatsapp.platform').
        Schema::create('whatsapp_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            // Não é unique: a checagem "WABA já vinculada a outra empresa" é feita no
            // onboarding (com erro amigável); o webhook resolve por phone_number_id antes.
            $table->string('waba_id')->nullable()->index();
            $table->string('phone_number_id')->nullable()->unique();
            $table->string('display_phone_number')->nullable();
            $table->string('verified_name')->nullable();
            $table->text('access_token')->nullable();
            $table->json('token_scopes')->nullable();
            $table->text('registration_pin')->nullable();
            // cloud_api | coexistence
            $table->string('onboarding_type', 20)->default('cloud_api');
            // pending | provisioning | templates_pending | active | disconnected | error
            $table->string('status', 30)->default('pending')->index();
            $table->string('quality_rating', 20)->nullable();
            $table->string('messaging_limit_tier', 40)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->timestamp('last_app_activity_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_connections');
    }
};
