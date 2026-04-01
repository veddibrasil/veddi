<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('type', ['pix', 'cartao', 'boleto']);
            $table->enum('status', ['pending', 'confirmed', 'released', 'withdrawn', 'refunded', 'chargeback'])
                  ->default('pending');
            $table->decimal('value', 10, 2);
            $table->decimal('net_value', 10, 2);
            $table->date('payment_date');
            $table->date('release_date');
            $table->boolean('withdrawn')->default(false);
            $table->timestamp('withdrawn_at')->nullable();
            $table->foreignId('withdrawal_id')
                  ->nullable()
                  ->constrained('company_withdrawals')
                  ->nullOnDelete();
            $table->boolean('is_anticipated')->default(false);
            $table->decimal('anticipation_fee', 10, 2)->default(0);
            $table->string('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status', 'release_date'], 'ct_company_status_release_idx');
            $table->index(['company_id', 'withdrawn'], 'ct_company_withdrawn_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_transactions');
    }
};
