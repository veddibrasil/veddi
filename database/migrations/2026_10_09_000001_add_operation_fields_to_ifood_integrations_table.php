<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ifood_integrations', function (Blueprint $table) {
            $table->string('merchant_name')->nullable()->after('merchant_id');
            // Loja do iFood dona do catalog_id, das categorias e dos itens gravados pra esta filial:
            // trocar de loja exige recomeçar o mapeamento (os ids não existem na loja nova).
            $table->string('catalog_merchant_id')->nullable()->after('catalog_id');
            // Publicação do cardápio é opt-in: sem esta data, nenhum sync automático envia itens.
            $table->timestamp('catalog_published_at')->nullable()->after('catalog_merchant_id');
            $table->string('catalog_sync_status', 20)->nullable()->after('catalog_published_at');
            $table->text('catalog_sync_error')->nullable()->after('catalog_sync_status');
            // last_synced_at passa a ser só do cardápio; o polling grava aqui.
            $table->timestamp('last_polled_at')->nullable()->after('last_synced_at');
            $table->json('alerts_sent')->nullable()->after('last_webhook_received_at');
        });

        // Integrações já conectadas publicaram o cardápio ao ativar (comportamento anterior):
        // continuam com o sync automático.
        DB::table('ifood_integrations')
            ->whereNotNull('merchant_id')
            ->update([
                'catalog_published_at' => DB::raw('COALESCE(last_synced_at, updated_at)'),
                'catalog_merchant_id' => DB::raw('merchant_id'),
                // Ponto de partida do monitor de polling (sem ele a loja nunca seria avaliada).
                'last_polled_at' => now(),
            ]);
    }

    public function down(): void
    {
        Schema::table('ifood_integrations', function (Blueprint $table) {
            $table->dropColumn([
                'merchant_name',
                'catalog_merchant_id',
                'catalog_published_at',
                'catalog_sync_status',
                'catalog_sync_error',
                'last_polled_at',
                'alerts_sent',
            ]);
        });
    }
};
