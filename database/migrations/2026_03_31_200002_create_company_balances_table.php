<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('total_balance', 10, 2)->default(0);
            $table->decimal('blocked_balance', 10, 2)->default(0);
            $table->decimal('available_balance', 10, 2)->default(0);
            $table->decimal('withdrawn_balance', 10, 2)->default(0);
            $table->decimal('reserve_balance', 10, 2)->default(0);
            $table->timestamp('last_calculated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_balances');
    }
};
