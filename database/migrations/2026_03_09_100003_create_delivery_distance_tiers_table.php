<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_distance_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_setting_id')->constrained()->cascadeOnDelete();
            $table->decimal('min_km', 5, 2)->default(0);
            $table->decimal('max_km', 5, 2)->nullable();
            $table->decimal('fee', 8, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_distance_tiers');
    }
};
