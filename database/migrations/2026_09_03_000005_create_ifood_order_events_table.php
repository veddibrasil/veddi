<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ifood_order_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('event_type');
            $table->string('source'); // webhook | polling
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ifood_integration_id')->constrained()->cascadeOnDelete();
            $table->json('payload');
            $table->string('status')->default('pending'); // pending | processed | failed
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['ifood_integration_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ifood_order_events');
    }
};
