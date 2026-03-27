<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->enum('fee_type', ['flat', 'neighborhood', 'distance'])->default('flat');
            $table->decimal('flat_fee', 8, 2)->default(0);
            $table->decimal('minimum_order_value', 8, 2)->default(0);
            $table->decimal('free_delivery_above', 8, 2)->nullable();
            $table->decimal('branch_latitude', 10, 7)->nullable();
            $table->decimal('branch_longitude', 10, 7)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_settings');
    }
};
