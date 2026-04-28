<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->string('gateway', 20);
            $table->decimal('amount', 10, 2);
            $table->string('status', 20)->index();
            $table->string('reason', 50)->nullable();
            $table->string('requested_by_type', 20)->nullable();
            $table->unsignedBigInteger('requested_by_id')->nullable();
            $table->string('external_refund_id')->nullable()->index();
            $table->string('external_status')->nullable();
            $table->json('raw_request')->nullable();
            $table->json('raw_response')->nullable();
            $table->string('failure_code')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamp('requested_at')->nullable()->index();
            $table->timestamp('processed_at')->nullable()->index();
            $table->timestamps();

            $table->index(['company_id', 'created_at'], 'refunds_company_created_at_idx');
            $table->unique(['payment_id', 'amount', 'status'], 'refunds_payment_amount_status_uniq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_refunds');
    }
};
