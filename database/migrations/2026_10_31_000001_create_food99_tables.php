<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Uma integração por filial. app_shop_id é gerado pelo VEDDI (veddi-{empresa}-{filial})
        // e identifica a filial em todo webhook; shop_id é o id da loja na 99 (int64, guardado
        // como string). Os dois são únicos: a mesma loja em duas empresas misturaria pedidos.
        Schema::create('food99_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('app_shop_id', 64)->unique();
            $table->string('shop_id', 32)->nullable()->unique();
            $table->string('shop_name')->nullable();
            $table->json('available_shops')->nullable();
            $table->text('auth_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('last_token_refreshed_at')->nullable();
            $table->text('authorization_url')->nullable();
            $table->timestamp('authorization_url_expires_at')->nullable();
            $table->string('status', 30)->default('disconnected');
            $table->unsignedTinyInteger('order_confirm_method')->nullable();
            $table->boolean('receive_cancel_apply')->default(true);
            $table->boolean('receive_refund_apply')->default(true);
            $table->unsignedTinyInteger('store_status')->nullable();
            $table->unsignedTinyInteger('sub_biz_status')->nullable();
            $table->timestamp('store_status_updated_at')->nullable();
            $table->string('webhook_status', 20)->default('unknown');
            $table->timestamp('last_webhook_received_at')->nullable();
            $table->string('last_request_id', 64)->nullable();
            $table->timestamp('catalog_published_at')->nullable();
            $table->string('catalog_sync_status', 20)->nullable();
            $table->text('catalog_sync_error')->nullable();
            $table->string('catalog_task_id', 32)->nullable();
            $table->timestamp('catalog_synced_at')->nullable();
            $table->json('alerts_sent')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'branch_id']);
        });

        // Todo callback recebido. A 99 não manda id de evento: dedupe_key é derivada do tipo
        // e do pedido/solicitação/task (ver Food99WebhookController::dedupeKey). Sem
        // BelongsToCompany: shopBindStatus chega sem loja; o isolamento é pela integração.
        Schema::create('food99_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('dedupe_key', 191)->unique();
            $table->foreignId('food99_integration_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type', 40);
            $table->string('external_order_id', 32)->nullable()->index();
            $table->string('apply_id', 32)->nullable();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->json('payload');
            $table->string('request_id', 64)->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['food99_integration_id', 'status']);
        });

        // Pedido de cancelamento (orderCancelApply) ou de reembolso (orderRefundApply) do cliente.
        // Sem resposta: cancelamento expira recusado em 5 min; reembolso expira aceito em 24 h.
        Schema::create('food99_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('food99_integration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_order_id', 32)->index();
            $table->string('kind', 10);
            $table->string('apply_id', 32)->unique();
            $table->text('apply_reason')->nullable();
            $table->json('reason_options')->nullable();
            $table->json('images')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('status', 20)->default('open');
            $table->foreignId('responded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('responded_at')->nullable();
            $table->string('response_reason', 500)->nullable();
            $table->timestamps();
        });

        Schema::table('branch_product', function (Blueprint $table) {
            // Resultado do último envio do item à 99Food nesta filial (operationList da task de upload).
            $table->string('food99_sync_status', 20)->nullable();
            $table->string('food99_sync_error')->nullable();
            $table->timestamp('food99_synced_at')->nullable();
        });

        Schema::table('products', function (Blueprint $table) {
            // [VALIDAR 99Food] padrão true como o iFood; publicação do cardápio continua opt-in.
            $table->boolean('available_in_99food')->default(true)->after('available_in_ifood');
        });

        Schema::table('customers', function (Blueprint $table) {
            // Cliente 99Food achado pelo uid dele (telefone vem mascarado/virtual), único por empresa.
            $table->string('food99_customer_id', 64)->nullable()->after('ifood_customer_id');
            $table->unique(['company_id', 'food99_customer_id']);
        });

        Schema::table('branch_pauses', function (Blueprint $table) {
            // Pausa da filial replicada como fechamento/pausa da loja na 99Food (opcional).
            $table->boolean('food99_sync')->default(false)->after('ifood_interruption_id');
            $table->string('food99_sync_state', 20)->nullable()->after('food99_sync');
        });
    }

    public function down(): void
    {
        Schema::table('branch_pauses', function (Blueprint $table) {
            $table->dropColumn(['food99_sync', 'food99_sync_state']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'food99_customer_id']);
            $table->dropColumn('food99_customer_id');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('available_in_99food');
        });

        Schema::table('branch_product', function (Blueprint $table) {
            $table->dropColumn(['food99_sync_status', 'food99_sync_error', 'food99_synced_at']);
        });

        Schema::dropIfExists('food99_requests');
        Schema::dropIfExists('food99_webhook_events');
        Schema::dropIfExists('food99_integrations');
    }
};
