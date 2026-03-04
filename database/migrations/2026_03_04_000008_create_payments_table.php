<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('abacatepay_billing_id')->unique();
            $table->string('abacatepay_url')->nullable();
            $table->text('pix_qr_code')->nullable();
            $table->text('pix_copy_paste')->nullable();
            $table->decimal('amount', 8, 2);
            $table->enum('status', ['pending', 'paid', 'expired', 'failed'])->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->json('webhook_payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
