<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('order_number')->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->decimal('subtotal', 8, 2);
            $table->decimal('total', 8, 2);
            $table->decimal('delivery_fee', 8, 2)->default(0);
            $table->decimal('discount', 8, 2)->default(0);
            $table->decimal('fee', 8, 2)->nullable();
            $table->decimal('net_value', 8, 2)->nullable();
            $table->enum('status', [
                'pending',
                'awaiting_payment',
                'paid',
                'preparing',
                'ready',
                'delivered',
                'cancelled',
            ])->default('pending');
            $table->string('payment_method')->nullable();
            $table->enum('order_type', ['delivery', 'pickup'])->nullable();
            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('fee_billed_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'created_at'], 'orders_company_created_at_idx');
            $table->index(['branch_id', 'status'], 'orders_branch_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
