<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('asaas_subscription_id')->unique();
            $table->enum('plan', ['free', 'pro']);
            $table->enum('status', ['active', 'inactive', 'overdue', 'cancelled'])->default('active');
            $table->decimal('amount', 8, 2);
            $table->enum('billing_cycle', ['MONTHLY', 'YEARLY'])->default('MONTHLY');
            $table->date('next_due_date')->nullable();
            $table->timestamp('last_payment_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
