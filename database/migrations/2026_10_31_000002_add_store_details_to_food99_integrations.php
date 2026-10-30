<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // O que shop/detail e o callback shopStatus informam da loja: horários, modo de abertura,
        // preparo, modo ocupado e indisponibilidade. activated_at é o início da contagem do
        // alerta de callback silencioso enquanto a loja ainda não recebeu nenhum evento;
        // catalog_queued_at solta o botão de envio se o job do cardápio se perder na fila.
        Schema::table('food99_integrations', function (Blueprint $table) {
            $table->timestamp('activated_at')->nullable()->after('status');
            $table->unsignedTinyInteger('auto_switch')->nullable()->after('sub_biz_status');
            $table->unsignedInteger('promise_produce_time')->nullable()->after('auto_switch');
            $table->json('business_hours')->nullable()->after('promise_produce_time');
            $table->json('busy_mode')->nullable()->after('business_hours');
            $table->string('store_unavailable_reason')->nullable()->after('busy_mode');
            $table->timestamp('store_unavailable_until')->nullable()->after('store_unavailable_reason');
            $table->timestamp('catalog_queued_at')->nullable()->after('catalog_sync_error');
        });
    }

    public function down(): void
    {
        Schema::table('food99_integrations', function (Blueprint $table) {
            $table->dropColumn([
                'activated_at',
                'auto_switch',
                'promise_produce_time',
                'business_hours',
                'busy_mode',
                'store_unavailable_reason',
                'store_unavailable_until',
                'catalog_queued_at',
            ]);
        });
    }
};
