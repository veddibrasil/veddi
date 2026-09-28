<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // null = enviada pelo número da plataforma (fallback/homologação)
            $table->foreignId('whatsapp_connection_id')->nullable()->constrained('whatsapp_connections')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            // Evento de notificação (new_order, paid, ready, ...) — não o nome do template.
            $table->string('event', 40);
            $table->string('template');
            $table->string('to_phone', 20);
            $table->string('wamid')->nullable()->unique();
            // queued | sent | delivered | read | failed
            $table->string('status', 20)->default('queued');
            $table->string('error_code', 20)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // Impede notificação duplicada por pedido/evento.
            $table->unique(['order_id', 'event'], 'wa_messages_order_event_unique');
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
    }
};
