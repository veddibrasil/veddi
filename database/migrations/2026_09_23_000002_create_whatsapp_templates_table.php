<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_connection_id')->constrained('whatsapp_connections')->cascadeOnDelete();
            // Chave em config/whatsapp_templates.php (ex.: ready_pickup)
            $table->string('event', 40);
            $table->string('name');
            $table->string('language', 10)->default('pt_BR');
            $table->string('meta_template_id')->nullable();
            // PENDING | APPROVED | REJECTED | PAUSED | DISABLED
            $table->string('status', 20)->default('PENDING');
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->unique(['whatsapp_connection_id', 'name', 'language'], 'wa_templates_connection_name_lang_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_templates');
    }
};
