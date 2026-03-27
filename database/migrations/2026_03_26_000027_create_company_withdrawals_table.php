<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->enum('status', ['pending', 'processing', 'done', 'failed'])->default('pending');
            $table->enum('payout_type', ['PIX', 'TED']);
            $table->string('pix_key')->nullable();
            $table->string('pix_key_type')->nullable();
            $table->string('bank_code')->nullable();
            $table->string('bank_agency')->nullable();
            $table->string('bank_account')->nullable();
            $table->string('bank_account_digit')->nullable();
            $table->string('bank_account_type')->nullable();
            $table->string('bank_owner_cpf_cnpj')->nullable();
            $table->string('bank_owner_name')->nullable();
            $table->string('asaas_transfer_id')->nullable();
            $table->json('asaas_response')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_withdrawals');
    }
};
