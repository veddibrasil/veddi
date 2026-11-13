<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('month', 7);
            $table->string('environment');
            $table->string('status')->default('processing');
            $table->json('note_ids');
            $table->unsignedInteger('documents_count')->default(0);
            $table->unsignedInteger('errors_count')->default(0);
            $table->string('path')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'branch_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_exports');
    }
};
