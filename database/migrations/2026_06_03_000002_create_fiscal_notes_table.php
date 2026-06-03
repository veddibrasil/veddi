<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('status', ['pending', 'authorized', 'rejected', 'cancelled', 'error'])->default('pending');
            $table->string('provider_reference')->nullable();
            $table->char('access_key', 44)->nullable()->index();
            $table->json('data')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_notes');
    }
};
