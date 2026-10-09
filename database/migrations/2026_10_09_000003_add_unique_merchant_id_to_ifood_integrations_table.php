<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Uma loja do iFood só pode estar ligada a uma integração (de qualquer empresa): com duas,
     * o webhook e o polling entregam pedidos de uma empresa na outra. Desconectar e reiniciar a
     * conexão zeram merchant_id, então NULLs repetidos continuam permitidos.
     *
     * Se o banco já tiver duplicidade, o índice não é criado (o deploy não trava) e o caso vai
     * pro log; IfoodAuthService::ensureMerchantAvailable continua bloqueando novos conflitos.
     */
    public function up(): void
    {
        $duplicates = DB::table('ifood_integrations')
            ->whereNotNull('merchant_id')
            ->groupBy('merchant_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('merchant_id');

        if ($duplicates->isNotEmpty()) {
            Log::channel('ifood')->critical('iFood: lojas ligadas a mais de uma integração; índice único de merchant_id não criado', [
                'merchant_ids' => $duplicates->all(),
            ]);

            return;
        }

        Schema::table('ifood_integrations', function (Blueprint $table) {
            $table->unique('merchant_id', 'ifood_integrations_merchant_id_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasIndex('ifood_integrations', 'ifood_integrations_merchant_id_unique')) {
            return;
        }

        Schema::table('ifood_integrations', function (Blueprint $table) {
            $table->dropUnique('ifood_integrations_merchant_id_unique');
        });
    }
};
