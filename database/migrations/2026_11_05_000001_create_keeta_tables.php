<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Uma integração por filial. merchant_ref é o nosso merchantId no Open Delivery (36 a 100
        // caracteres, gerado pelo VEDDI) e chega em todo webhook (X-App-MerchantId) e em todo
        // pedido (merchant.id); keeta_merchant_id é o id da loja na Keeta (int64, guardado como
        // string). Os dois são únicos: a mesma loja em duas empresas misturaria pedidos.
        Schema::create('keeta_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('merchant_ref', 100)->unique();
            $table->string('keeta_merchant_id', 32)->nullable()->unique();
            $table->string('shop_name')->nullable();
            $table->string('shop_address')->nullable();
            $table->string('auth_id', 64)->nullable();
            $table->json('available_shops')->nullable();
            $table->string('authorization_state', 64)->nullable();
            $table->string('status', 30)->default('disconnected');
            $table->timestamp('activated_at')->nullable();
            $table->text('menu_api_key')->nullable();
            $table->boolean('auto_accept')->default(true);
            $table->string('store_status', 20)->nullable();
            $table->timestamp('store_status_updated_at')->nullable();
            $table->string('webhook_status', 20)->default('unknown');
            $table->timestamp('last_webhook_received_at')->nullable();
            $table->timestamp('last_polled_at')->nullable();
            $table->timestamp('last_menu_fetched_at')->nullable();
            $table->timestamp('catalog_published_at')->nullable();
            $table->string('catalog_sync_status', 20)->nullable();
            $table->text('catalog_sync_error')->nullable();
            $table->timestamp('catalog_synced_at')->nullable();
            $table->json('alerts_sent')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'branch_id']);
        });

        // Todo evento de pedido recebido (webhook ou polling) e de autorização (1301/1302). A Keeta
        // manda eventId: é a chave de dedupe (o mesmo evento pelo webhook e pelo polling vira um só).
        // Sem BelongsToCompany: evento de polling chega sem loja; o isolamento é pela integração.
        Schema::create('keeta_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 191)->unique();
            $table->foreignId('keeta_integration_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type', 40);
            $table->string('external_order_id', 40)->nullable()->index();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 20)->default('webhook');
            $table->json('payload');
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('error')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['keeta_integration_id', 'status']);
        });

        // Pedido do cliente na Keeta: cancelamento (CANCELLATION_REQUESTED) ou reembolso
        // (USER_REFUND_REQUEST). Vale a primeira resposta, pela API ou pelo portal da Keeta.
        Schema::create('keeta_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('keeta_integration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_order_id', 40)->index();
            $table->string('kind', 10);
            $table->string('event_id', 191)->unique();
            $table->text('reason')->nullable();
            $table->decimal('amount', 10, 2)->nullable();
            $table->json('items')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('status', 20)->default('open');
            $table->foreignId('responded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('responded_at')->nullable();
            $table->string('response_reason', 500)->nullable();
            $table->timestamps();
        });

        Schema::table('branch_product', function (Blueprint $table) {
            // Resultado do último cardápio montado para a Keeta nesta filial (item fora e por quê).
            $table->string('keeta_sync_status', 20)->nullable();
            $table->string('keeta_sync_error')->nullable();
            $table->timestamp('keeta_synced_at')->nullable();
        });

        Schema::table('products', function (Blueprint $table) {
            // Padrão true como iFood e 99Food; a publicação do cardápio continua opt-in.
            $table->boolean('available_in_keeta')->default(true)->after('available_in_99food');
        });

        Schema::table('customers', function (Blueprint $table) {
            // Cliente Keeta achado pelo customer.id da Keeta (telefone vem criptografado), único por empresa.
            $table->string('keeta_customer_id', 64)->nullable()->after('food99_customer_id');
            $table->unique(['company_id', 'keeta_customer_id']);
        });

        Schema::table('branch_pauses', function (Blueprint $table) {
            // Pausa da filial replicada como loja fechada na Keeta (opcional).
            $table->boolean('keeta_sync')->default(false)->after('food99_sync_state');
            $table->string('keeta_sync_state', 20)->nullable()->after('keeta_sync');
        });
    }

    public function down(): void
    {
        Schema::table('branch_pauses', function (Blueprint $table) {
            $table->dropColumn(['keeta_sync', 'keeta_sync_state']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'keeta_customer_id']);
            $table->dropColumn('keeta_customer_id');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('available_in_keeta');
        });

        Schema::table('branch_product', function (Blueprint $table) {
            $table->dropColumn(['keeta_sync_status', 'keeta_sync_error', 'keeta_synced_at']);
        });

        Schema::dropIfExists('keeta_requests');
        Schema::dropIfExists('keeta_webhook_events');
        Schema::dropIfExists('keeta_integrations');
    }
};
