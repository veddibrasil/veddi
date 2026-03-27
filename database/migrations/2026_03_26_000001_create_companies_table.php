<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('subdomain')->unique()->nullable();
            $table->string('primary_color')->default('#5c347f');
            $table->string('primary_color_dark')->default('#19273c');
            $table->string('primary_color_light')->default('#5c347f');
            $table->string('secondary_color')->default('#e36831');
            $table->string('secondary_color_light')->default('#D97706');
            $table->string('accent_color')->default('#cad1d8');
            $table->string('logo_path')->nullable();
            $table->string('favicon_path')->nullable();
            $table->string('tagline')->nullable();
            $table->string('footer_text')->nullable();
            $table->json('chat_highlights')->nullable();
            $table->string('order_prefix', 10)->default('ORD');
            $table->boolean('active')->default(true);
            $table->string('plan', 30)->nullable();
            $table->string('pending_plan', 30)->nullable();
            $table->enum('status', ['PENDING_PAYMENT', 'ACTIVE', 'BLOCKED'])->default('ACTIVE');
            $table->string('asaas_customer_id')->nullable();
            $table->string('asaas_subscription_id')->nullable();
            $table->string('asaas_setup_charge_id')->nullable();
            $table->timestamp('setup_fee_paid_at')->nullable();
            $table->string('default_payout_type')->nullable();
            $table->string('default_pix_key')->nullable();
            $table->string('default_pix_key_type')->nullable();
            $table->string('default_bank_code')->nullable();
            $table->string('default_bank_agency')->nullable();
            $table->string('default_bank_account')->nullable();
            $table->string('default_bank_account_digit')->nullable();
            $table->string('default_bank_account_type')->nullable();
            $table->string('default_bank_owner_cpf_cnpj')->nullable();
            $table->string('default_bank_owner_name')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
