<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_neighborhoods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_setting_id')->constrained()->cascadeOnDelete();
            $table->string('neighborhood', 100);
            $table->decimal('fee', 8, 2);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_neighborhoods');
    }
};
