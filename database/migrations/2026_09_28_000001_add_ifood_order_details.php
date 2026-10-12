<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // O iFood manda o mesmo 0800 (com localizador por pedido) pra todos os clientes,
        // então o telefone não identifica ninguém. O cliente iFood é achado pelo customer.id
        // dele, único por empresa.
        Schema::table('customers', function (Blueprint $table) {
            $table->string('ifood_customer_id', 64)->nullable()->after('phone');
            $table->unique(['company_id', 'ifood_customer_id']);
        });

        // Observação por item ("sem cebola"): vem no pedido iFood e precisa sair na comanda.
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('notes', 500)->nullable()->after('options');
        });

        // Tentativas de processar o evento: falha transitória volta pra 'pending' e é
        // reprocessada (retry da fila ou próximo polling) até o limite do job.
        Schema::table('ifood_order_events', function (Blueprint $table) {
            $table->unsignedSmallInteger('attempts')->default(0)->after('status');
        });

        // Plataforma de Negociação do iFood: cada disputa (HANDSHAKE_DISPUTE) aberta pelo
        // cliente num pedido, com prazo, alternativas oferecidas e o desfecho (HANDSHAKE_SETTLEMENT).
        Schema::create('ifood_disputes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ifood_integration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('dispute_id')->unique();
            $table->string('ifood_order_id')->index();
            $table->string('action', 40)->nullable();
            $table->string('handshake_type', 40)->nullable();
            $table->string('timeout_action', 60)->nullable();
            $table->text('message')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('alternatives')->nullable();
            $table->json('metadata')->nullable();
            $table->string('status', 30)->default('pending');
            $table->string('response', 30)->nullable();
            $table->string('response_reason', 250)->nullable();
            $table->foreignId('responded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('responded_at')->nullable();
            $table->json('settlement')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ifood_disputes');

        Schema::table('ifood_order_events', function (Blueprint $table) {
            $table->dropColumn('attempts');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('notes');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'ifood_customer_id']);
            $table->dropColumn('ifood_customer_id');
        });
    }
};
