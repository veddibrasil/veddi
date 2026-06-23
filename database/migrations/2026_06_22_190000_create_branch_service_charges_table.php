<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_service_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->boolean('service_fee_enabled')->default(false);
            $table->enum('service_fee_type', ['fixed', 'percent'])->default('percent');
            $table->decimal('service_fee_value', 8, 2)->default(0);
            $table->boolean('couvert_enabled')->default(false);
            $table->enum('couvert_type', ['fixed', 'percent'])->default('fixed');
            $table->decimal('couvert_value', 8, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_service_charges');
    }
};
