<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name', 100);
            $table->text('description')->nullable();

            // Tipo de desconto
            $table->enum('type', ['percentage', 'fixed', 'free_delivery', 'free_product']);
            $table->decimal('discount_value', 8, 2)->nullable(); // percentage ou fixed
            $table->foreignId('free_product_id')->nullable()->constrained('products')->nullOnDelete();

            // Escopo (somente para percentage/fixed)
            $table->enum('scope', ['order', 'category', 'product'])->default('order');
            $table->json('scope_ids')->nullable(); // array de category_ids ou product_ids

            // Restrições
            $table->decimal('minimum_order_value', 8, 2)->nullable();
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('max_uses_per_customer')->nullable()->default(1);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
